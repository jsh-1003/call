<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

// 할당시 카운트 어떻게 할 것인지
define('CALL_STATUS_AUTO_SKIP', -99999);

// 할당시 카운트 어떻게 할 것인지
define('CALL_API_COUNT', 3);
// 배정 유효 기본 시간
define('CALL_LEASE_MIN', 180);
// 세션 만료 시간
define('API_SESSION_TTL_SECONDS', 60*60*24*1); // 1일

define('AWS_REGION', 'ap-northeast-2'); // 서울 리전
define('S3_BUCKET',  'call-save');

define('CALL_SHOP_API_TOKEN', 'a7df2c6e9b814f0dac3f5c12990e4fd8c5bfc79ab3e64d69f0a2b771c8d93451'); // SHOP API 토큰 키

/* -----------------------------------------------------------
 * 유료DB 단가 관련
 * --------------------------------------------------------- */
define('AGENCY_PRICE_CONN', 10);
define('AGENCY_PRICE_10S',  20);

define('MEDIA_PRICE_CONN',  20);
define('MEDIA_PRICE_10S',   40);

define('PAID_PRICE_TYPE_1', 160); // 1번 : 통화10초시 과금, 160원
define('PAID_PRICE_TYPE_2', 80); // 2번 : 통화당 과금, 80원


if($is_admin != 'super') {
    define('G5_DEBUG', false);
} else if($is_admin == 'super' && !empty($_GET['is_debug'])){
    define('G5_DEBUG', true);
}

/**
 * 기본 권한 처리
 */
$is_shop_api_view = false;
$is_admin_pay = false;

$is_company_leader = false; // 사용자 대표인 경우
$is_paid_company = false; // 에이전시+매체사 여부
$is_agency = false;
$is_vendor = false;
$member['member_type'] = empty($member['member_type']) ? 0 : $member['member_type'];


if($member['mb_id'] == 'admin_pay') {
    $auth[] = array(
        '700990' => 'rw',
    );
    $is_admin_pay = true;
    $is_admin = 'super';
}
if($is_admin == 'super') {
    return;
}
unset($auth);

switch ($member['member_type']) {
    case 1:
        $is_agency = true;
    case 2:
        $is_vendor = false;
        $is_paid_company = true;
        $is_admin = 'group';
        $auth = array(
            '200710' => 'rw',
        );
        break;
    // 사용자
    default:
        if($member['mb_level'] >= 9) {
            $is_admin = 'super';
        } else if($member['mb_level'] >= 7) {
            $is_admin = 'group';
            $is_company_leader = $member['mb_level'] == 8;
            $auth = array(
                '700000' => 'rw',
                '700100' => 'rw',
                '700110' => 'rw',
                '700200' => 'rw',
                '700300' => 'rw',
                '700400' => 'rw',
                '700420' => 'rw',
                '700500' => 'rw',
                '700700' => 'rw',
                '700750' => 'rw',
                '700770' => 'rw',
            );
        } else if($member['mb_level'] >= 3) {
            $is_admin = 'group';
            $auth = array(
                '700000' => 'r',
                '700100' => 'r',
                '700110' => 'r',
                '700200' => 'r',
                '700400' => 'rw',
                '700420' => 'rw',
                '700500' => 'rw',
                '700750' => 'rw',
            );
        }
}


/**
 * 예외 처리
 */
// 쇼핑API
if ( isset($member['mb_no']) && in_array($member['mb_no'], array(363,48)) ) {
    $is_shop_api_view = true;
    $auth['700930'] = 'r';
}

// 접수DB
if(isset($member['is_after_db_use']) && $member['is_after_db_use']) {
    $auth['700500'] = 'rw';
}