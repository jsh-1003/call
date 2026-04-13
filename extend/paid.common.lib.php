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
        $paid_price = get_company_paid_price($company_id);
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

/**
 * 유료DB 캠페인별 대상 회사 매핑 테이블명
 */
function get_paid_campaign_company_table(): string {
    return 'call_campaign_company';
}

function normalize_paid_campaign_scope_mode(string $scope_mode): string {
    $scope_mode = strtolower(trim($scope_mode));
    if (!in_array($scope_mode, ['all', 'selected', 'exclude'], true)) {
        $scope_mode = 'all';
    }
    return $scope_mode;
}

function get_paid_campaign_scope_summary_text(string $scope_mode, int $company_count): string {
    $scope_mode = normalize_paid_campaign_scope_mode($scope_mode);
    $company_count = max(0, (int)$company_count);

    if ($scope_mode === 'selected') {
        return '선택 회사 '.$company_count.'곳만 사용';
    }
    if ($scope_mode === 'exclude') {
        return '선택 회사 '.$company_count.'곳 제외';
    }
    return '전체 사용';
}

/**
 * 캠페인별 대상 회사 요약
 * - rows가 없으면 전체 사용
 */
function get_paid_campaign_target_summaries(array $campaign_ids): array {
    $summaries = [];
    $campaign_ids = array_values(array_filter(array_map('intval', $campaign_ids), static function($v) {
        return $v > 0;
    }));

    if (!$campaign_ids) {
        return $summaries;
    }

    foreach ($campaign_ids as $campaign_id) {
        $summaries[$campaign_id] = [
            'mode' => 'all',
            'company_ids' => [],
            'company_count' => 0,
            'summary_text' => '전체 사용',
        ];
    }

    $table = get_paid_campaign_company_table();
    $ids_sql = implode(',', $campaign_ids);

    $res = sql_query("
        SELECT campaign_id, company_id, scope_mode
          FROM {$table}
         WHERE campaign_id IN ({$ids_sql})
         ORDER BY campaign_id ASC, company_id ASC
    ");
    while ($row = sql_fetch_array($res)) {
        $campaign_id = (int)$row['campaign_id'];
        $company_id = (int)$row['company_id'];
        $scope_mode = normalize_paid_campaign_scope_mode((string)($row['scope_mode'] ?? 'selected'));

        if (!isset($summaries[$campaign_id])) {
            $summaries[$campaign_id] = [
                'mode' => 'all',
                'company_ids' => [],
                'company_count' => 0,
                'summary_text' => '전체 사용',
            ];
        }

        $summaries[$campaign_id]['mode'] = ($scope_mode === 'exclude') ? 'exclude' : 'selected';
        $summaries[$campaign_id]['company_ids'][] = $company_id;
    }

    foreach ($summaries as $campaign_id => $summary) {
        $count = count($summary['company_ids']);
        $summaries[$campaign_id]['company_count'] = $count;
        $summaries[$campaign_id]['summary_text'] = get_paid_campaign_scope_summary_text((string)$summary['mode'], $count);
    }

    return $summaries;
}

/**
 * 유료DB 캠페인 대상 회사 동기화
 * - company_ids 비우면 전체 사용으로 전환
 */
function sync_paid_campaign_target_companies(int $campaign_id, array $company_ids, int $created_by = 0, string $scope_mode = 'all'): array {
    $campaign_id = (int)$campaign_id;
    $created_by = (int)$created_by;
    $scope_mode = normalize_paid_campaign_scope_mode($scope_mode);

    if ($campaign_id < 1) {
        return ['ok' => false, 'message' => '캠페인 ID가 올바르지 않습니다.'];
    }

    $campaign = sql_fetch("SELECT campaign_id
          FROM call_campaign
         WHERE campaign_id = {$campaign_id}
           AND is_paid_db = 1
           AND mb_group = 0
           AND deleted_at IS NULL
         LIMIT 1
    ");
    if (!$campaign) {
        return ['ok' => false, 'message' => '유효한 유료DB 캠페인이 아닙니다.'];
    }

    $company_ids = array_values(array_unique(array_filter(array_map('intval', $company_ids), static function($v) {
        return $v > 0;
    })));

    $valid_company_ids = [];
    if ($company_ids) {
        $ids_sql = implode(',', $company_ids);
        $res = sql_query("SELECT mb_no
              FROM g5_member
             WHERE mb_no IN ({$ids_sql})
               AND member_type = 0
               AND mb_level = 8
               AND is_paid_db = 1
               AND IFNULL(mb_leave_date,'') = ''
               AND IFNULL(mb_intercept_date,'') = ''
        ");
        while ($row = sql_fetch_array($res)) {
            $valid_company_ids[] = (int)$row['mb_no'];
        }
        sort($valid_company_ids);
    }

    if (count($valid_company_ids) !== count($company_ids)) {
        return ['ok' => false, 'message' => '선택한 회사 중 사용할 수 없는 항목이 있습니다.'];
    }

    $table = get_paid_campaign_company_table();

    sql_query("START TRANSACTION");

    try {
        sql_query("DELETE FROM {$table} WHERE campaign_id = {$campaign_id}");

        if ($scope_mode !== 'all' && $valid_company_ids) {
            $values = [];
            foreach ($valid_company_ids as $company_id) {
                $values[] = "({$campaign_id}, {$company_id}, '{$scope_mode}', ".($created_by > 0 ? $created_by : 'NULL').", NOW())";
            }
            $sql = "
                INSERT INTO {$table} (campaign_id, company_id, scope_mode, created_by, created_at)
                VALUES ".implode(',', $values)."
            ";
            sql_query($sql);
        }

        sql_query("COMMIT");
    } catch (Exception $e) {
        @sql_query("ROLLBACK");
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'mode' => $scope_mode === 'all' ? 'all' : $scope_mode,
        'company_ids' => $valid_company_ids,
        'company_count' => count($valid_company_ids),
        'summary_text' => get_paid_campaign_scope_summary_text($scope_mode, count($valid_company_ids)),
    ];
}

function build_paid_campaign_company_scope_where_sql(string $campaign_alias, int $company_id): string {
    $campaign_alias = trim($campaign_alias);
    $company_id = (int)$company_id;

    if ($campaign_alias === '') {
        return '';
    }

    $table = get_paid_campaign_company_table();
    return "
       AND (
            NOT EXISTS (
                SELECT 1
                  FROM {$table} pct_any
                 WHERE pct_any.campaign_id = {$campaign_alias}.campaign_id
            )
            OR EXISTS (
                SELECT 1
                  FROM {$table} pct_in
                 WHERE pct_in.campaign_id = {$campaign_alias}.campaign_id
                   AND pct_in.scope_mode = 'selected'
                   AND pct_in.company_id = {$company_id}
            )
            OR (
                EXISTS (
                    SELECT 1
                      FROM {$table} pct_ex_any
                     WHERE pct_ex_any.campaign_id = {$campaign_alias}.campaign_id
                       AND pct_ex_any.scope_mode = 'exclude'
                )
                AND NOT EXISTS (
                    SELECT 1
                      FROM {$table} pct_ex_me
                     WHERE pct_ex_me.campaign_id = {$campaign_alias}.campaign_id
                       AND pct_ex_me.scope_mode = 'exclude'
                       AND pct_ex_me.company_id = {$company_id}
                )
            )
       )
    ";
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
        $res = sql_query("SELECT m.mb_no AS company_id
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

/**
 * company_id로 연결당 과금 요금 가져오기
 */
function get_company_paid_price(int $company_id, int $price_type = 2) {
    $return_price = PAID_PRICE_TYPE_2;
    if (in_array($company_id, PAID_PRICE_TYPE_2_PLUS_COMPANY_IDS)) {
        $return_price = PAID_PRICE_TYPE_2_PLUS_COMPANY;
    }    
    if($price_type == 2) {
        $sql = "SELECT paid_price_type_2 FROM g5_member WHERE mb_no = '{$company_id}' ";
        $row = sql_fetch($sql);
        if(!empty($row['paid_price_type_2']) && $row['paid_price_type_2'] ) {
            $return_price = $row['paid_price_type_2'];
        }
    } else {
        $return_price = PAID_PRICE_TYPE_1;
    }
    return (int)$return_price;
}
