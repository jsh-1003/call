<?php
// /adm/call_target_excel_update.php
$sub_menu = '700700';
include_once('./_common.php');

// 대량 처리 대비
set_time_limit(0);
ini_set('memory_limit', '512M');

auth_check_menu($auth, $sub_menu, "w");

// CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['call_upload_token'] ?? '')) {
    alert('잘못된 요청입니다.(CSRF)');
}

// 권한/그룹
$my_level = (int)$member['mb_level'];
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$mb_group = ($my_level >= 8) ? (int)($_POST['mb_group'] ?? 0) : $my_group;
if (!$mb_group) alert('그룹이 선택되지 않았습니다.');

function only_number($n) { return preg_replace('/[^0-9]/', '', (string)$n); }
function sql_quote_or_null($v) { return ($v === null || $v === '') ? "NULL" : "'".sql_escape_string($v)."'"; }

// 생년월일 파싱: 엑셀 직렬값/문자열 → YYYY-MM-DD
function parse_birth_date($s) {
    $s = trim((string)$s);
    if ($s === '') return null;

    if (is_numeric($s)) {
        $ival = (int)$s;
        if ($ival > 30000 && $ival < 90000) {
            // Excel serial date base 1899-12-30
            $base = new DateTime('1899-12-30');
            $base->modify("+{$ival} days");
            return $base->format('Y-m-d');
        }
    }
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits !== '') {
        if (strlen($digits) === 8) {
            $y = (int)substr($digits,0,4);
            $m = (int)substr($digits,4,2);
            $d = (int)substr($digits,6,2);
            if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
        }
        if (strlen($digits) === 6) {
            $yy = (int)substr($digits,0,2);
            $y  = ($yy >= 70) ? (1900+$yy) : (2000+$yy);
            $m  = (int)substr($digits,2,2);
            $d  = (int)substr($digits,4,2);
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

// 파일 체크
$is_upload_file = (isset($_FILES['excelfile']['tmp_name']) && $_FILES['excelfile']['tmp_name']) ? 1 : 0;
if (!$is_upload_file) alert("엑셀 파일을 업로드해 주세요.");

$file = $_FILES['excelfile']['tmp_name'];
$orig_name = $_FILES['excelfile']['name'];

// PHPExcel 로드
include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');

try {
    $objPHPExcel = PHPExcel_IOFactory::load($file);
} catch (Exception $e) {
    alert('엑셀 파일을 읽을 수 없습니다: '.$e->getMessage());
}

$sheet = $objPHPExcel->getSheet(0);
$num_rows = $sheet->getHighestRow();
$highestColumn = $sheet->getHighestColumn();

// 1행 헤더
$headerRow = $sheet->rangeToArray('A1:'.$highestColumn.'1', NULL, TRUE, FALSE);
$header = array_map(function($v){
    $v = is_null($v) ? '' : (string)$v;
    return trim($v);
}, $headerRow[0]);

// 필수 컬럼 매핑
$name_keys  = ['이름','name','성명'];
$hp_keys    = ['전화번호','연락처','휴대폰','핸드폰','phone','hp','call_hp'];
$birth_keys = ['생년월일','생일','birth','birth_date','생년','dob'];

$idx_name = $idx_hp = $idx_birth = null;
$header_lc = array_map(function($v){ return mb_strtolower($v); }, $header);
foreach ($header_lc as $i=>$h) {
    if ($idx_name === null  && in_array($h, array_map('mb_strtolower',$name_keys), true))  $idx_name  = $i;
    if ($idx_hp   === null  && in_array($h, array_map('mb_strtolower',$hp_keys), true))    $idx_hp    = $i;
    if ($idx_birth=== null  && in_array($h, array_map('mb_strtolower',$birth_keys), true)) $idx_birth = $i;
}
if ($idx_hp === null) alert('헤더에 "전화번호" 열이 필요합니다. (예: 전화번호/연락처/휴대폰/phone/hp)');

// 1) 파일명으로 캠페인 자동 생성
function create_campaign_from_filename($mb_group, $orig_name) {
    $base = pathinfo($orig_name, PATHINFO_FILENAME);
    $base = trim(preg_replace('/\s+/', ' ', $base));
    $stamp = date('Ymd_His');
    $name  = mb_substr($base, 0, 80).' '.$stamp;
    $sql = "INSERT INTO call_campaign (mb_group, name, status, created_at, updated_at)
            VALUES ('{$mb_group}', '".sql_escape_string($name)."', 1, NOW(), NOW())";
    sql_query($sql);
    return sql_insert_id();
}
$campaign_id = create_campaign_from_filename($mb_group, $orig_name);

// 2) 스테이징 적재 + 3) 타겟 자동 반영
$batch_id = (int)(microtime(true)*1000) + random_int(1, 999);

// 카운터
$total_count = 0;    // 데이터 행 시도
$stg_count   = 0;    // 스테이징 적재 성공(유효한 전화번호)
$skip_count  = 0;    // 유효성 실패(전화번호 형식 등)
$ins_count   = 0;    // 타겟 신규 삽입 수
$dup_count   = 0;    // 타겟 중복(기존 존재) 추정치
$fail_msgs   = [];   // 일부 실패 샘플

sql_query("START TRANSACTION");

try {
    // 데이터는 2행부터
    for ($i = 2; $i <= $num_rows; $i++) {
        $rowData = $sheet->rangeToArray('A' . $i . ':' . $highestColumn . $i, NULL, TRUE, FALSE);
        if (!isset($rowData[0])) continue;
        $row = $rowData[0];
        // 헤더 길이 패딩
        if (count($row) < count($header)) $row = array_pad($row, count($header), null);

        $total_count++;

        $raw_hp  = trim((string)($row[$idx_hp] ?? ''));
        $call_hp = preg_replace('/\D+/', '', $raw_hp);
        if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 잘못된 전화번호 '{$raw_hp}'";
            continue;
        }

        $name = ($idx_name !== null) ? trim((string)$row[$idx_name]) : null;

        $birth_date = null;
        if ($idx_birth !== null && isset($row[$idx_birth])) {
            $birth_date = parse_birth_date((string)$row[$idx_birth]);
        }

        // meta_json 구성(나머지 열)
        $meta = [];
        foreach ($header as $k=>$h) {
            if ($k === $idx_hp || $k === $idx_name || $k === $idx_birth) continue;
            $key = trim((string)$h);
            if ($key === '') continue;
            $val = isset($row[$k]) ? trim((string)$row[$k]) : '';
            if ($val !== '') $meta[$key] = $val;
        }
        $meta_json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        // 스테이징 적재
        $sql = "INSERT INTO call_stg_target_upload
                (batch_id, campaign_id, mb_group, call_hp, name, birth_date, meta_json, created_at)
                VALUES
                ('{$batch_id}', '{$campaign_id}', '{$mb_group}', '{$call_hp}', ".sql_quote_or_null($name).", ".sql_quote_or_null($birth_date).", ".sql_quote_or_null($meta_json).", NOW())";
        $res = sql_query($sql, false);
        if ($res) $stg_count++; else { $skip_count++; if (count($fail_msgs)<20) $fail_msgs[]="행 {$i}: 스테이징 실패"; }
    }

    // 타겟 자동 반영 (신규만 삽입)
    $ins_sql = "
      INSERT IGNORE INTO call_target
      (campaign_id, mb_group, call_hp, name, birth_date, meta_json, created_at, updated_at)
      SELECT s.campaign_id, s.mb_group, s.call_hp, s.name, s.birth_date, s.meta_json, NOW(), NOW()
      FROM call_stg_target_upload s
      WHERE s.batch_id = '{$batch_id}' AND s.mb_group = '{$mb_group}' AND s.campaign_id = '{$campaign_id}'
    ";
    sql_query($ins_sql);
    $ins_count = max(0, (int)sql_affected_rows());

    // 중복수(추정) = 스테이징 성공 - 신규삽입
    $dup_count = max(0, $stg_count - $ins_count);

    // 스테이징 처리 마킹
    $upd = "
      UPDATE call_stg_target_upload
      SET processed_at = NOW(),
          processed_ok = 1,
          error_msg = NULL
      WHERE batch_id='{$batch_id}' AND mb_group='{$mb_group}' AND campaign_id='{$campaign_id}'
    ";
    sql_query($upd);

    sql_query("COMMIT");
} catch (Exception $e) {
    sql_query("ROLLBACK");
    alert('처리 중 오류: '.$e->getMessage());
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
        <dt>총 데이터 행</dt>
        <dd><?php echo number_format($total_count); ?></dd>

        <dt>엑셀 처리 성공</dt>
        <dd><?php echo number_format($stg_count); ?></dd>

        <dt>신규 등록</dt>
        <dd><?php echo number_format($ins_count); ?></dd>

        <dt>중복(기존 존재)</dt>
        <dd><?php echo number_format($dup_count); ?></dd>

        <dt>실패(정보 이상)</dt>
        <dd><?php echo number_format($skip_count); ?></dd>

        <?php if (!empty($fail_msgs)) { ?>
        <dt>실패 샘플(최대 20건)</dt>
        <dd>
            <ul style="margin:0;padding-left:18px;">
                <?php foreach ($fail_msgs as $m) { echo '<li>'.get_text($m).'</li>'; } ?>
            </ul>
        </dd>
        <?php } ?>
    </dl>

    <div class="btn_win01 btn_win">
        <button type="button" onclick="window.close();">창닫기</button>
    </div>
</div>

<?php
include_once(G5_PATH.'/tail.sub.php');
