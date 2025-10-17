<?php
$menu["menu700"] = array(
    array('700110', '모니터링', '' . G5_ADMIN_URL.'/call/call_monitor.php', 'call'),
    array('700110', '모니터링', '' . G5_ADMIN_URL.'/call/call_monitor.php', 'call'),
    array('700200', '통계확인', '' . G5_ADMIN_URL.'/call/call_stats.php', 'call'),
    array('700100', 'DB리스트', '' . G5_ADMIN_URL.'/call/index.php', 'call'),
);
if($member['mb_level'] >= 7) {
    $menu["menu700"][] = array('700700', 'DB파일', '' . G5_ADMIN_URL.'/call/call_campaign_list.php', 'campaign_list');
    $menu["menu700"][] = array('700750', '회원관리', '' . G5_ADMIN_URL.'/call/call_member_list.php', 'member_list');
}
if($member['mb_level'] >= 8) {
    $menu["menu700"][] = array('700900', '코드관리', '' . G5_ADMIN_URL.'/call/status_code_list.php', 'call_status');
}