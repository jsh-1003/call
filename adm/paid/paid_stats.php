<?php
// /adm/paid/paid_stats.php
$sub_menu = '200750';
require_once './_common.php';

/* -----------------------------------------------------------
 * 0) 접근권한:
 *  - 관리자(9+)
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

$is_admin9      = ($is_admin === 'super' || $mb_level >= 9);
$is_agency      = ($my_type === 1); // 에이전시
$is_media       = ($my_type === 2); // 매체사
$is_company_rep = ($my_type === 0 && $mb_level === 8); // 사용자 대표

if (!$is_admin9 && !$is_agency && !$is_media && !$is_company_rep) {
    alert('접근 권한이 없습니다.');
}

/* -----------------------------------------------------------
 * 1) 단가(상단 고정 - 추후 DB로 대체)
 * --------------------------------------------------------- */
define('AGENCY_PRICE_CONN', 10);
define('AGENCY_PRICE_10S',  20);

define('MEDIA_PRICE_CONN',  20);
define('MEDIA_PRICE_10S',   40);

define('USER_PRICE_CONN',   80);
define('USER_PRICE_10S',    160);

/* -----------------------------------------------------------
 * 2) 파라미터(상단영역 유지)
 * --------------------------------------------------------- */
$default_start = date('Y-m-d').'T08:00';
$default_end   = date('Y-m-d').'T19:00';

$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

// 상단폼 유지하면서, 매체사(레벨7)도 회사기반으로 볼 수 있게 UI레벨만 8로 승격
$mb_level_ui = $mb_level;
if (!$is_admin9 && ($is_agency || $is_media)) $mb_level_ui = max($mb_level_ui, 8);

// 회사/지점/상담사 선택값(권한 스코프)
if ($is_admin9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체
} else {
    $sel_company_id = $my_company_id; // 파트너/대표는 company_id 고정
}
$sel_mb_group = ($mb_level_ui >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // UI레벨 기준
$sel_agent_no = (int)($_GET['agent'] ?? 0);

// 검색(상단폼 유지)
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
    if ($name === '') $name = trim((string)($row['mb_name'] ?? ''));
    if ($name === '') $name = '회사-'.(int)$row['mb_no'];
    return $name;
}

/* -----------------------------------------------------------
 * 3-1) 상단 "에이전시/매체사" 셀렉트(동적)
 *  - 관리자: 에이전시 + 매체사 노출
 *  - 에이전시: 매체사만(해당 에이전시의 회사(company_id) 소속만)
 *  - 사용자 대표/매체사: 노출 안함
 *  - 선택값은 결과 스코프(회사)로 반영 (현재 스키마상 partner는 company_id 단위)
 * --------------------------------------------------------- */
$sel_agency_no = 0;
$sel_vendor_no = 0;

// 관리자만 agency 선택 허용
if ($is_admin9) $sel_agency_no = (int)($_GET['sc_agency'] ?? 0);

// 관리자 + 에이전시만 vendor 선택 노출/허용
if ($is_admin9 || $is_agency) $sel_vendor_no = (int)($_GET['sc_vendor'] ?? 0);

