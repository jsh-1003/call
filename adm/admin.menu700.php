<?php
$menu["menu700"] = array(
    array('700400', '접수관리', '' . G5_ADMIN_URL.'/call/call_after_list.php', 'call'),
    array('700400', '접수관리', '' . G5_ADMIN_URL.'/call/call_after_list.php', 'call'),
    array('700420', '접수DB관리', '' . G5_ADMIN_URL.'/call/call_after_db_list.php', 'call'),
    array('700500', '블랙관리', '' . G5_ADMIN_URL.'/call/call_blacklist.php', 'call'),
);

if($member['mb_level'] >= 7) {
    $menu["menu700"] = array(
        array('700110', '관리', '' . G5_ADMIN_URL.'/call/call_monitor.php', 'call'),
        array('700110', '모니터링', '' . G5_ADMIN_URL.'/call/call_monitor.php', 'call'),
        array('700200', '통계확인', '' . G5_ADMIN_URL.'/call/call_stats.php', 'call'),
        array('700300', '녹취내역', '' . G5_ADMIN_URL.'/call/call_recordings.php', 'call'),
        array('700320', '수동녹취', '' . G5_ADMIN_URL.'/call/call_manual_recordings.php', 'call'),
        array('700000', '---', '#this', 'line'),
        array('700400', '접수관리', '' . G5_ADMIN_URL.'/call/call_after_list.php', 'call'),
    );
    if( $member['mb_level'] >= 9 || (isset($member['is_after_db_use']) && $member['is_after_db_use']) ) {
        $menu["menu700"][] = array('700420', '접수DB관리', '' . G5_ADMIN_URL.'/call/call_after_db_list.php', 'call');
    }
    
    $menu["menu700"][] = array('700500', '블랙관리', '' . G5_ADMIN_URL.'/call/call_blacklist.php', 'call');
    
    if($member['mb_no'] != 363) {
        // 모니터링 회원은 회원관리 안보이게 처리
        $menu["menu700"][] = array('700100', 'DB리스트', '' . G5_ADMIN_URL.'/call/index.php', 'call');
        $menu["menu700"][] = array('700700', 'DB파일', '' . G5_ADMIN_URL.'/call/call_campaign_list.php', 'campaign_list');
        $menu["menu700"][] = array('700000', '---', '#this', 'line');
        $menu["menu700"][] = array('700750', '회원관리', '' . G5_ADMIN_URL.'/call/call_member_list.php', 'member_list');
        $menu["menu700"][] = array('700770', '환경설정', '' . G5_ADMIN_URL.'/call/call_config.php', 'call_config');
    } else if($is_shop_api_view) {
        $menu["menu700"][] = array('700930', '쇼핑API', '' . G5_ADMIN_URL.'/call/call_api_order_list.php', 'call_api');
    }
}

if($member['mb_level'] >= 9) {
    $menu["menu700"][] = array('700900', '코드관리', '' . G5_ADMIN_URL.'/call/status_code_list.php', 'call_status');
    $menu["menu700"][] = array('700930', '쇼핑API', '' . G5_ADMIN_URL.'/call/call_api_order_list.php', 'call_api');
}

if($member['mb_no'] != 363) {
    $menu["menu700"][] = array('700000', '---', '#this', 'line');
    $menu["menu700"][] = array('700950', '공지사항', '' . G5_BBS_URL.'/board.php?bo_table=notice', 'board_notice');
}

if($member['mb_id'] == 'admin_pay') {
    $menu["menu700"][] = array('700990', '결제관리', '' . G5_ADMIN_URL.'/call/billing_company_list.php', 'bill');
}