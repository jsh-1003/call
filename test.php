<?php
include_once('./_common.php');
require_once G5_LIB_PATH.'/call.assign.lib.php';

$k = call_assign_count_my_queue(3, 4, 0, '1', true);
var_dump($k);
$chk = build_org_select_options();
print_r2($chk);

// $code_list = get_code_list($sel_mb_group);
// print_r2($code_list);