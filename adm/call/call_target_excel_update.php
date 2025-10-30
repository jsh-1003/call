<?php
// /adm/call_target_excel_update.php
$sub_menu = '700700';
include_once('./_common.php');

set_time_limit(0);
ini_set('memory_limit', '512M');

auth_check_menu($auth, $sub_menu, "w");

function only_number($n) { return preg_replace('/[^0-9]/', '', (string)$n); }
function sql_quote_or_null($v) { return ($v === null || $v === '') ? "NULL" : "'".sql_escape_string($v)."'"; }

// ===== CSRF 체크 =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['call_upload_token'] ?? '')) {
    alert_close('잘못된 요청입니다.(CSRF)');
}
$chk = check_admin_token();
if(!$chk) {
    goto_url('/adm/call/call_target_excel.php');
}

$my_level = (int)$member['mb_level'];
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$mb_group = ($my_level >= 8) ? (int)($_POST['mb_group'] ?? 0) : $my_group;
if (!$mb_group) alert_close('지점이 선택되지 않았습니다.');

// 회사 ID (지점→회사 캐시 헬퍼 사용)
$company_id = (int)get_company_id_from_group_id_cached($mb_group);

// ===== is_open_number 결정(캠페인 플래그) =====
// 레벨 9+만 체크박스 허용: 체크되면 0(1차 비공개), 미체크=1(공개)
// 레벨 9 미만은 항상 1(공개) 강제
$is_open_number = 1;
if ($my_level >= 9) {
    $is_open_number = isset($_POST['is_open_number0']) ? 0 : 1;
}

// 메모도 NFC로
$memo = isset($_POST['memo']) ? k_nfc(strip_tags(clean_xss_attributes($_POST['memo']))) : '';

// ===== 성별 변환 유틸 =====
// 0=모름, 1=남, 2=여
function normalize_sex($v) {
    $v = trim(mb_strtolower((string)$v));
    if ($v === '') return 0;
    // 숫자 직접 입력 케이스
    if ($v === '1' || $v === '남' || $v === '남자' || $v === '남성' || $v === 'm' || $v === 'male') return 1;
    if ($v === '2' || $v === '여' || $v === '여자' || $v === '여성' || $v === 'f' || $v === 'female') return 2;
    return 0;
}

// ===== 생년월일(+성별) 파싱 =====
// 반환: [birth_date|null, sex(0/1/2)]
function parse_birth_and_sex($s) {
    $s = trim((string)$s);
    if ($s === '') return [null, 0];

    // Excel serial number
    if (is_numeric($s)) {
        $ival = (int)$s;
        if ($ival > 30000 && $ival < 90000) {
            $base = new DateTime('1899-12-30');
            $base->modify("+{$ival} days");
            return [$base->format('Y-m-d'), 0];
        }
    }

    // 주민번호형 yymmddX 또는 yymmdd-X (예: 8310031, 831003-1)
    $digits = preg_replace('/\D+/', '', $s); // 하이픈 제거
    if ($digits !== '') {
        // yyyymmdd
        if (strlen($digits) === 8) {
            $y=(int)substr($digits,0,4);
            $m=(int)substr($digits,4,2);
            $d=(int)substr($digits,6,2);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
        }
        // yymmdd
        if (strlen($digits) === 6) {
            $yy=(int)substr($digits,0,2);
            $y=($yy>=70)?(1900+$yy):(2000+$yy);
            $m=(int)substr($digits,2,2);
            $d=(int)substr($digits,4,2);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
        }
        // yymmddX (7자리) -> 성별 포함 패턴
        if (strlen($digits) === 7) {
            $yy=(int)substr($digits,0,2);
            $m =(int)substr($digits,2,2);
            $d =(int)substr($digits,4,2);
            $x =(int)substr($digits,6,1); // 성별/세기 코드
            // 세기 결정
            if (in_array($x, [1,2,5,6,7,8], true)) $y = 1900 + $yy;
            elseif (in_array($x, [3,4,7,8], true)) $y = 2000 + $yy; // 7,8은 외국인 코드(세부 구분 무시)
            else $y = ($yy>=70)?(1900+$yy):(2000+$yy); // fallback
            $sex = ($x===1 || $x===3 || $x===5 || $x===7) ? 1 : (($x===2 || $x===4 || $x===6 || $x===8) ? 2 : 0);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), $sex];
        }
        // 6+1 with hyphen은 위에서 하이픈 제거로 동일 처리
    }

    // 일반 구분자 파싱(yyyy-mm-dd / yy-mm-dd / yyyy.mm.dd 등)
    $s2 = str_replace(['.','년','월','일'], ['-','','-',''], $s);
    $s2 = preg_replace('/[\/\.]/','-',$s2);
    $parts = array_values(array_filter(explode('-', $s2), fn($v)=>$v!==''));
    if (count($parts) === 3) {
        $y=(int)$parts[0]; $m=(int)$parts[1]; $d=(int)$parts[2];
        if ($y < 100) $y = ($y>=70)?(1900+$y):(2000+$y);
        if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
    }
    return [null, 0];
}

