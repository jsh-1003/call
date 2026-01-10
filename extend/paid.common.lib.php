<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

/** 
 * 유료대상 통화인지 확인
 */
function paid_db_use($mb_no, $target_id, $call_id, $call_time, $call_duration) {
    // 1초 이하면 무효로 판단
    if( $call_time < 1 || $call_duration < 1 ) return 0;

    $paid_info = get_member_from_mb_no($mb_no, 'company_id, is_paid_db, paid_db_billing_type');
    
    // 유료DB 사용이 아니면 리턴
    if(!$paid_info['is_paid_db']) return 0;
    
    $company_info = get_member_from_mb_no($paid_info['company_id'], 'mb_id');
    $mb_id = $company_info['mb_id'];
    $paid_db_billing_type = $paid_info['paid_db_billing_type'];

    if($paid_db_billing_type == 1 && $call_duration > 9.9) {
        // 1번 : 통화10초시 과금, 150원
        $rel_table = '@paid1';
        $paid_price = PAID_PRICE_TYPE_1;
        $content = '통화과금/'.$target_id.'/'.$paid_price;
    } else if($paid_db_billing_type == 2) {
        // 2번 : 통화당 과금
        $rel_table = '@paid2';
        $paid_price = PAID_PRICE_TYPE_2;
        $content = '연결과금/'.$target_id.'/'.$paid_price;
    } else {
        return 0;
    }
    $point = $paid_price*-1;
    // 대표ID에서 포인트 차감
    insert_point($mb_id, $point, $content, $rel_table, $mb_no, $call_id);
    
    // 차감 정보 업데이트
    $sql = "UPDATE call_log SET is_paid = '{$paid_db_billing_type}', paid_price = '{$paid_price}' WHERE call_id = '{$call_id}' ";
    sql_query($sql);
    return 1;
}
