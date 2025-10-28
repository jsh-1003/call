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
$csrf_token = $_SESSION[get_csrf_token_key()];

// -------------------------------------------
// 파라미터
// -------------------------------------------
$month  = isset($_GET['month']) && preg_match('/^\d{4}\-\d{2}$/', $_GET['month'])
        ? $_GET['month'] : ym_now();
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$pay = isset($_GET['pay']) ? trim($_GET['pay']) : ''; // '', 'paid', 'unpaid'
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

// -------------------------------------------
// 결제 완료 처리(수동)
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
        $prorate_fee = 0; // (향후 배치/수작업으로 갱신)
        $total       = $base_fee + $prorate_fee;

        sql_query("
            INSERT INTO billing_company_month
              (company_id, month, plan_id, agent_count, base_fee, prorate_fee, total_fee, payment_status, memo, created_at, updated_at)
            VALUES
              ({$cid}, '".sql_escape_string($ym)."', ".(int)$plan['plan_id'].", {$agent_count}, {$base_fee}, {$prorate_fee}, {$total}, 'unpaid', NULL, NOW(), NOW())
        ");
        $row = sql_fetch("SELECT bill_id, total_fee, payment_status FROM billing_company_month WHERE company_id={$cid} AND month='".sql_escape_string($ym)."'");
    }

    if ($row && $row['payment_status'] !== 'paid') {
        sql_query("
            UPDATE billing_company_month
               SET payment_status='paid', paid_at=NOW(), updated_at=NOW()
             WHERE bill_id=".(int)$row['bill_id']."
        ");
        $amt = $amount > 0 ? $amount : (int)$row['total_fee'];
        sql_query("
            INSERT INTO billing_payment_log (company_id, month, amount, method, processed_by, processed_at, note)
            VALUES ({$cid}, '".sql_escape_string($ym)."', {$amt}, 'manual', ".(int)$member['mb_no'].", NOW(), '관리자 결제완료 처리')
        ");
        alert('결제완료 처리했습니다.', './billing_company_list.php?month='.urlencode($ym).'&company_id='.$cid.'&pay='.urlencode($pay));
    } else {
        alert('이미 결제완료 상태이거나 대상이 없습니다.');
    }
    exit;
}

// -------------------------------------------
// 회사 집합(과금대상 상담원 보유 회사 기준)
// -------------------------------------------

$total_cnt_row = sql_fetch("
    SELECT COUNT(*) AS c
    FROM (
        SELECT m.company_id
        FROM g5_member m
        WHERE m.mb_level=3
          AND (m.mb_leave_date IS NULL OR m.mb_leave_date='')
          ".($company_id>0 ? " AND m.company_id={$company_id} " : "")."
        GROUP BY m.company_id
    ) T
");
$total_count = (int)$total_cnt_row['c'];
$rows = $config['cf_page_rows'];
$total_page  = $rows ? (int)ceil($total_count / $rows) : 1;
$from_record = ($page - 1) * $rows;

$company_rs = sql_query("
    SELECT m.company_id
    FROM g5_member m
    WHERE m.mb_level=3
      AND (m.mb_leave_date IS NULL OR m.mb_leave_date='')
      ".($company_id>0 ? " AND m.company_id={$company_id} " : "")."
    GROUP BY m.company_id
    ORDER BY m.company_id ASC
    LIMIT {$from_record}, {$rows}
");
$company_ids = [];
while($c = sql_fetch_array($company_rs)) $company_ids[] = (int)$c['company_id'];

// -------------------------------------------
// 집계(팀 수/상담원 수/스냅샷)
//  - 팀 수: company_id 기준, mb_level=7 (삭제/차단 제외) 단순 인원 수
//  - 상담원 수: mb_level=3 (삭제/차단 제외)
// -------------------------------------------
$list = [];
if ($company_ids) {
    $id_in = implode(',', $company_ids);

    // 팀 수: 레벨7 수
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

    // 상담원 수: 레벨3 수
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

    // 스냅샷(월 정산)
    $snap = [];
    $rs_snap = sql_query("
        SELECT company_id, month, total_fee, payment_status, paid_at
        FROM billing_company_month
        WHERE month='".sql_escape_string($month)."'
          AND company_id IN ({$id_in})
    ");
    while($r2 = sql_fetch_array($rs_snap)){
        $snap[(int)$r2['company_id']] = [
            'total_fee'      => (int)$r2['total_fee'],
            'payment_status' => $r2['payment_status'],
            'paid_at'        => $r2['paid_at']
        ];
    }

    // 활성 요금제 (즉석 계산 시 사용)
    $plan = sql_fetch("SELECT plan_id, monthly_fee FROM billing_plan WHERE active=1 ORDER BY plan_id LIMIT 1");
    $monthly_fee = $plan ? (int)$plan['monthly_fee'] : 0;

    foreach($company_ids as $cid){
        $company_name = get_company_name_cached($cid);
        $team_count   = $team_map[$cid]  ?? 0;
        $agent_count  = $agent_map[$cid] ?? 0;

        if (isset($snap[$cid])) {
            $total_fee      = (int)$snap[$cid]['total_fee'];
            $payment_status = $snap[$cid]['payment_status'];
            $paid_at        = $snap[$cid]['paid_at'];
        } else {
            // 스냅샷 없으면 즉석 계산(일할 제외)
            $total_fee      = $agent_count * $monthly_fee;
            $payment_status = 'unpaid';
            $paid_at        = null;
        }

        // 결제상태 필터
        if ($pay === 'paid' && $payment_status !== 'paid') continue;
        if ($pay === 'unpaid' && $payment_status === 'paid') continue;

        $list[] = [
            'company_id'    => $cid,
            'company_name'  => $company_name,
            'team_count'    => $team_count,
            'agent_count'   => $agent_count,
            'month'         => $month,
            'total_fee'     => $total_fee,
            'payment_status'=> $payment_status,
            'paid_at'       => $paid_at
        ];
    }
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
        <span class="ov_num"> <?php echo number_format($total_count); ?> 명</span>
    </span>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./billing_company_list.php" class="form-row" autocomplete="off">
        <label for="month">월</label>
        <input type="month" id="month" name="month" value="<?php echo get_text($month); ?>" class="frm_input">

        <label for="company_id">회사</label>
        <input type="number" id="company_id" name="company_id" placeholder="회사ID" value="<?php echo (int)$company_id; ?>" class="frm_input" style="width:120px">

        <label>결제상태</label>
        <label class="lb_inline"><input type="radio" name="pay" value="" <?php echo $pay===''?'checked':''; ?>> 전체</label>
        <label class="lb_inline"><input type="radio" name="pay" value="paid" <?php echo $pay==='paid'?'checked':''; ?>> 결제완료</label>
        <label class="lb_inline"><input type="radio" name="pay" value="unpaid" <?php echo $pay==='unpaid'?'checked':''; ?>> 미납</label>

        <button type="submit" class="btn btn_03">검색</button>

        <span class="btn_right">
            <a class="btn btn_02" href="./billing_company_list.php?month=<?php echo urlencode(ym_prev($month)); ?>&company_id=<?php echo (int)$company_id; ?>&pay=<?php echo urlencode($pay); ?>">◀ 지난달</a>
            <a class="btn btn_02" href="./billing_company_list.php?month=<?php echo urlencode(ym_next($month)); ?>&company_id=<?php echo (int)$company_id; ?>&pay=<?php echo urlencode($pay); ?>">다음달 ▶</a>
        </span>
    </form>
</div>

<div class="tbl_head01 tbl_wrap">
    <table>
        <caption>회사별 월 정산</caption>
        <thead>
            <tr>
                <th scope="col">회사명</th>
                <th scope="col">팀 수</th>
                <th scope="col">상담원수</th>
                <th scope="col">월</th>
                <th scope="col">총요금</th>
                <th scope="col">결제상태</th>
                <th scope="col">결제일</th>
                <th scope="col">관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$list) { ?>
            <tr><td class="empty_table" colspan="8">데이터가 없습니다.</td></tr>
        <?php } else { 
            foreach($list as $row){
                $is_paid = ($row['payment_status']==='paid');
                ?>
                <tr>
                    <td class="td_left"><?php echo get_text($row['company_name']); ?></td>
                    <td class="td_num"><?php echo number_format($row['team_count']); ?></td>
                    <td class="td_num"><?php echo number_format($row['agent_count']); ?></td>
                    <td class="td_datetime"><?php echo get_text($row['month']); ?></td>
                    <td class="td_money"><?php echo number_format($row['total_fee']); ?>원</td>
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
                        <form method="post" action="./billing_company_list.php?month=<?php echo urlencode($row['month']); ?>&company_id=<?php echo (int)$row['company_id']; ?>&pay=<?php echo urlencode($pay); ?>" onsubmit="return confirm('결제완료로 처리하시겠습니까?');" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="mark_paid">
                            <input type="hidden" name="company_id" value="<?php echo (int)$row['company_id']; ?>">
                            <input type="hidden" name="month" value="<?php echo get_text($row['month']); ?>">
                            <!-- 필요시 수납액 수동 입력 -->
                            <!-- <input type="number" name="amount" placeholder="수납액(원)" class="frm_input" style="width:120px"> -->
                            <button type="submit" class="btn btn_01">결제완료</button>
                        </form>
                        <?php } else { echo '-'; } ?>
                    </td>
                </tr>
            <?php } } ?>
        </tbody>
    </table>
</div>

<?php
// 페이징
$qstr = "month='.urlencode($month).'&company_id='.$company_id.'&pay='.urlencode($pay)";
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?{$qstr}&amp;page=");
?>

<style>
.status_badge{display:inline-block;padding:.2rem .5rem;border-radius:6px;font-size:.85rem}
.bg-green{background:#16a34a;color:#fff}
.bg-gray{background:#9ca3af;color:#fff}
.lb_inline{margin-right:.6rem}
.td_num{text-align:right}
.td_center{text-align:center}
.td_money {width:150px}
</style>

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
