<?php
// /adm/call/billing_company_list.php
$sub_menu = '700950';
require_once './_common.php';

auth_check_menu($auth, $sub_menu, 'r');

// -------------------------------------------
// 접근 권한: 레벨 10 이상만
// -------------------------------------------
if ((int)$member['mb_level'] < 10) {
    alert('접근 권한이 없습니다.');
}

$g5['title'] = '회사별 월 정산(요금제)';

// -------------------------------------------
// 공통 유틸
// -------------------------------------------
function ym_now(){ return (new DateTimeImmutable('first day of this month'))->format('Y-m'); }
function ym_prev($ym, $n=1){
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym.'-01');
    if (!$dt) $dt = new DateTimeImmutable('first day of this month');
    return $dt->modify("-{$n} month")->format('Y-m');
}
function ym_next($ym, $n=1){
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym.'-01');
    if (!$dt) $dt = new DateTimeImmutable('first day of this month');
    return $dt->modify("+{$n} month")->format('Y-m');
}
function get_csrf_token_key() { return 'billing_company_csrf'; }
if (!isset($_SESSION[get_csrf_token_key()])) {
    $_SESSION[get_csrf_token_key()] = bin2hex(random_bytes(16));
}
// 현재 쿼리스트링을 유지하며 특정 파라미터만 덮어쓰기
function url_with(array $overrides = []) {
    $base = [
        'month'      => $GLOBALS['month'] ?? '',
        'company_id' => $GLOBALS['company_id'] ?? 0,
        'cname'      => $GLOBALS['cname'] ?? '',
        'pay'        => $GLOBALS['pay'] ?? '',
    ];
    $q = array_merge($base, $overrides);
    return $_SERVER['SCRIPT_NAME'] . '?' . http_build_query($q);
}

$csrf_token = $_SESSION[get_csrf_token_key()];

// -------------------------------------------
/* 파라미터 */
// -------------------------------------------
$month  = isset($_GET['month']) && preg_match('/^\d{4}\-\d{2}$/', $_GET['month'])
        ? $_GET['month'] : ym_now();
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$cname = isset($_GET['cname']) ? trim((string)$_GET['cname']) : '';
$pay = isset($_GET['pay']) ? trim($_GET['pay']) : ''; // '', 'paid', 'unpaid'
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

