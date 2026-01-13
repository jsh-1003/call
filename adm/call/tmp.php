<?php
// /adm/call/tmp_load_mid4_only.php
// 목적: /mnt/data/phone.xlsx 전체 셀에서 4자리 숫자 토큰(mid4)만 추출 → call_phone_mid4(mid4) 에 적재
// 특징: 한 셀에 "1234/5678"처럼 여러 개여도 각 토큰을 개별 row로 저장. 중복은 PK로 무시.
// 실행: 브라우저에서 1회 실행하고 삭제 권장
// 환경: 그누보드5, PHPExcel(내장)
require_once './_common.php';
require_once G5_LIB_PATH.'/call.lib.php';

$sel_company_id = 76;
$sel_mb_group = 77;
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
print_r2($build_org_select_options);