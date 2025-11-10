<?php
// /adm/call/call_blacklist_excel.php
$sub_menu = '700500';
require_once './_common.php';

if ((int)$member['mb_level'] < 5) alert('접근 권한이 없습니다.');

$mb_level      = (int)$member['mb_level'];
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);

$mode   = _g('mode', 'condition'); // screen|condition|all|template
$q      = _g('q','');
$q_type = _g('q_type','last4');
$page   = max(1, (int)_g('page','1'));
$rows   = max(10, min(200, (int)_g('rows','50')));
$offset = ($page-1) * $rows;

if ($mb_level >= 9) {
    $sel_company_id = (int)_g('company_id', 0);
    $sel_mb_group   = (int)_g('mb_group', 0);
} elseif ($mb_level >= 8) {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = (int)_g('mb_group', 0);
} else {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

if ($mode === 'template') {
    include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');
    $exl = new PHPExcel();
    $sh  = $exl->setActiveSheetIndex(0);
    $sh->setTitle('blacklist_template');
    $sh->setCellValue('A1', '전화번호');
    $sh->setCellValue('B1', '이유(선택)');
    $sh->setCellValue('C1', '메모(선택)');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="blacklist_template.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = PHPExcel_IOFactory::createWriter($exl, 'Excel2007');
    $writer->save('php://output');
    exit;
}

// WHERE
$where = [];
if     ($mb_level >= 9) { /* no-op */ }
elseif ($mb_level == 8)  $where[] = "b.company_id = {$my_company_id}";
else                     $where[] = "b.mb_group = {$my_group}";

if ($mb_level >= 8) {
    if ($sel_mb_group > 0)       $where[] = "b.mb_group = {$sel_mb_group}";
    else if ($mb_level >= 9 && $sel_company_id > 0) $where[] = "b.company_id = {$sel_company_id}";
    else if ($mb_level == 8)     $where[] = "b.company_id = {$my_company_id}";
}
if ($q !== '' && $q_type !== '') {
    if ($q_type === 'last4') {
        $last4 = substr(preg_replace('/\D+/', '', $q), -4);
        if ($last4 !== '') $where[] = "b.hp_last4 = '".sql_escape_string($last4)."'";
    } elseif ($q_type === 'full') {
        $full = preg_replace('/\D+/', '', $q);
        if ($full !== '') $where[] = "b.call_hp = '".sql_escape_string($full)."'";
    } elseif ($q_type === 'reason') {
        $where[] = "b.reason LIKE '%".sql_escape_string($q)."%'";
    }
}
$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$limit_sql = '';
if ($mode === 'screen')    $limit_sql = " LIMIT {$offset}, {$rows}";

$sql = "
  SELECT b.blacklist_id, b.company_id, b.mb_group, b.call_hp, b.hp_last4,
         b.reason, b.memo, b.created_by, b.created_at
    FROM call_blacklist b
    {$where_sql}
   ORDER BY b.blacklist_id DESC
   {$limit_sql}
";
$rs = sql_query($sql);

// Excel build
include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');
$exl = new PHPExcel();
$sh  = $exl->setActiveSheetIndex(0);
$sh->setTitle('blacklist');

$cols = ['회사','지점','전화번호','사유','메모','등록자','등록일'];
for ($i=0; $i<count($cols); $i++) $sh->setCellValueByColumnAndRow($i, 1, $cols[$i]);

$r = 2;
while ($row = sql_fetch_array($rs)) {
    $gid    = (int)$row['mb_group'];
    $cname  = $gid > 0 ? get_company_name_from_group_id_cached($gid) : ('회사ID '.$row['company_id']);
    $gname  = $gid > 0 ? get_group_name_cached($gid) : '-';
    $creator= get_agent_name_cached((int)$row['created_by']) ?: ('#'.$row['created_by']);
    $sh->setCellValueByColumnAndRow(0, $r, $cname);
    $sh->setCellValueByColumnAndRow(1, $r, $gname);
    $sh->setCellValueByColumnAndRow(2, $r, format_korean_phone($row['call_hp']));
    $sh->setCellValueByColumnAndRow(3, $r, (string)$row['reason']);
    $sh->setCellValueByColumnAndRow(4, $r, (string)$row['memo']);
    $sh->setCellValueByColumnAndRow(5, $r, $creator);
    $sh->setCellValueByColumnAndRow(6, $r, $row['created_at']);
    $r++;
}

foreach (range('A','G') as $c) $exl->getActiveSheet()->getColumnDimension($c)->setAutoSize(true);

$fname = 'blacklist_'.date('Ymd_His').'_'.$mode.'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Cache-Control: max-age=0');
$writer = PHPExcel_IOFactory::createWriter($exl, 'Excel2007');
$writer->save('php://output');
exit;
