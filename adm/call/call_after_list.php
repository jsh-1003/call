<?php
// /adm/call/call_after_list.php
$sub_menu = '700400';
require_once './_common.php';

// 접근 권한: 관리자 레벨 7 이상만
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

/** 일정 표시 포맷터 */
function format_schedule_display($scheduled_at, $schedule_note) {
    $when = $scheduled_at ? fmt_datetime(get_text($scheduled_at), 'mdhi') : '';
    $note = $schedule_note ? get_text($schedule_note) : '';
    if (!$when && !$note) return '-';
    if ($when && $note) return $when.' / '.$note;
    return $when ?: $note;
}

/** 정렬 th 링크 유틸 */
function sort_th($key, $label){
    global $cur_sort, $cur_dir, $qparams_for_sort;
    $nextDir = ($cur_sort === $key && $cur_dir === 'desc') ? 'asc' : 'desc';
    $params = $qparams_for_sort;
    $params['sort'] = $key;
    $params['dir']  = $nextDir;
    $params['page'] = 1;
    $url = './call_after_list.php?'.http_build_query($params);
    $arrow = '';
    if ($cur_sort === $key) $arrow = ($cur_dir === 'asc') ? ' ▲' : ' ▼';
    return '<a href="'.htmlspecialchars($url, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'" class="th-sort">'.get_text($label).$arrow.'</a>';
}

$mb_no          = (int)($member['mb_no'] ?? 0);
$mb_level       = (int)($member['mb_level'] ?? 0);
$my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;
$member_table   = $g5['member_table'];

$today      = date('Y-m-d');
$seven_ago  = date('Y-m-d', strtotime('-7 days'));
$start_date = _g('start', $seven_ago);
$end_date   = _g('end',   $today);

// ===== 조직 선택 파라미터 (회사/그룹/상담원) =====
// - 9+: 회사/그룹 자유 선택 (회사 0=전체, 그룹 0=전체)
// - 8 : 회사 고정(본인 회사), 그룹 선택 가능(0=회사 내 전체)
// - 7 : 그룹 고정(본인 그룹)
if ($mb_level >= 9) {
    $sel_company_id = (int)(_g('company_id', 0));
    $sel_mb_group   = (int)(_g('mb_group', 0));
} elseif ($mb_level >= 8) {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = (int)(_g('mb_group', 0));
} else {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}
$sel_agent_no = (int)($_GET['agent'] ?? 0);

// ===== 검색/필터 =====
$q         = _g('q', '');
$q_type    = _g('q_type', '');              // name | last4 | full | all
// $f_status  = isset($_GET['status']) ? (int)$_GET['status'] : 0;    // 원콜 상태
$f_acstate = isset($_GET['acstate']) ? (int)$_GET['acstate'] : -1; // 2차콜 상태
$page      = max(1, (int)(_g('page', '1')));
$page_rows = 30;
$offset    = ($page - 1) * $page_rows;

/* ===== 정렬 파라미터 ===== */
$cur_sort = _g('sort', 'scheduled_at');
$cur_dir  = strtolower((string)_g('dir', 'desc'));
$cur_dir  = in_array($cur_dir, ['asc','desc'], true) ? $cur_dir : 'desc';
$SORT_MAP = [
    'agent_name'   => 'agent_sort',
    'call_start'   => 'b.call_start',
    'call_end'     => 'b.call_end',
    'target_name'  => 't.name',
    'man_age'      => 'man_age',
    'call_hp'      => 'b.call_hp',
    'ac_state'     => 's.sort_order, s.state_id',
    'scheduled_at' => 'tk.scheduled_at',
    'ac_updated_at'=> 'tk.updated_at',
];
if (!isset($SORT_MAP[$cur_sort])) $cur_sort = 'scheduled_at';
$DIR_SQL = strtoupper($cur_dir);
$__parts = array_map('trim', explode(',', $SORT_MAP[$cur_sort]));
$__orders = [];
foreach ($__parts as $__p) { if ($__p !== '') $__orders[] = $__p.' '.$DIR_SQL; }
$__orders[] = 'b.call_start DESC';
$__orders[] = 'b.call_id DESC';
$order_sql = implode(', ', $__orders);

/* ==========================
   AJAX: 단건 조회/저장 (기존 그대로)
   ========================== */
if (isset($_GET['ajax']) && $_GET['ajax']==='get') {
    $target_id   = (int)($_GET['target_id'] ?? 0);
    $campaign_id = (int)($_GET['campaign_id'] ?? 0);
    $mb_group    = (int)($_GET['mb_group'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    if ($target_id<=0 || $campaign_id<=0 || $mb_group<=0) { echo json_encode(['success'=>false,'message'=>'invalid'], JSON_UNESCAPED_UNICODE); exit; }
    if ($mb_level == 7 && $mb_group !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$mb_group} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }

    if ($mb_level < 7) {
        $own = sql_fetch("SELECT 1 FROM call_log WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} AND mb_no={$mb_no} LIMIT 1");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }
    $ticket = sql_fetch("SELECT t.ticket_id, t.state_id, t.scheduled_at, t.schedule_note, t.updated_by, t.updated_at FROM call_aftercall_ticket t WHERE t.mb_group={$mb_group} AND t.campaign_id={$campaign_id} AND t.target_id={$target_id} LIMIT 1");
    if (!$ticket) $ticket = ['ticket_id'=>null,'state_id'=>0,'scheduled_at'=>null,'schedule_note'=>null,'updated_by'=>null,'updated_at'=>null];
    $hist = [];
    if (!empty($ticket['ticket_id'])) {
        $rh = sql_query("
            SELECT h.prev_state, h.new_state, h.memo, h.changed_by, h.changed_at,
                   ps.name_ko AS prev_label, ns.name_ko AS new_label,
                   m.mb_name AS who_name, m.mb_id AS who_id
              FROM call_aftercall_history h
         LEFT JOIN call_aftercall_state_code ps ON ps.state_id=h.prev_state
         LEFT JOIN call_aftercall_state_code ns ON ns.state_id=h.new_state
         LEFT JOIN {$member_table} m ON m.mb_no = h.changed_by
             WHERE h.ticket_id=".(int)$ticket['ticket_id']."
          ORDER BY h.changed_at DESC, h.hist_id DESC
             LIMIT 200
        ");
        while ($r = sql_fetch_array($rh)) { $r['kind']='state'; $hist[]=$r; }
    }
    $notes = [];
    if (!empty($ticket['ticket_id'])) {
        $rn = sql_query("
            SELECT n.note_id, n.note_type, n.note_text, n.scheduled_at, n.created_by, n.created_at,
                   m.mb_name AS who_name, m.mb_id AS who_id
              FROM call_aftercall_note n
         LEFT JOIN {$member_table} m ON m.mb_no = n.created_by
             WHERE n.ticket_id=".(int)$ticket['ticket_id']."
          ORDER BY n.created_at DESC, n.note_id DESC
             LIMIT 200
        ");
        while ($r = sql_fetch_array($rn)) $notes[] = $r;
    }
    echo json_encode(['success'=>true,'ticket'=>$ticket,'history'=>$hist,'notes'=>$notes], JSON_UNESCAPED_UNICODE); exit;
}

if (isset($_POST['ajax']) && $_POST['ajax']==='save') {
    check_admin_token();
    $campaign_id   = (int)($_POST['campaign_id'] ?? 0);
    $mb_group      = (int)($_POST['mb_group'] ?? 0);
    $target_id     = (int)($_POST['target_id'] ?? 0);
    $new_state_id  = (int)($_POST['state_id'] ?? 0);
    $memo_input    = trim((string)($_POST['memo'] ?? ''));
    $schedule_date = trim((string)($_POST['schedule_date'] ?? ''));
    $schedule_time = trim((string)($_POST['schedule_time'] ?? ''));
    $schedule_note = trim((string)($_POST['schedule_note'] ?? ''));
    $schedule_clear= (int)($_POST['schedule_clear'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');

    if ($campaign_id<=0 || $mb_group<=0 || $target_id<=0) { echo json_encode(['success'=>false,'message'=>'invalid']); exit; }
    if ($mb_level == 7 && $mb_group !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied']); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$mb_group} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) {
            echo json_encode(['success'=>false,'message'=>'denied']); exit;
        }
    }
    if ($mb_level < 7) {
        $own = sql_fetch("SELECT 1 FROM call_log WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} AND mb_no={$mb_no} LIMIT 1");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied']); exit; }
    }
    $last = sql_fetch("SELECT call_id FROM call_log WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} ORDER BY call_start DESC, call_id DESC LIMIT 1");
    $last_call_id = (int)($last['call_id'] ?? 0);
    $rowPrev = sql_fetch("SELECT ticket_id, state_id, scheduled_at, schedule_note FROM call_aftercall_ticket WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} LIMIT 1");
    $prev_state_id = $rowPrev ? (int)$rowPrev['state_id'] : null;
    $prev_sched_at = $rowPrev ? ($rowPrev['scheduled_at'] ?? null) : null;
    $prev_sched_nt = $rowPrev ? ($rowPrev['schedule_note'] ?? null) : null;

    $scheduled_at = null;
    if ($schedule_clear) { $scheduled_at = null; $schedule_note = ''; }
    else {
        if ($schedule_date !== '') {
            if ($schedule_time === '') $schedule_time = '09:00';
            $scheduled_at = $schedule_date.' '.$schedule_time.':00';
        } else {
            $scheduled_at = $prev_sched_at;
            if ($schedule_note === '' && $prev_sched_nt !== null) $schedule_note = $prev_sched_nt;
        }
    }

    $state_changed    = ($prev_state_id === null || $prev_state_id !== $new_state_id);
    $schedule_changed = ($scheduled_at !== $prev_sched_at) || ($schedule_note !== (string)$prev_sched_nt);

    sql_query("START TRANSACTION");
    if ($rowPrev) {
        $ticket_id = (int)$rowPrev['ticket_id'];
        $ok = sql_query("
            UPDATE call_aftercall_ticket
               SET state_id={$new_state_id},
                   scheduled_at=".($scheduled_at ? "'".sql_escape_string($scheduled_at)."'" : "NULL").",
                   schedule_note=".($schedule_note!=='' ? "'".sql_escape_string($schedule_note)."'" : "NULL").",
                   last_call_id=".($last_call_id ?: "NULL").",
                   updated_by={$mb_no},
                   updated_at=NOW()
             WHERE ticket_id={$ticket_id}
             LIMIT 1
        ", true);
        if (!$ok) { sql_query("ROLLBACK"); echo json_encode(['success'=>false,'message'=>'update failed']); exit; }
        if ($state_changed) {
            sql_query("INSERT INTO call_aftercall_history (ticket_id, prev_state, new_state, memo, scheduled_at, changed_by, changed_at)
                       VALUES ({$ticket_id},".($prev_state_id===null?'NULL':$prev_state_id).",{$new_state_id},NULL,NULL,{$mb_no},NOW())");
        }
    } else {
        $ok = sql_query("
            INSERT INTO call_aftercall_ticket
                (campaign_id, mb_group, target_id, last_call_id, state_id, memo, scheduled_at, schedule_note, updated_by, updated_at, created_at)
            VALUES
                ({$campaign_id}, {$mb_group}, {$target_id}, ".($last_call_id ?: "NULL").",
                 {$new_state_id}, NULL,
                 ".($scheduled_at ? "'".sql_escape_string($scheduled_at)."'" : "NULL").",
                 ".($schedule_note!=='' ? "'".sql_escape_string($schedule_note)."'" : "NULL").",
                 {$mb_no}, NOW(), NOW())
        ", true);
        if (!$ok) { sql_query("ROLLBACK"); echo json_encode(['success'=>false,'message'=>'insert failed']); exit; }
        $ticket_id = (int)sql_insert_id();
        sql_query("INSERT INTO call_aftercall_history (ticket_id, prev_state, new_state, memo, scheduled_at, changed_by, changed_at)
                   VALUES ({$ticket_id}, NULL, {$new_state_id}, NULL, NULL, {$mb_no}, NOW())");
    }
    if ($schedule_changed) {
        sql_query("INSERT INTO call_aftercall_note (ticket_id, note_type, note_text, scheduled_at, created_by, created_at)
                   VALUES ({$ticket_id}, 'schedule', ".($schedule_note!=='' ? "'".sql_escape_string($schedule_note)."'" : "NULL").",
                           ".($scheduled_at ? "'".sql_escape_string($scheduled_at)."'" : "NULL").",
                           {$mb_no}, NOW())");
    }
    if ($memo_input !== '') {
        sql_query("INSERT INTO call_aftercall_note (ticket_id, note_type, note_text, scheduled_at, created_by, created_at)
                   VALUES ({$ticket_id}, 'note', '".sql_escape_string($memo_input)."', NULL, {$mb_no}, NOW())");
    }
    sql_query("COMMIT");

    $ticket = sql_fetch("SELECT ticket_id, state_id, scheduled_at, schedule_note, updated_by, updated_at FROM call_aftercall_ticket WHERE ticket_id={$ticket_id} LIMIT 1");
    $hist = [];
    $rh = sql_query("
        SELECT h.prev_state, h.new_state, h.memo, h.changed_by, h.changed_at,
               ps.name_ko AS prev_label, ns.name_ko AS new_label,
               m.mb_name AS who_name, m.mb_id AS who_id
          FROM call_aftercall_history h
     LEFT JOIN call_aftercall_state_code ps ON ps.state_id=h.prev_state
     LEFT JOIN call_aftercall_state_code ns ON ns.state_id=h.new_state
     LEFT JOIN {$member_table} m ON m.mb_no = h.changed_by
         WHERE h.ticket_id={$ticket_id}
      ORDER BY h.changed_at DESC, h.hist_id DESC
         LIMIT 200
    ");
    while ($r = sql_fetch_array($rh)) { $r['kind']='state'; $hist[]=$r; }
    $notes = [];
    $rn = sql_query("
        SELECT n.note_id, n.note_type, n.note_text, n.scheduled_at, n.created_by, n.created_at,
               m.mb_name AS who_name, m.mb_id AS who_id
          FROM call_aftercall_note n
     LEFT JOIN {$member_table} m ON m.mb_no = n.created_by
         WHERE n.ticket_id={$ticket_id}
      ORDER BY n.created_at DESC, n.note_id DESC
         LIMIT 200
    ");
    while ($r = sql_fetch_array($rn)) $notes[] = $r;
    echo json_encode(['success'=>true,'message'=>'saved','ticket'=>$ticket,'history'=>$hist,'notes'=>$notes], JSON_UNESCAPED_UNICODE); exit;
}

/* ==========================
   WHERE (리스트) + 회사/그룹/상담원 필터
   ========================== */
$where = [];
$start_esc = sql_escape_string($start_date.' 00:00:00');
$end_esc   = sql_escape_string($end_date.' 23:59:59');
$where[]   = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";
$where[]   = "sc.is_after_call = 1";

// if ($f_status > 0) $where[] = "l.call_status = {$f_status}";

if ($q !== '' && $q_type !== '') {
    if ($q_type === 'name') {
        $where[] = "t.name LIKE '%".sql_escape_string($q)."%'";
    } elseif ($q_type === 'last4') {
        $q4 = substr(preg_replace('/\D+/', '', $q), -4);
        if ($q4 !== '') $where[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
    } elseif ($q_type === 'full') {
        $hp = preg_replace('/\D+/', '', $q);
        if ($hp !== '') $where[] = "l.call_hp = '".sql_escape_string($hp)."'";
    } elseif ($q_type === 'all') {
        $q_esc = sql_escape_string($q);
        $q4 = substr(preg_replace('/\D+/', '', $q), -4);
        $hp = preg_replace('/\D+/', '', $q);
        $conds = ["t.name LIKE '%{$q_esc}%'"];
        if ($q4 !== '') $conds[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
        if ($hp !== '') $conds[] = "l.call_hp = '".sql_escape_string($hp)."'";
        $where[] = '('.implode(' OR ', $conds).')';
    }
}

// 권한 기반 / 조직 기반 범위
if ($mb_level == 7) {
    $where[] = "l.mb_group = {$my_group}";
} elseif ($mb_level < 7) {
    $where[] = "l.mb_no = {$mb_no}";
} else {
    // 8+
    if ($sel_mb_group > 0) {
        $where[] = "l.mb_group = {$sel_mb_group}";
    } else {
        if ($mb_level >= 9 && $sel_company_id > 0) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        }
        // ★ 추가: 레벨 8은 자기 회사 전체 그룹만
        elseif ($mb_level == 8) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        }
    }
}

// ▼▼▼ 2차콜 상태 필터 추가 ▼▼▼
if ($f_acstate >= 0) {
    if ($f_acstate === 0) {
        // '대기' : 티켓이 없거나(state_id NULL) 0인 경우 포함
        $where[] = "(tk.state_id IS NULL OR tk.state_id = 0)";
    } else {
        $where[] = "tk.state_id = {$f_acstate}";
    }
}
// ▲▲▲ 2차콜 상태 필터 추가 끝 ▲▲▲

if ($sel_agent_no > 0) $where[] = "l.mb_no = {$sel_agent_no}";

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ==========================
   드롭다운/라벨 (상태/2차콜)
   ========================== */
$codes = [];
$rc = sql_query("SELECT call_status, name_ko, status, ui_type FROM call_status_code WHERE mb_group=0 ORDER BY sort_order ASC, call_status ASC");
while ($r = sql_fetch_array($rc)) $codes[] = $r;

$ac_codes = [];
$rac = sql_query("SELECT state_id, name_ko, ui_type, sort_order FROM call_aftercall_state_code WHERE status=1 ORDER BY sort_order ASC, state_id ASC");
while ($r = sql_fetch_array($rac)) $ac_codes[(int)$r['state_id']] = $r;

$status_ui = [];
$rui = sql_query("SELECT call_status, ui_type FROM call_status_code WHERE mb_group=0");
while ($v = sql_fetch_array($rui)) $status_ui[(int)$v['call_status']] = ($v['ui_type'] ?: 'secondary');


/**
 * ========================
 * 회사/그룹/담당자 드롭다운 옵션
 * ========================
 */
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
// 회사 옵션(9+)
$company_options = $build_org_select_options['company_options'];
// 그룹 옵션(8+)
$group_options = $build_org_select_options['group_options'];
// 상담사 옵션(회사/그룹 필터 반영) — 상담원 레벨(3)만
$agent_options = $build_org_select_options['agent_options'];
/**
 * ========================
 * // 회사/그룹/담당자 드롭다운 옵션
 * ========================
 */


/* ==========================
   총 건수
   ========================== */
$sql_cnt = "
    SELECT COUNT(*) AS cnt FROM (
      SELECT ROW_NUMBER() OVER (PARTITION BY l.mb_group, l.campaign_id, l.target_id
                                ORDER BY l.call_start DESC, l.call_id DESC) AS rn
        FROM call_log l
        JOIN call_target t ON t.target_id = l.target_id
        JOIN call_status_code sc ON sc.call_status = l.call_status AND sc.mb_group=0 AND sc.is_after_call=1
        LEFT JOIN call_aftercall_ticket tk
          ON tk.campaign_id = l.campaign_id AND tk.mb_group = l.mb_group AND tk.target_id = l.target_id
        {$where_sql}
    ) x
    WHERE x.rn=1
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

/* ==========================
   상세 목록 (중복 제거: rn=1만)
   ========================== */
$sql_list = "
  SELECT
    b.call_id, b.mb_group, b.campaign_id, b.target_id, b.mb_no AS agent_id,
    b.call_status, b.call_start, b.call_end, b.call_time, b.call_hp,

    COALESCE(g.mv_group_name, CONCAT('그룹 ', b.mb_group))     AS group_name,
    m.mb_name AS agent_name, m.mb_id AS agent_mb_id,
    COALESCE(NULLIF(m.mb_name,''), m.mb_id) AS agent_sort,
    sc.name_ko AS status_label,

    t.name AS target_name, t.birth_date, t.meta_json, t.sex, 
    CASE
      WHEN t.birth_date IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
           - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
    END AS man_age,

    cc.name AS campaign_name,

    COALESCE(tk.state_id,0) AS ac_state_id,
    s.name_ko               AS ac_state_label,
    s.ui_type               AS ac_state_ui,
    s.sort_order            AS ac_state_sort,
    tk.scheduled_at         AS ac_scheduled_at,
    tk.schedule_note        AS ac_schedule_note,
    tk.updated_at           AS ac_updated_at
  FROM (
    SELECT l.*,
           ROW_NUMBER() OVER (PARTITION BY l.mb_group, l.campaign_id, l.target_id
                              ORDER BY l.call_start DESC, l.call_id DESC) AS rn
      FROM call_log l
      JOIN call_target t ON t.target_id = l.target_id
      JOIN call_status_code sc ON sc.call_status = l.call_status AND sc.mb_group=0 AND sc.is_after_call=1
      LEFT JOIN call_aftercall_ticket tk
        ON tk.campaign_id = l.campaign_id AND tk.mb_group = l.mb_group AND tk.target_id = l.target_id
      {$where_sql}
  ) AS b
  JOIN call_target t ON t.target_id = b.target_id
  JOIN call_status_code sc ON sc.call_status=b.call_status AND sc.mb_group=0
  LEFT JOIN {$member_table} m ON m.mb_no = b.mb_no
  LEFT JOIN (
      SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹 ', mb_group))) AS mv_group_name
        FROM {$member_table} WHERE mb_group>0 GROUP BY mb_group
  ) g ON g.mb_group = b.mb_group
  JOIN call_campaign cc ON cc.campaign_id=b.campaign_id AND cc.mb_group=b.mb_group
  LEFT JOIN call_aftercall_ticket tk
    ON tk.campaign_id=b.campaign_id AND tk.mb_group=b.mb_group AND tk.target_id=b.target_id
  LEFT JOIN call_aftercall_state_code s ON s.state_id=COALESCE(tk.state_id,0)
  WHERE b.rn=1
  ORDER BY {$order_sql}
  LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

/* ==========================
   렌더
   ========================== */
$token = get_token();
$g5['title'] = '접수관리';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';
// 정렬 링크 생성용
$qparams_for_sort = $_GET;
unset($qparams_for_sort['sort'], $qparams_for_sort['dir'], $qparams_for_sort['page']);
?>

<style>
.th-sort { text-decoration:none; color:inherit; }
.th-sort:hover { text-decoration:underline; }
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./call_after_list.php" class="form-row" id="searchForm" autocomplete="off">
        <label for="start">기간</label>
        <input type="date" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input">
        <span class="tilde">~</span>
        <input type="date" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input">

        <span class="btn-line">
            <button type="button" class="btn-mini" id="btnYesterday">어제</button>
            <button type="button" class="btn-mini" id="btnToday">오늘</button>
        </span>

        <span class="pipe">|</span>

        <label for="q_type">검색구분</label>
        <select name="q_type" id="q_type">
            <option value="all"  <?php echo $q_type==='all'?'selected':'';?>>전체</option>
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" placeholder="검색어 입력">

        <span class="pipe">|</span>
<?php /*
        <label for="status">상태코드</label>
        <select name="status" id="status">
            <option value="0">전체</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)'); ?>
                </option>
            <?php } ?>
        </select>
        <span class="pipe">|</span>
*/ ?>
        <label for="acstate">2차콜상태</label>
        <select name="acstate" id="acstate">
            <option value="-1" <?php echo $f_acstate<0?'selected':'';?>>전체</option>
            <?php foreach ($ac_codes as $sid=>$code) { ?>
                <option value="<?php echo $sid;?>" <?php echo ($f_acstate===$sid?'selected':'');?>>
                    <?php echo get_text($code['name_ko']); ?>
                </option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql) { ?><a href="./call_after_list.php" class="btn btn_02">초기화</a><?php } ?>

        <span class="row-split"></span>

        <?php if ($mb_level >= 9) { ?>
            <label for="company_id">회사</label>
            <select name="company_id" id="company_id">
                <option value="0"<?php echo $sel_company_id===0?' selected':'';?>>전체 회사</option>
                <?php foreach ($company_options as $c) { ?>
                    <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                        <?php echo get_text($c['company_name']); ?> (그룹 <?php echo (int)$c['group_count']; ?>)
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="company_id" id="company_id" value="<?php echo (int)$sel_company_id; ?>">
        <?php } ?>

        <?php if ($mb_level >= 8) { ?>
            <label for="mb_group">그룹선택</label>
            <select name="mb_group" id="mb_group">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 그룹</option>
                <?php
                if ($group_options) {
                    if ($mb_level >= 9 && $sel_company_id == 0) {
                        $last_cid = null;
                        foreach ($group_options as $g) {
                            if ($last_cid !== (int)$g['company_id']) {
                                echo '<option value="" disabled>── '.get_text($g['company_name']).' ──</option>';
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
            <input type="hidden" name="mb_group" id="mb_group" value="<?php echo (int)$sel_mb_group; ?>">
        <?php } ?>

        <?php if ($sel_mb_group > 0 || $mb_level >= 7) { ?>
        <label for="agent">담당자</label>
        <select name="agent" id="agent">
            <option value="0">전체 담당자</option>
            <?php echo render_agent_options($agent_options, $sel_agent_no); ?>
        </select>
        <?php } ?>
    </form>
</div>

<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <th>그룹명</th>
                <th>아이디</th>
                <th><?php echo sort_th('agent_name','상담원명'); ?></th>
                <th>통화결과</th>
                <th><?php echo sort_th('call_start','통화시작'); ?></th>
                <th><?php echo sort_th('call_end','통화종료'); ?></th>
                <th>통화시간</th>
                <th><?php echo sort_th('target_name','고객명'); ?></th>
                <th>생년월일</th>
                <th><?php echo sort_th('man_age','만나이'); ?></th>
                <th>추가정보</th>
                <th><?php echo sort_th('call_hp','전화번호'); ?></th>
                <th><?php echo sort_th('ac_state','처리상태'); ?></th>
                <th><?php echo sort_th('scheduled_at','일정'); ?></th>
                <th><?php echo sort_th('ac_updated_at','최근처리시간'); ?></th>
                <th>캠페인명</th>
                <th>작업</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="17" class="empty_table">데이터가 없습니다。</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                // ★ 접수관리: is_open_number와 무관 — 항상 번호 노출
                $hp_fmt   = format_korean_phone($row['call_hp']);
                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);
                $agent    = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];

                $status_label = $row['status_label'] ?: ('코드 '.$row['call_status']);
                $ui_call = !empty($status_ui[$row['call_status']]) ? $status_ui[$row['call_status']] : 'secondary';

                $ac_label = $row['ac_state_label'] ?: '대기';
                $ac_ui    = $row['ac_state_ui'] ?: 'secondary';

                $bday = empty($row['birth_date']) ? '-' : substr(get_text($row['birth_date']), 2, 8);
                $man_age = is_null($row['man_age']) ? '-' : ((int)$row['man_age']).'세';

                // 추가 정보 표시
                $sex_txt = '';
                if ((int)$row['sex'] === 1) $sex_txt = '남성';
                elseif ((int)$row['sex'] === 2) $sex_txt = '여성';                
                $meta_json = $row['meta_json'];
                $meta_txt  = '';
                if ($sex_txt !== '') $meta_txt .= $sex_txt;
                if (is_array($meta_json) && !empty($meta_json)) {
                    if ($meta_txt !== '') $meta_txt .= ', ';
                    $meta_txt .= implode(', ', $meta_json);
                }

                $ac_time  = $row['ac_updated_at'] ? fmt_datetime(get_text($row['ac_updated_at']), 'mdhis') : '-';
                $schedule_disp = format_schedule_display($row['ac_scheduled_at'], $row['ac_schedule_note']);
                ?>
                <tr>
                    <td><?php echo get_text($row['group_name']); ?></td>
                    <td><?php echo get_text($row['agent_mb_id']); ?></td>
                    <td><?php echo get_text($agent); ?></td>
                    <td class="status-col status-<?php echo get_text($ui_call); ?>"><?php echo get_text($status_label); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_start']), 'mdhi'); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_end']), 'mdhi'); ?></td>
                    <td><?php echo $call_sec; ?></td>
                    <td><?php echo get_text($row['target_name'] ?: '-'); ?></td>
                    <td><?php echo $bday; ?></td>
                    <td><?php echo $man_age; ?></td>
                    <td><?php echo $meta_txt; ?></td>
                    <td><?php echo get_text($hp_fmt); ?></td>
                    <td class="status-col status-<?php echo get_text($ac_ui); ?>"><?php echo get_text($ac_label); ?></td>
                    <td class="small_txt"><?php echo $schedule_disp; ?></td>
                    <td><?php echo $ac_time; ?></td>
                    <td class="small_txt"><?php echo get_text($row['campaign_name'] ?: '-'); ?></td>
                    <td class="btn_one">
                        <button type="button"
                                class="btn btn_02 ac-edit-btn"
                                data-campaign-id="<?php echo (int)$row['campaign_id'];?>"
                                data-mb-group="<?php echo (int)$row['mb_group'];?>"
                                data-target-id="<?php echo (int)$row['target_id'];?>"
                                data-target-name="<?php echo get_text($row['target_name'] ?: '-');?>"
                                data-call-hp="<?php echo get_text($hp_fmt);?>"
                                data-state-id="<?php echo (int)$row['ac_state_id'];?>"
                                data-birth="<?php echo $bday; ?>"
                                data-age="<?php echo is_null($row['man_age'])?'':(int)$row['man_age']; ?>"
                                data-meta="<?php echo $meta_txt; ?>"
                                >
                            처리
                        </button>
                    </td>
                </tr>
                <?php
            }
        }
        ?>
        </tbody>
    </table>
</div>

<?php
$total_page = max(1, (int)ceil($total_count / $page_rows));
$qstr = $_GET; unset($qstr['page']);
$base = './call_after_list.php?'.http_build_query($qstr);
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

<!-- 오버레이 & 중앙 모달 (기존) -->
<div id="acOverlay" class="ac-overlay" hidden></div>
<div class="ac-panel" id="acPanel" aria-hidden="true" hidden>
  <div class="ac-panel__head">
    <strong>2차콜 처리</strong>
    <button type="button" class="ac-panel__close" id="acClose" aria-label="닫기">×</button>
  </div>
  <div class="ac-panel__body">
    <div class="ac-summary">
      <div><b id="s_target_name">-</b> / <span id="s_hp"></span> / 생년월일: <span id="s_birth">-</span> / 만나이: <span id="s_age">-</span></div>
      <div>추가정보: <span id="s_meta">-</span></div>
    </div>

    <form id="acForm" method="post" action="./call_after_list.php" autocomplete="off">
      <input type="hidden" name="ajax" value="save">
      <input type="hidden" name="token" value="<?php echo get_token();?>">
      <input type="hidden" name="campaign_id" id="f_campaign_id" value="">
      <input type="hidden" name="mb_group" id="f_mb_group" value="">
      <input type="hidden" name="target_id" id="f_target_id" value="">
      <input type="hidden" name="schedule_clear" id="f_schedule_clear" value="0">

      <div class="ac-field">
        <label for="f_state_id">처리상태</label>
        <select name="state_id" id="f_state_id" class="ac-input">
          <?php foreach ($ac_codes as $sid=>$code) { ?>
            <option value="<?php echo $sid; ?>"><?php echo get_text($code['name_ko']); ?></option>
          <?php } ?>
        </select>
      </div>

      <div class="ac-field">
        <label>일정 메모</label>
        <div class="ac-inline">
            <input type="date" name="schedule_date" id="f_schedule_date" class="frm_input">
            <input type="time" name="schedule_time" id="f_schedule_time" class="frm_input" step="60">
            <input type="text" name="schedule_note" id="f_schedule_note" class="ac-input frm_input" placeholder="예) 재통화(점심시간 피해서)">
            <button type="button" class="btn btn-mini2" id="btnSchedToday">오늘</button>
            <button type="button" class="btn btn-mini2" id="btnSchedTomorrow">내일</button>
            <button type="button" class="btn btn-mini2" id="btnSchedClear">일정삭제</button>
        </div>
      </div>

      <div class="ac-field">
        <label for="f_memo">처리 메모</label>
        <textarea name="memo" id="f_memo" class="ac-input" rows="4" placeholder="고객 메모를 입력하세요. (저장 시 작성자/시간과 함께 1행으로 기록됩니다)"></textarea>
      </div>

      <div class="ac-actions">
        <button type="submit" class="btn btn_01">저장</button>
        <button type="button" class="btn btn_02" id="acCancel">닫기</button>
      </div>

      <div class="ac-field">
        <label>활동 로그</label>
        <div id="f_timeline" class="ac-timeline"></div>
      </div>
    </form>
  </div>
</div>

<script>
// 날짜 버튼
function pad2(n){ return (n<10?'0':'')+n; }
function fmt(d){ return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); }
(function(){
  var $start = document.getElementById('start');
  var $end   = document.getElementById('end');
  var $form  = document.getElementById('searchForm');
  var btnYesterday = document.getElementById('btnYesterday');
  var btnToday = document.getElementById('btnToday');

  function setActive(){
    var start = $start.value, end = $end.value;
    var today = fmt(new Date());
    var yd = new Date(); yd.setDate(yd.getDate()-1);
    var yest = fmt(yd);
    btnYesterday.classList.toggle('active', start===yest && end===yest);
    btnToday.classList.toggle('active', start===today && end===today);
  }
  setActive();
  btnYesterday.addEventListener('click', function(){
    var now = new Date(); now.setDate(now.getDate()-1);
    var y = fmt(now); $start.value = y; $end.value = y; setActive(); $form.submit();
  });
  btnToday.addEventListener('click', function(){
    var now = new Date(); var t = fmt(now);
    $start.value = t; $end.value = t; setActive(); $form.submit();
  });

  // 그룹/상담원 자동 제출
  var mbGroup = document.getElementById('mb_group');
  if (mbGroup) mbGroup.addEventListener('change', function(){
    var agent = document.getElementById('agent'); if (agent) agent.selectedIndex = 0;
    $form.submit();
  });
  var agentSel = document.getElementById('agent');
  if (agentSel) agentSel.addEventListener('change', function(){ $form.submit(); });

  // 회사→그룹 비동기(9+만)
  var companySel = document.getElementById('company_id');
  if (companySel) {
    companySel.addEventListener('change', function(){
      var groupSel = document.getElementById('mb_group');
      if (!groupSel) return;
      groupSel.innerHTML = '<option value="">로딩 중...</option>';
      // 상담원 초기화
      var agent = document.getElementById('agent'); if (agent) agent.selectedIndex = 0;

      fetch('./ajax_group_options.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ company_id: parseInt(this.value||'0',10)||0 }),
        credentials: 'same-origin'
      })
      .then(function(res){ if(!res.ok) throw new Error('네트워크 오류'); return res.json(); })
      .then(function(json){
        if (!json.success) throw new Error(json.message || '가져오기 실패');
        var opts = [];
        opts.push(new Option('전체 그룹', 0));
        json.items.forEach(function(item){
          if (item.separator) {
            var sep = document.createElement('option');
            sep.textContent = '── ' + item.separator + ' ──';
            sep.disabled = true;
            opts.push(sep);
          } else {
            opts.push(new Option(item.label, item.value));
          }
        });
        groupSel.innerHTML = '';
        opts.forEach(function(o){ groupSel.appendChild(o); });
        groupSel.value = '0'; // 회사 변경 시 전체 그룹 유지
      })
      .catch(function(err){
        alert('그룹 목록을 불러오지 못했습니다: ' + err.message);
        groupSel.innerHTML = '<option value="0">전체 그룹</option>';
      });
    });
  }
  // 모달/단건 조회/저장/타임라인 렌더
  var panel   = document.getElementById('acPanel');
  var overlay = document.getElementById('acOverlay');
  var btnClose= document.getElementById('acClose');
  var btnCancel=document.getElementById('acCancel');

  function openPanel(){ panel.hidden=false; overlay.hidden=false; panel.setAttribute('aria-hidden','false'); document.body.classList.add('ac-open'); }
  function closePanel(){ panel.hidden=true; overlay.hidden=true; panel.setAttribute('aria-hidden','true'); document.body.classList.remove('ac-open'); }
  if (btnClose)  btnClose.addEventListener('click', closePanel);
  if (btnCancel) btnCancel.addEventListener('click', closePanel);
  if (overlay)   overlay.addEventListener('click', closePanel);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePanel(); });

  // 타임라인 렌더
  function renderTimeline(history, notes){
    var el = document.getElementById('f_timeline');
    el.innerHTML = '';

    // 합치고 정렬
    var items = [];
    (history||[]).forEach(function(h){
      items.push({
        t: new Date(h.changed_at.replace(' ','T')),
        time: h.changed_at,
        who: h.who_name || h.who_id || h.changed_by,
        kind: 'state',
        text: (h.prev_label || (h.prev_state==null?'-':h.prev_state))+' → '+(h.new_label || h.new_state)
      });
    });
    (notes||[]).forEach(function(n){
      items.push({
        t: new Date(n.created_at.replace(' ','T')),
        time: n.created_at,
        who: n.who_name || n.who_id || n.created_by,
        kind: n.note_type,
        text: n.note_type==='schedule'
              ? ((n.scheduled_at? (n.scheduled_at.substring(5,16)+' / ') : '') + (n.note_text||''))
              : (n.note_text||'')
      });
    });
    items.sort(function(a,b){ return b.t - a.t; });

    if (!items.length) {
      var none = document.createElement('div');
      none.className='ac-timeline__item';
      none.innerHTML = '<div class="ac-timeline__time">-</div><div class="ac-timeline__body small-muted">로그가 없습니다.</div>';
      el.appendChild(none);
      return;
    }

    items.forEach(function(it){
      var row = document.createElement('div'); row.className='ac-timeline__item';
      var badgeClass = it.kind==='state' ? 'ac-badge ac-badge--state' : (it.kind==='schedule'?'ac-badge ac-badge--sched':'ac-badge ac-badge--note');
      var typeLabel  = it.kind==='state' ? '상태' : (it.kind==='schedule'?'일정':'메모');

      var time = document.createElement('div'); time.className='ac-timeline__time'; time.textContent = it.time;
      var body = document.createElement('div'); body.className='ac-timeline__body';
      body.innerHTML = '<span class="'+badgeClass+'">'+typeLabel+'</span>'
                     + '<b>'+ (it.who||'') +'</b> · '
                     + (it.text ? (it.text+'') : '');
      row.appendChild(time); row.appendChild(body);
      el.appendChild(row);
    });
  }

  // 팝업 열기
  document.querySelectorAll('.ac-edit-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var campaign_id = this.getAttribute('data-campaign-id');
      var mb_group    = this.getAttribute('data-mb-group');
      var target_id   = this.getAttribute('data-target-id');
      var targetName  = this.getAttribute('data-target-name') || '';
      var hp          = this.getAttribute('data-call-hp') || '';
      var state_id    = this.getAttribute('data-state-id') || 0;

      // 고객 요약
      document.getElementById('s_target_name').textContent = targetName || '-';
      document.getElementById('s_hp').textContent = hp || '';
      document.getElementById('s_birth').textContent = this.getAttribute('data-birth') || '-';
      var age = this.getAttribute('data-age') || '';
      document.getElementById('s_age').textContent = (age!==''? (age+'세') : '-');
      document.getElementById('s_meta').textContent = this.getAttribute('data-meta') || '-';

      // 폼 기본값
      document.getElementById('f_campaign_id').value = campaign_id;
      document.getElementById('f_mb_group').value    = mb_group;
      document.getElementById('f_target_id').value   = target_id;
      document.getElementById('f_state_id').value    = state_id;
      document.getElementById('f_memo').value        = '';
      document.getElementById('f_schedule_date').value = '';
      document.getElementById('f_schedule_time').value = '';
      document.getElementById('f_schedule_note').value = '';
      document.getElementById('f_schedule_clear').value = '0';
      renderTimeline([],[]);

      // 티켓/이력/노트 로드
      var url = new URL('./call_after_list.php', location.href);
      url.searchParams.set('ajax','get');
      url.searchParams.set('campaign_id', campaign_id);
      url.searchParams.set('mb_group', mb_group);
      url.searchParams.set('target_id', target_id);
      fetch(url.toString(), {credentials:'same-origin'})
        .then(r=>r.json())
        .then(j=>{
          if (j && j.success) {
            var t = j.ticket || {};
            if (typeof t.state_id !== 'undefined' && t.state_id !== null)
              document.getElementById('f_state_id').value = t.state_id;

            // 일정 값 프리필
            if (t.scheduled_at) {
              // YYYY-MM-DD HH:MM:SS
              var d = t.scheduled_at.split(' ');
              if (d[0]) document.getElementById('f_schedule_date').value = d[0];
              if (d[1]) document.getElementById('f_schedule_time').value = d[1].slice(0,5);
            }
            if (t.schedule_note) document.getElementById('f_schedule_note').value = t.schedule_note;

            renderTimeline(j.history || [], j.notes || []);
          }
        })
        .catch(console.error);

      openPanel();
    });
  });

  // 일정 퀵버튼
  function setDateInput(offsetDays){
    var iDate = document.getElementById('f_schedule_date');
    var d = new Date();
    d.setDate(d.getDate() + offsetDays);
    var y = d.getFullYear(), m = (d.getMonth()+1+'').padStart(2,'0'), day = (d.getDate()+'').padStart(2,'0');
    iDate.value = y+'-'+m+'-'+day;
  }
  var btnToday = document.getElementById('btnSchedToday');
  var btnTomorrow = document.getElementById('btnSchedTomorrow');
  var btnClear = document.getElementById('btnSchedClear');
  if (btnToday) btnToday.addEventListener('click', function(){ setDateInput(0); if(!document.getElementById('f_schedule_time').value) document.getElementById('f_schedule_time').value='14:00'; });
  if (btnTomorrow) btnTomorrow.addEventListener('click', function(){ setDateInput(1); if(!document.getElementById('f_schedule_time').value) document.getElementById('f_schedule_time').value='10:00'; });
  if (btnClear) btnClear.addEventListener('click', function(){
      document.getElementById('f_schedule_date').value='';
      document.getElementById('f_schedule_time').value='';
      document.getElementById('f_schedule_note').value='';
      document.getElementById('f_schedule_clear').value='1';
  });

  // 저장
  var form = document.getElementById('acForm');
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(form);
    fetch('./call_after_list.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(r=>r.json())
      .then(j=>{
        if (j && j.success) {
          // 타임라인 갱신
          renderTimeline(j.history || [], j.notes || []);
          // 리스트 최신 반영 위해 새로고침
          location.reload();
        } else {
          alert('저장 실패: '+(j && j.message ? j.message : ''));
        }
      })
      .catch(err=>{ console.error(err); alert('저장 중 오류'); });
  });

  // 닫기
  var btnCancel = document.getElementById('acCancel');
  if (btnCancel) btnCancel.addEventListener('click', function(){ closePanel(); });

})();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