// 기존 호환 위해 wrapper 유지(다른 코드에서 호출 가능)
function parse_birth_date($s) {
    [$date, $sex] = parse_birth_and_sex($s);
    return $date;
}

// ===== 파일 체크 & 로드 =====
if (empty($_FILES['excelfile']['tmp_name'])) alert_close("엑셀 파일을 업로드해 주세요.");

$file = $_FILES['excelfile']['tmp_name'];
// 원본 파일명도 NFC로
$orig_name = k_nfc($_FILES['excelfile']['name']);

include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');

try {
    $objPHPExcel = PHPExcel_IOFactory::load($file);
} catch (Exception $e) {
    alert_close('엑셀 파일을 읽을 수 없습니다: '.$e->getMessage());
}

$sheet = $objPHPExcel->getSheet(0);
$num_rows = $sheet->getHighestRow();
$highestColumn = $sheet->getHighestColumn();

// 첫 행 가져오기 (+ 헤더 셀들 NFC 정규화)
$firstRowRaw = $sheet->rangeToArray('A1:'.$highestColumn.'1', NULL, TRUE, FALSE)[0];
$firstRow = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $firstRowRaw));

// A열이 전화번호 패턴인지 확인 (헤더 유무 감지)
$is_headerless = false;
$first_col = trim((string)$firstRow[0]);
if (preg_match('/^0\d{1,2}-?\d{3,4}-?\d{4}$/', $first_col) || preg_match('/^01\d{8,9}$/', preg_replace('/\D/','',$first_col))) {
    $is_headerless = true;
}

if ($is_headerless) {
    // 헤더 없이 데이터부터 시작
    $start_row = 1;
    $header = [];
    $idx_hp = 0;
    $idx_name = 1;
    $idx_birth = 2;
    $idx_sex = 3;
} else {
    // 정상 헤더 (모두 NFC 정규화됨)
    $start_row = 2;
    $header = $firstRow;

    $name_keys  = ['이름','name','성명','성함','고객명'];
    $hp_keys    = ['전화번호','연락처','휴대폰','핸드폰','폰','전화','tel','phone','hp','call_hp'];
    $birth_keys = ['생년월일','생일','주민번호','birth','birth_date','생년','dob'];
    $sex_keys   = ['성별','남녀','sex','gender'];

    $idx_name = $idx_hp = $idx_birth = $idx_sex = null;
    $header_lc = array_map('mb_strtolower', $header);
    foreach ($header_lc as $i=>$h) {
        if ($idx_name === null  && in_array($h, array_map('mb_strtolower',$name_keys), true))  $idx_name  = $i;
        if ($idx_hp   === null  && in_array($h, array_map('mb_strtolower',$hp_keys), true))    $idx_hp    = $i;
        if ($idx_birth=== null  && in_array($h, array_map('mb_strtolower',$birth_keys), true)) $idx_birth = $i;
        if ($idx_sex  === null  && in_array($h, array_map('mb_strtolower',$sex_keys), true))   $idx_sex   = $i;
    }

    if ($idx_hp === null) {
        alert_close('헤더에 "전화번호" 열이 필요합니다.');
    }
}

