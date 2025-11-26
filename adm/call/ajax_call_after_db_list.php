<?php
// /adm/call/call_after_list.php
$sub_menu = '700400';
require_once './_common.php';

// 접근 권한: 관리자 레벨 5 이상
if ($is_admin !== 'super' && (int)$member['mb_level'] < 5) {
    die('접근 권한이 없습니다~!'.$member['mb_level']);
}

$mb_no          = (int)($member['mb_no'] ?? 0);
$mb_level       = (int)($member['mb_level'] ?? 0);
$my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;
$member_table   = $g5['member_table'];

// ===== 조직 선택 파라미터 (회사/지점/상담원) =====
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
$sel_agent_no       = (int)($_GET['agent'] ?? 0);
$sel_after_agent_no = (int)(_g('ac_agent', 0));

/* ==========================
   AJAX: 단건 조회, 저장, 후보목록
   ========================== */
if (isset($_GET['ajax']) && $_GET['ajax']==='get') {
    $target_id   = (int)($_GET['target_id'] ?? 0);
    $campaign_id = (int)($_GET['campaign_id'] ?? 0);
    $mb_group    = (int)($_GET['mb_group'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    if ($target_id<=0 || $campaign_id<=0 || $mb_group<=0) { echo json_encode(['success'=>false,'message'=>'invalid'], JSON_UNESCAPED_UNICODE); exit; }
    if ($mb_level <= 7 && $mb_group !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$mb_group} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }
    if ($mb_level == 5) {
        $own = sql_fetch("SELECT 1 FROM call_aftercall_ticket WHERE target_id = {$target_id} and assigned_after_mb_no = {$mb_no} LIMIT 1 ");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied/3']); exit; }
    } else if ($mb_level < 7) {
        $own = sql_fetch("SELECT 1 FROM call_log WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} AND mb_no={$mb_no} LIMIT 1");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }
    $ticket = sql_fetch("SELECT t.ticket_id, t.state_id, t.scheduled_at, t.schedule_note, t.updated_by, t.updated_at, t.assigned_after_mb_no FROM call_aftercall_ticket t WHERE t.mb_group={$mb_group} AND t.campaign_id={$campaign_id} AND t.target_id={$target_id} LIMIT 1");
    if (!$ticket) $ticket = ['ticket_id'=>null,'state_id'=>0,'scheduled_at'=>null,'schedule_note'=>null,'updated_by'=>null,'updated_at'=>null,'assigned_after_mb_no'=>0];
    $hist = [];
    if (!empty($ticket['ticket_id'])) {
        $rh = sql_query("
            SELECT h.prev_state, h.new_state, h.memo, h.changed_by, h.changed_at,
                   ps.name_ko AS prev_label, ns.name_ko AS new_label,
                   m.mb_name AS who_name, m.mb_id AS who_id,
                   h.assigned_after_mb_no,
                   ma.mb_name AS after_name,
                   ma.mb_id   AS after_id
              FROM call_aftercall_history h
         LEFT JOIN call_aftercall_state_code ps ON ps.state_id=h.prev_state
         LEFT JOIN call_aftercall_state_code ns ON ns.state_id=h.new_state
         LEFT JOIN {$member_table} m  ON m.mb_no = h.changed_by
         LEFT JOIN {$member_table} ma ON ma.mb_no = h.assigned_after_mb_no
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

/* ==========================
   AJAX: 2차담당자 후보 목록 (해당 지점만)
   ========================== */
if (isset($_GET['ajax']) && $_GET['ajax']==='agents') {
    header('Content-Type: application/json; charset=utf-8');
    $q_grp = (int)($_GET['mb_group'] ?? 0);

    // 지점은 필수. 없으면 빈목록.
    if ($q_grp <= 0) { echo json_encode(['success'=>true,'rows'=>[]]); exit; }

    // 권한 범위 체크
    if ($mb_level == 7 && $q_grp !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied']); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$q_grp} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) { echo json_encode(['success'=>false,'message'=>'denied']); exit; }
    }

    // 해당 '지점'의 레벨5/7만
    $sql = "SELECT m.mb_no, m.mb_id, m.mb_name, COALESCE(m.is_after_call,0) AS is_after_call
              FROM {$member_table} m
             WHERE m.mb_group = {$q_grp}
               AND m.mb_level IN (5,7)
             ORDER BY (m.mb_name<>''), m.mb_name, m.mb_id";
    $rows = [];
    $rs = sql_query($sql);
    while($r=sql_fetch_array($rs)) $rows[]=$r;
    echo json_encode(['success'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE); exit;
}

// 저장
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
    $assigned_after_mb_no = (int)($_POST['assigned_after_mb_no'] ?? 0);

    // 레벨7 이상만 변경 허용, 5레벨은 입력을 무시
    if ($mb_level < 7) {
        $assigned_after_mb_no = null; // 업데이트/인서트 시 그대로 유지
    } else {
        // 같은 지점 + 레벨(5,7)만 유효
        $valid = ($assigned_after_mb_no===0) ? true :
            sql_fetch("SELECT 1 FROM {$member_table} m WHERE m.mb_no={$assigned_after_mb_no} AND m.mb_level IN (5,7) AND m.mb_group={$mb_group} LIMIT 1");
        if (!$valid) $assigned_after_mb_no = 0; // 범위 벗어나면 미지정
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($campaign_id<=0 || $mb_group<=0 || $target_id<=0) { echo json_encode(['success'=>false,'message'=>'invalid']); exit; }
    if ($mb_level == 7 && $mb_group !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied/1']); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$mb_group} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) { echo json_encode(['success'=>false,'message'=>'denied/2']); exit; }
    }
    if ($mb_level == 5) {
        $own = sql_fetch("SELECT 1 FROM call_aftercall_ticket WHERE target_id = {$target_id} and assigned_after_mb_no = {$mb_no} LIMIT 1 ");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied/3']); exit; }
    } else if ($mb_level < 7) {
        $own = sql_fetch("SELECT 1 FROM call_log WHERE mb_group={$mb_group} AND target_id={$target_id} AND mb_no={$mb_no} LIMIT 1");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied/4']); exit; }
    }

    $last = sql_fetch("SELECT call_id FROM call_log WHERE mb_group={$mb_group} AND target_id={$target_id} ORDER BY call_start DESC, call_id DESC LIMIT 1");
    $last_call_id = (int)($last['call_id'] ?? 0);
    $rowPrev = sql_fetch("SELECT ticket_id, state_id, scheduled_at, schedule_note, assigned_after_mb_no FROM call_aftercall_ticket WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} LIMIT 1");
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

    // 담당자 변경 여부(레벨7 이상만 의미)
    $assign_changed   = ($mb_level >= 7)
                        && !is_null($assigned_after_mb_no)
                        && (int)($rowPrev['assigned_after_mb_no'] ?? 0) !== (int)$assigned_after_mb_no;

    // SET 절 (콤마 처리 주의)
    $set_after_sql = '';
    if ($mb_level >= 7) {
        $set_after_sql = "assigned_after_mb_no = ".(is_null($assigned_after_mb_no) ? "assigned_after_mb_no" : (int)$assigned_after_mb_no);
    }

    sql_query("START TRANSACTION");
    if ($rowPrev) {
        $ticket_id = (int)$rowPrev['ticket_id'];
        $ok = sql_query("
            UPDATE call_aftercall_db_info
               SET state_id={$new_state_id},
                   scheduled_at=".($scheduled_at ? "'".sql_escape_string($scheduled_at)."'" : "NULL").",
                   schedule_note=".($schedule_note!=='' ? "'".sql_escape_string($schedule_note)."'" : "NULL").",
                   last_call_id=".($last_call_id ?: "NULL").",
                   updated_by={$mb_no},
                   updated_at=NOW()"
                   .($set_after_sql ? ", ".$set_after_sql : "")."
             WHERE ticket_id={$ticket_id}
             LIMIT 1
        ", true);
        if (!$ok) { sql_query("ROLLBACK"); echo json_encode(['success'=>false,'message'=>'update failed']); exit; }

    } else {
        $cols_after = $mb_level>=7 ? ', assigned_after_mb_no' : '';
        $vals_after = $mb_level>=7 ? ', '.(int)$assigned_after_mb_no : '';
        $ok = sql_query("
            INSERT INTO call_aftercall_db_info
                (campaign_id, mb_group, target_id, last_call_id, state_id, memo, scheduled_at, schedule_note, updated_by, updated_at, created_at{$cols_after})
            VALUES
                ({$campaign_id}, {$mb_group}, {$target_id}, ".($last_call_id ?: "NULL").",
                 {$new_state_id}, NULL,
                 ".($scheduled_at ? "'".sql_escape_string($scheduled_at)."'" : "NULL").",
                 ".($schedule_note!=='' ? "'".sql_escape_string($schedule_note)."'" : "NULL").",
                 {$mb_no}, NOW(), NOW(){$vals_after})
        ", true);
        if (!$ok) { sql_query("ROLLBACK"); echo json_encode(['success'=>false,'message'=>'insert failed']); exit; }
        $ticket_id = (int)sql_insert_id();
    }
    echo json_encode(['success'=>true,'message'=>'saved','ticket'=>$ticket,'history'=>$hist,'notes'=>$notes], JSON_UNESCAPED_UNICODE); exit;
}
