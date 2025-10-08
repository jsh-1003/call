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
 */


/**
 * N건 배정(트랜잭션)
 *
 * @param int   $mb_group
 * @param int   $campaign_id
 * @param int   $mb_no                 상담원/담당자 번호
 * @param int   $n                     배정할 건수
 * @param int   $lease_min             리스(분) – 자동 회수 기준
 * @param int   $batch_id              배치 ID(감사/그룹핑용)
 * @param array $opts                  ['use_skip_locked'=>true, 'assigned_status_to'=>1, 'assigned_status_filter'=>0, 'order'=>'target_id', 'where_extra'=>null]
 * @return array                       ['ok'=>bool, 'picked'=>int, 'ids'=>[...], 'err'=>string|null]
 */
function call_assign_pick_and_lock($mb_group, $mb_no, $n, $lease_min, $batch_id, $opts = [], $campaign_id=0) {
    $use_skip_locked      = isset($opts['use_skip_locked']) ? (bool)$opts['use_skip_locked'] : true;
    $assigned_status_to   = isset($opts['assigned_status_to']) ? (int)$opts['assigned_status_to'] : 1;  // 배정(통화전)
    $assigned_status_filter = isset($opts['assigned_status_filter']) ? (int)$opts['assigned_status_filter'] : 0; // 미배정
    $order_col            = isset($opts['order']) ? $opts['order'] : 'target_id'; // 우선순위 컬럼 (인덱스와 일치 권장)
    $where_extra          = isset($opts['where_extra']) ? trim($opts['where_extra']) : null; // 추가 필터 (예: AND do_not_call=0)

    // 안전 캐스팅
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $mb_no       = (int)$mb_no;
    $n           = max(0, (int)$n);
    $lease_min   = max(1, (int)$lease_min);
    $batch_id    = (int)$batch_id;

    if ($n <= 0) {
        return ['ok'=>true, 'picked'=>0, 'ids'=>[], 'err'=>null];
    }

    // 임시테이블은 세션 범위. 동시 호출 대비로 유니크 네임
    $tmp = 'tmp_pick_' . substr(md5(uniqid('', true)), 0, 8);

    try {
        sql_query("START TRANSACTION");

        // 임시 테이블
        sql_query("DROP TEMPORARY TABLE IF EXISTS {$tmp}");
        sql_query("CREATE TEMPORARY TABLE {$tmp} (target_id BIGINT PRIMARY KEY) ENGINE=MEMORY");

        // 1) 후보 픽 (잠금 포함)
        $where_extra_sql = '';
        if ($where_extra) {
            // 개발자가 직접 안전한 조건만 넣도록 가정 (ex: "AND do_not_call=0")
            $where_extra_sql = "\n      " . $where_extra;
        }
        $where_campagin = '';
        if($campaign_id > 0) {
            $where_campagin = " AND t.campaign_id = {$campaign_id} ";
        }
        $pick_sql = "INSERT INTO {$tmp} (target_id)
            SELECT t.target_id
            FROM call_target AS t
            WHERE t.mb_group = {$mb_group}
              AND t.assigned_status = {$assigned_status_filter}
              {$where_campagin}
              {$where_extra_sql}
            ORDER BY t.{$order_col}
            LIMIT {$n}
        ";

        if ($use_skip_locked) {
            // MySQL 8.x 권장: 경합 회피
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
        //   SKIP LOCKED가 아닌 경우 동시성 보완 위해 WHERE에 현재 상태 재확인
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

        // 실제 배정된 수 재확인 (특히 SKIP LOCKED 미사용 시)
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
            SELECT {$campaign_id}, {$mb_group}, p.target_id, {$mb_no}, NOW(), 1
            FROM {$tmp} AS p
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
          AND assigned_status IN (1,2)
          AND assign_lease_until IS NOT NULL
          AND assign_lease_until < NOW()
    ";
    $res = sql_query($sql);
    return (bool)$res;
}


/**
 * 내 큐 조회(배정/진행중 목록)
 * @return array of rows
 */
function call_assign_list_my_queue($mb_group, $mb_no, $limit=5, $campaign_id=0, $assigned_status='1,2') {
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $mb_no       = (int)$mb_no;
    $limit       = max(1, (int)$limit);

    $rows = [];
    $where_campagin = '';
    if($campaign_id > 0) {
        $where_campagin = " AND t.campaign_id = {$campaign_id} ";
    }

    $sql = "SELECT t.*
        FROM call_target t
        WHERE t.mb_group = {$mb_group}
          {$where_campagin}
          AND t.assigned_mb_no = {$mb_no}
          AND t.assigned_status IN ({$assigned_status})
        ORDER BY t.assigned_at ASC, t.target_id ASC
        LIMIT {$limit}
    ";
    $q = sql_query($sql);
    while ($r = sql_fetch_array($q)) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * 내 큐 개수(배정 상태/캠페인/리스 유효 기준)
 */
function call_assign_count_my_queue($mb_group, $mb_no, $campaign_id=0, $assigned_status='1', $only_valid_lease=false) {
    $mb_group    = (int)$mb_group;
    $campaign_id = (int)$campaign_id;
    $mb_no       = (int)$mb_no;

    $lease_cond = $only_valid_lease ? " AND t.assign_lease_until > NOW() " : "";
    $where_campaign = $campaign_id > 0 ? " AND t.campaign_id = {$campaign_id} " : "";

    $sql = "SELECT COUNT(*) AS cnt
              FROM call_target t
             WHERE t.mb_group = {$mb_group}
               {$where_campaign}
               AND t.assigned_mb_no = {$mb_no}
               AND t.assigned_status IN ({$assigned_status})
               {$lease_cond}";
    $row = sql_fetch($sql);
    return (int)$row['cnt'];
}
