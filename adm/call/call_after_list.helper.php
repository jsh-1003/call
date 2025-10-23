<?php
// /adm/call/call_after_list.helper.php
require_once './_common.php';
header('Content-Type: application/json; charset=utf-8');

if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) { echo json_encode(['success'=>false]); exit; }

$fn = $_GET['fn'] ?? '';
if ($fn === 'target') {
    $call_id = (int)($_GET['call_id'] ?? 0);
    if ($call_id<=0) { echo json_encode(['success'=>false]); exit; }
    $row = sql_fetch("SELECT target_id FROM call_log WHERE call_id={$call_id} LIMIT 1");
    if (!$row) { echo json_encode(['success'=>false]); exit; }
    echo json_encode(['success'=>true,'target_id'=>(int)$row['target_id']]);
    exit;
}
echo json_encode(['success'=>false]);