// ===== 캠페인 생성 (파일명 기반 + NFC + is_open_number 반영) =====
function create_campaign_from_filename($mb_group, $orig_name, $memo, $is_open_number) {
    $base = pathinfo($orig_name, PATHINFO_FILENAME);
    $base = k_nfc(trim(preg_replace('/\s+/', ' ', $base)));
    $stamp = date('ymd_Hi');
    $name  = mb_substr($base, 0, 80).'_'.$stamp;   // 저장명도 NFC
    $sql = "INSERT INTO call_campaign (mb_group, name, campaign_memo, is_open_number, status, created_at, updated_at)
            VALUES ('{$mb_group}', '".sql_escape_string($name)."', '".sql_escape_string(k_nfc($memo))."', '{$is_open_number}', 1, NOW(), NOW())";
    sql_query($sql);
    return sql_insert_id();
}
$campaign_id = create_campaign_from_filename($mb_group, $orig_name, $memo, $is_open_number);

$batch_id = (int)(microtime(true)*1000) + random_int(1, 999);
$total_count = $stg_count = $skip_count = $ins_count = $dup_count = 0;
$fail_msgs = [];

sql_query("START TRANSACTION");

try {
    for ($i = $start_row; $i <= $num_rows; $i++) {
        $rowData = $sheet->rangeToArray('A'.$i.':'.$highestColumn.$i, NULL, TRUE, FALSE);
        if (!isset($rowData[0])) continue;
        // 각 셀을 문자열화 + 트림 + NFC
        $row = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $rowData[0]));
        $total_count++;

        // 전화번호
        if ($is_headerless) {
            $raw_hp  = (string)$row[0];
        } else {
            $raw_hp  = (string)($row[$idx_hp] ?? '');
        }
        $call_hp = preg_replace('/\D+/', '', $raw_hp);
        if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 잘못된 전화번호 '{$raw_hp}'";
            continue;
        }

        // 이름
        $name = $is_headerless
            ? (isset($row[1]) ? k_nfc((string)$row[1]) : null)
            : (($idx_name !== null) ? k_nfc((string)$row[$idx_name]) : null);

        // 생년월일 + 성별
        $birth_date = null;
        $sex = 0;

        if ($is_headerless) {
            // 2열에 생년월일 가정
            if (isset($row[2])) {
                [$birth_date, $sex_from_birth] = parse_birth_and_sex((string)$row[2]);
                $sex = $sex_from_birth;
            }
        } else {
            // 1) 성별 열이 있으면 우선 사용
            if ($idx_sex !== null && isset($row[$idx_sex])) {
                $sex = normalize_sex($row[$idx_sex]);
            }
            // 2) 생년월일 열 파싱 (성별 미제공/모름인 경우 생년월일 패턴에서 보강)
            if ($idx_birth !== null && isset($row[$idx_birth])) {
                [$parsed_birth, $sex_from_birth] = parse_birth_and_sex((string)$row[$idx_birth]);
                if ($parsed_birth) $birth_date = $parsed_birth;
                if ($sex === 0 && $sex_from_birth > 0) $sex = $sex_from_birth;
            }
        }

        // birth_date가 아직 없고, 메타에 들어있을 수도 있으니 안전하게 한 번 더 시도(헤더풀의 나머지 필드)
        // -> 여기서는 과도한 추론을 피하기 위해 스킵

        // 기타정보(meta): 헤더풀일 때 나머지 컬럼을 JSON으로
        if ($is_headerless) {
            $meta = [];
            for ($k = 3; $k < count($row); $k++) {
                $meta['col'.($k+1)] = k_nfc((string)$row[$k]);
            }
        } else {
            $meta = [];
            foreach ($header as $k=>$h) {
                if ($k === $idx_hp || $k === $idx_name || $k === $idx_birth || $k === $idx_sex) continue;
                $key = k_nfc(trim((string)$h));
                if ($key === '') continue;
                $val = isset($row[$k]) ? k_nfc((string)$row[$k]) : '';
                if ($val !== '') $meta[$key] = $val;
            }
        }
        $meta_json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        // 스테이징 적재
        $sql = "INSERT INTO call_stg_target_upload
                (batch_id, campaign_id, mb_group, call_hp, name, birth_date, sex, meta_json, created_at)
                VALUES
                ('{$batch_id}', '{$campaign_id}', '{$mb_group}', '{$call_hp}', ".sql_quote_or_null($name).", ".sql_quote_or_null($birth_date).", '{$sex}', ".sql_quote_or_null($meta_json).", NOW())";
        $res = sql_query($sql, false);
        if ($res) $stg_count++;
        else {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 스테이징 실패";
        }
    }

    // 최종 적재
    // $ins_sql = "
    //   INSERT IGNORE INTO call_target
    //   (campaign_id, mb_group, call_hp, name, birth_date, sex, meta_json, created_at, updated_at)
    //   SELECT s.campaign_id, s.mb_group, s.call_hp, s.name, s.birth_date, s.sex, s.meta_json, NOW(), NOW()
    //   FROM call_stg_target_upload s
    //   WHERE s.batch_id = '{$batch_id}' AND s.mb_group = '{$mb_group}' AND s.campaign_id = '{$campaign_id}'
    // ";
    // 블랙리스트 제외
    $ins_sql = "
    INSERT IGNORE INTO call_target
    (campaign_id, mb_group, call_hp, name, birth_date, sex, meta_json, created_at, updated_at)
    SELECT s.campaign_id, s.mb_group, s.call_hp, s.name, s.birth_date, s.sex, s.meta_json, NOW(), NOW()
        FROM call_stg_target_upload s
    WHERE s.batch_id   = '{$batch_id}'
        AND s.mb_group   = '{$mb_group}'
        AND s.campaign_id= '{$campaign_id}'
        AND NOT EXISTS (
            SELECT 1
                FROM call_blacklist b
                WHERE b.company_id = {$company_id}
                AND b.call_hp    = s.call_hp
        )
    ";

    sql_query($ins_sql);
    $ins_count = max(0, (int)mysqli_affected_rows($g5['connect_db']));
    $dup_count = max(0, $stg_count - $ins_count);

    sql_query("COMMIT");
} catch (Exception $e) {
    sql_query("ROLLBACK");
    alert_close('처리 중 오류: '.$e->getMessage());
}

