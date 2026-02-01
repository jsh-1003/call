<?php
// /adm/paid/paid_stats.php
$sub_menu = '200750';
require_once './_common.php';

/* -----------------------------------------------------------
 * 0) 접근권한:
 *  - $is_admin_pay 전용(관리자)
 *  - 에이전시(member_type=1)
 *  - 매체사(member_type=2)
 *  - 사용자 대표(member_type=0 && mb_level=8)
 * --------------------------------------------------------- */
$member_table = $g5['member_table']; // g5_member

$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$my_type       = (int)($member['member_type'] ?? 0);

$is_admin9      = (bool)$is_admin_pay || ($mb_level >= 9);
$is_agency      = ($my_type === 1); // 에이전시
$is_media       = ($my_type === 2); // 매체사
$is_company_rep = ($my_type === 0 && $mb_level === 8); // 사용자 대표

if (!$is_admin9 && !$is_agency && !$is_media && !$is_company_rep) {
    alert('접근 권한이 없습니다.');
}

/* -----------------------------------------------------------
 * 2) 파라미터
 * --------------------------------------------------------- */
$default_start = date('Y-m-d').'T08:00';
$default_end   = date('Y-m-d').'T19:00';

$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

// SQL용(WHERE) 날짜값: datetime-local의 'T' 제거 + 초 보정
$start_sql = normalize_datetime_local($start_date, false);
$end_sql   = normalize_datetime_local($end_date,   true);

// 파트너(에이전시/매체사) 필터
// - 관리자: 선택 가능
// - 에이전시: sc_agency는 본인 고정, sc_vendor만 선택 가능
// - 매체사: sc_vendor는 본인 고정
$sel_agency_no = 0;
$sel_vendor_no = 0;
if ($is_admin9) {
    $sel_agency_no = (int)($_GET['sc_agency'] ?? 0);
    $sel_vendor_no = (int)($_GET['sc_vendor'] ?? 0);
} elseif ($is_agency) {
    $sel_agency_no = $mb_no;
    $sel_vendor_no = (int)($_GET['sc_vendor'] ?? 0);
} elseif ($is_media) {
    $sel_vendor_no = $mb_no;
}

// 회사/지점/상담사 셀렉트는 "사용자(member_type=0)" 유료 회원 기반(초기 코드가 맞음)
// - 관리자/사용자대표만 노출
$show_org_select = ($is_admin9 || $is_company_rep);

// 회사/지점/상담사 선택값
if ($is_admin9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체
} elseif ($is_company_rep) {
    $sel_company_id = $my_company_id; // 대표는 자기 회사 고정
} else {
    $sel_company_id = 0; // 파트너는 사용자 회사 스코프를 걸지 않음
}

// 지점/상담사 필터는 관리자/대표만
$sel_mb_group = $show_org_select ? (int)($_GET['mb_group'] ?? 0) : 0;
$sel_agent_no = $show_org_select ? (int)($_GET['agent'] ?? 0) : 0;

// (이하) 검색/페이징

// 검색
$q      = _g('q', '');
$q_type = _g('q_type', 'all'); // name | last4 | full | all

$page      = max(1, (int)(_g('page', '1')));
$page_rows = 50;
$offset    = ($page - 1) * $page_rows;

/* -----------------------------------------------------------
 * 3) 유틸
 * --------------------------------------------------------- */
function fmt_rate($num, $den){
    $n = (int)$num; $d = (int)$den;
    if ($d <= 0 || $n <= 0) return '-';
    return number_format($n * 100 / $d, 1) . '%';
}
function billing_type_label($v){
    $v = (int)$v;
    return ($v === 1) ? '10초 이상' : (($v === 2) ? '연결당' : '-');
}
function db_age_label($v){
    $v = (int)$v;
    return ($v === 2) ? '실버' : '일반';
}
function safe_company_name($row){
    $name = trim((string)($row['company_name'] ?? ''));
    if ($name === '') $name = get_company_name_cached($row['mb_no']);
    else if ($name === '') $name = trim((string)($row['mb_name'] ?? ''));
    else if ($name === '') $name = '회사-'.(int)($row['mb_no'] ?? 0);
    return $name;
}

// datetime-local(YYYY-MM-DDTHH:MM) -> MySQL DATETIME(YYYY-MM-DD HH:MM:SS)
function normalize_datetime_local($v, $is_end=false){
    $v = trim((string)$v);
    if ($v === '') return $v;
    $v = str_replace('T',' ', $v);
    // date only
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $v . ($is_end ? ' 23:59:59' : ' 00:00:00');
    }
    // no seconds
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $v)) {
        return $v . ($is_end ? ':59' : ':00');
    }
    // already has seconds or other - return as-is
    return $v;
}

/* -----------------------------------------------------------
 * 4) WHERE 구성(기간 내 사용/매출) - call_log 기반
 * --------------------------------------------------------- */
$start_esc = sql_escape_string($start_sql);
$end_esc   = sql_escape_string($end_sql);

$need_target_join = false;
$where = [];
$where[] = "l.is_paid_db = 1";
$where[] = "l.is_paid IN (1,2)"; // 1/2 등 확장
$where[] = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 회사 스코프: 관리자 전체/선택, 파트너/대표는 고정
if ($sel_company_id > 0) {
    $where[] = "ag.company_id = {$sel_company_id}";
}

// 파트너(에이전시/매체사) 필터
// - Table_1 기준이지만, 상세/요약도 동일한 필터를 적용하는 편이 혼동이 적음
if ($sel_agency_no > 0) $where[] = "c.db_agency = {$sel_agency_no}";
if ($sel_vendor_no > 0) $where[] = "c.db_vendor = {$sel_vendor_no}";

// 지점/상담사 필터
if ($sel_mb_group > 0) $where[] = "l.mb_group = {$sel_mb_group}";
if ($sel_agent_no > 0) $where[] = "l.mb_no = {$sel_agent_no}";

