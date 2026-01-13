<?php
$is_paid_db_use_company = 0;
if($member['mb_level'] < 8) return;
if($member['member_type'] ==0 && $member['mb_level'] < 10) {
    $is_paid_db_use_company = is_paid_db_use_company($member['mb_no']);
    if($is_paid_db_use_company < 1) return;
}
if($member['mb_level'] > 10) {
    $menu['menu200'] = array(
        array('200000', '회원관리', G5_ADMIN_URL . '/member_list.php', 'member'),
        array('200100', '회원관리', G5_ADMIN_URL . '/member_list.php', 'mb_list'),
        array('200400', '회원관리파일', G5_ADMIN_URL . '/member_list_exel.php', 'mb_list'),
        array('200300', '회원메일발송', G5_ADMIN_URL . '/mail_list.php', 'mb_mail'),
        array('200800', '접속자집계', G5_ADMIN_URL . '/visit_list.php', 'mb_visit', 1),
        array('200810', '접속자검색', G5_ADMIN_URL . '/visit_search.php', 'mb_search', 1),
        array('200820', '접속자로그삭제', G5_ADMIN_URL . '/visit_delete.php', 'mb_delete', 1),
        array('200200', '포인트관리', G5_ADMIN_URL . '/point_list.php', 'mb_point'),
        array('200900', '투표관리', G5_ADMIN_URL . '/poll_list.php', 'mb_poll')
    );
}

$menu['menu200'] = array(
    array('200000', '매체사관리', G5_ADMIN_URL . '/paid/paid_member_list.php', 'member'),
    array('200710', '회원관리', G5_ADMIN_URL . '/paid/paid_member_list.php', 'mb_list'),
    array('200750', '사용통계', G5_ADMIN_URL . '/paid/paid_stats.php', 'paid_stats'),
);

// 유료DB 사용하는 회원사인경우
if($is_paid_db_use_company > 0) {
    $menu['menu200'] = array(
        array('200000', '유료DB 통계', G5_ADMIN_URL . '/member_list.php', 'member'),
        array('200750', '사용통계', G5_ADMIN_URL . '/paid/paid_stats.php', 'paid_stats'),
        array('200770', '포인트확인', G5_ADMIN_URL . '/paid/paid_point_list.php', 'paid_point'),
    );
}

if($is_admin_pay) {
    $menu['menu200'] = array(
        array('200000', '매체사관리', G5_ADMIN_URL . '/paid/paid_member_list.php', 'member'),
        array('200710', '회원관리', G5_ADMIN_URL . '/paid/paid_member_list.php', 'mb_list'),
        array('200750', '사용통계', G5_ADMIN_URL . '/paid/paid_stats.php', 'paid_stats'),
        array('200770', '포인트확인', G5_ADMIN_URL . '/paid/paid_point_list.php', 'paid_point'),    
        array('200765', '유료DB파일', G5_ADMIN_URL . '/paid/paid_campaign_list.php', 'paid_campaign'),    
        array('200767', '유료DB리스트', G5_ADMIN_URL . '/paid/paid_db_list.php', 'paid_campaign'),    
    ); 
}