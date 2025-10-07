<?php
include_once('./_common.php');
include_once(G5_LIB_PATH.'/call.assign.lib.php');

$mb_group    = 1;
$campaign_id = 1;
$mb_no       = 2;   // 상담원
$n           = 5;   // 배정 수량
$lease_min   = 90;  // 리스
$batch_id    = 4001;

// 1) (옵션) 만료 회수 먼저
call_assign_release_expired($mb_group, $campaign_id);

/*
// 2) 배정 실행 (MySQL 8.x면 기본값 true로 SKIP LOCKED 사용)
$result = call_assign_pick_and_lock($mb_group, $mb_no, $n, $lease_min, $batch_id, [
    'use_skip_locked'      => true,                 // 구버전이면 false
    'assigned_status_to'   => 1,                    // 통화 전 배정
    'assigned_status_filter'=> 0,                   // 미배정만 픽
    'order'                => 'target_id',          // 우선순위 컬럼(인덱스와 일치)
    'where_extra'          => 'AND do_not_call = 0' // 추가필터(옵션)
], $campaign_id);

if (!$result['ok']) {
    echo '배정 실패: ' . $result['err'];
    exit;
}

echo '배정 건수: ' . $result['picked'] . '<br>';
echo '타겟 IDs: ' . implode(', ', $result['ids']) . '<br>';
*/
// 3) 내 큐 보기
$limit = 100;
$assigned_status = 1;
$my = call_assign_list_my_queue($mb_group, $mb_no, $limit, $campaign_id, $assigned_status);
foreach ($my as $row) {
    echo "[{$row['target_id']}] {$row['call_hp']} / status={$row['assigned_status']} / lease={$row['assign_lease_until']}<br>";
}
