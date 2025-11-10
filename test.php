<?php
include_once('./_common.php');
include_once('./lib/call.assign.lib.php');

$res = aftercall_pick_next_agent(37);
var_dump($res);