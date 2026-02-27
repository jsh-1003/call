<?php
require_once './_common.php';
include_once(G5_PATH.'/lib/call.assign.lib.php');
$mb_no          = 585;
$mb_group       = 423;
$call_api_count = 1;
$call_lease_min = 240;
$campaign_id    = 0;
$need = 1;
// 1) 만료 회수
call_assign_release_expired($mb_group, $campaign_id);
// 2) 현재 보유 개수(통화전=1, 리스 유효)
$k = call_assign_count_my_queue($mb_group, $mb_no, $campaign_id, '1', true);
if($k) die('있음');
$batch_id = get_uniqid();
$result = call_assign_pick_and_lock(
    $mb_group,
    $mb_no,
    $need,
    $call_lease_min,
    $batch_id,
    [
        'use_skip_locked'        => true,
        'assigned_status_to'     => 1,
        'assigned_status_filter' => 0,
        'order'                  => 'target_id',
        // 'where_extra'            => 'AND do_not_call = 0',
        // 'exclude_blacklist'      => true,
        // 'exclude_dnc_flag'       => true, // 내부 DNC 플래그도 제외
    ],
    $campaign_id
);
var_dump($result);