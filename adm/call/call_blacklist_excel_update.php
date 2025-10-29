<?php
// /adm/call/blacklist_excel_update.php
$sub_menu = '700500';
require_once './_common.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

auth_check_menu($auth, $sub_menu, "w");

if ((int)$member['mb_level'] < 5) alert('접근 권한이 없습니다.');

$chk = check_admin_token();
if(!$chk) {
    goto_url('/adm/call/call_blacklist.php');
}

$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);

$company_id = ($mb_level >= 9) ? (int)($_POST['company_id'] ?? 0) : $my_company_id;
$mb_group   = ($mb_level >= 8) ? (int)($_POST['mb_group'] ?? 0)   : $my_group;
$update_on_dup = isset($_POST['update_on_dup']) && $_POST['update_on_dup']=='1';

if ($company_id <= 0) alert('회사 선택/확인이 필요합니다.');

// 8레벨: 자사만 허용
if ($mb_level == 8 && $company_id !== $my_company_id) {
    alert('자기 회사에만 업로드할 수 있습니다.');
}
// 7레벨 이하: 지점 고정
if ($mb_level <= 7 && $mb_group !== $my_group) {
    alert('자기 지점에만 업로드할 수 있습니다.');
}

if (!isset($_FILES['excel']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
    alert('업로드된 파일이 없습니다.');
}

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$filepath = $_FILES['excel']['tmp_name'];
$orig     = $_FILES['excel']['name'];
$ext      = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

$rows = [];
if ($ext === 'csv') {
    $fp = fopen($filepath, 'r');
    if (!$fp) alert('CSV 파일을 열 수 없습니다.');
    // 첫 줄 헤더 가정: call_hp,reason,memo
    $header = fgetcsv($fp);
    if (!$header) alert('CSV 헤더를 읽을 수 없습니다.');
    // 간단한 헤더 정규화
    $map = [];
    foreach ($header as $idx=>$h) {
        $h = strtolower(trim($h));
        if (in_array($h, ['전화번호', '전화번호(필수)', 'call_hp', '휴대폰', '핸드폰'])) $map['call_hp'] = $idx;
        if (in_array($h, ['이유(선택)', 'reason','사유', '이유'])) $map['reason'] = $idx;
        if (in_array($h, ['메모(선택)', 'memo','메모']))   $map['memo']   = $idx;
    }
    if (!isset($map['call_hp'])) alert('헤더에 전화번호 컬럼이 필요합니다.');
    while (($r = fgetcsv($fp)) !== false) {
        $hp     = isset($r[$map['call_hp']]) ? preg_replace('/\D+/', '', $r[$map['call_hp']]) : '';
        $reason = isset($map['reason']) ? trim((string)($r[$map['reason']] ?? '')) : '';
        $memo   = isset($map['memo'])   ? trim((string)($r[$map['memo']] ?? ''))   : '';
        $rows[] = compact('hp','reason','memo');
    }
    fclose($fp);
} else {
    include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');
    try {
        $obj = PHPExcel_IOFactory::load($filepath);
    } catch (Exception $e) {
        alert('엑셀 파일을 읽을 수 없습니다: '.$e->getMessage());
    }
    $sheet = $obj->getSheet(0);
    $highestRow = $sheet->getHighestRow();
    $highestCol = PHPExcel_Cell::columnIndexFromString($sheet->getHighestColumn());

    // 헤더: 1행
    $hmap = ['call_hp'=>null,'reason'=>null,'memo'=>null];
    for ($c=0; $c<$highestCol; $c++) {
        $name = strtolower(trim((string)$sheet->getCellByColumnAndRow($c,1)->getValue()));
        if (in_array($name, ['전화번호', '전화번호(필수)', 'call_hp', '휴대폰', '핸드폰'])) $hmap['call_hp'] = $c;
        if (in_array($name, ['이유(선택)', 'reason','사유', '이유'])) $hmap['reason'] = $c;
        if (in_array($name, ['메모(선택)', 'memo','메모']))   $hmap['memo']   = $c;
    }
    if ($hmap['call_hp']===null) alert('헤더에 전화번호 컬럼이 필요합니다.');

    for ($r=2; $r<=$highestRow; $r++) {
        $hp     = preg_replace('/\D+/', '', (string)$sheet->getCellByColumnAndRow($hmap['call_hp'],$r)->getValue());
        $reason = $hmap['reason']!==null ? trim((string)$sheet->getCellByColumnAndRow($hmap['reason'],$r)->getValue()) : '';
        $memo   = $hmap['memo']!==null   ? trim((string)$sheet->getCellByColumnAndRow($hmap['memo'],$r)->getValue())   : '';
        if ($hp==='' && $reason==='' && $memo==='') continue;
        $rows[] = compact('hp','reason','memo');
    }
}

$total = count($rows);
$invalid = $inserted = $duplicated = $updated = 0;

sql_query("START TRANSACTION");
foreach ($rows as $it) {
    $hp = $it['hp'];
    if (!preg_match('/^[0-9]{10,12}$/', $hp)) { $invalid++; continue; }

    $reason = sql_escape_string($it['reason']);
    $memo   = sql_escape_string($it['memo']);

    if ($update_on_dup) {
        // 중복시 사유/메모 업데이트
        $sql = "INSERT INTO call_blacklist (company_id, mb_group, call_hp, reason, memo, created_by, created_at)
                VALUES ('{$company_id}', '{$mb_group}', '{$hp}', '{$reason}', '{$memo}', '{$mb_no}', NOW())
                ON DUPLICATE KEY UPDATE
                    reason = IF(VALUES(reason)='', reason, VALUES(reason)),
                    memo   = IF(VALUES(memo)  ='', memo,   VALUES(memo))";
        $ok = sql_query($sql, false);
        if ($ok) {
            if ((int)mysqli_affected_rows($g5['connect_db']) === 1) $inserted++; else $updated++;
        } else {
            $duplicated++; // 보호적 카운트
        }
    } else {
        // 단순 삽입, 중복이면 스킵
        $ok = sql_query("INSERT IGNORE INTO call_blacklist (company_id, mb_group, call_hp, reason, memo, created_by, created_at)
                         VALUES ('{$company_id}', '{$mb_group}', '{$hp}', '{$reason}', '{$memo}', '{$mb_no}', NOW())");
        if ($ok) {
            if ((int)mysqli_affected_rows($g5['connect_db']) === 1) $inserted++; else $duplicated++;
        } else {
            $duplicated++;
        }
    }
}
sql_query("COMMIT");

// 결과 출력 (간단 화면)
$g5['title'] = '블랙리스트 엑셀 업로드 결과';
include_once(G5_ADMIN_PATH.'/admin.head.php');
?>
<div class="local_ov01 local_ov">
    <span class="btn_ov01"><span class="ov_txt">총 행수</span> <span class="ov_num"><?php echo number_format($total);?></span></span>
    <span class="btn_ov01"><span class="ov_txt">등록</span>   <span class="ov_num"><?php echo number_format($inserted);?></span></span>
    <?php if ($update_on_dup) { ?>
    <span class="btn_ov01"><span class="ov_txt">업데이트</span> <span class="ov_num"><?php echo number_format($updated);?></span></span>
    <?php } ?>
    <span class="btn_ov01"><span class="ov_txt">중복/스킵</span> <span class="ov_num"><?php echo number_format($duplicated);?></span></span>
    <span class="btn_ov01"><span class="ov_txt">형식오류</span> <span class="ov_num"><?php echo number_format($invalid);?></span></span>
</div>

<div class="local_sch01 local_sch">
    <a href="./call_blacklist.php" class="btn btn_01">블랙리스트로 돌아가기</a>
</div>
<?php include_once(G5_ADMIN_PATH.'/admin.tail.php'); ?>