// 검색
$q = trim($q);
$q_type = trim($q_type);
if ($q !== '') {
    if ($q_type === 'name') {
        $need_target_join = true;
        $q_esc = sql_escape_string($q);
        $where[] = "t.name LIKE '%{$q_esc}%'";
    } elseif ($q_type === 'last4') {
        $need_target_join = true;
        $q4 = preg_replace('/\D+/', '', $q);
        $q4 = substr($q4, -4);
        if ($q4 !== '') {
            $q4_esc = sql_escape_string($q4);
            $where[] = "t.hp_last4 = '{$q4_esc}'";
        }
    } elseif ($q_type === 'full') {
        $hp = preg_replace('/\D+/', '', $q);
        if ($hp !== '') {
            $hp_esc = sql_escape_string($hp);
            $where[] = "l.call_hp = '{$hp_esc}'";
        }
    } else { // all
        $need_target_join = true;
        $q_esc = sql_escape_string($q);
        $q4    = substr(preg_replace('/\D+/', '', $q), -4);
        $hp    = preg_replace('/\D+/', '', $q);

        $conds = [];
        $conds[] = "t.name LIKE '%{$q_esc}%'";
        if ($q4 !== '') $conds[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
        if ($hp !== '') $conds[] = "l.call_hp = '".sql_escape_string($hp)."'";
        $where[] = '(' . implode(' OR ', $conds) . ')';
    }
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$show_admin_cols = $is_admin9;

/* -----------------------------------------------------------
 * 5) 드롭다운 옵션
 *  - (A) 파트너(에이전시/매체사) 셀렉트: call_campaign 기반
 *  - (B) 조직 셀렉트(사용업체/지점/상담사): member_type=0(사용자) 유료 회원 기반(초기 로직 유지)
 * --------------------------------------------------------- */

// (A) 파트너 셀렉트 노출 여부
$show_agency_select = $is_admin9;              // 관리자만
$show_vendor_select = ($is_admin9 || $is_agency); // 관리자/에이전시

$agency_select_options = [];
$vendor_select_options = [];

if ($show_agency_select) {
    $qA = sql_query("
        SELECT c.db_agency AS mb_no, m.mb_name AS name
          FROM call_campaign c
          JOIN {$member_table} m
            ON m.mb_no = c.db_agency
           AND m.member_type = 1
           AND IFNULL(m.mb_leave_date,'')=''
           AND IFNULL(m.mb_intercept_date,'')=''
         WHERE c.is_paid_db=1
           AND c.db_agency > 0
         GROUP BY c.db_agency
         ORDER BY m.mb_name ASC, c.db_agency ASC
    ");
    while ($r = sql_fetch_array($qA)) {
        $agency_select_options[] = ['mb_no'=>(int)$r['mb_no'], 'name'=>trim((string)$r['name'])];
    }
}

if ($show_vendor_select) {
    $vendor_where = ["c.is_paid_db=1", "c.db_vendor > 0"];
    if ($is_agency) {
        $vendor_where[] = "c.db_agency = {$mb_no}";
    } elseif ($sel_agency_no > 0) {
        $vendor_where[] = "c.db_agency = {$sel_agency_no}";
    }
    $vendor_where_sql = 'WHERE '.implode(' AND ', $vendor_where);

    $qV = sql_query("
        SELECT c.db_vendor AS mb_no, m.mb_name AS name
          FROM call_campaign c
          JOIN {$member_table} m
            ON m.mb_no = c.db_vendor
           AND m.member_type = 2
           AND IFNULL(m.mb_leave_date,'')=''
           AND IFNULL(m.mb_intercept_date,'')=''
          {$vendor_where_sql}
         GROUP BY c.db_vendor
         ORDER BY m.mb_name ASC, c.db_vendor ASC
    ");
    while ($r = sql_fetch_array($qV)) {
        $vendor_select_options[] = ['mb_no'=>(int)$r['mb_no'], 'name'=>trim((string)$r['name'])];
    }
}

// 선택값 유효성 보정(에이전시 선택 시 매체사가 해당 소속이 아니면 초기화)
if ($show_vendor_select && $sel_vendor_no > 0 && !$is_media) {
    $ok = false;
    foreach ($vendor_select_options as $v) {
        if ((int)$v['mb_no'] === (int)$sel_vendor_no) { $ok = true; break; }
    }
    if (!$ok) $sel_vendor_no = 0;
}
if ($is_media) {
    $sel_vendor_no = $mb_no;
}

// (B) 조직(사용업체/지점/상담사) 옵션
$company_options = [];
$group_options   = [];
$agent_options   = [];
if ($show_org_select) {
    $build_org_select_options = build_org_select_options_paid_db($sel_company_id, $sel_mb_group);
    $company_options = $build_org_select_options['company_options'];
    $group_options   = $build_org_select_options['group_options'];
    $agent_options   = $build_org_select_options['agent_options'];
}

/* -----------------------------------------------------------
 * 6) 회사 목록/회사명/포인트 맵 (상단/상세용)
 * --------------------------------------------------------- */
$company_map = []; // [cid => ['name'=>, 'point'=>]]
if ($is_admin9) {
    $company_ids = [];
    if ($sel_company_id > 0) {
        $company_ids = [$sel_company_id];
    } else {
        foreach ($company_options as $c) $company_ids[] = (int)$c['company_id'];
    }
    $company_ids = array_values(array_unique(array_filter($company_ids)));

    if (!empty($company_ids)) {
        $in = implode(',', array_map('intval', $company_ids));
        $q_company = sql_query("
            SELECT mb_no, mb_name, company_name, mb_point
              FROM {$member_table}
             WHERE member_type=0
               AND mb_level=8
               AND mb_no IN ({$in})
               AND IFNULL(mb_leave_date,'')=''
               AND IFNULL(mb_intercept_date,'')=''
        ");
        while ($r = sql_fetch_array($q_company)) {
            $cid = (int)$r['mb_no'];
            $company_map[$cid] = [
                'name'  => safe_company_name($r),
                'point' => (int)($r['mb_point'] ?? 0),
            ];
        }
        foreach ($company_ids as $cid) {
            if (!isset($company_map[$cid])) $company_map[$cid] = ['name'=>'회사-'.$cid, 'point'=>0];
        }
    }
}

/* -----------------------------------------------------------
 * 7) 집계
 *  - Table_1: "유료DB 통계" (에이전시/매체사 기준)
 *      * 잔여/전체: 활성 캠페인(status=1)만
 *      * 사용/매출: 캠페인 상태와 무관 (기간/필터 반영)
 *  - Table_2: "사용 업체 요약" (관리자용) - 사용 내역 있는 업체만 출력
 * --------------------------------------------------------- */
$join_t = $need_target_join ? "JOIN call_target t ON t.target_id = l.target_id" : "";

/** (A) 기간 내 사용/매출 - 회사별(테이블2용) */
$usage_period_company = []; // [cid => ...]
if ($is_admin9) {
    $sql_usage_company = "SELECT
            ag.company_id AS company_id,
            COUNT(*) AS total_used,
            SUM(CASE WHEN l.db_age_type=1 THEN 1 ELSE 0 END) AS normal_used,
            SUM(CASE WHEN l.db_age_type=2 THEN 1 ELSE 0 END) AS silver_used,
            SUM(CASE WHEN l.paid_db_billing_type=1 THEN 1 ELSE 0 END) AS cnt_10s,
            SUM(CASE WHEN l.paid_db_billing_type=2 THEN 1 ELSE 0 END) AS cnt_conn,
            SUM(CASE WHEN l.paid_db_billing_type=1 THEN l.paid_price ELSE 0 END) AS sum_10s_user,
            SUM(CASE WHEN l.paid_db_billing_type=2 THEN l.paid_price ELSE 0 END) AS sum_conn_user,
            SUM(l.paid_price) AS sum_user_total
        FROM call_log l
        JOIN {$member_table} ag
          ON ag.mb_no = l.mb_no
         AND ag.member_type=0
         AND ag.mb_level=3
         AND IFNULL(ag.mb_leave_date,'')=''
         AND IFNULL(ag.mb_intercept_date,'')=''
        JOIN call_campaign c
          ON c.campaign_id = l.campaign_id
         AND c.is_paid_db  = 1
        {$join_t}
        {$where_sql}
        GROUP BY ag.company_id
    ";
    $q_usage_company = sql_query($sql_usage_company);
    while ($r = sql_fetch_array($q_usage_company)) {
        $cid = (int)$r['company_id'];
        $usage_period_company[$cid] = [
            'total_used'     => (int)$r['total_used'],
            'normal_used'    => (int)$r['normal_used'],
            'silver_used'    => (int)$r['silver_used'],
            'cnt_10s'        => (int)$r['cnt_10s'],
            'cnt_conn'       => (int)$r['cnt_conn'],
            'sum_10s_user'   => (int)$r['sum_10s_user'],
            'sum_conn_user'  => (int)$r['sum_conn_user'],
            'sum_user_total' => (int)$r['sum_user_total'],
        ];
    }
}

/** (B) 기간 내 사용/매출 - 에이전시/매체사 기준(테이블1용), 캠페인 상태와 무관 */
$usage_period_partner = []; // ["agency|vendor" => ...]
$sql_usage_partner = "SELECT
        c.db_agency AS db_agency,
        c.db_vendor AS db_vendor,
        COUNT(*) AS total_used,
        SUM(CASE WHEN l.db_age_type=1 THEN 1 ELSE 0 END) AS normal_used,
        SUM(CASE WHEN l.db_age_type=2 THEN 1 ELSE 0 END) AS silver_used,
        SUM(CASE WHEN l.paid_db_billing_type=1 THEN 1 ELSE 0 END) AS cnt_10s,
        SUM(CASE WHEN l.paid_db_billing_type=2 THEN 1 ELSE 0 END) AS cnt_conn,
        SUM(CASE WHEN l.paid_db_billing_type=1 THEN l.paid_price ELSE 0 END) AS sum_10s_user,
        SUM(CASE WHEN l.paid_db_billing_type=2 THEN l.paid_price ELSE 0 END) AS sum_conn_user,
        SUM(l.paid_price) AS sum_user_total
    FROM call_log l
    JOIN {$member_table} ag
      ON ag.mb_no = l.mb_no
     AND ag.member_type=0
     AND ag.mb_level BETWEEN 3 AND 7
     AND IFNULL(ag.mb_leave_date,'')=''
     AND IFNULL(ag.mb_intercept_date,'')=''
    JOIN call_campaign c
      ON c.campaign_id = l.campaign_id
     AND c.is_paid_db  = 1
    {$join_t}
    {$where_sql}
";

$sql_usage_partner .= "\n GROUP BY c.db_agency, c.db_vendor";

$q_usage_partner = sql_query($sql_usage_partner);
while ($r = sql_fetch_array($q_usage_partner)) {
    $k = (int)$r['db_agency'].'|'.(int)$r['db_vendor'];
    $usage_period_partner[$k] = [
        'db_agency'      => (int)$r['db_agency'],
        'db_vendor'      => (int)$r['db_vendor'],
        'total_used'     => (int)$r['total_used'],
        'normal_used'    => (int)$r['normal_used'],
        'silver_used'    => (int)$r['silver_used'],
        'cnt_10s'        => (int)$r['cnt_10s'],
        'cnt_conn'       => (int)$r['cnt_conn'],
        'sum_10s_user'   => (int)$r['sum_10s_user'],
        'sum_conn_user'  => (int)$r['sum_conn_user'],
        'sum_user_total' => (int)$r['sum_user_total'],
    ];
}

/** (C) 보유/잔여(활성 캠페인 대상만) - 에이전시/매체사 기준 */
$stock_partner_active = []; // ["agency|vendor" => ...]
$scope_company_for_pool = $sel_company_id;
if ($scope_company_for_pool <= 0 && $sel_mb_group > 0) {
    // 회사 전체(0)에서 지점을 선택한 경우, 풀(0)이 전체로 섞이지 않도록 해당 지점의 회사로 스코프 보정
    $scope_company_for_pool = (int)get_company_id_from_group_id_cached($sel_mb_group);
}

$where_pool = [];
$where_pool[] = "t.is_paid_db = 1";
if ($sel_mb_group > 0) {
    // 지점 선택 시: 풀(0) + 해당 지점 타겟 포함
    $where_pool[] = "(t.mb_group = 0 OR t.mb_group = {$sel_mb_group})";
}
$where_pool_sql = $where_pool ? ('WHERE '.implode(' AND ', $where_pool)) : '';

$sql_stock_partner = "
    SELECT
        c.db_agency AS db_agency,
        c.db_vendor AS db_vendor,
        SUM(CASE WHEN t.db_age_type=1 THEN 1 ELSE 0 END) AS normal_total,
        SUM(CASE WHEN t.db_age_type=2 THEN 1 ELSE 0 END) AS silver_total,
        SUM(CASE WHEN t.db_age_type=1 AND t.last_result IS NULL THEN 1 ELSE 0 END) AS normal_remain,
        SUM(CASE WHEN t.db_age_type=2 AND t.last_result IS NULL THEN 1 ELSE 0 END) AS silver_remain
    FROM call_target t
    JOIN call_campaign c
      ON c.campaign_id = t.campaign_id
     AND c.is_paid_db  = 1
     AND c.status      = 1
     AND c.deleted_at IS NULL
    LEFT JOIN {$member_table} v
      ON v.mb_no = c.db_vendor
     AND v.member_type = 2
     AND IFNULL(v.mb_leave_date,'')=''
     AND IFNULL(v.mb_intercept_date,'')=''
    LEFT JOIN {$member_table} a
      ON a.mb_no = c.db_agency
     AND a.member_type = 1
     AND IFNULL(a.mb_leave_date,'')=''
     AND IFNULL(a.mb_intercept_date,'')=''
    {$where_pool_sql}
";

// 파트너(에이전시/매체사) 필터(잔여/전체는 캠페인 status=1만)
if ($sel_agency_no > 0) $sql_stock_partner .= "\n AND c.db_agency = {$sel_agency_no}";
if ($sel_vendor_no > 0) $sql_stock_partner .= "\n AND c.db_vendor = {$sel_vendor_no}";

/* 회사 스코프(관리자/파트너/대표 공통): 캠페인 귀속 회사 기준 */
if ($scope_company_for_pool > 0) {
    $sql_stock_partner .= "\n AND COALESCE(v.company_id, a.company_id, 0) = {$scope_company_for_pool}";
}

$sql_stock_partner .= "\n GROUP BY c.db_agency, c.db_vendor";

$q_stock_partner = sql_query($sql_stock_partner);
while ($r = sql_fetch_array($q_stock_partner)) {
    $k = (int)$r['db_agency'].'|'.(int)$r['db_vendor'];
    $stock_partner_active[$k] = [
        'db_agency'      => (int)$r['db_agency'],
        'db_vendor'      => (int)$r['db_vendor'],
        'normal_total'   => (int)$r['normal_total'],
        'silver_total'   => (int)$r['silver_total'],
        'normal_remain'  => (int)$r['normal_remain'],
        'silver_remain'  => (int)$r['silver_remain'],
    ];
}

/* -----------------------------------------------------------
 * 7-1) 테이블1/2 행 구성 + KPI 합계
 * --------------------------------------------------------- */
$rows_t1 = [];
$rows_t2 = [];

$sum_cnt_10s = 0;
$sum_cnt_conn = 0;
$sum_user_total = 0;
$sum_agency_fee = 0;
$sum_media_fee  = 0;

// 로그인 역할별 "표시 단가"(요청사항: 파트너 로그인 시 표의 금액은 파트너 단가 기준)
$viewer_price_conn = PAID_PRICE_TYPE_1;
$viewer_price_10s  = PAID_PRICE_TYPE_2;
if ($is_agency) { $viewer_price_conn = AGENCY_PRICE_CONN; $viewer_price_10s = AGENCY_PRICE_10S; }
if ($is_media)  { $viewer_price_conn = MEDIA_PRICE_CONN;  $viewer_price_10s = MEDIA_PRICE_10S;  }

/** (A) 테이블1 키(활성풀/사용 둘 다 포함) */
$keys = array_unique(array_merge(array_keys($usage_period_partner), array_keys($stock_partner_active)));

/** 에이전시/매체사 이름 맵 */
$agency_ids = [];
$vendor_ids = [];
foreach ($keys as $k) {
    [$aid,$vid] = array_map('intval', explode('|',$k));
    if ($aid > 0) $agency_ids[$aid] = 1;
    if ($vid > 0) $vendor_ids[$vid] = 1;
}

$agency_name_map = [];
$vendor_name_map = [];

if (!empty($agency_ids)) {
    $in = implode(',', array_keys($agency_ids));
    $qA = sql_query("SELECT mb_no, mb_name FROM {$member_table}
                     WHERE mb_no IN ({$in}) AND member_type=1
                       AND IFNULL(mb_leave_date,'')='' AND IFNULL(mb_intercept_date,'')=''");
    while ($r = sql_fetch_array($qA)) $agency_name_map[(int)$r['mb_no']] = trim((string)$r['mb_name']);
}
if (!empty($vendor_ids)) {
    $in = implode(',', array_keys($vendor_ids));
    $qV = sql_query("SELECT mb_no, mb_name FROM {$member_table}
                     WHERE mb_no IN ({$in}) AND member_type=2
                       AND IFNULL(mb_leave_date,'')='' AND IFNULL(mb_intercept_date,'')=''");
    while ($r = sql_fetch_array($qV)) $vendor_name_map[(int)$r['mb_no']] = trim((string)$r['mb_name']);
}

/** 테이블1 rows */
foreach ($keys as $k) {
    $u = $usage_period_partner[$k] ?? [
        'db_agency'=>0,'db_vendor'=>0,'total_used'=>0,'normal_used'=>0,'silver_used'=>0,
        'cnt_10s'=>0,'cnt_conn'=>0,'sum_10s_user'=>0,'sum_conn_user'=>0,'sum_user_total'=>0
    ];
    $s = $stock_partner_active[$k] ?? [
        'db_agency'=>(int)$u['db_agency'], 'db_vendor'=>(int)$u['db_vendor'],
        'normal_total'=>0,'silver_total'=>0,'normal_remain'=>0,'silver_remain'=>0
    ];

    $aid = (int)($s['db_agency'] ?? $u['db_agency']);
    $vid = (int)($s['db_vendor'] ?? $u['db_vendor']);

    $cnt_10s  = (int)$u['cnt_10s'];
    $cnt_conn = (int)$u['cnt_conn'];

    // 파트너 정산(단가 기준)
    $agency_fee = ($cnt_conn * (int)AGENCY_PRICE_CONN) + ($cnt_10s * (int)AGENCY_PRICE_10S);
    $media_fee  = ($cnt_conn * (int)MEDIA_PRICE_CONN)  + ($cnt_10s * (int)MEDIA_PRICE_10S);
    $admin_profit = (int)$u['sum_user_total'] - ($agency_fee + $media_fee);

    // 표에 표시할 과금총액
    $sum_10s_display  = (int)$u['sum_10s_user'];
    $sum_conn_display = (int)$u['sum_conn_user'];
    if ($is_agency || $is_media) {
        $sum_10s_display  = $cnt_10s  * (int)$viewer_price_10s;
        $sum_conn_display = $cnt_conn * (int)$viewer_price_conn;
    }

    $rows_t1[] = [
        'db_agency'     => $aid,
        'db_vendor'     => $vid,
        'agency_name'   => $aid > 0 ? (trim((string)($agency_name_map[$aid] ?? '')) ?: ('에이전시-'.$aid)) : '-',
        'media_name'    => $vid > 0 ? (trim((string)($vendor_name_map[$vid] ?? '')) ?: ('매체사-'.$vid)) : '-',

        // 활성 캠페인 기준 풀
        'normal_total'  => (int)$s['normal_total'],
        'silver_total'  => (int)$s['silver_total'],
        'normal_remain' => (int)$s['normal_remain'],
        'silver_remain' => (int)$s['silver_remain'],

        // 기간 내 사용(캠페인 상태 무관)
        'normal_used'   => (int)$u['normal_used'],
        'silver_used'   => (int)$u['silver_used'],

        'cnt_10s'       => $cnt_10s,
        'cnt_conn'      => $cnt_conn,

        'sum_10s_display'  => (int)$sum_10s_display,
        'sum_conn_display' => (int)$sum_conn_display,
        'sum_user_total'   => (int)$u['sum_user_total'],

        // 관리자 컬럼
        'agency_fee'    => (int)$agency_fee,
        'media_fee'     => (int)$media_fee,
        'admin_profit'  => (int)$admin_profit,
    ];

    // KPI 합계(기간 내)
    $sum_cnt_10s     += $cnt_10s;
    $sum_cnt_conn    += $cnt_conn;
    $sum_user_total  += (int)$u['sum_user_total'];
    $sum_agency_fee  += (int)$agency_fee;
    $sum_media_fee   += (int)$media_fee;
}
$sum_admin_profit = $sum_user_total - ($sum_agency_fee + $sum_media_fee);

// Table1 보기 정렬(에이전시→매체사)
usort($rows_t1, function($a,$b){
    $x = strcmp((string)$a['agency_name'], (string)$b['agency_name']);
    if ($x !== 0) return $x;
    return strcmp((string)$a['media_name'], (string)$b['media_name']);
});

/** (B) 테이블2: 사용 내역 있는 업체만 출력(관리자만 노출) */
foreach ($usage_period_company as $cid => $us) {
    $cid = (int)$cid;
    if ($cid <= 0) continue;

    $rows_t2[] = [
        'company_id'     => $cid,
        'company_name'   => $company_map[$cid]['name'] ?? get_company_name_cached($cid),
        'total_receive'  => (int)$us['total_used'],
        'normal_used'    => (int)$us['normal_used'],
        'silver_used'    => (int)$us['silver_used'],
        'sum_10s_user'   => (int)$us['sum_10s_user'],
        'sum_conn_user'  => (int)$us['sum_conn_user'],
        'sum_user_total' => (int)$us['sum_user_total'],
        'mb_point'       => (int)($company_map[$cid]['point'] ?? get_company_info($cid)['mb_point']),
    ];
}
usort($rows_t2, function($a,$b){
    return ($b['sum_user_total'] <=> $a['sum_user_total']);
});

/* -----------------------------------------------------------
 * 7-2) (대표 전용) 지점/상담원 요약 (기간 내)
 * --------------------------------------------------------- */
$rows_rep_summary = [];
if ($is_company_rep) {
    $sql_rep = "
        SELECT
            l.mb_group,
            l.mb_no AS agent_id,
            ag.mb_name AS agent_name,
            ag.mb_id   AS agent_mb_id,
            COUNT(*) AS total_used,
            SUM(CASE WHEN l.db_age_type=1 THEN 1 ELSE 0 END) AS normal_used,
            SUM(CASE WHEN l.db_age_type=2 THEN 1 ELSE 0 END) AS silver_used,
            SUM(CASE WHEN l.paid_db_billing_type=1 THEN l.paid_price ELSE 0 END) AS sum_10s_user,
            SUM(CASE WHEN l.paid_db_billing_type=2 THEN l.paid_price ELSE 0 END) AS sum_conn_user,
            SUM(l.paid_price) AS sum_user_total
        FROM call_log l
        JOIN {$member_table} ag
          ON ag.mb_no = l.mb_no
         AND ag.member_type=0
         AND ag.mb_level BETWEEN 3 AND 7
         AND IFNULL(ag.mb_leave_date,'')=''
         AND IFNULL(ag.mb_intercept_date,'')=''
        {$join_t}
        {$where_sql}
        GROUP BY l.mb_group, l.mb_no
        ORDER BY l.mb_group ASC, ag.mb_name ASC, l.mb_no ASC
    ";
    $q_rep = sql_query($sql_rep);
    while ($r = sql_fetch_array($q_rep)) {
        $gid = (int)$r['mb_group'];
        $agent = $r['agent_name'] ? get_text($r['agent_name']) : get_text($r['agent_mb_id']);
        $rows_rep_summary[] = [
            'mb_group'      => $gid,
            'group_name'    => get_group_name_cached($gid),
            'agent_id'      => (int)$r['agent_id'],
            'agent_name'    => $agent,
            'total_used'    => (int)$r['total_used'],
            'normal_used'   => (int)$r['normal_used'],
            'silver_used'   => (int)$r['silver_used'],
            'sum_10s_user'  => (int)$r['sum_10s_user'],
            'sum_conn_user' => (int)$r['sum_conn_user'],
            'sum_user_total'=> (int)$r['sum_user_total'],
        ];
    }
}

/* -----------------------------------------------------------
 * 8) 상세(최근) - call_id 먼저 LIMIT 후 조인 (테이블3)
 *    * 에이전시/매체사 표시는 call_campaign.db_agency/db_vendor 기준으로 표시
 * --------------------------------------------------------- */
$sub_joins = [];
$sub_joins[] = "JOIN {$member_table} ag ON ag.mb_no=l.mb_no AND ag.member_type=0 AND ag.mb_level BETWEEN 3 AND 7 AND IFNULL(ag.mb_leave_date,'')='' AND IFNULL(ag.mb_intercept_date,'')=''";
$sub_joins[] = "JOIN call_campaign c ON c.campaign_id=l.campaign_id AND c.is_paid_db=1";
if ($need_target_join) {
    $sub_joins[] = "JOIN call_target t ON t.target_id = l.target_id";
}
$sub_joins_sql = implode("\n", $sub_joins);

$sql_cnt = "
    SELECT COUNT(*) AS cnt
      FROM call_log l
      {$sub_joins_sql}
      {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

$sub = "
    SELECT l.call_id
      FROM call_log l
      {$sub_joins_sql}
      {$where_sql}
     ORDER BY l.call_start DESC, l.call_id DESC
     LIMIT {$offset}, {$page_rows}
";

$res_list = sql_query("
    SELECT
        l.call_id,
        ag.company_id,
        l.mb_group,
        l.mb_no AS agent_id,
        ag.mb_name AS agent_name,
        ag.mb_id   AS agent_mb_id,

        l.db_age_type,
        l.paid_db_billing_type,
        l.paid_price,
        l.call_hp,

        t.name       AS target_name,
        t.call_hp    AS target_hp,
        t.birth_date,
        t.meta_json,

        c.db_agency,
        c.db_vendor,
        a.mb_name AS agency_name,
        v.mb_name AS vendor_name,

        l.call_start,
        l.call_end,
        l.call_time
    FROM ({$sub}) pick
    JOIN call_log l ON l.call_id = pick.call_id
    JOIN {$member_table} ag
      ON ag.mb_no=l.mb_no
     AND ag.member_type=0
     AND ag.mb_level BETWEEN 3 AND 7
     AND IFNULL(ag.mb_leave_date,'')=''
     AND IFNULL(ag.mb_intercept_date,'')=''
    JOIN call_target t ON t.target_id = l.target_id
    JOIN call_campaign c ON c.campaign_id = l.campaign_id AND c.is_paid_db=1
    LEFT JOIN {$member_table} a ON a.mb_no=c.db_agency AND a.member_type=1
    LEFT JOIN {$member_table} v ON v.mb_no=c.db_vendor AND v.member_type=2
    ORDER BY l.call_start DESC, l.call_id DESC
");

/* -----------------------------------------------------------
 * 9) 화면 출력
 * --------------------------------------------------------- */
$token = get_token();
$g5['title'] = '사용통계';
include_once(G5_ADMIN_PATH.'/admin.head.php');

$hide_table1 = $is_company_rep; // 대표는 테이블1 숨김(기존 유지)
$hide_table2 = $is_company_rep || $is_agency || $is_media; // 파트너/대표는 테이블2 숨김
$hide_t3_branch_agent_cols = ($is_agency || $is_media); // 파트너는 지점/상담원 숨김
?>
<style>
.opt-sep { color:#888; font-style:italic; }
.tbl_call_list td {max-width:200px;}
.small-muted{ color:#777; font-size:12px; }
.sortable th {cursor:pointer}

.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.kpi { display:flex;justify-content:flex-end;gap:12px; flex-wrap:wrap; margin:10px 0; }
.kpi .card { padding:12px 16px; border:1px solid #e5e5e5; border-radius:6px; min-width:160px; text-align:center; background:#fff; }
.kpi .big { font-size:20px; font-weight:bold; }
</style>

<!-- 검색/필터 -->
<div class="local_sch01 local_sch">
    <form method="get" action="./paid_stats.php" class="form-row" id="searchForm">
        <input type="datetime-local" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input">
        <span class="tilde">~</span>
        <input type="datetime-local" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input">

        <?php render_date_range_buttons('dateRangeBtns'); ?>
        <script>
          DateRangeButtons.init({
            container: '#dateRangeBtns', startInput: '#start', endInput: '#end', form: '#searchForm',
            autoSubmit: true, weekStart: 1, thisWeekEndToday: true, thisMonthEndToday: true
          });
        </script>

        <span>&nbsp;|&nbsp;</span>

        <label for="q_type">검색</label>
        <select name="q_type" id="q_type" style="width:100px">
            <option value="all"   <?php echo $q_type==='all'?'selected':'';?>>전체</option>
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" style="width:160px" placeholder="검색어 입력">

        <span>&nbsp;|&nbsp;</span>

        <?php // 파트너(에이전시/매체사) 필터 셀렉트 ?>
        <?php if ($show_agency_select) { ?>
            <select name="sc_agency" id="sc_agency" style="width:170px">
                <option value="0">에이전시(전체)</option>
                <?php foreach ($agency_select_options as $a) { ?>
                    <option value="<?php echo (int)$a['mb_no']; ?>" <?php echo get_selected($sel_agency_no, (int)$a['mb_no']); ?>>
                        <?php echo get_text($a['name']); ?>
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="sc_agency" id="sc_agency" value="<?php echo (int)$sel_agency_no; ?>">
        <?php } ?>

        <?php if ($show_vendor_select) { ?>
            <select name="sc_vendor" id="sc_vendor" style="width:150px">
                <option value="0">매체사(전체)</option>
                <?php foreach ($vendor_select_options as $v) { ?>
                    <option value="<?php echo (int)$v['mb_no']; ?>" <?php echo get_selected($sel_vendor_no, (int)$v['mb_no']); ?>>
                        <?php echo get_text($v['name']); ?>
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="sc_vendor" id="sc_vendor" value="<?php echo (int)$sel_vendor_no; ?>">
        <?php } ?>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if (!empty($_GET)) { ?><a href="./paid_stats.php" class="btn btn_02">초기화</a><?php } ?>
        <span class="small-muted">권한:
            <?php
            if ($is_admin9) echo '전체(관리자)';
            elseif ($is_agency) echo '에이전시';
            elseif ($is_media) echo '매체사';
            else echo '회사';
            ?>
        </span>

        <span class="row-split"></span>

        <?php if ($show_org_select) { ?>
            <?php if ($is_admin9) { ?>
                <select name="company_id" id="company_id" style="width:140px">
                    <option value="0"<?php echo $sel_company_id===0?' selected':'';?>>사용업체</option>
                    <?php foreach ($company_options as $c) { ?>
                        <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                            <?php echo get_text($c['company_name']); ?> (지점 <?php echo (int)$c['group_count']; ?>)
                        </option>
                    <?php } ?>
                </select>
            <?php } else { ?>
                <input type="hidden" name="company_id" id="company_id" value="<?php echo (int)$sel_company_id; ?>">
            <?php } ?>

            <select name="mb_group" id="mb_group" style="width:140px">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>사용지점</option>
                <?php
                if ($group_options) {
                    if ($is_admin9 && $sel_company_id == 0) {
                        $last_cid = null;
                        foreach ($group_options as $g) {
                            if ($last_cid !== (int)$g['company_id']) {
                                echo '<option value="" disabled class="opt-sep">── '.get_text($g['company_name']).' ──</option>';
                                $last_cid = (int)$g['company_id'];
                            }
                            echo '<option value="'.(int)$g['mb_group'].'" '.get_selected($sel_mb_group,(int)$g['mb_group']).'>'.get_text($g['mb_group_name']).' (상담원 '.(int)$g['member_count'].')</option>';
                        }
                    } else {
                        foreach ($group_options as $g) {
                            echo '<option value="'.(int)$g['mb_group'].'" '.get_selected($sel_mb_group,(int)$g['mb_group']).'>'.get_text($g['mb_group_name']).' (상담원 '.(int)$g['member_count'].')</option>';
                        }
                    }
                }
                ?>
            </select>

            <select name="agent" id="agent" style="width:140px">
                <option value="0">전체 상담사</option>
                <?php
                if (empty($agent_options)) {
                    echo '<option value="" disabled>상담사가 없습니다</option>';
                } else {
                    $last_cid = null; $last_gid = null;
                    foreach ($agent_options as $a) {
                        if ($last_cid !== $a['company_id']) {
                            echo '<option value="" disabled class="opt-sep">[── '.get_text($a['company_name']).' ──]</option>';
                            $last_cid = $a['company_id'];
                            $last_gid = null;
                        }
                        if ($last_gid !== $a['mb_group']) {
                            echo '<option value="" disabled class="opt-sep">── '.get_text($a['mb_group_name']).' ──</option>';
                            $last_gid = $a['mb_group'];
                        }
                        $sel = ($sel_agent_no === (int)$a['mb_no']) ? ' selected' : '';
                        echo '<option value="'.$a['mb_no'].'"'.$sel.'>'.get_text($a['mb_name']).'</option>';
                    }
                }
                ?>
            </select>
        <?php } else { ?>
            <?php // 파트너는 사용자 회사/지점/상담사 필터를 숨김(업체 노출도 방지) ?>
            <input type="hidden" name="company_id" value="0">
            <input type="hidden" name="mb_group" value="0">
            <input type="hidden" name="agent" value="0">
        <?php } ?>
    </form>
</div>

<script>
// 에이전시 변경 시: 매체사 초기화 후 자동 제출
(function(){
  var f = document.getElementById('searchForm');
  if(!f) return;
  var a = document.getElementById('sc_agency');
  var v = document.getElementById('sc_vendor');
  if(a && v && a.tagName === 'SELECT'){
    a.addEventListener('change', function(){
      if(v && v.tagName === 'SELECT') v.value = '0';
      f.submit();
    });
  }
  if(v && v.tagName === 'SELECT'){
    v.addEventListener('change', function(){ f.submit(); });
  }
})();
</script>

<!-- 상단 총괄 -->
<div class="kpi" id="kpiWrap">
    <?php if ($show_admin_cols) { ?>
        <div class="card"><div>제휴 수수료 총액</div><div class="big"><?php echo number_format($sum_agency_fee); ?></div></div>
        <div class="card"><div>매체 수수료 총액</div><div class="big"><?php echo number_format($sum_media_fee); ?></div></div>
        <div class="card"><div>사용자 매출 총액</div><div class="big"><?php echo number_format($sum_user_total); ?></div></div>
        <div class="card"><div>순익 총액</div><div class="big"><?php echo number_format($sum_admin_profit); ?></div></div>
    <?php } else if ($is_company_rep) { ?>
        <div class="card"><div>전체 사용 총액</div><div class="big"><?php echo number_format($sum_user_total); ?></div></div>
        <div class="card"><div>충전 잔액(포인트)</div><div class="big"><?php echo number_format((int)($member['mb_point'] ?? 0)); ?></div></div>
    <?php } else if($is_agency) { ?>
        <div class="card"><div>에이전시 정산액</div><div class="big"><?php echo number_format($sum_agency_fee); ?></div></div>
        <div class="card"><div>매체사 정산액</div><div class="big"><?php echo number_format($sum_media_fee); ?></div></div>
    <?php } else if($is_media) { ?>
        <div class="card"><div>매체사 정산액</div><div class="big"><?php echo number_format($sum_media_fee); ?></div></div>
    <?php } ?>
</div>

<?php if (!$hide_table1) { ?>
<!-- 테이블1 -->
<h3 style="margin-top:18px;">유료DB 통계</h3>
<div class="tbl_head01 tbl_wrap" style="margin-top:8px;">
    <table class="sortable">
        <caption>테이블1</caption>
        <thead>
        <tr>
            <?php if(!$is_media) { ?>
            <th scope="col" style="width:110px;">에이전시</th>
            <?php } ?>
            <th scope="col" style="width:110px;">매체사</th>

            <th scope="col" style="width:90px;">일반 전체</th>
            <th scope="col" style="width:90px;">실버 전체</th>

            <th scope="col" style="width:90px;">일반 사용</th>
            <th scope="col" style="width:90px;">실버 사용</th>

            <th scope="col" style="width:90px;">일반 잔여</th>
            <th scope="col" style="width:90px;">실버 잔여</th>

            <th scope="col" style="width:90px;">10초 과금수</th>
            <th scope="col" style="width:90px;">연결 과금수</th>

            <th scope="col" style="width:120px;">10초 과금총액</th>
            <th scope="col" style="width:120px;">연결 과금총액</th>

            <?php if ($show_admin_cols) { ?>
                <th scope="col" style="width:110px;background:#eef7ff;">제휴 수수료</th>
                <th scope="col" style="width:110px;background:#eef7ff;">매체 수수료</th>
                <th scope="col" style="width:110px;background:#eef7ff;">수익 총액</th>
            <?php } ?>
        </tr>
        </thead>
        <tbody>
        <?php
        if (empty($rows_t1)) {
            $colspan = $show_admin_cols ? 15 : 12;
            echo '<tr><td colspan="'.$colspan.'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            foreach ($rows_t1 as $r) {
                echo '<tr>';
                if(!$is_media) {
                    echo '<td>'.get_text($r['agency_name']).'</td>';
                }
                echo '<td>'.get_text($r['media_name']).'</td>';

                echo '<td class="td_num">'.number_format($r['normal_total']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_total']).'</td>';

                echo '<td class="td_num">'.number_format($r['normal_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_used']).'</td>';

                echo '<td class="td_num">'.number_format($r['normal_remain']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_remain']).'</td>';

                echo '<td class="td_num">'.number_format($r['cnt_10s']).'</td>';
                echo '<td class="td_num">'.number_format($r['cnt_conn']).'</td>';

                echo '<td class="td_num">'.number_format($r['sum_10s_display']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_conn_display']).'</td>';

                if ($show_admin_cols) {
                    echo '<td class="td_num" style="background:#eef7ff">'.number_format($r['agency_fee']).'</td>';
                    echo '<td class="td_num" style="background:#eef7ff">'.number_format($r['media_fee']).'</td>';
                    echo '<td class="td_num" style="background:#eef7ff">'.number_format($r['admin_profit']).'</td>';
                }
                echo '</tr>';
            }
        }
        ?>
        </tbody>
    </table>
</div>
<?php } ?>

<?php if (!$hide_table2) { ?>
<!-- 테이블2 (관리자만 표시) -->
<h3 style="margin-top:18px;">사용 업체 요약</h3>
<div class="tbl_head01 tbl_wrap" style="margin-top:8px;">
    <table class="sortable">
        <caption>테이블2</caption>
        <thead>
        <tr>
            <th scope="col" style="width:200px;">사용 업체</th>
            <th scope="col" style="width:90px;">전체 수신</th>
            <th scope="col" style="width:90px;">일반 사용량</th>
            <th scope="col" style="width:110px;">실버 사용량</th>
            <th scope="col" style="width:120px;">10초 과금 총금</th>
            <th scope="col" style="width:120px;">연결당 과금 총금</th>
            <th scope="col" style="width:120px;">전체 사용 총금</th>
            <th scope="col" style="width:110px;">충전 잔액(포인트)</th>
        </tr>
        </thead>
        <tbody>
        <?php
        if (empty($rows_t2)) {
            echo '<tr><td colspan="8" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            foreach ($rows_t2 as $r) {
                echo '<tr>';
                echo '<td class="td_left">'.get_text($r['company_name']).'</td>';
                echo '<td class="td_num">'.number_format($r['total_receive']).'</td>';
                echo '<td class="td_num">'.number_format($r['normal_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_10s_user']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_conn_user']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_user_total']).'</td>';
                echo '<td class="td_num">'.number_format($r['mb_point']).'</td>';
                echo '</tr>';
            }
        }
        ?>
        </tbody>
    </table>
</div>
<?php } ?>

<?php if ($is_company_rep) { ?>
<!-- (대표 전용) 지점/상담원 요약 -->
<h3 style="margin-top:18px;">지점/상담원 사용 통계</h3>
<div class="tbl_head01 tbl_wrap" style="margin-top:8px;">
    <table class="sortable">
        <caption>지점/상담원 사용 통계</caption>
        <thead>
        <tr>
            <th scope="col" style="width:160px;">지점명</th>
            <th scope="col" style="width:180px;">상담원</th>
            <th scope="col" style="width:90px;">전체 사용량</th>
            <th scope="col" style="width:90px;">일반 사용량</th>
            <th scope="col" style="width:110px;">실버 사용량</th>
            <th scope="col" style="width:120px;">10초과금 총액</th>
            <th scope="col" style="width:120px;">연결과금 총액</th>
            <th scope="col" style="width:120px;">전체 사용 총액</th>
        </tr>
        </thead>
        <tbody>
        <?php
        if (empty($rows_rep_summary)) {
            echo '<tr><td colspan="8" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            foreach ($rows_rep_summary as $r) {
                echo '<tr>';
                echo '<td class="td_left">'.get_text($r['group_name']).'</td>';
                echo '<td class="td_left">'.get_text($r['agent_name']).' ('.(int)$r['agent_id'].')</td>';
                echo '<td class="td_num">'.number_format($r['total_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['normal_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_10s_user']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_conn_user']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_user_total']).'</td>';
                echo '</tr>';
            }
        }
        ?>
        </tbody>
    </table>
</div>
<?php } ?>

<!-- 테이블3 -->
<h3 style="margin-top:18px;">상세 내역(최근)</h3>
<div class="tbl_head01 tbl_wrap tbl_call_list" style="margin-top:14px;">
    <table class="table-fixed">
        <thead>
            <tr>
                <?php $hide_t3_company_col = ($is_agency || $is_media); ?>
                <?php if(!$is_company_rep) { ?>
                <?php if(!$is_media) { ?>
                <th>에이전시</th>
                <?php } ?>
                <th>매체사</th>
                <?php if (!$hide_t3_company_col) { ?><th>사용 업체</th><?php } ?>
                <?php } ?>
                <?php if (!$hide_t3_branch_agent_cols) { ?>
                    <th>사용 지점</th>
                    <th>상담원</th>
                <?php } ?>
                <th>디비 유형</th>
                <th>과금 방식</th>
                <th>고객명</th>
                <th>전화번호</th>
                <th>생년월일</th>
                <th>추가 정보</th>
                <th>통화 시작</th>
                <th>통화 종료</th>
                <th>상담 시간</th>
                <th>과금금액</th>
            </tr>
        </thead>
        <tbody>
        <?php
        // colspan 계산(표 헤더와 동일하게)
        $base_cols = 10; // 디비유형~과금금액
        $extra = 0;
        if (!$is_company_rep) {
            $extra += 2; // 에이전시, 매체사
            if (!$hide_t3_company_col) $extra += 1; // 사용 업체
        }
        if (!$hide_t3_branch_agent_cols) $extra += 2; // 사용 지점, 상담원
        $t3_colspan = $base_cols + $extra;

        if ($total_count === 0) {
            echo '<tr><td colspan="'.$t3_colspan.'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                $cid = (int)$row['company_id'];

                $agency_name  = trim((string)($row['agency_name'] ?? '')) ?: '-';
                $vendor_name  = trim((string)($row['vendor_name'] ?? '')) ?: '-';
                $company_name = $company_map[$cid]['name'] ?? ('회사-'.$cid);

                $gname = get_group_name_cached((int)$row['mb_group']);
                $agent = $row['agent_name'] ? get_text($row['agent_name']) : get_text($row['agent_mb_id']);

                $bday = empty($row['birth_date']) ? '-' : get_text($row['birth_date']);
                $hp_display = get_text(format_korean_phone($row['target_hp'] ?: $row['call_hp']));
                $target_name = get_text($row['target_name'] ?: '-');
                if(!$is_admin_pay) {
                    $hp_display = mask_phone_010_style($hp_display);
                    $target_name = mb_substr($target_name, 0, 1).str_repeat('*', mb_strlen($target_name)-2).mb_substr($target_name, -1);
                }
                $meta = '-';
                if (!is_null($row['meta_json']) && $row['meta_json'] !== '') {
                    $decoded = json_decode($row['meta_json'], true);
                    if (is_array($decoded)) $meta = implode(',', $decoded);
                    else $meta = get_text($row['meta_json']);
                }
                $meta = cut_str($meta, 30);

                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);

                // 과금금액: 파트너 로그인 시 단가 기준으로 표기
                $bill_type = (int)$row['paid_db_billing_type'];
                $price_display = (int)$row['paid_price']; // 기본: 사용자 매출(로그 저장값)
                if ($is_agency || $is_media) {
                    if ($bill_type === 1) $price_display = (int)$viewer_price_10s;
                    else if ($bill_type === 2) $price_display = (int)$viewer_price_conn;
                    else $price_display = 0;
                }
                ?>
                <tr>
                    <?php if(!$is_company_rep) { ?>
                    <?php if(!$is_media) { ?>
                    <td><?php echo get_text($agency_name); ?></td>
                    <?php } ?>
                    <td><?php echo get_text($vendor_name); ?></td>
                    <?php if (!$hide_t3_company_col) { ?>
                        <td><?php echo get_text($company_name); ?></td>
                    <?php } ?>
                    <?php } ?>
                    <?php if (!$hide_t3_branch_agent_cols) { ?>
                        <td><?php echo get_text($gname); ?></td>
                        <td><?php echo get_text($agent); ?> (<?php echo (int)$row['agent_id']; ?>)</td>
                    <?php } ?>
                    <td><?php echo get_text(db_age_label($row['db_age_type'])); ?></td>
                    <td><?php echo get_text(billing_type_label($row['paid_db_billing_type'])); ?></td>
                    <td><?php echo get_text($target_name ?: '-'); ?></td>
                    <td><?php echo $hp_display; ?></td>
                    <td><?php echo $bday; ?></td>
                    <td><?php echo $meta; ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_start']), 'mdhi'); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_end']), 'mdhi'); ?></td>
                    <td><?php echo $call_sec; ?></td>
                    <td class="td_num"><?php echo number_format((int)$price_display); ?></td>
                </tr>
                <?php
            }
        }
        ?>
        </tbody>
    </table>
</div>

<!-- 페이징 -->
<?php
$total_page = max(1, (int)ceil($total_count / $page_rows));
$qstr = $_GET; unset($qstr['page']);
$base = './paid_stats.php?'.http_build_query($qstr);
?>
<div class="pg_wrap">
    <span class="pg">
        <?php if ($page > 1) { ?>
            <a href="<?php echo $base.'&page=1';?>" class="pg_page">처음</a>
            <a href="<?php echo $base.'&page='.($page-1);?>" class="pg_page">이전</a>
        <?php } else { ?>
            <span class="pg_page">처음</span><span class="pg_page">이전</span>
        <?php } ?>
        <span class="pg_current"><?php echo $page;?> / <?php echo $total_page;?></span>
        <?php if ($page < $total_page) { ?>
            <a href="<?php echo $base.'&page='.($page+1);?>" class="pg_page">다음</a>
            <a href="<?php echo $base.'&page='.$total_page;?>" class="pg_page">끝</a>
        <?php } else { ?>
            <span class="pg_page">다음</span><span class="pg_page">끝</span>
        <?php } ?>
    </span>
</div>

<script>
(function(){
    // 회사→지점 셀렉트 자동 전송
    var companySel = document.getElementById('company_id');
    var groupSel   = document.getElementById('mb_group');

    <?php if ($is_admin9) { ?>
    if (companySel && groupSel) {
        initCompanyGroupSelector(companySel, groupSel);
        companySel.addEventListener('change', function(){
            if (groupSel) groupSel.selectedIndex = 0;
            const agent = document.getElementById('agent');
            if (agent) agent.selectedIndex = 0;
            document.getElementById('searchForm').submit();
        });
    }
    <?php } ?>

    if (groupSel) {
        groupSel.addEventListener('change', function(){
            const agent = document.getElementById('agent');
            if (agent) agent.selectedIndex = 0;
            document.getElementById('searchForm').submit();
        });
    }
    var agentSel = document.getElementById('agent');
    if (agentSel) {
        agentSel.addEventListener('change', function(){
            document.getElementById('searchForm').submit();
        });
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/gh/stuartlangridge/sorttable/sorttable/sorttable.js"></script>
<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
