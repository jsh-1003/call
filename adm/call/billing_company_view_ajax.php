<?php
// /adm/call/billing_company_view_ajax.php
$sub_menu = '700990';
require_once './_common.php';
if ($member['mb_id'] != 'admin_pay') {
    die('접근 권한이 없습니다.');
}

header('Content-Type: application/json; charset=UTF-8');

// 권한
if ((int)$member['mb_level'] < 10) {
    echo json_encode(['ok'=>0,'message'=>'권한없음']); exit;
}

// -------------------------------------------
// 유틸
// -------------------------------------------
function get_csrf_token_key(){ return 'billing_company_view_csrf'; }
function is_ym($s){ return (bool)preg_match('/^\d{4}\-\d{2}$/', (string)$s); }
function nf($n){ return number_format((int)$n).'원'; }
function ym_now(){ return (new DateTimeImmutable('first day of this month'))->format('Y-m'); }
function ym_add($ym, $n){
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym.'-01');
    if (!$dt) $dt = new DateTimeImmutable('first day of this month');
    return $dt->modify(($n>=0?'+':'').$n.' month')->format('Y-m');
}

// -------------------------------------------
// 액션/메서드/기본값
// -------------------------------------------
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$is_post = ($method === 'POST');

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';
$READ_ACTIONS  = ['history','payments','list_comments'];
$WRITE_ACTIONS = ['add_comment','delete_comment'];

// 유효하지 않거나 공백이면 기본 history로
if ($action === '' || (!in_array($action, array_merge($READ_ACTIONS,$WRITE_ACTIONS), true))) {
    $action = 'history';
}

// 쓰기 액션은 POST만
if (in_array($action, $WRITE_ACTIONS, true) && !$is_post) {
    echo json_encode(['ok'=>0,'message'=>'POST only']); exit;
}

// 쓰기 액션은 CSRF 필수
if (in_array($action, $WRITE_ACTIONS, true)) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$csrf || $csrf !== ($_SESSION[get_csrf_token_key()] ?? '')) {
        echo json_encode(['ok'=>0,'message'=>'잘못된 요청(CSRF)']); exit;
    }
}

// 필요한 액션에서만 회사ID 필수
$company_required_actions = ['history','payments','list_comments','add_comment'];
if (in_array($action, $company_required_actions, true)) {
    $company_id = (int)($_REQUEST['company_id'] ?? 0);
    if ($company_id <= 0) {
        echo json_encode(['ok'=>0,'message'=>'company_id required']); exit;
    }
}

