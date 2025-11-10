<?php
if (!defined('_GNUBOARD_')) exit;

/**
 * call.assign.lib.php
 * - 미배정 큐에서 N건을 원자적으로 픽 → 특정 상담원(mb_no)에게 배정 → 이력 적재
 * - MySQL 8.x의 SKIP LOCKED 사용 가능 (옵션)
 * - 테이블 접두어는 질문과 동일하게 call_ 사용: call_target, call_assignment
 *
 * 필요 인덱스(권장):
 *   CREATE INDEX idx_target_pick_queue ON call_target (mb_group, campaign_id, assigned_status, target_id);
 *   CREATE INDEX idx_target_my_queue   ON call_target (mb_group, campaign_id, assigned_mb_no, assigned_status, assigned_at);
 * 
 * 2차콜 배정도 추가
 * 
 */


/**
 * N건 배정(트랜잭션) - 블랙리스트 제외 포함
 *
 * @param int   $mb_group
 * @param int   $mb_no
 * @param int   $need
 * @param int   $lease_min
 * @param int   $batch_id
 * @param array $opts                   // ['use_skip_locked'=>true, 'assigned_status_to'=>1, 'assigned_status_filter'=>0, 'order'=>'target_id', 'where_extra'=>null, 'exclude_blacklist'=>true, 'exclude_dnc_flag'=>true]
 * @param int   $campaign_id            // 0이면 특정 캠페인 미지정 (활성 캠페인만)
 * @return array                        // ['ok'=>bool, 'picked'=>int, 'ids'=>[...], 'err'=>string|null]
 */
