<?php
// /adm/call/tmp_load_mid4_only.php
// 목적: /mnt/data/phone.xlsx 전체 셀에서 4자리 숫자 토큰(mid4)만 추출 → call_phone_mid4(mid4) 에 적재
// 특징: 한 셀에 "1234/5678"처럼 여러 개여도 각 토큰을 개별 row로 저장. 중복은 PK로 무시.
// 실행: 브라우저에서 1회 실행하고 삭제 권장
// 환경: 그누보드5, PHPExcel(내장)
require_once './_common.php';

if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

echo date("1aaa983-10-03");
exit;
$info = get_aftercall_db_info(149194);
print_r2($info);
$company_id = 5;
$company_info = get_company_info($company_id, 'is_after_db_use');
var_dump($company_info);
    $detail = array();
    $detail['name'] = !empty($info['detail']['db_name']) ? $info['detail']['db_name'] : $info['basic']['name'];
    $detail['birth'] = !empty($info['detail']['db_birth_date']) ? $info['detail']['db_birth_date'] : $info['basic']['birth_date'];
    $detail['age'] = calc_age_years($detail['birth']);
    $detail['sex'] = !empty($info['detail']['sex']) ? $info['detail']['sex'] : $info['basic']['sex'];
    $hp = !empty($info['detail']['db_hp']) ? $info['detail']['db_hp'] : $info['basic']['call_hp'];
    $detail['hp'] = format_korean_phone($hp);
    $detail['month_pay'] = !empty($info['detail']['month_pay']) ? $info['detail']['month_pay'] : '';
    $detail['visit_at'] = !empty($info['detail']['db_scheduled_at']) ? $info['detail']['db_scheduled_at'] : '';
    $detail['region1'] = !empty($info['detail']['area1']) ? $info['detail']['area1'] : '';
    $detail['region2'] = !empty($info['detail']['area2']) ? $info['detail']['area2'] : '';
    $detail['addr_etc'] = !empty($info['detail']['area3']) ? $info['detail']['area3'] : '';
    $detail['memo'] = !empty($info['detail']['memo']) ? get_text($info['detail']['memo']) : '';
print_r2($detail);