// -------------------------------------------
// 결제 완료 처리(수동) - 상태를 'paid'로 변경 + 수납로그 기록
// -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='mark_paid') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        alert('잘못된 요청(CSRF).');
    }
    $cid   = (int)($_POST['company_id'] ?? 0);
    $ym    = preg_match('/^\d{4}\-\d{2}$/', ($_POST['month'] ?? '')) ? $_POST['month'] : '';
    $amount= (int)($_POST['amount'] ?? 0);
    if ($cid <= 0 || $ym==='') alert('잘못된 요청 파라미터입니다.');

    // 활성 요금제
    $plan = sql_fetch("SELECT plan_id, monthly_fee FROM billing_plan WHERE active=1 ORDER BY plan_id LIMIT 1");
    if (!$plan) {
        alert('활성 요금제가 없습니다. 먼저 billing_plan을 설정하세요.');
    }

    // 스냅샷 존재 보장
    $row = sql_fetch("SELECT bill_id, total_fee, payment_status FROM billing_company_month WHERE company_id={$cid} AND month='".sql_escape_string($ym)."'");
    if (!$row) {
        // 즉석: 과금대상 상담원 수(레벨3, 삭제 제외)
        $agent = sql_fetch("
            SELECT COUNT(*) AS c
            FROM g5_member
            WHERE company_id={$cid}
              AND mb_level=3
              AND (mb_leave_date IS NULL OR mb_leave_date='')
        ");
        $agent_count = (int)$agent['c'];
        $base_fee    = $agent_count * (int)$plan['monthly_fee'];
        $prorate_fee = 0;
        $total       = $base_fee + $prorate_fee;

        sql_query("
            INSERT INTO billing_company_month
              (company_id, month, plan_id, agent_count, base_fee, prorate_fee, additional_fee, total_fee, payment_status, memo, created_at, updated_at)
            VALUES
              ({$cid}, '".sql_escape_string($ym)."', ".(int)$plan['plan_id'].", {$agent_count}, {$base_fee}, {$prorate_fee}, 0, {$total}, 'unpaid', NULL, NOW(), NOW())
        ");
        $row = sql_fetch("SELECT bill_id, total_fee, payment_status FROM billing_company_month WHERE company_id={$cid} AND month='".sql_escape_string($ym)."'");
    }

    if ($row && $row['payment_status'] !== 'paid') {
        // 현 미수금 기준 기본 수납액 설정
        $paid_sum_row = sql_fetch("
            SELECT COALESCE(SUM(amount),0) AS paid_sum
            FROM billing_payment_log
            WHERE company_id={$cid}
              AND month='".sql_escape_string($ym)."'
        ");
        $paid_sum = (int)($paid_sum_row['paid_sum'] ?? 0);
        $outstanding = max(0, (int)$row['total_fee'] - $paid_sum);
        $amt = $amount > 0 ? $amount : ($outstanding > 0 ? $outstanding : (int)$row['total_fee']);

        sql_query("
            UPDATE billing_company_month
               SET payment_status='paid', paid_at=NOW(), updated_at=NOW()
             WHERE bill_id=".(int)$row['bill_id']."
        ");
        sql_query("
            INSERT INTO billing_payment_log (company_id, month, amount, method, processed_by, processed_at, note)
            VALUES ({$cid}, '".sql_escape_string($ym)."', {$amt}, 'manual', ".(int)$member['mb_no'].", NOW(), '관리자 결제완료 처리')
        ");
        // alert('결제완료 처리했습니다.', './billing_company_list.php?month='.urlencode($ym).'&company_id='.$cid.'&pay='.urlencode($pay).'&cname='.urlencode($cname));
        alert('결제완료 처리했습니다.', './billing_company_list.php?month='.urlencode($ym));
    } else {
        alert('이미 결제완료 상태이거나 대상이 없습니다.');
    }
    exit;
}

// -------------------------------------------
// 추가요금 결제 처리(상태 변경 없음) - 수납로그만 기록
// -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='pay_additional') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        alert('잘못된 요청(CSRF).');
    }
    $cid   = (int)($_POST['company_id'] ?? 0);
    $ym    = preg_match('/^\d{4}\-\d{2}$/', ($_POST['month'] ?? '')) ? $_POST['month'] : '';
    $amount= (int)($_POST['amount'] ?? 0);
    if ($cid <= 0 || $ym==='') alert('잘못된 요청 파라미터입니다.');
    if ($amount <= 0) alert('추가요금 금액을 입력하세요.');

    // 현재 총요금/수납합계/미수금 계산
    $snap = sql_fetch("
        SELECT total_fee, payment_status
        FROM billing_company_month
        WHERE company_id={$cid}
          AND month='".sql_escape_string($ym)."'
        LIMIT 1
    ");
    if (!$snap) alert('해당 월 스냅샷이 존재하지 않습니다.');

    $paid_sum_row = sql_fetch("
        SELECT COALESCE(SUM(amount),0) AS paid_sum
        FROM billing_payment_log
        WHERE company_id={$cid}
          AND month='".sql_escape_string($ym)."'
    ");
    $paid_sum = (int)($paid_sum_row['paid_sum'] ?? 0);
    $outstanding = max(0, (int)$snap['total_fee'] - $paid_sum);

    if ($outstanding <= 0) {
        alert('추가요금(미수금)이 없습니다.');
    }
    if ($amount > $outstanding) {
        $amount = $outstanding;
    }

    // 수납 로그만 추가 (상태 변경 없음)
    sql_query("
        INSERT INTO billing_payment_log (company_id, month, amount, method, processed_by, processed_at, note)
        VALUES ({$cid}, '".sql_escape_string($ym)."', {$amount}, 'manual', ".(int)$member['mb_no'].", NOW(), '추가요금 수납')
    ");

    // alert('추가요금 수납이 기록되었습니다.', './billing_company_list.php?month='.urlencode($ym).'&company_id='.$cid.'&pay='.urlencode($pay).'&cname='.urlencode($cname));
    alert('추가요금 수납이 기록되었습니다.', './billing_company_list.php?month='.urlencode($ym));
    exit;
}

// -------------------------------------------
// 결제 취소(환불) 처리 - 입력 금액만큼 음수 수납 기록 + 상태 재계산
// -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='refund_payment') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        alert('잘못된 요청(CSRF).');
    }
    $cid    = (int)($_POST['company_id'] ?? 0);
    $ym     = preg_match('/^\d{4}\-\d{2}$/', ($_POST['month'] ?? '')) ? $_POST['month'] : '';
    $amount = (int)($_POST['amount'] ?? 0);
    if ($cid <= 0 || $ym==='') alert('잘못된 요청 파라미터입니다.');
    if ($amount <= 0) alert('결제취소(환불) 금액을 입력하세요.');

    // 스냅샷/수납 합계
    $snap = sql_fetch("
        SELECT bill_id, total_fee, payment_status
        FROM billing_company_month
        WHERE company_id={$cid}
          AND month='".sql_escape_string($ym)."'
        LIMIT 1
    ");
    if (!$snap) alert('해당 월 스냅샷이 존재하지 않습니다.');

    $paid_row = sql_fetch("
        SELECT COALESCE(SUM(amount),0) AS paid_sum
        FROM billing_payment_log
        WHERE company_id={$cid}
          AND month='".sql_escape_string($ym)."'
    ");
    $paid_sum_before = (int)($paid_row['paid_sum'] ?? 0);
    if ($paid_sum_before <= 0) {
        alert('환불 처리할 수납 기록이 없습니다.');
    }

    // 환불액은 현재 수납합계 한도 내에서만
    if ($amount > $paid_sum_before) $amount = $paid_sum_before;

    // 음수 금액으로 환불 로그 기록
    $neg = -1 * $amount;
    sql_query("
        INSERT INTO billing_payment_log (company_id, month, amount, method, processed_by, processed_at, note)
        VALUES ({$cid}, '".sql_escape_string($ym)."', {$neg}, 'refund', ".(int)$member['mb_no'].", NOW(), '결제취소(환불)')
    ");

    // 상태 재계산: 환불 후 수납합계가 총요금 미만이면 unpaid로 되돌림
    $paid_row2 = sql_fetch("
        SELECT COALESCE(SUM(amount),0) AS paid_sum
        FROM billing_payment_log
        WHERE company_id={$cid}
          AND month='".sql_escape_string($ym)."'
    ");
    $paid_sum_after = (int)($paid_row2['paid_sum'] ?? 0);

    if ($paid_sum_after < (int)$snap['total_fee']) {
        sql_query("
            UPDATE billing_company_month
               SET payment_status='unpaid', paid_at=NULL, updated_at=NOW()
             WHERE bill_id=".(int)$snap['bill_id']."
        ");
    } else {
        // 총요금 이상이면 paid 유지(부분환불로도 여전히 전액 납부 상태일 수 있음)
        // 필요시 별도 처리 없음
    }

    // alert('결제취소(환불) 처리되었습니다.', './billing_company_list.php?month='.urlencode($ym).'&company_id='.$cid.'&pay='.urlencode($pay).'&cname='.urlencode($cname));
    alert('결제취소(환불) 처리되었습니다.', './billing_company_list.php?month='.urlencode($ym));
    exit;
}

// -------------------------------------------
// 회사 목록(셀렉트/검색용 원본 후보군): 레벨3 보유 회사
// -------------------------------------------
$all_company_rs = sql_query("
    SELECT m.company_id
    FROM g5_member m
    WHERE m.mb_level=3
      AND (m.mb_leave_date IS NULL OR m.mb_leave_date='')
    GROUP BY m.company_id
    ORDER BY m.company_id ASC
");
$all_company_ids = [];
$company_options = []; // [cid => name]
while($c = sql_fetch_array($all_company_rs)) {
    $cid = (int)$c['company_id'];
    $all_company_ids[] = $cid;
    $company_options[$cid] = get_company_name_cached($cid);
}

// 회사명(cname) 필터 → 매칭되는 company_id만 남김
$filtered_company_ids = $all_company_ids;
if ($cname !== '') {
    $match = [];
    foreach ($company_options as $cid => $label) {
        if (mb_stripos($label, $cname) !== false) $match[] = $cid;
    }
    $filtered_company_ids = $match;
}

// company_id(셀렉트) 필터가 있으면 최종 집합을 그 회사로 제한
if ($company_id > 0) {
    $filtered_company_ids = array_values(array_intersect($filtered_company_ids, [$company_id]));
}

// 최종 회사 개수(통계/페이징의 기준이 됨)
$total_count = count($filtered_company_ids);

// -------------------------------------------
// 페이징
// -------------------------------------------
$rows = $config['cf_page_rows'];
if (!$rows) $rows = 20;
$total_page  = $rows ? (int)ceil(($total_count ?: 1) / $rows) : 1;
if ($page > $total_page) $page = $total_page;
$from_record = ($page - 1) * $rows;
$paged_company_ids = array_slice($filtered_company_ids, $from_record, $rows);

// -------------------------------------------
// 집계(팀 수/상담원 수/스냅샷/수납합계) - 페이징된 회사들
// -------------------------------------------
$list = [];
if ($paged_company_ids) {
    $id_in = implode(',', array_map('intval', $paged_company_ids));

    // 팀 수: 레벨7 수(탈퇴/차단 제외)
    $team_map = [];
    $rs_team = sql_query("
        SELECT company_id, COUNT(*) AS team_count
        FROM g5_member
        WHERE mb_level=7
          AND (mb_leave_date IS NULL OR mb_leave_date='')
          AND (mb_intercept_date IS NULL OR mb_intercept_date='')
          AND company_id IN ({$id_in})
        GROUP BY company_id
    ");
    while($r = sql_fetch_array($rs_team)){
        $team_map[(int)$r['company_id']] = (int)$r['team_count'];
    }

    // 상담원 수: 레벨3 수(탈퇴 제외, 차단 포함)
    $agent_map = [];
    $rs_agent = sql_query("
        SELECT company_id, COUNT(*) AS agent_count
        FROM g5_member
        WHERE mb_level=3
          AND (mb_leave_date IS NULL OR mb_leave_date='')
          AND company_id IN ({$id_in})
        GROUP BY company_id
    ");
    while($r = sql_fetch_array($rs_agent)){
        $agent_map[(int)$r['company_id']] = (int)$r['agent_count'];
    }

    // 월 스냅샷(요약)
    $snap = [];
    $rs_snap = sql_query("
        SELECT company_id, month, base_fee, prorate_fee, additional_fee, total_fee, payment_status, paid_at
        FROM billing_company_month
        WHERE month='".sql_escape_string($month)."'
          AND company_id IN ({$id_in})
    ");
    while($r2 = sql_fetch_array($rs_snap)){
        $snap[(int)$r2['company_id']] = [
            'base_fee'       => (int)$r2['base_fee'],
            'prorate_fee'    => (int)$r2['prorate_fee'],
            'additional_fee' => (int)$r2['additional_fee'],
            'total_fee'      => (int)$r2['total_fee'],
            'payment_status' => $r2['payment_status'],
            'paid_at'        => $r2['paid_at']
        ];
    }

    // 회사별 수납 합계
    $paid_map = [];
    $rs_paid = sql_query("
        SELECT company_id, COALESCE(SUM(amount),0) AS paid_sum
        FROM billing_payment_log
        WHERE month='".sql_escape_string($month)."'
          AND company_id IN ({$id_in})
        GROUP BY company_id
    ");
    while($rp = sql_fetch_array($rs_paid)){
        $paid_map[(int)$rp['company_id']] = (int)$rp['paid_sum'];
    }

    // 활성 요금제 (즉석 계산 시 사용)
    $plan = sql_fetch("SELECT plan_id, monthly_fee FROM billing_plan WHERE active=1 ORDER BY plan_id LIMIT 1");
    $monthly_fee = $plan ? (int)$plan['monthly_fee'] : 0;

    foreach($paged_company_ids as $cid){
        $company_name = $company_options[$cid] ?? ('회사#'.$cid);
        $team_count   = $team_map[$cid]  ?? 0;
        $agent_count  = $agent_map[$cid] ?? 0;

        if (isset($snap[$cid])) {
            $base_fee       = (int)$snap[$cid]['base_fee'];
            $prorate_fee    = (int)$snap[$cid]['prorate_fee'];
            $additional_fee = (int)$snap[$cid]['additional_fee'];
            $total_fee      = (int)$snap[$cid]['total_fee'];
            $payment_status = $snap[$cid]['payment_status'];
            $paid_at        = $snap[$cid]['paid_at'];
        } else {
            // 스냅샷 없으면 즉석 계산(일할/추가 제외)
            $base_fee       = $agent_count * $monthly_fee;
            $prorate_fee    = 0;
            $additional_fee = 0;
            $total_fee      = $base_fee;
            $payment_status = 'unpaid';
            $paid_at        = null;
        }

        $paid_sum    = $paid_map[$cid] ?? 0;
        $outstanding = max(0, $total_fee - $paid_sum);

        // 결제상태 필터
        if ($pay === 'paid' && $payment_status !== 'paid') continue;
        if ($pay === 'unpaid' && $payment_status === 'paid') continue;

        $list[] = [
            'company_id'     => $cid,
            'company_name'   => $company_name,
            'team_count'     => $team_count,
            'agent_count'    => $agent_count,
            'month'          => $month,
            'base_fee'       => $base_fee,
            'prorate_fee'    => $prorate_fee,
            'additional_fee' => $additional_fee,
            'total_fee'      => $total_fee,
            'paid_sum'       => $paid_sum,
            'outstanding'    => $outstanding,
            'payment_status' => $payment_status,
            'paid_at'        => $paid_at
        ];
    }
}

// -------------------------------------------
// 월 통계 카드(현재 필터 기준: cname/company_id/pay 적용)
//  - 페이징 무관, 전체 필터링 집합을 대상으로 집계
// -------------------------------------------
$cards = [
    'company_cnt' => 0,
    'base_fee' => 0, 'prorate_fee' => 0, 'additional_fee' => 0,
    'total_fee' => 0, 'paid_sum' => 0, 'outstanding' => 0
];

if ($filtered_company_ids) {
    $id_in_all = implode(',', array_map('intval', $filtered_company_ids));

    // 스냅샷 합계
    $where_pay = '';
    if ($pay === 'paid')   $where_pay = " AND bcm.payment_status='paid' ";
    if ($pay === 'unpaid') $where_pay = " AND bcm.payment_status!='paid' ";

    $sum_snap = sql_fetch("
        SELECT
          COUNT(*) AS cc,
          COALESCE(SUM(bcm.base_fee),0)       AS s_base,
          COALESCE(SUM(bcm.prorate_fee),0)    AS s_prorate,
          COALESCE(SUM(bcm.additional_fee),0) AS s_additional,
          COALESCE(SUM(bcm.total_fee),0)      AS s_total
        FROM billing_company_month bcm
        WHERE bcm.month = '".sql_escape_string($month)."'
          AND bcm.company_id IN ({$id_in_all})
          {$where_pay}
    ");

    // 스냅샷이 없는 회사(필터 집합 내)에 대해 base_fee 추정(레벨3 * monthly_fee)
    $plan = sql_fetch("SELECT monthly_fee FROM billing_plan WHERE active=1 ORDER BY plan_id LIMIT 1");
    $monthly_fee = $plan ? (int)$plan['monthly_fee'] : 0;

    // 스냅샷 존재 회사 목록
    $snap_ids_rs = sql_query("
        SELECT company_id
        FROM billing_company_month
        WHERE month='".sql_escape_string($month)."'
          AND company_id IN ({$id_in_all})
          {$where_pay}
    ");
    $snap_ids = [];
    while($sx = sql_fetch_array($snap_ids_rs)){ $snap_ids[] = (int)$sx['company_id']; }

    $no_snap_ids = array_diff($filtered_company_ids, $snap_ids);

    $no_snap_base_sum = 0;
    if ($no_snap_ids) {
        $id_in_no = implode(',', array_map('intval', $no_snap_ids));
        $agent_sum_row = sql_fetch("
            SELECT COALESCE(SUM(t.cnt),0) AS s_agent
            FROM (
              SELECT company_id, COUNT(*) AS cnt
              FROM g5_member
              WHERE mb_level=3
                AND (mb_leave_date IS NULL OR mb_leave_date='')
                AND company_id IN ({$id_in_no})
              GROUP BY company_id
            ) t
        ");
        $no_snap_base_sum = (int)($agent_sum_row['s_agent'] ?? 0) * $monthly_fee;
    }

    // 수납 합계(필터된 전체)
    $sum_paid = sql_fetch("
        SELECT COALESCE(SUM(amount),0) AS s_paid
        FROM billing_payment_log
        WHERE month = '".sql_escape_string($month)."'
          AND company_id IN ({$id_in_all})
    ");

    $cards['company_cnt']   = (int)($sum_snap['cc'] ?? 0) + count($no_snap_ids);
    $cards['base_fee']      = (int)($sum_snap['s_base'] ?? 0) + $no_snap_base_sum;
    $cards['prorate_fee']   = (int)($sum_snap['s_prorate'] ?? 0);
    $cards['additional_fee']= (int)($sum_snap['s_additional'] ?? 0);
    $cards['total_fee']     = (int)($sum_snap['s_total'] ?? 0) + $no_snap_base_sum;
    $cards['paid_sum']      = (int)($sum_paid['s_paid'] ?? 0);
    $cards['outstanding']   = max(0, $cards['total_fee'] - $cards['paid_sum']);
}

// -------------------------------------------
// 출력
// -------------------------------------------
include_once (G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
?>
<div class="local_ov">
    <?php echo $listall; ?>
    <span class="btn_ov01">
        <span class="ov_txt">회사별 월 정산</span>
        <span class="ov_num"> <?php echo number_format($total_count); ?> 개</span>
    </span>
</div>

<!-- 월 통계 카드 -->
<div class="cards_wrap">
  <div class="card">
    <div class="card_tit">대상 회사</div>
    <div class="card_val"><?php echo number_format($cards['company_cnt']); ?> 개</div>
  </div>
  <div class="card">
    <div class="card_tit">기본요금 합계</div>
    <div class="card_val"><?php echo number_format($cards['base_fee']); ?>원</div>
  </div>
  <div class="card">
    <div class="card_tit">일할 합계</div>
    <div class="card_val"><?php echo number_format($cards['prorate_fee']); ?>원</div>
  </div>
  <div class="card">
    <div class="card_tit">추가요금 합계</div>
    <div class="card_val"><?php echo number_format($cards['additional_fee']); ?>원</div>
  </div>
  <div class="card">
    <div class="card_tit">총요금</div>
    <div class="card_val"><?php echo number_format($cards['total_fee']); ?>원</div>
  </div>
  <div class="card">
    <div class="card_tit">수납합계</div>
    <div class="card_val"><?php echo number_format($cards['paid_sum']); ?>원</div>
  </div>
  <div class="card">
    <div class="card_tit">미수금</div>
    <div class="card_val alert"><?php echo number_format($cards['outstanding']); ?>원</div>
  </div>
</div>

<div class="local_sch01 local_sch" style="margin-bottom:20px">
    <form method="get" action="./billing_company_list.php" class="form-row" autocomplete="off" id="searchForm">
        <label for="month">월</label>
        <input type="month" id="month" name="month" value="<?php echo get_text($month); ?>" class="frm_input">

        <label for="company_id">회사</label>
        <select id="company_id" name="company_id" class="frm_input" style="min-width:220px">
            <option value="0">-- 전체 회사 --</option>
            <?php foreach ($company_options as $cid=>$label) {
                // cname 텍스트 검색을 이미 적용 중이라면, 셀렉트에는 필터링된 회사만 보여주고 싶다면 아래 if 사용
                if ($cname !== '' && !in_array($cid, $filtered_company_ids, true)) continue;
                $sel = $company_id===$cid ? 'selected' : '';
                echo '<option value="'.$cid.'" '.$sel.'>'.get_text($label).' </option>';
            } ?>
        </select>

        <label for="cname">회사명</label>
        <input type="text" id="cname" name="cname" placeholder="회사명 검색" value="<?php echo get_text($cname); ?>" class="frm_input" style="width:180px">

        <label>결제상태</label>
        <label class="lb_inline"><input type="radio" name="pay" value="" <?php echo $pay===''?'checked':''; ?>> 전체</label>
        <label class="lb_inline"><input type="radio" name="pay" value="paid" <?php echo $pay==='paid'?'checked':''; ?>> 결제완료</label>
        <label class="lb_inline"><input type="radio" name="pay" value="unpaid" <?php echo $pay==='unpaid'?'checked':''; ?>> 미납</label>

        <button type="submit" class="btn btn_03">검색</button>

        <span class="btn_right btn-nav">
            <a class="btn btn_02" href="<?php echo url_with(['month' => ym_prev($month)]); ?>">◀ 지난달</a>
            <a class="btn btn_02" href="<?php echo url_with(['month' => ym_now()]); ?>">이번 달</a>
            <a class="btn btn_02" href="<?php echo url_with(['month' => ym_next($month)]); ?>">다음달 ▶</a>
        </span>
    </form>
</div>

<div class="tbl_head01 tbl_wrap pay_table">
    <table>
        <caption>회사별 월 정산</caption>
        <thead>
            <tr>
                <th scope="col">회사명</th>
                <th scope="col">팀 수</th>
                <th scope="col">상담원수</th>
                <th scope="col">월</th>
                <th scope="col">기본요금</th>
                <th scope="col">일할</th>
                <th scope="col">추가요금<br>(28일 이후)</th>
                <th scope="col">총요금</th>
                <th scope="col">수납합계</th>
                <th scope="col">미수금</th>
                <th scope="col">결제상태</th>
                <th scope="col">결제일</th>
                <th scope="col">관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$list) { ?>
            <tr><td class="empty_table" colspan="13">데이터가 없습니다.</td></tr>
        <?php } else {
            foreach($list as $row){
                $is_paid   = ($row['payment_status']==='paid');
                $has_out   = ($row['outstanding'] > 0);
                $can_refund= ($row['paid_sum'] > 0); // 환불 가능한지(수납합계 존재)
                ?>
                <tr>
                    <td class="td_left">
                        <a href="./billing_company_view.php?company_id=<?php echo (int)$row['company_id']; ?>" class="btn_link">
                            <?php echo get_text($row['company_name']); ?>
                        </a>
                        <?php if ($is_paid && $has_out) { ?>
                            <span class="status_badge bg-amber" title="총요금이 수납합계보다 큼">추가요금 발생</span>
                        <?php } ?>
                    </td>
                    <td class="td_num"><?php echo number_format($row['team_count']); ?></td>
                    <td class="td_num"><?php echo number_format($row['agent_count']); ?></td>
                    <td class="td_datetime"><?php echo get_text($row['month']); ?></td>
                    <td class="td_money td_right"><?php echo number_format($row['base_fee']); ?>원</td>
                    <td class="td_money td_right"><?php echo number_format($row['prorate_fee']); ?>원</td>
                    <td class="td_money td_right"><?php echo number_format($row['additional_fee']); ?>원</td>
                    <td class="td_money td_right"><?php echo number_format($row['total_fee']); ?>원</td>
                    <td class="td_money td_right"><?php echo number_format($row['paid_sum']); ?>원</td>
                    <td class="td_money td_right <?php echo $has_out?'txt-alert':''; ?>"><?php echo number_format($row['outstanding']); ?>원</td>
                    <td class="td_center">
                        <?php if ($is_paid) { ?>
                            <span class="status_badge bg-green">결제완료</span>
                        <?php } else { ?>
                            <span class="status_badge bg-gray">미납</span>
                        <?php } ?>
                    </td>
                    <td class="td_datetime"><?php echo $row['paid_at'] ? get_text($row['paid_at']) : '-'; ?></td>
                    <td class="td_mng">
                        <?php if (!$is_paid) { ?>
                            <!-- 결제완료(상태 변경) -->
                            <form method="post" action="./billing_company_list.php?month=<?php echo urlencode($row['month']); ?>&company_id=<?php echo (int)$row['company_id']; ?>&cname=<?php echo urlencode($cname); ?>&pay=<?php echo urlencode($pay); ?>" onsubmit="return confirm('결제완료로 처리하시겠습니까?');" style="display:block">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="mark_paid">
                                <input type="hidden" name="company_id" value="<?php echo (int)$row['company_id']; ?>">
                                <input type="hidden" name="month" value="<?php echo get_text($row['month']); ?>">
                                <?php if ($row['outstanding'] > 0) { ?>
                                    <input type="number" name="amount" placeholder="수납액(원)" class="frm_input" style="width:85px;text-align:right" value="<?php echo (int)$row['outstanding']; ?>">
                                <?php } ?>
                                <button type="submit" class="btn btn_01">결제완료</button>
                            </form>

                            <?php if ($can_refund) { ?>
                                <!-- 결제취소(환불) - unpaid 상태에서도 과납/부분납 환불 가능 -->
                                <form method="post" action="./billing_company_list.php?month=<?php echo urlencode($row['month']); ?>&company_id=<?php echo (int)$row['company_id']; ?>&cname=<?php echo urlencode($cname); ?>&pay=<?php echo urlencode($pay); ?>" onsubmit="return confirm('입력한 금액만큼 결제를 취소(환불)하시겠습니까?');" style="display:block;margin-top:5px">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="refund_payment">
                                    <input type="hidden" name="company_id" value="<?php echo (int)$row['company_id']; ?>">
                                    <input type="hidden" name="month" value="<?php echo get_text($row['month']); ?>">
                                    <input type="number" name="amount" placeholder="취소액(원)" class="frm_input" style="width:85px;text-align:right" value="<?php echo (int)min($row['paid_sum'], $row['total_fee']); ?>">
                                    <button type="submit" class="btn btn_02">결제취소</button>
                                </form>
                            <?php } ?>

                        <?php } else { ?>
                            <?php if ($has_out) { ?>
                                <!-- 추가요금 결제(상태 변경 없음, 수납로그만) -->
                                <form method="post" action="./billing_company_list.php?month=<?php echo urlencode($row['month']); ?>&company_id=<?php echo (int)$row['company_id']; ?>&cname=<?php echo urlencode($cname); ?>&pay=<?php echo urlencode($pay); ?>" onsubmit="return confirm('추가요금 수납을 기록하시겠습니까?');" style="display:block">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="pay_additional">
                                    <input type="hidden" name="company_id" value="<?php echo (int)$row['company_id']; ?>">
                                    <input type="hidden" name="month" value="<?php echo get_text($row['month']); ?>">
                                    <input type="number" name="amount" placeholder="추가요금(원)" class="frm_input" style="width:85px;text-align:right" value="<?php echo (int)$row['outstanding']; ?>">
                                    <button type="submit" class="btn btn_02">추가요금 결제</button>
                                </form>
                            <?php } ?>

                            <?php if ($can_refund) { ?>
                                <!-- 결제취소(환불) - paid 상태에서도 가능 -->
                                <form method="post" action="./billing_company_list.php?month=<?php echo urlencode($row['month']); ?>&company_id=<?php echo (int)$row['company_id']; ?>&cname=<?php echo urlencode($cname); ?>&pay=<?php echo urlencode($pay); ?>" onsubmit="return confirm('입력한 금액만큼 결제를 취소(환불)하시겠습니까?');" style="display:block;margin-top:5px">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="refund_payment">
                                    <input type="hidden" name="company_id" value="<?php echo (int)$row['company_id']; ?>">
                                    <input type="hidden" name="month" value="<?php echo get_text($row['month']); ?>">
                                    <input type="number" name="amount" placeholder="취소액(원)" class="frm_input" style="width:85px;text-align:right" value="<?php echo (int)min($row['paid_sum'], $row['total_fee']); ?>">
                                    <button type="submit" class="btn btn_02">결제취소</button>
                                </form>
                            <?php } else { echo $has_out ? '' : '-'; } ?>
                        <?php } ?>
                    </td>
                </tr>
            <?php } } ?>
        </tbody>
    </table>
</div>

<?php
// 페이징
$qstr = 'month='.urlencode($month).'&company_id='.$company_id.'&cname='.urlencode($cname).'&pay='.urlencode($pay);
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?{$qstr}&amp;page=");
?>

<style>
/* 통계 카드 */
.cards_wrap{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:10px;margin:25px 0}
.card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px}
.card_tit{font-size:.85rem;color:#6b7280;margin-bottom:4px}
.card_val{font-size:1.1rem;font-weight:700}
.card_val.alert{color:#dc2626}

/* 배지/표 스타일 */
.status_badge{display:inline-block;padding:.2rem .5rem;border-radius:6px;font-size:.85rem;vertical-align:middle;margin-left:.3rem}
.bg-green{background:#16a34a;color:#fff}
.bg-gray{background:#9ca3af;color:#fff}
.bg-amber{background:#f59e0b;color:#111}
.lb_inline{margin-right:.6rem}
.td_num{text-align:right}
.td_right{text-align:right}
.td_center{text-align:center}
.td_money {width:90px; white-space:nowrap}
.td_mng {width:260px !important} /* 버튼 늘어나서 넓힘 */
.txt-alert{color:#dc2626; font-weight:600}

/* 폼 */
#searchForm .frm_input{vertical-align:middle}
.pay_table .frm_input{height:30px}
</style>

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