$g5['title'] = '엑셀 등록 결과';
include_once(G5_PATH.'/head.sub.php');
?>

<div class="new_win">
    <h1><?php echo $g5['title']; ?></h1>
    <div class="local_desc01 local_desc">
        <p>대상 추가 완료.</p>
        <p>
            <strong>캠페인ID:</strong> <?php echo (int)$campaign_id; ?> /
            <strong>배치ID:</strong> <?php echo (int)$batch_id; ?> /
            <strong>공개여부:</strong> <?php echo ((int)$is_open_number===0 ? '1차 비공개' : '공개'); ?>
        </p>
    </div>

    <dl id="excelfile_result">
        <dt>총 데이터 행</dt><dd><?php echo number_format($total_count); ?></dd>
        <dt>엑셀 처리 성공</dt><dd><?php echo number_format($stg_count); ?></dd>
        <dt>신규 등록</dt><dd><?php echo number_format($ins_count); ?></dd>
        <dt>중복(기존 존재)</dt><dd><?php echo number_format($dup_count); ?></dd>
        <dt>실패(정보 이상)</dt><dd><?php echo number_format($skip_count); ?></dd>
        <?php if (!empty($fail_msgs)) { ?>
        <dt>실패 샘플(최대 20건)</dt>
        <dd><ul style="margin:0;padding-left:18px;"><?php foreach ($fail_msgs as $m) echo '<li>'.get_text($m).'</li>'; ?></ul></dd>
        <?php } ?>
    </dl>
    <div class="btn_win01 btn_win">
        <button type="button" onclick="closeAndRefresh();">창 닫기</button>
    </div>
</div>

<script>
(function(){
  function refreshOpener() {
    var done = false;

    // 1) window.opener (새 창/팝업으로 열린 경우)
    try {
      if (window.opener && !window.opener.closed) {
        // 필요 시 특정 리스트 페이지만 새로고침하고 싶다면 location.href 체크 후 reload
        window.opener.location.reload();
        done = true;
      }
    } catch (e) {}

    // 2) iframe/modal 안에서 열렸을 가능성 (동일 오리진일 때만)
    if (!done) {
      try {
        if (window.parent && window.parent !== window && window.parent.location) {
          window.parent.location.reload();
          done = true;
        }
      } catch (e) {}
    }

    return done;
  }

  // 버튼에서 호출
  window.closeAndRefresh = function(){
    refreshOpener();
    window.close();
  };

  // 사용자가 X 버튼으로 닫거나 브라우저 제스처로 종료할 때도 시도
  window.addEventListener('unload', function(){
    refreshOpener();
  });

  // (선택) 업로드 완료 페이지라면 자동으로 0.3초 뒤 닫기
  // setTimeout(closeAndRefresh, 300);
})();
</script>

<?php include_once(G5_PATH.'/tail.sub.php'); ?>
