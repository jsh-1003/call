<?php
// /adm/call/tmp_load_mid4_only.php
// 목적: /mnt/data/phone.xlsx 전체 셀에서 4자리 숫자 토큰(mid4)만 추출 → call_phone_mid4(mid4) 에 적재
// 특징: 한 셀에 "1234/5678"처럼 여러 개여도 각 토큰을 개별 row로 저장. 중복은 PK로 무시.
// 실행: 브라우저에서 1회 실행하고 삭제 권장
// 환경: 그누보드5, PHPExcel(내장)
require_once './_common.php';
include_once(G5_LIB_PATH.'/call.after.api.lib.php');
// $target_id = 13680050;
// $after_api_info = sql_fetch("SELECT t.call_hp, t.name, meta_json, t.assigned_mb_no
//         FROM call_target t
//         WHERE t.target_id = $target_id
//         LIMIT 1
//     ");
// if($after_api_info) {
//     $_a_mb_name = get_member_from_mb_no($after_api_info['assigned_mb_no'], 'mb_name');
//     $b_mo_no = sql_fetch("SELECT  assigned_after_mb_no FROM call_aftercall_ticket WHERE target_id = '{$target_id}' ");
//     if(!empty($b_mo_no['assigned_after_mb_no'])) {
//         $_b_mb_name = get_member_from_mb_no($b_mo_no['assigned_after_mb_no'], 'mb_name');
//     } else {
//         $_b_mb_name['mb_name'] = '';
//     }
//     $a_mb_name = !empty($_a_mb_name['mb_name']) ? $_a_mb_name['mb_name'] : '';
//     $b_mb_name = !empty($_b_mb_name['mb_name']) ? $_b_mb_name['mb_name'] : '';
// }
// $after_api_send_res = send_jnjsmart_call_regist($after_api_info['name'],
//     $after_api_info['call_hp'], 
//     $a_mb_name, 
//     $b_mb_name, 
//     $after_api_info['meta_json']
// );

// $tmp = iconv("euc-kr", "utf-8", $after_api_send_res['body']);
// var_dump($tmp);
// var_dump($after_api_send_res);