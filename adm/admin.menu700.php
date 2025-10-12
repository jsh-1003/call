<?php
$menu["menu700"] = array(
    array('700000', '콜리스트', '' . G5_ADMIN_URL.'/call/index.php', 'call'),
    array('700100', '콜리스트', '' . G5_ADMIN_URL.'/call/index.php', 'call'),
    array('700110', '모니터링', '' . G5_ADMIN_URL.'/call/call_monitor.php', 'call'),
    array('700200', '통계확인', '' . G5_ADMIN_URL.'/call/call_stats.php', 'call'),
);
if($member['mb_level'] >= 7) {
    $menu["menu700"][] = array('700700', '대상등록관리', '' . G5_ADMIN_URL.'/call/call_campaign_list.php', 'campaign_list');
    $menu["menu700"][] = array('700750', '회원관리', '' . G5_ADMIN_URL.'/call/call_member_list.php', 'member_list');
} else if($member['mb_level'] >= 8) {
    $menu["menu700"][] = array('700900', '코드관리', '' . G5_ADMIN_URL.'/call/status_code_list.php', 'call_status');
}