function call_assign_pick_and_lock($mb_group, $mb_no, $need, $lease_min, $batch_id, $opts = [], $campaign_id=0) {
    $use_skip_locked        = isset($opts['use_skip_locked']) ? (bool)$opts['use_skip_locked'] : true;
    $assigned_status_to     = isset($opts['assigned_status_to']) ? (int)$opts['assigned_status_to'] : 1;  // 배정(통화전)
    $assigned_status_filter = isset($opts['assigned_status_filter']) ? (int)$opts['assigned_status_filter'] : 0; // 미배정
    $order_col              = isset($opts['order']) ? $opts['order'] : 'target_id';
    $where_extra            = isset($opts['where_extra']) ? trim($opts['where_extra']) : null;

    // ★ 변경: 기본값으로 블랙리스트/내부 DNC(do_not_call) 제외 옵션 추가
    $exclude_blacklist      = array_key_exists('exclude_blacklist', $opts) ? (bool)$opts['exclude_blacklist'] : true;
    $exclude_dnc_flag       = array_key_exists('exclude_dnc_flag', $opts) ? (bool)$opts['exclude_dnc_flag'] : false;

    // 안전 캐스팅
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $mb_no       = (int)$mb_no;
    $n           = max(0, (int)$need);
    $lease_min   = max(1, (int)$lease_min);
    $batch_id    = (int)$batch_id;

    if ($n <= 0) {
        return ['ok'=>true, 'picked'=>0, 'ids'=>[], 'err'=>null];
    }

    // ★ 변경: 회사ID 캐시 헬퍼 사용 (블랙리스트 조회용)
    $company_id = (int)get_company_id_from_group_id_cached($mb_group);

    // 임시테이블은 세션 범위. 동시 호출 대비로 유니크 네임
    $tmp = 'tmp_pick_' . substr(md5(uniqid('', true)), 0, 8);

    try {
        sql_query("START TRANSACTION");

        // 임시 테이블
        sql_query("DROP TEMPORARY TABLE IF EXISTS {$tmp}");
        sql_query("CREATE TEMPORARY TABLE {$tmp} (target_id BIGINT PRIMARY KEY) ENGINE=MEMORY");

        // WHERE 추가 구성
        $where_extra_sql = '';
        if ($where_extra) {
            // 개발자가 직접 안전한 조건만 넣도록 가정 (ex: "AND do_not_call=0")
            $where_extra_sql = "\n      " . $where_extra;
        }

        $where_campaign = '';
        if ($campaign_id > 0) {
            $where_campaign = " AND t.campaign_id = {$campaign_id} ";
        }

        // ★ 변경: 블랙리스트 및 do_not_call 제외 조건 생성
        $where_guard_sql = '';
        if ($exclude_dnc_flag) {
            $where_guard_sql .= "\n      AND t.do_not_call = 0";
        }
        if ($exclude_blacklist && $company_id > 0) {
            // call_blacklist(company_id, call_hp) 유니크 인덱스 활용
            $where_guard_sql .= "\n      AND NOT EXISTS (
                    SELECT 1
                      FROM call_blacklist b
                     WHERE b.company_id = {$company_id}
                       AND b.call_hp    = t.call_hp
                )";
        }

        // 1) 후보 픽 (잠금 포함) - 활성 캠페인(status=1)만
        $pick_sql = "INSERT INTO {$tmp} (target_id)
            SELECT t.target_id
              FROM call_target AS t
              JOIN call_campaign AS c
                ON c.campaign_id = t.campaign_id
               AND c.mb_group    = t.mb_group
               AND c.status      = 1
             WHERE t.mb_group = {$mb_group}
               AND t.assigned_status = {$assigned_status_filter}
               {$where_campaign}
               {$where_guard_sql}
               {$where_extra_sql}
             ORDER BY t.{$order_col}
             LIMIT {$n}
        ";

        if ($use_skip_locked) {
            // MySQL 8.x+: 경합 회피
            $pick_sql .= " FOR UPDATE SKIP LOCKED";
        }
        sql_query($pick_sql);

        // 픽된 수 확인
        $row = sql_fetch("SELECT COUNT(*) AS cnt FROM {$tmp}");
        $picked = (int)$row['cnt'];
        if ($picked === 0) {
            sql_query("ROLLBACK");
            return ['ok'=>true, 'picked'=>0, 'ids'=>[], 'err'=>null];
        }

        // 2) 상태 업데이트(배정)
        $status_guard = $use_skip_locked ? '' : "AND t.assigned_status = {$assigned_status_filter}";

        $upd_sql = "UPDATE call_target AS t
            JOIN {$tmp} AS p ON p.target_id = t.target_id
               SET t.assigned_status     = {$assigned_status_to},
                   t.assigned_mb_no      = {$mb_no},
                   t.assigned_at         = NOW(),
                   t.assign_lease_until  = DATE_ADD(NOW(), INTERVAL {$lease_min} MINUTE),
                   t.assign_batch_id     = {$batch_id}
             {$status_guard}
        ";
        sql_query($upd_sql);

        // 실제 배정된 수 재확인
        $row2 = sql_fetch("
            SELECT COUNT(*) AS cnt
              FROM call_target t
              JOIN {$tmp} p ON p.target_id = t.target_id
             WHERE t.assigned_status = {$assigned_status_to}
               AND t.assigned_mb_no  = {$mb_no}
               AND t.assign_batch_id = {$batch_id}
        ");
        $picked2 = (int)$row2['cnt'];
        if ($picked2 === 0) {
            sql_query("ROLLBACK");
            return ['ok'=>false, 'picked'=>0, 'ids'=>[], 'err'=>'경합으로 배정 실패(다시 시도)'];
        }

        // 3) 배정 이력 적재
        $ins_hist_sql = "
            INSERT INTO call_assignment (campaign_id, mb_group, target_id, mb_no, assigned_at, status)
            SELECT t.campaign_id, {$mb_group}, p.target_id, {$mb_no}, NOW(), 1
              FROM {$tmp} AS p
              JOIN call_target AS t ON t.target_id = p.target_id
        ";
        sql_query($ins_hist_sql);

        sql_query("COMMIT");

        // 결과 id 목록
        $ids = [];
        $q = sql_query("SELECT target_id FROM {$tmp} ORDER BY target_id");
        while ($r = sql_fetch_array($q)) {
            $ids[] = (int)$r['target_id'];
        }

        return ['ok'=>true, 'picked'=>$picked2, 'ids'=>$ids, 'err'=>null];

    } catch (Exception $e) {
        @sql_query("ROLLBACK");
        return ['ok'=>false, 'picked'=>0, 'ids'=>[], 'err'=>$e->getMessage()];
    }
}


/**
 * 리스 만료 자동 회수
 * - assigned_status 가 1(배정) 또는 2(진행중)이고, assign_lease_until < NOW() 이면 회수(0) 처리
 * - 1(배정) 상태만 회수 처리하자
 */
