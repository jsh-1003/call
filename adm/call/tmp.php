<?php
require_once './_common.php';
include_once(G5_LIB_PATH.'/call.assign.lib.php');

exit;
$res = call_assign_pick_and_lock(10, 11, 1, 30, 112233);
var_dump($res);
goto_url('./tmp2.php?od_id=1222334');
exit;
$mb_no = 436;
$paid_db_billing_type = 2;
$call_duration = 10;
$_company_id = get_member_from_mb_no($mb_no, 'company_id');
$company_id = $_company_id['company_id'];
$company_info = get_member_from_mb_no($_company_id['company_id'], 'mb_id');
$mb_id = $company_info['mb_id']; // 대표 회원 아이디(포인트 차감용)

if($paid_db_billing_type == 1 && $call_duration > 9.9) {
    // 1번 : 통화10초시 과금, 150원
    $rel_table = '@paid1';
    $paid_price = PAID_PRICE_TYPE_1;
    $content = '통화과금/'.$paid_price;
} else if($paid_db_billing_type == 2) {
    // 2번 : 통화당 과금
    $rel_table = '@paid2';
    $paid_price = PAID_PRICE_TYPE_2;
    if (in_array($company_id, PAID_PRICE_TYPE_2_PLUS_COMPANY_IDS)) {
        // 예외 단가 적용
        $paid_price = PAID_PRICE_TYPE_2_PLUS_COMPANY;
    }        
    $content = '연결과금/'.$paid_price;
}

echo $content;

echo is_paid_db_use_member($mb_no);