// -------------------------------------------
// 1) 기간별 월 스냅샷 + 수납합계 (GET/POST 허용)
//    - start/end 미제공 시 최근 12개월 자동
// -------------------------------------------
if ($action === 'history') {
    $start = $_REQUEST['start'] ?? '';
    $end   = $_REQUEST['end']   ?? '';

    if (!is_ym($start) || !is_ym($end)) {
        // 파라미터 없으면 최근 12개월 기본
        $end   = ym_now();
        $start = ym_add($end, -11);
    }
    if ($start > $end) { [$start,$end] = [$end,$start]; }

    // 스냅샷
    $rs = sql_query("
        SELECT bcm.month, bcm.base_fee, bcm.prorate_fee, bcm.additional_fee, bcm.total_fee,
               bcm.payment_status, bcm.paid_at
          FROM billing_company_month bcm
         WHERE bcm.company_id = {$company_id}
           AND bcm.month BETWEEN '".sql_escape_string($start)."' AND '".sql_escape_string($end)."'
         ORDER BY bcm.month DESC
    ");
    $rows = [];
    while($r = sql_fetch_array($rs)){ $rows[] = $r; }

    // 수납합계
    $paid_map = [];
    if ($rows){
        $months = array_map(fn($x)=>"'".sql_escape_string($x['month'])."'", $rows);
        $in = implode(',', $months);
        $rs2 = sql_query("
            SELECT month, COALESCE(SUM(amount),0) AS paid_sum
              FROM billing_payment_log
             WHERE company_id={$company_id}
               AND month IN ({$in})
             GROUP BY month
        ");
        while($p = sql_fetch_array($rs2)){
            $paid_map[$p['month']] = (int)$p['paid_sum'];
        }
    }

    if (!$rows){
        $html = '<tr><td class="empty_table" colspan="10">데이터가 없습니다.</td></tr>';
        echo json_encode(['ok'=>1,'html'=>$html,'totals'=>null]); exit;
    }

    $tot = ['base_fee'=>0,'prorate_fee'=>0,'additional_fee'=>0,'total_fee'=>0,'paid_sum'=>0,'outstanding'=>0];

    ob_start();
    foreach($rows as $r){
        $month = get_text($r['month']);
        $base  = (int)$r['base_fee'];
        $pro   = (int)$r['prorate_fee'];
        $add   = (int)$r['additional_fee'];
        $totf  = (int)$r['total_fee'];
        $paid  = (int)($paid_map[$month] ?? 0);
        $out   = max(0, $totf - $paid);
        $is_paid = ($r['payment_status']==='paid');

        $tot['base_fee']       += $base;
        $tot['prorate_fee']    += $pro;
        $tot['additional_fee'] += $add;
        $tot['total_fee']      += $totf;
        $tot['paid_sum']       += $paid;
        $tot['outstanding']    += $out;
        ?>
        <tr>
          <td class="td_datetime"><?php echo $month; ?></td>
          <td class="td_money td_right"><?php echo nf($base); ?></td>
          <td class="td_money td_right"><?php echo nf($pro); ?></td>
          <td class="td_money td_right"><?php echo nf($add); ?></td>
          <td class="td_money td_right"><?php echo nf($totf); ?></td>
          <td class="td_money td_right"><?php echo nf($paid); ?></td>
          <td class="td_money td_right <?php echo $out>0?'txt-alert':''; ?>"><?php echo nf($out); ?></td>
          <td class="td_center">
            <?php if ($is_paid){ ?>
              <span class="status_badge bg-green">결제완료</span>
            <?php } else { ?>
              <span class="status_badge bg-gray">미납</span>
            <?php } ?>
          </td>
          <td class="td_datetime"><?php echo $r['paid_at'] ? get_text($r['paid_at']) : '-'; ?></td>
          <td class="td_center">
            <button type="button" class="btn btn_02 btn-payments" data-month="<?php echo $month; ?>">결제내역</button>
          </td>
        </tr>
        <?php
    }
    $html = ob_get_clean();

    echo json_encode(['ok'=>1,'html'=>$html,'totals'=>$tot]); exit;
}

// -------------------------------------------
// 2) 특정 월 결제 로그 상세 (GET/POST 허용, CSRF 불필요)
// -------------------------------------------
if ($action === 'payments') {
    $ym = $_REQUEST['month'] ?? '';
    if (!is_ym($ym)) { echo json_encode(['ok'=>0,'message'=>'월 형식 오류']); exit; }

    $rs = sql_query("
        SELECT pay_id, amount, method, processed_by, processed_at, note
          FROM billing_payment_log
         WHERE company_id={$company_id}
           AND month='".sql_escape_string($ym)."'
         ORDER BY pay_id DESC
    ");
    ob_start();
    ?>
    <div class="tbl_head01 tbl_wrap">
      <table>
        <thead>
          <tr>
            <th>처리일시</th>
            <th>금액</th>
            <th>방법</th>
            <th>처리자</th>
            <th>비고</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $has = false;
        while($r = sql_fetch_array($rs)){
            $has = true;
            $by = (int)$r['processed_by'];
            $name = get_member_name_cached($by,'mb_name') ?: ('#'.$by);
            $amt = (int)$r['amount'];
            ?>
            <tr>
              <td class="td_datetime"><?php echo get_text($r['processed_at']); ?></td>
              <td class="td_money td_right"><?php echo nf($amt); ?></td>
              <td class="td_center"><?php echo get_text($r['method']); ?></td>
              <td class="td_left"><?php echo get_text($name); ?></td>
              <td class="td_left"><?php echo get_text($r['note']); ?></td>
            </tr>
            <?php
        }
        if (!$has){
            echo '<tr><td class="empty_table" colspan="5">결제 로그가 없습니다.</td></tr>';
        }
        ?>
        </tbody>
      </table>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['ok'=>1,'html'=>$html]); exit;
}

// -------------------------------------------
// 3) 코멘트 등록 (POST만 + CSRF)
// -------------------------------------------
// DDL은 아래 참고
if ($action === 'add_comment') {
    $text  = trim((string)($_POST['text'] ?? ''));
    $month = $_POST['month'] ?? null;
    if ($month === '') $month = null;
    if ($month !== null && !is_ym($month)) { echo json_encode(['ok'=>0,'message'=>'관련월 형식 오류']); exit; }
    if ($text === '') { echo json_encode(['ok'=>0,'message'=>'내용 없음']); exit; }

    $mval = $month ? "'".sql_escape_string($month)."'" : "NULL";
    sql_query("
        INSERT INTO billing_company_comment (company_id, month, comment_text, created_by, created_at)
        VALUES ({$company_id}, {$mval}, '".sql_escape_string($text)."', ".(int)$member['mb_no'].", NOW())
    ");
    echo json_encode(['ok'=>1]); exit;
}

// -------------------------------------------
// 4) 코멘트 목록 (GET/POST 허용)
// -------------------------------------------
if ($action === 'list_comments') {
    $rs = sql_query("
        SELECT c.comment_id, c.month, c.comment_text, c.created_by, c.created_at, m.mb_name
          FROM billing_company_comment c
          LEFT JOIN g5_member m ON m.mb_no = c.created_by
         WHERE c.company_id = {$company_id}
         ORDER BY c.created_at DESC, c.comment_id DESC
         LIMIT 200
    ");

    ob_start();
    $has = false;
    while($r = sql_fetch_array($rs)){
        $has = true;
        $who = $r['mb_name'] ? get_text($r['mb_name']) : ('#'.(int)$r['created_by']);
        $month = $r['month'] ? get_text($r['month']) : '-';
        $can_delete = ($is_admin === 'super') || ((int)$member['mb_no'] === (int)$r['created_by']);
        ?>
        <tr>
          <td class="td_datetime"><?php echo fmt_datetime(get_text($r['created_at'])); ?></td>
          <td class="td_name"><?php echo $who; ?></td>
          <td class="td_mng"><?php echo $month; ?></td>
          <td class="td_left"><?php echo nl2br(get_text($r['comment_text'])); ?></td>
          <td class="td_mng">
            <?php if ($can_delete) { ?>
              <button type="button" class="btn btn_02 btn-del-comment" data-id="<?php echo (int)$r['comment_id']; ?>">삭제</button>
            <?php } else { echo '-'; } ?>
          </td>
        </tr>
        <?php
    }
    if (!$has){
        echo '<tr><td class="empty_table" colspan="4">코멘트가 없습니다.</td></tr>';
    }
    $html = ob_get_clean();
    echo json_encode(['ok'=>1,'html'=>$html]); exit;
}
// -------------------------------------------
// 5) 코멘트 삭제 (POST만 + CSRF, 작성자 본인 또는 슈퍼만)
// -------------------------------------------
if ($action === 'delete_comment') {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    if ($comment_id <= 0) { echo json_encode(['ok'=>0,'message'=>'comment_id required']); exit; }

    // 코멘트 조회
    $row = sql_fetch("
        SELECT comment_id, company_id, created_by
          FROM billing_company_comment
         WHERE comment_id = {$comment_id}
           AND company_id = {$company_id}
         LIMIT 1
    ");
    if (!$row) { echo json_encode(['ok'=>0,'message'=>'not found']); exit; }

    // 권한 체크: 슈퍼 또는 작성자 본인
    if ($is_admin !== 'super' && (int)$member['mb_no'] !== (int)$row['created_by']) {
        echo json_encode(['ok'=>0,'message'=>'권한없음']); exit;
    }

    // 삭제
    sql_query("DELETE FROM billing_company_comment WHERE comment_id = {$comment_id} AND company_id = {$company_id} LIMIT 1");
    echo json_encode(['ok'=>1]); exit;
}


echo json_encode(['ok'=>0,'message'=>'알 수 없는 요청']); exit;