function call_assign_release_expired($mb_group, $campaign_id=0) {
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $where_campagin = '';
    if($campaign_id > 0) {
        $where_campagin = " AND campaign_id = {$campaign_id} ";
    }

    $sql = "UPDATE call_target
        SET assigned_status    = 0,
            assigned_mb_no     = NULL,
            assigned_at        = NULL,
            assign_lease_until = NULL,
            assign_batch_id    = NULL
        WHERE mb_group = {$mb_group}
          {$where_campagin}
          AND assigned_status = 1
          AND assign_lease_until IS NOT NULL
          AND assign_lease_until < NOW()
        LIMIT 100;
    ";
    $res = sql_query($sql);
    return (bool)$res;
}

/**
 * 내 큐 조회(배정/진행중 목록)
 * 블랙리스트 제외 + 내부 DNC(do_not_call=1) 제외
 * @return array of rows
 */
function call_assign_list_my_queue($mb_group, $mb_no, $limit=5, $campaign_id=0, $assigned_status='1,2') {
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $mb_no       = (int)$mb_no;
    $limit       = max(1, (int)$limit);

    $rows = [];
    $where_campaign = '';
    if ($campaign_id > 0) {
        $where_campaign = " AND t.campaign_id = {$campaign_id} ";
    }

    // ★ 회사 ID 캐시 조회
    $company_id = (int)get_company_id_from_group_id_cached($mb_group);

    // ★ 블랙리스트 및 DNC 제외 조건 추가
    $where_guard = '';
    if ($company_id > 0) {
        $where_guard .= " AND NOT EXISTS (
            SELECT 1
              FROM call_blacklist b
             WHERE b.company_id = {$company_id}
               AND b.call_hp    = t.call_hp
        )";
    }
    // $where_guard .= " AND t.do_not_call = 0";

    $sql = "SELECT t.*
              FROM call_target t
             WHERE t.mb_group = {$mb_group}
               {$where_campaign}
               AND t.assigned_mb_no = {$mb_no}
               AND t.assigned_status IN ({$assigned_status})
               {$where_guard}
          ORDER BY t.assigned_at ASC, t.target_id ASC
             LIMIT {$limit}";

    $q = sql_query($sql);
    while ($r = sql_fetch_array($q)) {
        $rows[] = $r;
    }
    return $rows;
}


/**
 * 내 큐 개수(배정 상태/캠페인/리스 유효 기준)
 * 블랙리스트 제외 + 내부 DNC(do_not_call=1) 제외
 */
function call_assign_count_my_queue($mb_group, $mb_no, $campaign_id=0, $assigned_status='1', $only_valid_lease=false) {
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $mb_no       = (int)$mb_no;

    $lease_cond = $only_valid_lease ? " AND t.assign_lease_until > NOW() " : "";
    $where_campaign = $campaign_id > 0 ? " AND t.campaign_id = {$campaign_id} " : "";

    // ★ 회사 ID 캐시 조회
    $company_id = (int)get_company_id_from_group_id_cached($mb_group);

    // ★ 블랙리스트 및 DNC 제외 조건 추가
    $where_guard = '';
    if ($company_id > 0) {
        $where_guard .= " AND NOT EXISTS (
            SELECT 1
              FROM call_blacklist b
             WHERE b.company_id = {$company_id}
               AND b.call_hp    = t.call_hp
        )";
    }
    // $where_guard .= " AND t.do_not_call = 0";

    $sql = "SELECT COUNT(*) AS cnt
              FROM call_target t
             WHERE t.mb_group = {$mb_group}
               {$where_campaign}
               AND t.assigned_mb_no = {$mb_no}
               AND t.assigned_status IN ({$assigned_status})
               {$lease_cond}
               {$where_guard}";
    $row = sql_fetch($sql);
    return (int)$row['cnt'];
}



// ===============================
// 2차상담 자동 배정 유틸 - 단일건 처리
// ===============================

/**
 * aftercall 배정 대상자 목록(가용 멤버) 조회
 * - 조건: is_after_call=1 AND mb_level IN (5,7), 차단/탈퇴 제외
 */
function aftercall_list_candidates($mb_group){
    $mb_group = (int)$mb_group;
    $sql = "
      SELECT m.mb_no
        FROM g5_member m
       WHERE m.mb_group = {$mb_group}
         AND m.is_after_call = 1
         AND m.mb_level IN (5,7)
         AND (m.mb_intercept_date IS NULL OR m.mb_intercept_date = '')
         AND (m.mb_leave_date     IS NULL OR m.mb_leave_date = '')
    ";
    $res = sql_query($sql, true);
    $out = [];
    while ($r = sql_fetch_array($res)) $out[] = (int)$r['mb_no'];
    return $out;
}

