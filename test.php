<?php
include_once('./_common.php');
require_once G5_LIB_PATH.'/call.assign.lib.php';

$rr = aftercall_assign_bulk_unassigned(10, 10, 1);
var_dump($arr);
exit;
$r = aftercall_pick_next_agent(10);
var_dump($r);
exit;
$k = call_assign_count_my_queue(3, 4, 0, '1', true);
var_dump($k);
$chk = build_org_select_options();
print_r2($chk);

// $code_list = get_code_list($sel_mb_group);
// print_r2($code_list);