<?php
if (!defined('_GNUBOARD_')) exit;

/**
 * 회사ID / 팀ID 지정용 함수
 * @param int $mb_level
 * @param int $_company_id
 * @param mixed $_mb_group_id
 * @return array{company_id: int[], mb_group: array}
 */
function rn_select_company_mb_group_id(int $mb_level, int $_company_id=0, $_mb_group_id=0) {
    global $member;
    $my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
    $my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;
    
    $sel_company_id = $sel_mb_group = array();
    // 조직 선택(레벨 규칙)
    if ($mb_level >= 9) {
        $sel_company_id[] = $_company_id;
        $sel_mb_group[]   = $_mb_group_id;
    } elseif ($mb_level >= 8) {
        $sel_company_id[] = $my_company_id;
        $sel_mb_group[]   = $_mb_group_id;
    } else {
        $sel_company_id[] = $my_company_id;
        $sel_mb_group[]   = $my_group;
    }
    
    // 모니터링 회원
    if ($mb_level == 6) {
        if($member['mb_id'] == '11800890') {
            $sel_company_id = array(843,868);
        }
    }

    return ['company_id'=> $sel_company_id, 'mb_group' => $sel_mb_group];
}
/**
 * 회사ID로 팀ID를 배열로 가져오기
 * @param array $company_ids
 * @return int[]
 */
function rn_get_group_ids_from_company_ids(array $company_ids) {
    global $g5;
    $grp_ids = array();
    $res = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".implode(',',$company_ids)."'");
    while ($r = sql_fetch_array($res)) $grp_ids[] = (int)$r['mb_no'];
    return $grp_ids;
}