/** 최근 통화 ID */
function aftercall_last_call_id($mb_group, $campaign_id, $target_id){
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $target_id   = (int)$target_id;
    $row = sql_fetch("
        SELECT l.call_id
          FROM call_log l
         WHERE l.mb_group={$mb_group} AND l.campaign_id={$campaign_id} AND l.target_id={$target_id}
         ORDER BY l.call_start DESC, l.call_id DESC
         LIMIT 1
    ");
    return (int)($row['call_id'] ?? 0);
}

/**
 * 티켓 테이블만 활용한 공정배분 (스키마 변경 없음)
 * - 기준: call_aftercall_ticket에서 각 상담원의 MAX(ticket_id) = 마지막 배정 시퀀스
 * - NULL(배정 이력 없음) 우선
 * - 동시성: GET_LOCK('after_assign:{mb_group}')
 * - 옵션: cooldown_last_n (최근 N개 티켓 내에서 배정된 상담원 잠시 제외)
 */
function aftercall_pick_next_agent(int $mb_group, int $cooldown_last_n = 0): int {
    global $g5;

    // 0) 후보 목록 확보: 진행중/휴면/권한 등은 이 함수 밖에서 필터링된다고 가정
    $cands = aftercall_list_candidates($mb_group); // => [mb_no, ...]
    if (empty($cands)) return 0;

    $link = $g5['connect_db'];
    $lock_key = "after_assign:{$mb_group}";

    // 1) 그룹 락 (최대 2초 대기)
    $lockRes = mysqli_query($link, "SELECT GET_LOCK('{$lock_key}', 2) AS got");
    $got = ($lockRes && ($r = mysqli_fetch_assoc($lockRes)) && (int)$r['got'] === 1);
    if (!$got) return 0;

    try {
        // 2) 후보 파생테이블 (임시테이블 사용 안 함)
        $union = implode(' UNION ALL ', array_map(fn($n)=>'SELECT '.(int)$n.' AS mb_no', $cands));

        // 3) 최근 티켓 영역(쿨다운)을 위한 현재 max ticket_id 조회
        $max_tid = 0;
        if ($cooldown_last_n > 0) {
            $rsMax = mysqli_query($link, "SELECT IFNULL(MAX(ticket_id),0) AS m FROM call_aftercall_ticket WHERE mb_group={$mb_group}");
            if ($rsMax && ($m = mysqli_fetch_assoc($rsMax))) $max_tid = (int)$m['m'];
        }
        $cooldown_min_tid = ($cooldown_last_n > 0 && $max_tid > 0) ? max(0, $max_tid - $cooldown_last_n + 1) : 0;

        // 4) 각 후보별 마지막 배정 ticket_id (la.last_tid) 계산
        //    쿨다운을 쓰면, 최근 N개 티켓 내에서 이미 배정된 사람은 1차 후보에서 제외
        $cooldownWhere = ($cooldown_min_tid > 0)
            ? " AND (la.last_tid IS NULL OR la.last_tid < {$cooldown_min_tid}) "
            : "";

        $sql1 = "
          WITH c AS (
            {$union}
          ),
          la AS (
            SELECT assigned_after_mb_no AS mb_no,
                   MAX(ticket_id)       AS last_tid
              FROM call_aftercall_ticket
             WHERE mb_group = {$mb_group}
             GROUP BY assigned_after_mb_no
          )
          SELECT x.mb_no
            FROM (
              SELECT c.mb_no, la.last_tid
                FROM c
                LEFT JOIN la ON la.mb_no = c.mb_no
            ) x
           WHERE 1 {$cooldownWhere}
           ORDER BY
             (x.last_tid IS NULL) DESC,   -- 배정 이력 없음 우선
             x.last_tid ASC,              -- 마지막 배정이 가장 오래된 사람
             RAND()                       -- 동률 랜덤화(락 안에서만 사용)
           LIMIT 1
        ";

        $res1 = mysqli_query($link, $sql1);
        $row1 = $res1 ? mysqli_fetch_assoc($res1) : null;
        $picked = (int)($row1['mb_no'] ?? 0);

        // 5) 쿨다운 때문에 아무도 안 잡히면(=모두 최근에 배정) 쿨다운 해제하고 다시 시도
        if ($picked === 0) {
            $sql2 = "
              WITH c AS (
                {$union}
              ),
              la AS (
                SELECT assigned_after_mb_no AS mb_no,
                       MAX(ticket_id)       AS last_tid
                  FROM call_aftercall_ticket
                 WHERE mb_group = {$mb_group}
                 GROUP BY assigned_after_mb_no
              )
              SELECT x.mb_no
                FROM (
                  SELECT c.mb_no, la.last_tid
                    FROM c
                    LEFT JOIN la ON la.mb_no = c.mb_no
                ) x
               ORDER BY
                 (x.last_tid IS NULL) DESC,
                 x.last_tid ASC,
                 RAND()
               LIMIT 1
            ";
            $res2 = mysqli_query($link, $sql2);
            $row2 = $res2 ? mysqli_fetch_assoc($res2) : null;
            $picked = (int)($row2['mb_no'] ?? 0);
        }

        // 6) 반환
        mysqli_query($link, "SELECT RELEASE_LOCK('{$lock_key}')");
        return $picked;

    } catch (\Throwable $e) {
        mysqli_query($link, "SELECT RELEASE_LOCK('{$lock_key}')");
        // 필요 시 로그 남기기
        return 0;
    }
}


/**
 * 티켓 발행(+업데이트) & 2차팀장 배정(가능 시)
 * - 티켓이 없으면 INSERT, 있으면 UPDATE
 * - 가용 2차팀장이 없으면 티켓만 만들고 assigned_after_mb_no는 비워 둔다.
 * @return array {success, ticket_id, assigned_mb_no, message}
 */
function aftercall_issue_and_assign_one(
    int $campaign_id, int $mb_group, int $target_id,
    int $new_state_id, int $actor_mb_no,
    ?string $scheduled_at=null, ?string $schedule_note=null, ?string $memo=null,
    bool $force_reassign=false  // true면 기존 assigned_after_mb_no가 있어도 재배정 시도
){
    $campaign_id = (int)$campaign_id;
    $mb_group    = (int)$mb_group;
    $target_id   = (int)$target_id;
    $new_state_id= (int)$new_state_id;
    $actor_mb_no = (int)$actor_mb_no;

    $sch_esc  = $scheduled_at   ? "'".sql_escape_string($scheduled_at)."'"   : "NULL";
    $note_esc = ($schedule_note!==null && $schedule_note!=='') ? "'".sql_escape_string($schedule_note)."'" : "NULL";
    $memo_esc = ($memo!==null && $memo!=='') ? "'".sql_escape_string($memo)."'" : "NULL";

    // 지점 잠금 (동시 발행/배정 충돌 방지)
    $row = sql_fetch("SELECT GET_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id}), 10) got");
    if ((int)$row['got'] !== 1) {
        return ['success'=>false,'ticket_id'=>0,'assigned_mb_no'=>0,'message'=>'락 획득 실패'];
    }

    sql_query("START TRANSACTION");

    try {
        // 1) 기존 티켓 조회
        $tk = sql_fetch("
            SELECT ticket_id, state_id, assigned_after_mb_no, scheduled_at, schedule_note
              FROM call_aftercall_ticket
             WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id}
             LIMIT 1
        ");

        $last_call_id = aftercall_last_call_id($mb_group, $campaign_id, $target_id) ?: null;

        if ($tk) {
            $ticket_id = (int)$tk['ticket_id'];
            $prev_state = (int)$tk['state_id'];
            $already_assigned = (int)($tk['assigned_after_mb_no'] ?? 0);

            // 상태/일정 업데이트
            $qU = "
              UPDATE call_aftercall_ticket
                 SET state_id={$new_state_id},
                     last_call_id=".($last_call_id ? $last_call_id : "NULL").",
                     scheduled_at={$sch_esc},
                     schedule_note={$note_esc},
                     updated_by={$actor_mb_no},
                     updated_at=NOW()
               WHERE ticket_id={$ticket_id}
               LIMIT 1
            ";
            sql_query($qU);

            // 상태 이력
            if ($prev_state !== $new_state_id) {
                sql_query("
                  INSERT INTO call_aftercall_history
                    (ticket_id, prev_state, new_state, memo, scheduled_at, changed_by, changed_at, assigned_after_mb_no)
                  VALUES
                    ({$ticket_id}, ".($prev_state?:'NULL').", {$new_state_id}, {$memo_esc}, {$sch_esc}, {$actor_mb_no}, NOW(), ".($already_assigned?:'0').")
                ");
            }

            // 이미 배정되어 있고 재배정이 아니면 끝
            if ($already_assigned && !$force_reassign) {
                sql_query("COMMIT");
                sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
                return ['success'=>true,'ticket_id'=>$ticket_id,'assigned_mb_no'=>$already_assigned,'message'=>'티켓 업데이트(기배정 유지)'];
            }

            // 배정 시도
            $agent = aftercall_pick_next_agent($mb_group);
            if ($agent > 0) {
                // 재배정 or 최초배정
                $aff = sql_query("
                  UPDATE call_aftercall_ticket
                     SET assigned_after_mb_no={$agent}, updated_by={$actor_mb_no}, updated_at=NOW()
                   WHERE ticket_id={$ticket_id}
                   LIMIT 1
                ", true);

                if ($aff !== false) {
                    sql_query("
                      INSERT INTO call_aftercall_history
                        (ticket_id, prev_state, new_state, memo, scheduled_at, changed_by, changed_at, assigned_after_mb_no)
                      VALUES
                        ({$ticket_id}, {$new_state_id}, {$new_state_id}, '배정', {$sch_esc}, {$actor_mb_no}, NOW(), {$agent})
                    ");
                    sql_query("COMMIT");
                    sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
                    return ['success'=>true,'ticket_id'=>$ticket_id,'assigned_mb_no'=>$agent,'message'=>'티켓 업데이트+배정'];
                }
            }

            // 가용자 없음 → 티켓만 유지
            sql_query("COMMIT");
            sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
            return ['success'=>true,'ticket_id'=>$ticket_id,'assigned_mb_no'=>0,'message'=>'티켓 업데이트(배정 대기)'];
        }
        else {
            // 2) 신규 티켓 발행
            $ok = sql_query("
              INSERT INTO call_aftercall_ticket
                (campaign_id, mb_group, target_id, last_call_id,
                 state_id, memo, scheduled_at, schedule_note,
                 assigned_after_mb_no,
                 updated_by, updated_at, created_at)
              VALUES
                ({$campaign_id}, {$mb_group}, {$target_id}, ".($last_call_id ?: "NULL").",
                 0, {$memo_esc}, {$sch_esc}, {$note_esc},
                 0,
                 {$actor_mb_no}, NOW(), NOW())
            ", true);
            if (!$ok) throw new Exception('티켓 발행 실패');

            $ticket_id = (int)sql_insert_id();

            // 상태 이력
            // sql_query("
            //   INSERT INTO call_aftercall_history
            //     (ticket_id, prev_state, new_state, memo, scheduled_at, changed_by, changed_at)
            //   VALUES
            //     ({$ticket_id}, NULL, {$new_state_id}, {$memo_esc}, {$sch_esc}, {$actor_mb_no}, NOW())
            // ");

            // 배정 시도
            $agent = aftercall_pick_next_agent($mb_group);
            if ($agent > 0) {
                sql_query("
                  UPDATE call_aftercall_ticket
                     SET assigned_after_mb_no={$agent}, updated_by={$actor_mb_no}, updated_at=NOW()
                   WHERE ticket_id={$ticket_id}
                   LIMIT 1
                ");
                sql_query("
                  INSERT INTO call_aftercall_history
                    (ticket_id, prev_state, new_state, memo, scheduled_at, changed_by, changed_at, assigned_after_mb_no)
                  VALUES
                    ({$ticket_id}, 0, {$new_state_id}, '[SYSTEM] 자동할당', {$sch_esc}, {$actor_mb_no}, NOW(), {$agent})
                ");
                sql_query("COMMIT");
                sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
                return ['success'=>true,'ticket_id'=>$ticket_id,'assigned_mb_no'=>$agent,'message'=>'티켓 발행+배정'];
            }
            // sql_query("ROLLBACK");
            // sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
            // return ['success'=>false,'ticket_id'=>0,'assigned_mb_no'=>0,'message'=>'대상자 없음'];
            // 가용자 없음 → 티켓만 발행
            sql_query("COMMIT");
            sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
            return ['success'=>true,'ticket_id'=>$ticket_id,'assigned_mb_no'=>0,'message'=>'티켓 발행(배정 대기)'];
        }
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        sql_fetch("SELECT RELEASE_LOCK(CONCAT('after_issue_assign:',{$mb_group},':',{$campaign_id},':',{$target_id})) rel");
        return ['success'=>false,'ticket_id'=>0,'assigned_mb_no'=>0,'message'=>'DB 오류: '.$e->getMessage()];
    }
}