// 선택한 에이전시/매체사 → company_id 강제(스코프 일관성)
if ($is_admin9 && $sel_agency_no > 0) {
    $ar = sql_fetch("
        SELECT mb_no, mb_name, company_id
          FROM {$member_table}
         WHERE mb_no = {$sel_agency_no}
           AND member_type = 1
           AND mb_level = 8
           AND IFNULL(mb_leave_date,'')=''
           AND IFNULL(mb_intercept_date,'')=''
         LIMIT 1
    ");
    if (!$ar) {
        $sel_agency_no = 0;
    } else {
        $agency_cid = (int)$ar['company_id'];
        if ($agency_cid > 0) $sel_company_id = $agency_cid; // 회사 스코프 강제
    }
}

if (($is_admin9 || $is_agency) && $sel_vendor_no > 0) {
    $vr = sql_fetch("
        SELECT mb_no, mb_name, company_id
          FROM {$member_table}
         WHERE mb_no = {$sel_vendor_no}
           AND member_type = 2
           AND mb_level = 7
           AND IFNULL(mb_leave_date,'')=''
           AND IFNULL(mb_intercept_date,'')=''
         LIMIT 1
    ");
    if (!$vr) {
        $sel_vendor_no = 0;
    } else {
        $vendor_cid = (int)$vr['company_id'];

        // 에이전시 로그인: 내 company_id 밖이면 무효
        if ($is_agency && $vendor_cid !== $my_company_id) {
            $sel_vendor_no = 0;
        }

        // 관리자: vendor 선택 시 해당 company_id로 스코프 강제(agency 선택과 불일치하면 vendor 무효 처리)
        if ($is_admin9) {
            if ($sel_agency_no > 0 && $vendor_cid !== $sel_company_id) {
                // 에이전시로 이미 회사가 고정된 상태에서 vendor가 다른 회사면 무효
                $sel_vendor_no = 0;
            } else {
                if ($vendor_cid > 0) $sel_company_id = $vendor_cid;
            }
        }
    }
}

// 동적 옵션 생성
$agency_select_options = [];
$vendor_select_options = [];

// 관리자용: 에이전시 옵션
if ($is_admin9) {
    $resA = sql_query("
        SELECT a.mb_no, a.mb_name, a.company_id,
               rep.company_name AS rep_company_name,
               rep.mb_name      AS rep_mb_name
          FROM {$member_table} a
          LEFT JOIN {$member_table} rep
            ON rep.mb_no = a.company_id
           AND rep.member_type=0
           AND rep.mb_level=8
           AND IFNULL(rep.mb_leave_date,'')=''
           AND IFNULL(rep.mb_intercept_date,'')=''
         WHERE a.member_type=1
           AND a.mb_level=8
           AND IFNULL(a.mb_leave_date,'')=''
           AND IFNULL(a.mb_intercept_date,'')=''
         ORDER BY a.mb_name ASC, a.mb_no ASC
    ");
    while ($r = sql_fetch_array($resA)) {
        $cid = (int)$r['company_id'];
        $cname = safe_company_name([
            'mb_no'        => $cid,
            'company_name' => $r['rep_company_name'] ?? '',
            'mb_name'      => $r['rep_mb_name'] ?? '',
        ]);
        $agency_select_options[] = [
            'mb_no' => (int)$r['mb_no'],
            'name'  => trim((string)$r['mb_name']) ?: ('에이전시-'.(int)$r['mb_no']),
            'company_id' => $cid,
            'company_name' => $cname,
        ];
    }
}

// vendor 옵션: (관리자 or 에이전시 로그인에서만)
if (($is_admin9 || $is_agency) && !$is_company_rep && !$is_media) {
    $w = [];
    $w[] = "m.member_type=2";
    $w[] = "m.mb_level=7";
    $w[] = "IFNULL(m.mb_leave_date,'')=''";
    $w[] = "IFNULL(m.mb_intercept_date,'')=''";

    // 에이전시 로그인: 내 company_id 고정
    if ($is_agency) {
        $w[] = "m.company_id=".(int)$my_company_id;
    } else {
        // 관리자: 에이전시 선택 or 회사 선택 시 해당 회사로 제한
        if ($sel_company_id > 0) $w[] = "m.company_id=".(int)$sel_company_id;
    }

    $where_vendor = 'WHERE '.implode(' AND ', $w);
    $resV = sql_query("
        SELECT m.mb_no, m.mb_name, m.company_id
          FROM {$member_table} m
          {$where_vendor}
         ORDER BY m.mb_name ASC, m.mb_no ASC
    ");
    while ($r = sql_fetch_array($resV)) {
        $vendor_select_options[] = [
            'mb_no' => (int)$r['mb_no'],
            'name'  => trim((string)$r['mb_name']) ?: ('매체사-'.(int)$r['mb_no']),
            'company_id' => (int)$r['company_id'],
        ];
    }

    // 선택한 vendor가 옵션 목록에 없으면 무효
    if ($sel_vendor_no > 0) {
        $ok = false;
        foreach ($vendor_select_options as $vo) {
            if ((int)$vo['mb_no'] === $sel_vendor_no) { $ok = true; break; }
        }
        if (!$ok) $sel_vendor_no = 0;
    }
}

/* -----------------------------------------------------------
 * 4) WHERE 구성(유료DB 과금대상만) - "기간 내 사용/매출"용
 * --------------------------------------------------------- */
$start_esc = sql_escape_string($start_date);
$end_esc   = sql_escape_string($end_date);

$need_target_join = false;
$where = [];
$where[] = "l.is_paid_db = 1";
$where[] = "l.is_paid = 1";
$where[] = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 회사 스코프: 관리자 전체/선택, 파트너/대표는 고정
if ($sel_company_id > 0) {
    $where[] = "ag.company_id = {$sel_company_id}";
}

// 지점/상담사 필터 (기간 통계/상세에는 반영)
if ($sel_mb_group > 0) $where[] = "l.mb_group = {$sel_mb_group}";
if ($sel_agent_no > 0) $where[] = "l.mb_no = {$sel_agent_no}";

// 검색 (기간 상세/통계에는 반영)
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
 * 5) 조직 드롭다운 옵션(상단 유지용)
 *    - 기존 함수가 global $member['mb_level']을 보므로, 파트너는 호출 시점에만 UI레벨로 임시 승격
 * --------------------------------------------------------- */
$__orig_level = $member['mb_level'] ?? null;
if (!$is_admin9 && ($is_agency || $is_media)) {
    $member['mb_level'] = 8; // 옵션빌드용
}
$build_org_select_options = build_org_select_options_paid_db($sel_company_id, $sel_mb_group);
if (!$is_admin9 && ($is_agency || $is_media)) {
    $member['mb_level'] = $__orig_level;
}
$company_options = $build_org_select_options['company_options'];
$group_options   = $build_org_select_options['group_options'];
$agent_options   = $build_org_select_options['agent_options'];

/* -----------------------------------------------------------
 * 6) 회사 목록(표 출력용) + 회사명/포인트/파트너명 맵
 * --------------------------------------------------------- */
$company_ids = [];
if ($sel_company_id > 0) {
    $company_ids = [$sel_company_id];
} else {
    foreach ($company_options as $c) $company_ids[] = (int)$c['company_id'];
}
$company_ids = array_values(array_unique(array_filter($company_ids)));

$company_map = [];   // [cid => ['name'=>, 'point'=>]]
$partner_map = [];   // [cid => ['agency'=>, 'media'=>]]

if (!empty($company_ids)) {
    $in = implode(',', array_map('intval', $company_ids));

    // 대표회원 포인트 + 회사명
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

    // 에이전시/매체사 이름
    $q_partner = sql_query("
        SELECT company_id,
               MAX(CASE WHEN member_type=1 AND mb_level=8 THEN mb_name END) AS agency_name,
               MAX(CASE WHEN member_type=2 AND mb_level=7 THEN mb_name END) AS media_name
          FROM {$member_table}
         WHERE company_id IN ({$in})
           AND IFNULL(mb_leave_date,'')=''
           AND IFNULL(mb_intercept_date,'')=''
         GROUP BY company_id
    ");
    while ($r = sql_fetch_array($q_partner)) {
        $cid = (int)$r['company_id'];
        $partner_map[$cid] = [
            'agency' => trim((string)($r['agency_name'] ?? '')) ?: '-',
            'media'  => trim((string)($r['media_name']  ?? '')) ?: '-',
        ];
    }
    foreach ($company_ids as $cid) {
        if (!isset($partner_map[$cid])) $partner_map[$cid] = ['agency'=>'-', 'media'=>'-'];
        if (!isset($company_map[$cid])) $company_map[$cid] = ['name'=>'회사-'.$cid, 'point'=>0];
    }
}

/* -----------------------------------------------------------
 * 7) 테이블1/2 집계
 *   - 기간 통계(사용량/과금액): call_log (기간/필터 반영)
 *   - 보유량(전체수량): call_target (일자 무관)
 *   - 잔여(전체 기준): 보유량 - "누적 사용량(일자 무관)"
 * --------------------------------------------------------- */
$usage_period = []; // [cid => ...] 기간 내 사용/매출(사용자 매출=paid_price 합)
$usage_all    = []; // [cid => ...] 누적 사용(잔여 계산용)
$stock        = []; // [cid => ...] 보유량(일자 무관)

$join_t = $need_target_join ? "JOIN call_target t ON t.target_id = l.target_id" : "";

// 7-1) 기간 내 사용량/과금 집계
$sql_usage = "
    SELECT
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
    {$join_t}
    {$where_sql}
    GROUP BY ag.company_id
";
$q_usage = sql_query($sql_usage);
while ($r = sql_fetch_array($q_usage)) {
    $cid = (int)$r['company_id'];
    $usage_period[$cid] = [
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

// 7-1-b) 누적 사용량(잔여 계산용) - 일자 무관(회사/지점 스코프만 반영)
if (!empty($company_ids)) {
    $in = implode(',', array_map('intval', $company_ids));
    $where_remain = [];
    $where_remain[] = "l.is_paid_db = 1";
    $where_remain[] = "l.is_paid = 1";
    $where_remain[] = "ag.company_id IN ({$in})";
    if ($sel_mb_group > 0) $where_remain[] = "l.mb_group = {$sel_mb_group}";
    $where_remain_sql = 'WHERE '.implode(' AND ', $where_remain);

    $sql_usage_all = "
        SELECT
            ag.company_id AS company_id,
            SUM(CASE WHEN l.db_age_type=1 THEN 1 ELSE 0 END) AS normal_used_all,
            SUM(CASE WHEN l.db_age_type=2 THEN 1 ELSE 0 END) AS silver_used_all
        FROM call_log l
        JOIN {$member_table} ag
          ON ag.mb_no = l.mb_no
         AND ag.member_type=0
         AND ag.mb_level=3
         AND IFNULL(ag.mb_leave_date,'')=''
         AND IFNULL(ag.mb_intercept_date,'')=''
        {$where_remain_sql}
        GROUP BY ag.company_id
    ";
    $q_all = sql_query($sql_usage_all);
    while ($r = sql_fetch_array($q_all)) {
        $cid = (int)$r['company_id'];
        $usage_all[$cid] = [
            'normal_used_all' => (int)$r['normal_used_all'],
            'silver_used_all' => (int)$r['silver_used_all'],
        ];
    }
}

// 7-2) 전체수량(보유) 집계 - 일자 무관
if (!empty($company_ids)) {
    $in = implode(',', array_map('intval', $company_ids));
    $w_group_target = ($sel_mb_group > 0) ? " AND mb_group = {$sel_mb_group} " : "";

    $sql_stock = "
        SELECT
            company_id,
            SUM(CASE WHEN db_age_type=1 THEN 1 ELSE 0 END) AS normal_total,
            SUM(CASE WHEN db_age_type=2 THEN 1 ELSE 0 END) AS silver_total
        FROM call_target
        WHERE is_paid_db=1
          AND company_id IN ({$in})
          AND company_id > 0
          {$w_group_target}
        GROUP BY company_id
    ";
    $q_stock = sql_query($sql_stock);
    while ($r = sql_fetch_array($q_stock)) {
        $cid = (int)$r['company_id'];
        $stock[$cid] = [
            'normal_total' => (int)$r['normal_total'],
            'silver_total' => (int)$r['silver_total'],
        ];
    }
}

/* -----------------------------------------------------------
 * 7-3) 테이블1/2 행 구성 + KPI 합계
 *  - 관리자/대표: 사용자 매출(=paid_price 합) 기준 노출
 *  - 에이전시/매체사: "과금총액/상세 과금금액"은 각 단가 기준으로 노출(요청사항)
 * --------------------------------------------------------- */
$rows_t1 = [];
$rows_t2 = [];

$sum_cnt_10s = 0;
$sum_cnt_conn = 0;
$sum_user_total = 0;
$sum_agency_fee = 0;
$sum_media_fee  = 0;

$viewer_price_conn = USER_PRICE_CONN;
$viewer_price_10s  = USER_PRICE_10S;
if ($is_agency) { $viewer_price_conn = AGENCY_PRICE_CONN; $viewer_price_10s = AGENCY_PRICE_10S; }
if ($is_media)  { $viewer_price_conn = MEDIA_PRICE_CONN;  $viewer_price_10s = MEDIA_PRICE_10S;  }

foreach ($company_ids as $cid) {
    $st = $stock[$cid] ?? ['normal_total'=>0, 'silver_total'=>0];
    $us = $usage_period[$cid] ?? [
        'total_used'=>0,'normal_used'=>0,'silver_used'=>0,
        'cnt_10s'=>0,'cnt_conn'=>0,
        'sum_10s_user'=>0,'sum_conn_user'=>0,'sum_user_total'=>0
    ];
    $ua = $usage_all[$cid] ?? ['normal_used_all'=>0, 'silver_used_all'=>0];

    // 잔여는 "일자 무관" 누적 기준
    $normal_remain = max(0, (int)$st['normal_total'] - (int)$ua['normal_used_all']);
    $silver_remain = max(0, (int)$st['silver_total'] - (int)$ua['silver_used_all']);

    // 파트너 정산(단가 기준)
    $agency_fee = ((int)$us['cnt_conn'] * AGENCY_PRICE_CONN) + ((int)$us['cnt_10s'] * AGENCY_PRICE_10S);
    $media_fee  = ((int)$us['cnt_conn'] * MEDIA_PRICE_CONN)  + ((int)$us['cnt_10s'] * MEDIA_PRICE_10S);
    $admin_profit = (int)$us['sum_user_total'] - ($agency_fee + $media_fee);

    // 화면 노출용 "과금총액" (에이전시/매체사 로그인 시 단가 기준으로 보여주기)
    $sum_10s_display  = (int)$us['sum_10s_user'];
    $sum_conn_display = (int)$us['sum_conn_user'];
    if ($is_agency || $is_media) {
        $sum_10s_display  = (int)$us['cnt_10s']  * (int)$viewer_price_10s;
        $sum_conn_display = (int)$us['cnt_conn'] * (int)$viewer_price_conn;
    }

    $rows_t1[] = [
        'company_id'    => $cid,
        'agency_name'   => $partner_map[$cid]['agency'] ?? '-',
        'media_name'    => $partner_map[$cid]['media'] ?? '-',

        'normal_total'  => (int)$st['normal_total'],
        'silver_total'  => (int)$st['silver_total'],

        // 기간 내 사용
        'normal_used'   => (int)$us['normal_used'],
        'silver_used'   => (int)$us['silver_used'],

        // 잔여(누적 기준)
        'normal_remain' => $normal_remain,
        'silver_remain' => $silver_remain,

        'cnt_10s'       => (int)$us['cnt_10s'],
        'cnt_conn'      => (int)$us['cnt_conn'],

        // 표에 표시할 과금총액(역할별)
        'sum_10s_display'  => (int)$sum_10s_display,
        'sum_conn_display' => (int)$sum_conn_display,

        // 사용자 매출(관리자/대표 KPI 등 계산에는 그대로 유지)
        'sum_user_total' => (int)$us['sum_user_total'],

        // 관리자 전용 컬럼 계산용
        'agency_fee'    => (int)$agency_fee,
        'media_fee'     => (int)$media_fee,
        'admin_profit'  => (int)$admin_profit,
    ];

    $rows_t2[] = [
        'company_id'     => $cid,
        'company_name'   => $company_map[$cid]['name'] ?? ('회사-'.$cid),
        'total_receive'  => (int)$us['total_used'],
        'normal_used'    => (int)$us['normal_used'],
        'silver_used'    => (int)$us['silver_used'],
        'sum_10s_user'   => (int)$us['sum_10s_user'],
        'sum_conn_user'  => (int)$us['sum_conn_user'],
        'sum_user_total' => (int)$us['sum_user_total'],
        'mb_point'       => (int)($company_map[$cid]['point'] ?? 0),
    ];

    $sum_cnt_10s     += (int)$us['cnt_10s'];
    $sum_cnt_conn    += (int)$us['cnt_conn'];
    $sum_user_total  += (int)$us['sum_user_total'];
    $sum_agency_fee  += (int)$agency_fee;
    $sum_media_fee   += (int)$media_fee;
}
$sum_admin_profit = $sum_user_total - ($sum_agency_fee + $sum_media_fee);

/* -----------------------------------------------------------
 * 7-4) (대표 전용) 지점/상담원 요약 테이블 (기간 내) - 테이블3 위에 출력
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
         AND ag.mb_level=3
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
 * 8) 테이블3(상세) - 최적화: call_id 먼저 LIMIT 후 조인
 * --------------------------------------------------------- */
$sub_joins = [];
$sub_joins[] = "JOIN {$member_table} ag ON ag.mb_no=l.mb_no AND ag.member_type=0 AND ag.mb_level=3 AND IFNULL(ag.mb_leave_date,'')='' AND IFNULL(ag.mb_intercept_date,'')=''";
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

        t.name       AS target_name,
        t.call_hp    AS target_hp,
        t.birth_date,
        t.meta_json,

        l.call_start,
        l.call_end,
        l.call_time
    FROM ({$sub}) pick
    JOIN call_log l ON l.call_id = pick.call_id
    JOIN {$member_table} ag
      ON ag.mb_no=l.mb_no
     AND ag.member_type=0
     AND ag.mb_level=3
     AND IFNULL(ag.mb_leave_date,'')=''
     AND IFNULL(ag.mb_intercept_date,'')=''
    JOIN call_target t ON t.target_id = l.target_id
    ORDER BY l.call_start DESC, l.call_id DESC
");

/* -----------------------------------------------------------
 * 9) 화면 출력
 * --------------------------------------------------------- */
$token = get_token();
$g5['title'] = '사용통계';
include_once(G5_ADMIN_PATH.'/admin.head.php');

$hide_table1 = $is_company_rep; // 대표는 테이블1 숨김
$hide_table2 = $is_company_rep || $is_agency || $is_media; // 에이전시/매체사/대표는 테이블2 숨김
$hide_t3_branch_agent_cols = ($is_agency || $is_media); // 에이전시/매체사는 테이블3 지점/상담원 숨김

// partner 셀렉트 노출 조건
$show_agency_select = $is_admin9; // 관리자만
$show_vendor_select = (($is_admin9 || $is_agency) && !$is_company_rep && !$is_media); // 관리자/에이전시만
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

        <?php if ($show_agency_select) { ?>
            <select name="sc_agency" id="sc_agency" style="width:170px">
                <option value="0">에이전시(전체)</option>
                <?php foreach ($agency_select_options as $a) { ?>
                    <option value="<?php echo (int)$a['mb_no']; ?>" <?php echo get_selected($sel_agency_no, (int)$a['mb_no']); ?>>
                        <?php echo get_text($a['name']); ?>
                    </option>
                <?php } ?>
            </select>
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
        <?php } ?>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if (!empty($_GET)) { ?><a href="./paid_stats.php" class="btn btn_02">초기화</a><?php } ?>
        <span class="small-muted">권한:
            <?php echo $is_admin9 ? '전체' : '회사'; ?>
        </span>

        <span class="row-split"></span>

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

        <?php if ($mb_level_ui >= 8) { ?>
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
        <?php } else { ?>
            <input type="hidden" name="mb_group" value="<?php echo $sel_mb_group; ?>">
            <span class="small-muted">지점: <?php echo get_text(get_group_name_cached($sel_mb_group)); ?></span>
        <?php } ?>

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
    </form>
</div>

<!-- 상단 총괄 -->
<div class="kpi" id="kpiWrap">
    <?php if ($show_admin_cols) { ?>
        <div class="card"><div>제휴 수수료 총액</div><div class="big"><?php echo number_format($sum_agency_fee); ?></div></div>
        <div class="card"><div>매체 수수료 총액</div><div class="big"><?php echo number_format($sum_media_fee); ?></div></div>
        <div class="card"><div>사용자 매출 총액</div><div class="big"><?php echo number_format($sum_user_total); ?></div></div>
        <div class="card"><div>순익 총액</div><div class="big"><?php echo number_format($sum_admin_profit); ?></div></div>
    <?php } else { ?>
        <?php if ($is_company_rep) { ?>
            <div class="card"><div>전체 사용 총액</div><div class="big"><?php echo number_format($sum_user_total); ?></div></div>
            <div class="card"><div>충전 잔액(포인트)</div><div class="big"><?php echo number_format((int)($member['mb_point'] ?? 0)); ?></div></div>
        <?php } else { ?>
            <?php
            $my_fee = 0;
            $my_fee_label = '정산액';
            if ($is_agency) { $my_fee = $sum_agency_fee; $my_fee_label = '에이전시 정산액'; }
            if ($is_media)  { $my_fee = $sum_media_fee;  $my_fee_label = '매체사 정산액'; }
            ?>
            <div class="card"><div><?php echo get_text($my_fee_label); ?></div><div class="big"><?php echo number_format($my_fee); ?></div></div>
            <div class="card"><div>사용자 매출 총액</div><div class="big"><?php echo number_format($sum_user_total); ?></div></div>
        <?php } ?>
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
            <th scope="col" style="width:110px;">에이전시</th>
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
                <th scope="col" style="width:110px;background:#eef7ff;">판매 수수료</th>
                <th scope="col" style="width:110px;background:#eef7ff;">수익 총액</th>
            <?php } ?>
        </tr>
        </thead>
        <tbody>
        <?php
        if (empty($rows_t1)) {
            $colspan = $show_admin_cols ? 16 : 12;
            echo '<tr><td colspan="'.$colspan.'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            foreach ($rows_t1 as $r) {
                echo '<tr>';
                echo '<td>'.get_text($r['agency_name']).'</td>';
                echo '<td>'.get_text($r['media_name']).'</td>';

                echo '<td class="td_num">'.number_format($r['normal_total']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_total']).'</td>';

                echo '<td class="td_num">'.number_format($r['normal_used']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_used']).'</td>';

                echo '<td class="td_num">'.number_format($r['normal_remain']).'</td>';
                echo '<td class="td_num">'.number_format($r['silver_remain']).'</td>';

                echo '<td class="td_num">'.number_format($r['cnt_10s']).'</td>';
                echo '<td class="td_num">'.number_format($r['cnt_conn']).'</td>';

                // 관리자/대표: 사용자 매출(=call_log.paid_price 합)
                // 에이전시/매체사: 각 단가 기준 과금총액(요청사항)
                echo '<td class="td_num">'.number_format($r['sum_10s_display']).'</td>';
                echo '<td class="td_num">'.number_format($r['sum_conn_display']).'</td>';

                if ($show_admin_cols) {
                    echo '<td class="td_num" style="background:#eef7ff">'.number_format($r['agency_fee']).'</td>';
                    echo '<td class="td_num" style="background:#eef7ff">'.number_format($r['media_fee']).'</td>';
                    echo '<td class="td_num" style="background:#eef7ff">'.number_format($r['admin_profit']).'</td>';
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
            <th scope="col" style="width:110px;">실버 단독 사용량</th>
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
<!-- (대표 전용) 지점/상담원 요약 테이블: 상세 위에 추가 -->
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
            <th scope="col" style="width:110px;">실버 단독 사용량</th>
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
                <th>에이전시</th>
                <th>매체사</th>
                <th>사용 업체</th>
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
        $t3_colspan = $hide_t3_branch_agent_cols ? 13 : 15;

        if ($total_count === 0) {
            echo '<tr><td colspan="'.$t3_colspan.'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                $cid = (int)$row['company_id'];

                $agency_name  = $partner_map[$cid]['agency'] ?? '-';
                $media_name   = $partner_map[$cid]['media']  ?? '-';
                $company_name = $company_map[$cid]['name'] ?? ('회사-'.$cid);

                $gname = get_group_name_cached((int)$row['mb_group']);
                $agent = $row['agent_name'] ? get_text($row['agent_name']) : get_text($row['agent_mb_id']);

                $bday = empty($row['birth_date']) ? '-' : get_text($row['birth_date']);
                $hp_display = get_text(format_korean_phone($row['target_hp'] ?: $row['call_hp']));

                $meta = '-';
                if (!is_null($row['meta_json']) && $row['meta_json'] !== '') {
                    $decoded = json_decode($row['meta_json'], true);
                    if (is_array($decoded)) $meta = implode(',', $decoded);
                    else $meta = get_text($row['meta_json']);
                }
                $meta = cut_str($meta, 30);

                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);

                // 과금금액(요청사항): 에이전시/매체사 로그인 시 단가 기준으로 표기
                $bill_type = (int)$row['paid_db_billing_type'];
                $price_display = (int)$row['paid_price']; // 기본: 사용자 매출(로그 저장값)
                if ($is_agency || $is_media) {
                    if ($bill_type === 1) $price_display = (int)$viewer_price_10s;
                    else if ($bill_type === 2) $price_display = (int)$viewer_price_conn;
                    else $price_display = 0;
                }
                ?>
                <tr>
                    <td><?php echo get_text($agency_name); ?></td>
                    <td><?php echo get_text($media_name); ?></td>
                    <td><?php echo get_text($company_name); ?></td>
                    <?php if (!$hide_t3_branch_agent_cols) { ?>
                        <td><?php echo get_text($gname); ?></td>
                        <td><?php echo get_text($agent); ?> (<?php echo (int)$row['agent_id']; ?>)</td>
                    <?php } ?>
                    <td><?php echo get_text(db_age_label($row['db_age_type'])); ?></td>
                    <td><?php echo get_text(billing_type_label($row['paid_db_billing_type'])); ?></td>
                    <td><?php echo get_text($row['target_name'] ?: '-'); ?></td>
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
            // partner 셀렉트도 초기화(회사 바뀌면 의미가 달라지므로)
            var aSel = document.getElementById('sc_agency');
            var vSel = document.getElementById('sc_vendor');
            if (aSel) aSel.selectedIndex = 0;
            if (vSel) vSel.selectedIndex = 0;
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

    // partner 셀렉트 동작(서버에서 options를 동적으로 다시 그리도록 submit)
    var aSel2 = document.getElementById('sc_agency');
    var vSel2 = document.getElementById('sc_vendor');

    if (aSel2) {
        aSel2.addEventListener('change', function(){
            if (vSel2) vSel2.selectedIndex = 0; // 에이전시 변경 시 매체사 리셋
            // 회사/지점/상담사도 리셋(스코프 변경)
            if (companySel) companySel.selectedIndex = 0; // 서버에서 company_id 강제할 수 있음
            if (groupSel) groupSel.selectedIndex = 0;
            if (agentSel) agentSel.selectedIndex = 0;
            document.getElementById('searchForm').submit();
        });
    }
    if (vSel2) {
        vSel2.addEventListener('change', function(){
            // 회사/지점/상담사 리셋(스코프 변경 가능)
            if (groupSel) groupSel.selectedIndex = 0;
            if (agentSel) agentSel.selectedIndex = 0;
            document.getElementById('searchForm').submit();
        });
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/gh/stuartlangridge/sorttable/sorttable/sorttable.js"></script>
<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
