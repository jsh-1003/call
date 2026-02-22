<?php
$sub_menu = '700950';
if(!empty($_REQUEST['bo_table']) && $_REQUEST['bo_table'] == 'agree') {
    $sub_menu = '200791';
} else if(!empty($_REQUEST['bo_table']) && $_REQUEST['bo_table'] == 'withdraw') {
    $sub_menu = '200792';
}
include_once('../common.php');

// 커뮤니티 사용여부
if(defined('G5_COMMUNITY_USE') && G5_COMMUNITY_USE === false) {
    if (!defined('G5_USE_SHOP') || !G5_USE_SHOP)
        die('<p>쇼핑몰 설치 후 이용해 주십시오.</p>');

    define('_SHOP_', true);
}
if($is_member && $member['mb_level'] > 2) {
    define('G5_IS_ADMIN', true);
    include_once(G5_ADMIN_PATH.'/admin.lib.php');
}