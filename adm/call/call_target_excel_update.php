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
if (!$mb_group) alert_close('그룹이 선택되지 않았습니다.');

// 메모도 NFC로
$memo = isset($_POST['memo']) ? k_nfc(strip_tags(clean_xss_attributes($_POST['memo']))) : '';

// ===== 생년월일 파싱 =====
function parse_birth_date($s) {
    $s = trim((string)$s);
    if ($s === '') return null;

    if (is_numeric($s)) {
        $ival = (int)$s;
        if ($ival > 30000 && $ival < 90000) {
            $base = new DateTime('1899-12-30');
            $base->modify("+{$ival} days");
            return $base->format('Y-m-d');
        }
    }

    $digits = preg_replace('/\D+/', '', $s);
    if ($digits !== '') {
        if (strlen($digits) === 8) {
            $y=(int)substr($digits,0,4);
            $m=(int)substr($digits,4,2);
            $d=(int)substr($digits,6,2);
            if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
        }
        if (strlen($digits) === 6) {
            $yy=(int)substr($digits,0,2);
            $y=($yy>=70)?(1900+$yy):(2000+$yy);
            $m=(int)substr($digits,2,2);
            $d=(int)substr($digits,4,2);
            if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
        }
    }

    $s = str_replace(['.','년','월','일'], ['-','','-',''], $s);
    $s = preg_replace('/[\/\.]/','-',$s);
    $parts = array_values(array_filter(explode('-', $s), fn($v)=>$v!==''));
    if (count($parts) === 3) {
        $y=(int)$parts[0]; $m=(int)$parts[1]; $d=(int)$parts[2];
        if ($y < 100) $y = ($y>=70)?(1900+$y):(2000+$y);
        if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
    }
    return null;
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
} else {
    // 정상 헤더 (모두 NFC 정규화됨)
    $start_row = 2;
    $header = $firstRow;

    $name_keys  = ['이름','name','성명'];
    $hp_keys    = ['전화번호','연락처','휴대폰','핸드폰','phone','hp','call_hp'];
    $birth_keys = ['생년월일','생일','birth','birth_date','생년','dob'];

    $idx_name = $idx_hp = $idx_birth = null;
    $header_lc = array_map('mb_strtolower', $header);
    foreach ($header_lc as $i=>$h) {
        if ($idx_name === null  && in_array($h, array_map('mb_strtolower',$name_keys), true))  $idx_name  = $i;
        if ($idx_hp   === null  && in_array($h, array_map('mb_strtolower',$hp_keys), true))    $idx_hp    = $i;
        if ($idx_birth=== null  && in_array($h, array_map('mb_strtolower',$birth_keys), true)) $idx_birth = $i;
    }

    if ($idx_hp === null) {
        alert_close('헤더에 "전화번호" 열이 필요합니다.');
    }
}

// ===== 캠페인 생성 (파일명 기반 + NFC) =====
function create_campaign_from_filename($mb_group, $orig_name, $memo) {
    $base = pathinfo($orig_name, PATHINFO_FILENAME);
    $base = k_nfc(trim(preg_replace('/\s+/', ' ', $base)));
    $stamp = date('ymd_Hi');
    $name  = mb_substr($base, 0, 80).'_'.$stamp;   // 저장명도 NFC
    $sql = "INSERT INTO call_campaign (mb_group, name, campaign_memo, status, created_at, updated_at)
            VALUES ('{$mb_group}', '".sql_escape_string($name)."', '".sql_escape_string(k_nfc($memo))."', 1, NOW(), NOW())";
    sql_query($sql);
    return sql_insert_id();
}
$campaign_id = create_campaign_from_filename($mb_group, $orig_name, $memo);

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

        // 헤더 없는 경우: A열이 전화번호
        if ($is_headerless) {
            $raw_hp = (string)$row[0];
            $call_hp = preg_replace('/\D+/', '', $raw_hp);
            if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
                $skip_count++;
                if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 잘못된 전화번호 '{$raw_hp}'";
                continue;
            }

            // 이름/생일
            $name = isset($row[1]) ? k_nfc((string)$row[1]) : null;
            $birth_date = isset($row[2]) ? parse_birth_date((string)$row[2]) : null;

            // 기타정보: 4번째 이후 컬럼 모두 포함
            $meta = [];
            for ($k = 3; $k < count($row); $k++) {
                $meta['col'.($k+1)] = k_nfc((string)$row[$k]);
            }
        } else {
            $raw_hp = (string)($row[$idx_hp] ?? '');
            $call_hp = preg_replace('/\D+/', '', $raw_hp);
            if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
                $skip_count++;
                if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 잘못된 전화번호 '{$raw_hp}'";
                continue;
            }

            // 이름/생일
            $name = ($idx_name !== null) ? k_nfc((string)$row[$idx_name]) : null;
            $birth_date = ($idx_birth !== null && isset($row[$idx_birth])) ? parse_birth_date((string)$row[$idx_birth]) : null;

            // 나머지 컬럼 meta로 (key/value 모두 NFC)
            $meta = [];
            foreach ($header as $k=>$h) {
                if ($k === $idx_hp || $k === $idx_name || $k === $idx_birth) continue;
                $key = k_nfc(trim((string)$h));
                if ($key === '') continue;
                $val = isset($row[$k]) ? k_nfc((string)$row[$k]) : '';
                if ($val !== '') $meta[$key] = $val;
            }
        }

        $meta_json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        $sql = "INSERT INTO call_stg_target_upload
                (batch_id, campaign_id, mb_group, call_hp, name, birth_date, meta_json, created_at)
                VALUES
                ('{$batch_id}', '{$campaign_id}', '{$mb_group}', '{$call_hp}', ".sql_quote_or_null($name).", ".sql_quote_or_null($birth_date).", ".sql_quote_or_null($meta_json).", NOW())";
        $res = sql_query($sql, false);
        if ($res) $stg_count++;
        else {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 스테이징 실패";
        }
    }

    // 최종 적재
    $ins_sql = "
      INSERT IGNORE INTO call_target
      (campaign_id, mb_group, call_hp, name, birth_date, meta_json, created_at, updated_at)
      SELECT s.campaign_id, s.mb_group, s.call_hp, s.name, s.birth_date, s.meta_json, NOW(), NOW()
      FROM call_stg_target_upload s
      WHERE s.batch_id = '{$batch_id}' AND s.mb_group = '{$mb_group}' AND s.campaign_id = '{$campaign_id}'
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
        <p><strong>캠페인ID:</strong> <?php echo (int)$campaign_id; ?> / <strong>배치ID:</strong> <?php echo (int)$batch_id; ?></p>
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
        <button type="button" onclick="window.close();">창닫기</button>
    </div>
</div>

<?php include_once(G5_PATH.'/tail.sub.php'); ?>
