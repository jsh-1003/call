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

if($is_admin != 'super') {
    unset($auth);
    if($member['mb_level'] >= 9) {
        $is_admin = 'super';
    } else if($member['mb_level'] >= 7) {
        $is_admin = 'group';
        $auth = array(
            '700000' => 'rw',
            '700100' => 'rw',
            '700110' => 'rw',
            '700200' => 'rw',
            '700300' => 'rw',
            '700400' => 'rw',
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
            '700500' => 'rw',
            '700750' => 'rw',
        );
    }
}

$is_admin_pay = false;
if($member['mb_id'] == 'admin_pay') {
    $auth[] = array(
        '700990' => 'rw',
    );
    $is_admin_pay = true;
}