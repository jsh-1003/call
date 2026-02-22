<?php
include_once('./_common.php');
include_once('./lib/call.assign.lib.php');

// $res = aftercall_pick_next_agent(37);
// var_dump($res);

// print_r2($_SERVER);


// VPN 대역
$vpn_subnet = '10.78.';   // WireGuard 대역 앞부분

// VPN 강제 대상 관리자 ID
$vpn_only_ids = [
    'admin',
    'culture'
];

// 로그인 정보
$mb_id = $member['mb_id'] ?? '';
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';

// 해당 ID가 VPN 전용인지?
if (in_array($mb_id, $vpn_only_ids, true)) {

    // VPN IP인지 체크
    if (strpos($remote_ip, $vpn_subnet) !== 0) {

        // 로그 남기면 더 좋음
        error_log("[VPN BLOCK] {$mb_id} / {$remote_ip}");

        http_response_code(403);
        die('VPN 이용 바랍니다.');
    }
}
echo 1;