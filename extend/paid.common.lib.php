<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

/** 
 * 유료대상 통화인지 확인
 */
function paid_db_use($mb_no, $target_id, $call_id, $call_time, $call_duration, $paid_db_billing_type) {
    // 1초 이하면 무효로 판단
    if( $call_time < 1 || $call_duration < 1 ) return 0;

    $_company_id = get_member_from_mb_no($mb_no, 'company_id');
    $company_id = $_company_id['company_id'];
    $company_info = get_member_from_mb_no($_company_id['company_id'], 'mb_id');
    $mb_id = $company_info['mb_id']; // 대표 회원 아이디(포인트 차감용)

    if($paid_db_billing_type == 1 && $call_duration > 9.9) {
        // 1번 : 통화10초시 과금, 150원
        $rel_table = '@paid1';
        $paid_price = PAID_PRICE_TYPE_1;
        $content = '통화과금/'.$target_id.'/'.$paid_price;
    } else if($paid_db_billing_type == 2) {
        // 2번 : 통화당 과금
        $rel_table = '@paid2';
        $paid_price = PAID_PRICE_TYPE_2;
        if (in_array($company_id, PAID_PRICE_TYPE_2_PLUS_COMPANY_IDS)) {
            // 예외 단가 적용
            $paid_price = PAID_PRICE_TYPE_2_PLUS_COMPANY;
        }        
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

// 해당 회사가 유료DB 사용중인지 확인
function is_paid_db_use_company(int $company_id) {
    $sql = "SELECT is_paid_db FROM g5_member WHERE mb_no = '{$company_id}' ";
    return (int)current(sql_fetch($sql));
}

// 해당 회원이 유료DB 사용가능인지 확인
function is_paid_db_use_member(int $mb_no) {
    $sql = "SELECT is_paid_db FROM g5_member WHERE mb_no = '{$mb_no}' ";
    $is_paid_db = (int)current(sql_fetch($sql));
    if($is_paid_db < 1) return 0; // 내 설정 확인

    $_company_id = get_member_from_mb_no($mb_no, 'company_id');
    $company_id = $_company_id['company_id'];
    if(is_paid_db_use_company($company_id) < 1) return 0; // 회사 설정 확인

    $company_info = get_member_from_mb_no($company_id, 'mb_point');
    if($company_info['mb_point'] < 1000) return -1; // 1천점 이하 불가
    return $is_paid_db;
}

// 유료DB 빌링 타입
function get_paid_db_billing_type(int $mb_no) {
    $sql = "SELECT paid_db_billing_type FROM g5_member WHERE mb_no = '{$mb_no}' ";
    $paid_db_billing_type = (int)current(sql_fetch($sql));
    if($paid_db_billing_type < 1) $paid_db_billing_type = 1; // 기본값은 무조건 1
    return $paid_db_billing_type;
}

/**
 * 공급사 셀렉트 옵션(에이전시/벤더) 생성
 * - mb_level 은 global $member['mb_level'] 사용
 * - 반환: ['company_options'=>[], 'group_options'=>[]]
 *
 * @param int      $sel_company_id 선택한 회사ID (9+만 의미, 나머지는 회사 고정)
 * @param int      $sel_mb_group   선택한 지점ID (0=전체)
 * @param int      $my_company_id  내 회사ID (8 이하 권한에서 고정 범위)
 * @param int      $my_group       내 지점ID (7 권한에서 고정 범위)
 * @param null|str $member_table   g5 member 테이블명 (null이면 $g5['member_table'])
 * @return array{company_options: array<int, array>, group_options: array<int, array>, agent_options: array<int, array>}
 */
function build_paid_select_options($sel_company_id=0, $sel_mb_group=0) {
    global $member, $g5;

    $member_table   = $g5['member_table'];
    $mb_level       = (int)($member['mb_level'] ?? 0);
    $my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
    $my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;

    /* --------------------------
       회사 옵션(9+)
       -------------------------- */
    $company_options = [];
    if ($mb_level >= 9) {
        $res = sql_query("
            SELECT m.mb_no AS company_id
              FROM {$member_table} m
             WHERE m.member_type = 1
                AND m.mb_level = 8
                AND IFNULL(mb_leave_date,'') = ''
                AND IFNULL(mb_intercept_date,'') = ''
             ORDER BY COALESCE(NULLIF(m.company_name,''), CONCAT('에이전시-', m.mb_no)) ASC, m.mb_no ASC
        ");
        while ($r = sql_fetch_array($res)) {
            $cid   = (int)$r['company_id'];
            $cname = get_company_name_cached($cid);
            $gcnt  = count_groups_by_company_cached($cid);
            $company_options[] = [
                'company_id'   => $cid,
                'company_name' => $cname,
                'group_count'  => $gcnt,
            ];
        }
    }

    /* --------------------------
       지점 옵션(8+)
       -------------------------- */
    $group_options = [];
    if ($mb_level >= 8) {
        $where_g = " WHERE m.member_type = 2 and m.mb_level = 7
                AND IFNULL(m.mb_leave_date,'') = ''
                AND IFNULL(m.mb_intercept_date,'') = ''
        ";
        if ($mb_level >= 9) {
            if ((int)$sel_company_id > 0) $where_g .= " AND m.company_id = '".(int)$sel_company_id."' ";
        } else {
            $where_g .= " AND m.company_id = '".(int)$my_company_id."' ";
            $where_g .= " AND IFNULL(m.mb_leave_date,'') = '' AND IFNULL(m.mb_intercept_date,'') = '' ";
        }
        $res = sql_query("SELECT m.mb_no AS mb_group, m.company_id FROM {$member_table} m {$where_g}
             ORDER BY m.company_id ASC,
                      COALESCE(NULLIF(m.mb_group_name,''), CONCAT('매체사-', m.mb_no)) ASC,
                      m.mb_no ASC
        ");
        while ($r = sql_fetch_array($res)) {
            $gid   = (int)$r['mb_group'];
            $cid   = (int)$r['company_id'];
            $gname = get_group_name_cached($gid);
            $cname = get_company_name_cached($cid);
            $group_options[] = [
                'mb_group'      => $gid,
                'company_id'    => $cid,
                'company_name'  => $cname,
                'mb_group_name' => $gname,
            ];
        }
    }

    return [
        'company_options' => $company_options,
        'group_options'   => $group_options
    ];
}
