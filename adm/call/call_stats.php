<?php
// /adm/call/call_stats.php
$sub_menu = '700200';
require_once './_common.php';

/**
 * ============================================================
 * call_stats.php (Refactor-friendly version)
 * - 기존 첨부 코드 기준 유지 + 구조 리팩토링
 * - 핵심 개선:
 *   1) WHERE/권한/검색 조건을 “빌더”로 분리
 *   2) 통계 쿼리용 JOIN 정책(build_common_joins) 유지
 *   3) 상세목록 쿼리: (중요) call_log에서 먼저 50건(call_id) 확정 후 JOIN
 *      -> 슈퍼관리자에서 대량 스캔 + JOIN 폭증 문제 방지
 *   4) 총건수(count) 쿼리도 “필요한 JOIN만”으로 일관성 유지
 * ============================================================
 */

/* -----------------------------------------------------------
 * 0) 권한
 * --------------------------------------------------------- */
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

/* -----------------------------------------------------------
 * 1) 기본 파라미터
 * --------------------------------------------------------- */
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$member_table  = $g5['member_table']; // g5_member

$default_start = date('Y-m-d').'T08:00';
$default_end   = date('Y-m-d').'T19:00';

$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

// 회사/지점/담당자 선택값(권한 스코프)
if ($mb_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체 회사
} else {
    $sel_company_id = $my_company_id; // 8/7 고정
}
$sel_mb_group = ($mb_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // 8+=선택, 7=고정
$sel_agent_no = (int)($_GET['agent'] ?? 0);

// 검색/필터
$q         = _g('q', '');
$q_type    = _g('q_type', '');                    // name | last4 | full | all
$f_status  = isset($_GET['status']) ? (int)$_GET['status'] : 0;  // 0=전체
$page      = max(1, (int)(_g('page', '1')));
$page_rows = 50;
$offset    = ($page - 1) * $page_rows;

/* -----------------------------------------------------------
 * 2) 공통 유틸
 * --------------------------------------------------------- */
function fmt_rate($num, $den){
    $n = (int)$num; $d = (int)$den;
    if ($d <= 0 || $n <= 0) return '-';
    return number_format($n * 100 / $d, 1) . '%';
}

function build_common_joins($member_table, $need_member_filter, $need_target_join_for_stats) {
    // 통계에서 t JOIN은 필요할 때만
    $join_target = $need_target_join_for_stats ? "JOIN call_target t ON t.target_id = l.target_id" : "";
    // 통계에서 company 필터가 있으면 m JOIN 필요(최적화: INNER)
    if ($need_member_filter) {
        $join_member = "JOIN {$member_table} m ON m.mb_no = l.mb_no";
    } else {
        $join_member = ""; // company 필터 없으면 통계에서 m JOIN 제거
    }
    return [$join_target, $join_member];
}

/**
 * where 빌더: "조건 문자열(where_sql)"을 만들되,
 * - 어떤 JOIN이 필요한지 플래그도 함께 반환
 * - (주의) 기존 코드의 where_sql은 l/t/m 혼합 가능 => 그에 맞춰 flags 반환
 */
function build_where_and_flags($params) {
    $member_table = $params['member_table'];
    $mb_level = (int)$params['mb_level'];
    $mb_no = (int)$params['mb_no'];
    $my_group = (int)$params['my_group'];
    $my_company_id = (int)$params['my_company_id'];

    $sel_company_id = (int)$params['sel_company_id'];
    $sel_mb_group = (int)$params['sel_mb_group'];
    $sel_agent_no = (int)$params['sel_agent_no'];

    $start_date = $params['start_date'];
    $end_date   = $params['end_date'];

    $q = $params['q'];
    $q_type = $params['q_type'];
    $f_status = (int)$params['f_status'];

    $where = [];
    $start_esc = sql_escape_string($start_date);
    $end_esc   = sql_escape_string($end_date);
    $where[]   = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";

    if ($f_status > 0) {
        $where[] = "l.call_status = {$f_status}";
    }

    // 플래그: 통계/카운트에서 call_target JOIN 필요한지
    $need_target_join = false;

    if ($q !== '' && $q_type !== '') {
        if ($q_type === 'name') {
            $q_esc = sql_escape_string($q);
            $where[] = "t.name LIKE '%{$q_esc}%'";
            $need_target_join = true;
        } elseif ($q_type === 'last4') {
            $q4 = preg_replace('/\D+/', '', $q);
            $q4 = substr($q4, -4);
            if ($q4 !== '') {
                $q4_esc = sql_escape_string($q4);
                $where[] = "t.hp_last4 = '{$q4_esc}'";
                $need_target_join = true;
            }
        } elseif ($q_type === 'full') {
            $hp = preg_replace('/\D+/', '', $q);
            if ($hp !== '') {
                $hp_esc = sql_escape_string($hp);
                $where[] = "l.call_hp = '{$hp_esc}'";
            }
        } elseif ($q_type === 'all') {
            $q_esc = sql_escape_string($q);
            $q4    = substr(preg_replace('/\D+/', '', $q), -4);
            $hp    = preg_replace('/\D+/', '', $q);
            $conds = ["t.name LIKE '%{$q_esc}%'"];
            $need_target_join = true;
            if ($q4 !== '') $conds[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
            if ($hp !== '') $conds[] = "l.call_hp = '".sql_escape_string($hp)."'";
            if ($conds) $where[] = '(' . implode(' OR ', $conds) . ')';
        }
    }

    // 권한/선택 스코프
    if ($mb_level == 7) {
        $where[] = "l.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        $where[] = "l.mb_no = {$mb_no}";
    } else {
        // 8/9+ 회사필터는 m.company_id라서 member join 필요
        if ($mb_level == 8) {
            $where[] = "m.company_id = {$my_company_id}";
        } elseif ($mb_level >= 9 && $sel_company_id > 0) {
            $where[] = "m.company_id = {$sel_company_id}";
        }
        if ($sel_mb_group > 0) $where[] = "l.mb_group = {$sel_mb_group}";
    }
    if ($sel_agent_no > 0) {
        $where[] = "l.mb_no = {$sel_agent_no}";
    }

    $where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    // company 조건이 있으면 member join 필요
    $need_member_filter = (strpos($where_sql, 'm.company_id') !== false);

    return [
        'where_sql' => $where_sql,
        'need_member_filter' => $need_member_filter,
        'need_target_join_for_stats' => $need_target_join,
    ];
}

/**
 * 상세목록/COUNT를 위해 "필요한 JOIN만" 만드는 헬퍼
 * - where_sql이 t/m을 참조할 수 있으므로, 플래그 기반으로 JOIN을 추가한다.
 */
function build_scope_joins_for_where($member_table, $need_member_filter, $need_target_join) {
    $j = [];
    if ($need_target_join) {
        $j[] = "JOIN call_target t ON t.target_id = l.target_id";
    }
    if ($need_member_filter) {
        // where에서 m.company_id 필터를 쓰기 위해 필요
        $j[] = "JOIN {$member_table} m ON m.mb_no = l.mb_no";
    }
    return implode("\n", $j);
}

/**
 * 상세목록 최적화 쿼리:
 * 1) (서브쿼리) call_log에서 call_id 50건(정렬+LIMIT) 먼저 확정
 * 2) 확정된 call_id만 가지고 필요한 JOIN(t/cc/sc/rec/m)을 수행
 *
 * - 매우 중요: 슈퍼관리자(범위 넓음)일 때도 JOIN 폭발을 막음
 * - where 조건에 t/m이 있으면 서브쿼리에서 해당 JOIN을 포함해야 함
 */
function build_list_sql_optimized($args) {
    $member_table = $args['member_table'];
    $where_sql = $args['where_sql'];
    $offset = (int)$args['offset'];
    $page_rows = (int)$args['page_rows'];

    $need_member_filter = (bool)$args['need_member_filter'];
    $need_target_join = (bool)$args['need_target_join_for_stats']; // where에 t가 필요할 때

    // 서브쿼리 JOIN(검색/회사필터를 만족시키기 위한 최소 JOIN)
    $sub_joins = build_scope_joins_for_where($member_table, $need_member_filter, $need_target_join);

    // 서브쿼리에서 ORDER/LIMIT으로 call_id만 뽑는다.
    // 인덱스 힌트는 환경마다 다를 수 있어 강제하지 않음(필요시 FORCE INDEX 추가 가능)
    $sub = "
        SELECT
            l.call_id
        FROM call_log l
        {$sub_joins}
        {$where_sql}
        ORDER BY l.call_start DESC, l.call_id DESC
        LIMIT {$offset}, {$page_rows}
    ";

    // 바깥에서 확정된 call_id만 JOIN
    $sql = "
        SELECT
            l.call_id,
            l.mb_group,
            l.mb_no                                                        AS agent_id,
            m.mb_name                                                      AS agent_name,
            m.mb_id                                                        AS agent_mb_id,
            m.company_id                                                   AS agent_company_id,
            l.call_status,
            sc.name_ko                                                     AS status_label,
            sc.is_after_call                                               AS sc_is_after_call,
            l.campaign_id,
            l.target_id,
            l.call_start,
            l.call_end,
            l.call_time,
            l.agent_phone,
            rec.duration_sec                                               AS talk_time,
            t.name                                                         AS target_name,
            t.birth_date,
            CASE
              WHEN t.birth_date IS NULL THEN NULL
              ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
                   - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
            END AS man_age,
            l.call_hp,
            t.meta_json,
            cc.name                                                        AS campaign_name,
            cc.is_open_number                                              AS cc_is_open_number
        FROM (
            {$sub}
        ) pick
        JOIN call_log l
          ON l.call_id = pick.call_id
        JOIN call_target t
          ON t.target_id = l.target_id
        LEFT JOIN {$member_table} m
          ON m.mb_no = l.mb_no
        LEFT JOIN call_status_code sc
          ON sc.call_status = l.call_status AND sc.mb_group = 0
        LEFT JOIN call_recording rec
          ON rec.call_id = l.call_id
         AND rec.mb_group = l.mb_group
         AND rec.campaign_id = l.campaign_id
        JOIN call_campaign cc
          ON cc.campaign_id = l.campaign_id
         AND cc.mb_group = l.mb_group
        ORDER BY l.call_start DESC, l.call_id DESC
    ";

    return $sql;
}

/**
 * COUNT 쿼리(페이징용):
 * - where에서 t/m 참조 시 JOIN 포함
 * - 그렇지 않으면 call_log만 COUNT
 */
function build_count_sql($member_table, $where_sql, $need_member_filter, $need_target_join_for_stats) {
    $joins = build_scope_joins_for_where($member_table, $need_member_filter, $need_target_join_for_stats);

    // call_target JOIN은 "검색 조건" 때문에 필요할 때만 들어감
    // member JOIN은 "company filter" 때문에 필요할 때만 들어감
    $sql = "
        SELECT COUNT(*) AS cnt
          FROM call_log l
          {$joins}
          {$where_sql}
    ";
    return $sql;
}

/* -----------------------------------------------------------
 * 3) 코드/메타 로딩 (기존 유지)
 * --------------------------------------------------------- */
$codes = [];
$rc = sql_query("
    SELECT call_status, name_ko, status, ui_type
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
");
while ($r = sql_fetch_array($rc)) $codes[] = $r;

// 단일 after-call 코드
$after_code_row = sql_fetch("
    SELECT call_status, name_ko
      FROM call_status_code
     WHERE mb_group=0 AND is_after_call=1
     ORDER BY status DESC, sort_order ASC, call_status ASC
     LIMIT 1
");
$AFTER_STATUS = (int)($after_code_row['call_status'] ?? 0);
$AFTER_LABEL  = $after_code_row['name_ko'] ?? '접수(후처리)';

// 2차콜 상태코드 목록
$ac_code_list = [];
$qr_ac = sql_query("
    SELECT state_id, name_ko, ui_type, status
      FROM call_aftercall_state_code
     WHERE status=1
     ORDER BY sort_order ASC, state_id ASC
");
while ($r = sql_fetch_array($qr_ac)) {
    $ac_code_list[(int)$r['state_id']] = [
        'state_id' => (int)$r['state_id'],
        'name_ko'  => $r['name_ko'],
        'ui_type'  => $r['ui_type'] ?: 'secondary'
    ];
}
if (!isset($ac_code_list[10])) {
    $ac_code_list[10] = ['state_id'=>10, 'name_ko'=>'DB전환', 'ui_type'=>'success'];
}

// call_status_code 메타 캐시
$STATUS_META = [];
$qr_meta = sql_query("
    SELECT call_status,
           COALESCE(result_group, CASE WHEN call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS result_group,
           COALESCE(is_after_call,0) AS is_after_call
      FROM call_status_code
     WHERE mb_group=0
");
while ($r = sql_fetch_array($qr_meta)) {
    $st = (int)$r['call_status'];
    $STATUS_META[$st] = [
        'result_group'  => (int)$r['result_group'],
        'is_after_call' => (int)$r['is_after_call'],
    ];
}

/* -----------------------------------------------------------
 * 4) AJAX: 접수(후처리)로 변경 (기존 유지)
 * --------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'convert_to_after') {
    header('Content-Type: application/json; charset=utf-8');
    require_once G5_LIB_PATH.'/call.assign.lib.php';

    if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
        echo json_encode(['ok'=>false,'message'=>'권한이 없습니다.']); exit;
    }
    if (!check_token()) {
        echo json_encode(['ok'=>false,'message'=>'토큰이 유효하지 않습니다. 새로고침 후 다시 시도하세요.']); exit;
    }
    if ($AFTER_STATUS <= 0) {
        echo json_encode(['ok'=>false,'message'=>'after-call 상태코드가 설정되어 있지 않습니다.']); exit;
    }

    $call_id = (int)($_POST['call_id'] ?? 0);
    if ($call_id <= 0) {
        echo json_encode(['ok'=>false,'message'=>'잘못된 요청값']); exit;
    }

    $row = sql_fetch("
        SELECT l.call_id, l.mb_group, l.mb_no, l.campaign_id, l.target_id,
               l.call_status AS cur_status,
               sc.name_ko AS status_label,
               sc.is_after_call AS cur_is_after,
               m.company_id AS cur_company_id
          FROM call_log l
          JOIN call_status_code sc
            ON sc.call_status = l.call_status AND sc.mb_group = 0
          LEFT JOIN {$member_table} m
            ON m.mb_no = l.mb_no
         WHERE l.call_id = {$call_id}
         LIMIT 1
    ");
    if (!$row) { echo json_encode(['ok'=>false,'message'=>'대상을 찾을 수 없습니다.']); exit; }

    if ($mb_level < 9) {
        if ($mb_level == 8) {
            if ((int)$row['cur_company_id'] !== $my_company_id) {
                echo json_encode(['ok'=>false,'message'=>'회사 범위 밖 데이터입니다.']); exit;
            }
        } else { // 7
            if ((int)$row['mb_group'] !== $my_group) {
                echo json_encode(['ok'=>false,'message'=>'지점 범위 밖 데이터입니다.']); exit;
            }
        }
    }

    if ((int)$row['cur_is_after'] === 1) {
        echo json_encode(['ok'=>false,'message'=>'이미 접수(후처리) 상태입니다.']); exit;
    }

    sql_query("START TRANSACTION");
    try {
        $target_id     = (int)$row['target_id'];
        $mb_group2     = (int)$row['mb_group'];
        $campaign_id   = (int)$row['campaign_id'];

        $before_status = (int)$row['cur_status'];
        $before_label  = get_text($row['status_label'] ?? '');
        $after_status  = $AFTER_STATUS;
        $after_label   = $AFTER_LABEL;

        $operator_name     = get_text($member['mb_name'] ?? $member['mb_id'] ?? '');
        $operator_name_esc = sql_escape_string($operator_name);
        $operator_no       = (int)$mb_no;

        $memo_line = "[상태변경 ".date('Y-m-d H:i:s')." by {$operator_no}/{$operator_name}] "
                . "{$before_status}({$before_label}) → {$after_status}({$after_label})";

        sql_query("
            UPDATE call_log
               SET call_status    = {$after_status},
                   call_updatedat = NOW(),
                   memo           = CONCAT_WS('\\n','".sql_escape_string($memo_line)."', IFNULL(memo,''))
             WHERE call_id = {$call_id}
        ");

        sql_query("
            UPDATE call_target
               SET last_result={$AFTER_STATUS}, updated_at=NOW()
             WHERE target_id={$target_id}
               AND mb_group={$mb_group2}
               AND campaign_id={$campaign_id}
        ");

        $initial_after_state = 1;
        $ac_result = aftercall_issue_and_assign_one(
            $campaign_id,
            $mb_group2,
            $target_id,
            $initial_after_state,
            $mb_no,
            null,
            null,
            '[SYSTEM] 1차 상담 전환 - 관리자 상태 변경',
            false
        );

        sql_query("COMMIT");

        echo json_encode([
            'ok'=>true,
            'message'=>'변경 완료 되었습니다.',
            'call_id'=>$call_id,
            'new_status'=>$AFTER_STATUS,
            'new_status_label'=>$AFTER_LABEL,
            'ac_result'=>$ac_result
        ]);
        exit;

    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        echo json_encode(['ok'=>false,'message'=>'DB 오류: '.$e->getMessage()]); exit;
    }
}

/* -----------------------------------------------------------
 * 5) WHERE & flags 생성
 * --------------------------------------------------------- */
$wf = build_where_and_flags([
    'member_table' => $member_table,
    'mb_level' => $mb_level,
    'mb_no' => $mb_no,
    'my_group' => $my_group,
    'my_company_id' => $my_company_id,
    'sel_company_id' => $sel_company_id,
    'sel_mb_group' => $sel_mb_group,
    'sel_agent_no' => $sel_agent_no,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'q' => $q,
    'q_type' => $q_type,
    'f_status' => $f_status,
]);

$where_sql = $wf['where_sql'];
$need_member_filter = $wf['need_member_filter'];
$need_target_join_for_stats = $wf['need_target_join_for_stats'];

/* -----------------------------------------------------------
 * 6) 코드 리스트(요약 헤더용) (기존 유지)
 * --------------------------------------------------------- */
$code_list = get_code_list($sel_mb_group);
$code_list_status = [];
$status_ui = [];
foreach($code_list as $v) {
    $code_list_status[(int)$v['call_status']] = $v;
    $status_ui[(int)$v['call_status']] = $v['ui_type'] ?? 'secondary';
}

/* -----------------------------------------------------------
 * 7) 통계 계산 함수 (기존 첨부 코드 유지)
 * --------------------------------------------------------- */
function build_stats($where_sql, $member_table, $code_list_status, $mb_level, $sel_mb_group, $after_status, $ac_code_list, $status_meta, $need_member_filter, $need_target_join_for_stats) {
    $result = [
        'top_sum_by_status' => [],
        'success_total' => 0,
        'fail_total' => 0,
        'grand_total' => 0,
        'after_total' => 0,

        'dim_mode' => 'group',
        'matrix' => [],
        'dim_totals' => [],
        'dim_labels' => [],
        'dim_after_totals' => [],

        'group_agent_matrix' => [],
        'group_agent_totals' => [],
        'group_totals' => [],
        'group_labels' => [],
        'agent_labels' => [],
        'group_after_totals' => [],
        'group_agent_after_totals' => [],

        'ac_state_labels' => [],
        'ac_state_totals' => [],
        'dbconv_total'    => 0,
        'dim_ac_state_totals' => [],
        'dim_dbconv_totals'   => [],

        'group_ac_state_totals' => [],
        'group_dbconv_totals'   => [],
        'group_agent_ac_state_totals' => [],
        'group_agent_dbconv_totals'   => [],

        'distinct_target_count' => 0,
        'dim_distinct_target_count' => [],
        'group_distinct_target_count' => [],
        'group_agent_distinct_target_count' => []
    ];

    foreach ($ac_code_list as $sid => $info) {
        $result['ac_state_labels'][(int)$sid] = $info['name_ko'];
    }

    // JOIN 빌드
    [$join_target, $join_member] = build_common_joins($member_table, $need_member_filter, $need_target_join_for_stats);

    // A) 1차 콜 상태 총합(콜 건수 기반)
    $sql_top_sum = "
        SELECT l.call_status, COUNT(*) AS cnt
          FROM call_log l
          {$join_target}
          {$join_member}
          {$where_sql}
         GROUP BY l.call_status
    ";
    $res_top_sum = sql_query($sql_top_sum);
    while ($r = sql_fetch_array($res_top_sum)) {
        $st = (int)$r['call_status'];
        $c  = (int)$r['cnt'];
        $result['top_sum_by_status'][$st] = $c;
        $result['grand_total'] += $c;

        $rg = isset($status_meta[$st]['result_group']) ? (int)$status_meta[$st]['result_group'] : (($st>=200 && $st<300)?1:0);
        if ($rg === 1) $result['success_total'] += $c; else $result['fail_total'] += $c;

        if ($st === (int)$after_status) {
            $result['after_total'] += $c;
        }
    }

    // B) 피벗(콜 건수 기반)
    $dim_mode = ($mb_level >= 8 && $sel_mb_group === 0) ? 'group'
              : (($sel_mb_group > 0) ? 'agent' : 'group');
    $result['dim_mode'] = $dim_mode;

    $dim_select = ($dim_mode === 'group') ? 'l.mb_group' : 'l.mb_no';
    $sql_pivot = "
        SELECT {$dim_select} AS dim_id, l.call_status, COUNT(*) AS cnt
          FROM call_log l
          {$join_target}
          {$join_member}
          {$where_sql}
         GROUP BY dim_id, l.call_status
         ORDER BY dim_id ASC
    ";
    $res_pivot = sql_query($sql_pivot);
    while ($r = sql_fetch_array($res_pivot)) {
        $did = (int)$r['dim_id'];
        $st  = (int)$r['call_status'];
        $cnt = (int)$r['cnt'];
        if (!isset($result['matrix'][$did])) $result['matrix'][$did] = [];
        $result['matrix'][$did][$st] = $cnt;
        if (!isset($result['dim_totals'][$did])) $result['dim_totals'][$did] = 0;
        $result['dim_totals'][$did] += $cnt;
        if ($st === (int)$after_status) {
            if (!isset($result['dim_after_totals'][$did])) $result['dim_after_totals'][$did] = 0;
            $result['dim_after_totals'][$did] += $cnt;
        }
    }

    // 차원 라벨
    if ($dim_mode === 'agent') {
        $ids = array_keys($result['matrix']);
        if ($ids) {
            $id_list = implode(',', array_map('intval', $ids));
            $rla = sql_query("SELECT mb_no, mb_name FROM {$member_table} WHERE mb_no IN ({$id_list})");
            while ($row = sql_fetch_array($rla)) {
                $result['dim_labels'][(int)$row['mb_no']] = get_text($row['mb_name']);
            }
        }
    } else {
        $ids = array_keys($result['matrix']);
        foreach ($ids as $gid) {
            $result['dim_labels'][(int)$gid] = get_group_name_cached((int)$gid);
        }
    }

    // C) 지점 미선택 시: 지점-상담자 (콜 건수 기반)
    if ($sel_mb_group === 0) {
        $sql_ga = "
            SELECT l.mb_group AS gid, l.mb_no AS agent_id, l.call_status, COUNT(*) AS cnt
              FROM call_log l
              {$join_target}
              {$join_member}
              {$where_sql}
             GROUP BY gid, agent_id, l.call_status
             ORDER BY gid ASC, agent_id ASC
        ";
        $res_ga = sql_query($sql_ga);
        while ($r = sql_fetch_array($res_ga)) {
            $gid  = (int)$r['gid'];
            $aid  = (int)$r['agent_id'];
            $st   = (int)$r['call_status'];
            $cnt  = (int)$r['cnt'];

            if (!isset($result['group_agent_matrix'][$gid])) $result['group_agent_matrix'][$gid] = [];
            if (!isset($result['group_agent_matrix'][$gid][$aid])) $result['group_agent_matrix'][$gid][$aid] = [];
            $result['group_agent_matrix'][$gid][$aid][$st] = $cnt;

            if (!isset($result['group_agent_totals'][$gid])) $result['group_agent_totals'][$gid] = [];
            if (!isset($result['group_agent_totals'][$gid][$aid])) $result['group_agent_totals'][$gid][$aid] = 0;
            $result['group_agent_totals'][$gid][$aid] += $cnt;

            if (!isset($result['group_totals'][$gid])) $result['group_totals'][$gid] = 0;
            $result['group_totals'][$gid] += $cnt;

            if ($st === (int)$after_status) {
                if (!isset($result['group_after_totals'][$gid])) $result['group_after_totals'][$gid] = 0;
                $result['group_after_totals'][$gid] += $cnt;

                if (!isset($result['group_agent_after_totals'][$gid])) $result['group_agent_after_totals'][$gid] = [];
                if (!isset($result['group_agent_after_totals'][$gid][$aid])) $result['group_agent_after_totals'][$gid][$aid] = 0;
                $result['group_agent_after_totals'][$gid][$aid] += $cnt;
            }
        }

        // 라벨 벌크
        if ($result['group_agent_matrix']) {
            $gids = array_map('intval', array_keys($result['group_agent_matrix']));
            foreach ($gids as $gid) {
                $result['group_labels'][$gid] = get_group_name_cached($gid);
            }
            $agent_ids = [];
            foreach ($result['group_agent_matrix'] as $gid => $agents) {
                $agent_ids = array_merge($agent_ids, array_keys($agents));
            }
            $agent_ids = array_values(array_unique(array_map('intval', $agent_ids)));
            if ($agent_ids) {
                $alist = implode(',', $agent_ids);
                $rqa = sql_query("SELECT mb_no, mb_name FROM {$member_table} WHERE mb_no IN ({$alist})");
                while ($r = sql_fetch_array($rqa)) {
                    $result['agent_labels'][(int)$r['mb_no']] = get_text($r['mb_name']);
                }
            }
        }
    }

    // D) 2차 상태/DB전환 집계(고유 target_id 기준)
    $row_dt = sql_fetch("
        SELECT COUNT(DISTINCT l.target_id) AS cnt
          FROM call_log l
          {$join_target}
          {$join_member}
          {$where_sql}
    ");
    $result['distinct_target_count'] = (int)($row_dt['cnt'] ?? 0);

    // 차원별 고유 대상 수
    $dim_select2 = ($result['dim_mode']==='group') ? 'l.mb_group' : 'l.mb_no';
    $sql_dim_dt_cnt = "
        SELECT {$dim_select2} AS dim_id, COUNT(DISTINCT l.target_id) AS cnt
          FROM call_log l
          {$join_target}
          {$join_member}
          {$where_sql}
         GROUP BY dim_id
    ";
    $res_dim_dt = sql_query($sql_dim_dt_cnt);
    while ($r = sql_fetch_array($res_dim_dt)) {
        $did = (int)$r['dim_id'];
        $result['dim_distinct_target_count'][$did] = (int)$r['cnt'];
    }

    // 지점-상담자별 고유 대상 수
    if ($sel_mb_group === 0) {
        $sql_ga_dt_cnt = "
            SELECT l.mb_group AS gid, l.mb_no AS aid, COUNT(DISTINCT l.target_id) AS cnt
              FROM call_log l
              {$join_target}
              {$join_member}
              {$where_sql}
             GROUP BY gid, aid
        ";
        $res_ga_dt = sql_query($sql_ga_dt_cnt);
        while ($r = sql_fetch_array($res_ga_dt)) {
            $gid = (int)$r['gid']; $aid = (int)$r['aid']; $cnt = (int)$r['cnt'];

            if (!isset($result['group_distinct_target_count'][$gid])) $result['group_distinct_target_count'][$gid] = 0;
            $result['group_distinct_target_count'][$gid] += $cnt;

            if (!isset($result['group_agent_distinct_target_count'][$gid])) $result['group_agent_distinct_target_count'][$gid] = [];
            $result['group_agent_distinct_target_count'][$gid][$aid] = $cnt;
        }
    }

    // 전체 2차 분포 / DB전환
    if ($result['distinct_target_count'] > 0) {
        $sql_ac_all = "
            SELECT tk.state_id, COUNT(*) AS cnt
              FROM call_aftercall_ticket tk
              JOIN (
                    SELECT DISTINCT l.target_id
                      FROM call_log l
                      {$join_target}
                      {$join_member}
                      {$where_sql}
              ) bt ON bt.target_id = tk.target_id
             GROUP BY tk.state_id
        ";
        $res_ac_all = sql_query($sql_ac_all);
        while ($r = sql_fetch_array($res_ac_all)) {
            $sid = (int)$r['state_id'];
            $result['ac_state_totals'][$sid] = (int)$r['cnt'];
            if ($sid === 10) $result['dbconv_total'] += (int)$r['cnt'];
        }
    }

    // 차원별 분포 / DB전환
    $sql_ac_dim = "
        SELECT dt.dim_id, tk.state_id, COUNT(*) AS cnt
          FROM (
                SELECT {$dim_select2} AS dim_id, l.target_id
                  FROM call_log l
                  {$join_target}
                  {$join_member}
                  {$where_sql}
                 GROUP BY dim_id, l.target_id
          ) dt
          JOIN call_aftercall_ticket tk
            ON tk.target_id = dt.target_id
         GROUP BY dt.dim_id, tk.state_id
         ORDER BY dt.dim_id
    ";
    $res_ac_dim = sql_query($sql_ac_dim);
    while ($r = sql_fetch_array($res_ac_dim)) {
        $did = (int)$r['dim_id'];
        $sid = (int)$r['state_id'];
        if (!isset($result['dim_ac_state_totals'][$did])) $result['dim_ac_state_totals'][$did] = [];
        $result['dim_ac_state_totals'][$did][$sid] = (int)$r['cnt'];
        if ($sid === 10) {
            if (!isset($result['dim_dbconv_totals'][$did])) $result['dim_dbconv_totals'][$did] = 0;
            $result['dim_dbconv_totals'][$did] += (int)$r['cnt'];
        }
    }

    // 지점-상담자 분포 / DB전환
    if ($sel_mb_group === 0) {
        $sql_ac_ga = "
            SELECT gadt.gid, gadt.aid, tk.state_id, COUNT(*) AS cnt
              FROM (
                SELECT l.mb_group AS gid, l.mb_no AS aid, l.target_id
                  FROM call_log l
                  {$join_target}
                  {$join_member}
                  {$where_sql}
                 GROUP BY gid, aid, l.target_id
              ) gadt
              JOIN call_aftercall_ticket tk
                ON tk.target_id = gadt.target_id
             GROUP BY gadt.gid, gadt.aid, tk.state_id
             ORDER BY gadt.gid, gadt.aid
        ";
        $res_ac_ga = sql_query($sql_ac_ga);
        while ($r = sql_fetch_array($res_ac_ga)) {
            $gid = (int)$r['gid']; $aid = (int)$r['aid']; $sid = (int)$r['state_id']; $cnt = (int)$r['cnt'];

            if (!isset($result['group_ac_state_totals'][$gid])) $result['group_ac_state_totals'][$gid] = [];
            if (!isset($result['group_ac_state_totals'][$gid][$sid])) $result['group_ac_state_totals'][$gid][$sid] = 0;
            $result['group_ac_state_totals'][$gid][$sid] += $cnt;

            if (!isset($result['group_agent_ac_state_totals'][$gid])) $result['group_agent_ac_state_totals'][$gid] = [];
            if (!isset($result['group_agent_ac_state_totals'][$gid][$aid])) $result['group_agent_ac_state_totals'][$gid][$aid] = [];
            $result['group_agent_ac_state_totals'][$gid][$aid][$sid] = $cnt;

            if ($sid === 10) {
                if (!isset($result['group_dbconv_totals'][$gid])) $result['group_dbconv_totals'][$gid] = 0;
                $result['group_dbconv_totals'][$gid] += $cnt;

                if (!isset($result['group_agent_dbconv_totals'][$gid])) $result['group_agent_dbconv_totals'][$gid] = [];
                if (!isset($result['group_agent_dbconv_totals'][$gid][$aid])) $result['group_agent_dbconv_totals'][$gid][$aid] = 0;
                $result['group_agent_dbconv_totals'][$gid][$aid] += $cnt;
            }
        }
    }

    return $result;
}

/* -----------------------------------------------------------
 * 8) COUNT / LIST (리팩토링 + 성능 개선 적용)
 * --------------------------------------------------------- */
// COUNT
$sql_cnt = build_count_sql($member_table, $where_sql, $need_member_filter, $need_target_join_for_stats);
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// LIST (최적화: 먼저 call_id 50건 확정 후 JOIN)
$sql_list = build_list_sql_optimized([
    'member_table' => $member_table,
    'where_sql' => $where_sql,
    'offset' => $offset,
    'page_rows' => $page_rows,
    'need_member_filter' => $need_member_filter,
    'need_target_join_for_stats' => $need_target_join_for_stats,
]);
$res_list = sql_query($sql_list);

/* -----------------------------------------------------------
 * 9) 통계 계산
 * --------------------------------------------------------- */
$stats = build_stats(
    $where_sql,
    $member_table,
    $code_list_status,
    $mb_level,
    $sel_mb_group,
    $AFTER_STATUS,
    $ac_code_list,
    $STATUS_META,
    $need_member_filter,
    $need_target_join_for_stats
);

$top_sum_by_status = $stats['top_sum_by_status'];
$success_total = $stats['success_total'];
$fail_total = $stats['fail_total'];
$grand_total = $stats['grand_total'];
$after_total = $stats['after_total'];

$ac_state_labels  = $stats['ac_state_labels'];
unset($ac_state_labels[10]);
$ac_state_totals  = $stats['ac_state_totals'];
$dbconv_total     = (int)$stats['dbconv_total'];
$distinct_targets = (int)$stats['distinct_target_count'];

$dim_mode    = $stats['dim_mode'];
$matrix      = $stats['matrix'];
$dim_totals  = $stats['dim_totals'];
$dim_labels  = $stats['dim_labels'];
$dim_after_totals   = $stats['dim_after_totals'];

$group_agent_matrix  = $stats['group_agent_matrix'];
$group_agent_totals  = $stats['group_agent_totals'];
$group_totals        = $stats['group_totals'];
$group_labels        = $stats['group_labels'];
$agent_labels        = $stats['agent_labels'];

$group_after_totals        = $stats['group_after_totals'];
$group_agent_after_totals  = $stats['group_agent_after_totals'];

$dim_ac_state_totals = $stats['dim_ac_state_totals'];
$dim_dbconv_totals   = $stats['dim_dbconv_totals'];
$dim_distinct_target_count = $stats['dim_distinct_target_count'];

$group_ac_state_totals = $stats['group_ac_state_totals'];
$group_dbconv_totals   = $stats['group_dbconv_totals'];
$group_agent_ac_state_totals = $stats['group_agent_ac_state_totals'];
$group_agent_dbconv_totals   = $stats['group_agent_dbconv_totals'];
$group_distinct_target_count = $stats['group_distinct_target_count'];
$group_agent_distinct_target_count = $stats['group_agent_distinct_target_count'];

/* -----------------------------------------------------------
 * 10) 캠페인별 통계 (기존 코드 유지)
 * --------------------------------------------------------- */
$camp_totals          = [];
$camp_after_totals    = [];
$camp_status_matrix   = [];
$camp_labels          = [];

[$join_target_stats, $join_member_stats] = build_common_joins($member_table, $need_member_filter, $need_target_join_for_stats);

$sql_camp_calls = "
    SELECT
        x.campaign_id,
        cc.name AS campaign_name,
        x.call_status,
        x.cnt
    FROM (
        SELECT
            l.mb_group,
            l.campaign_id,
            l.call_status,
            COUNT(*) AS cnt
        FROM call_log l
        {$join_target_stats}
        {$join_member_stats}
        {$where_sql}
        GROUP BY l.mb_group, l.campaign_id, l.call_status
    ) x
    JOIN call_campaign cc
      ON cc.campaign_id = x.campaign_id
     AND cc.mb_group    = x.mb_group
";
$res_camp_calls = sql_query($sql_camp_calls);
while ($r = sql_fetch_array($res_camp_calls)) {
    $cid = (int)$r['campaign_id'];
    $st  = (int)$r['call_status'];
    $cnt = (int)$r['cnt'];

    $camp_labels[$cid] = get_text($r['campaign_name']);

    if (!isset($camp_status_matrix[$cid])) $camp_status_matrix[$cid] = [];
    $camp_status_matrix[$cid][$st] = $cnt;

    if (!isset($camp_totals[$cid])) $camp_totals[$cid] = 0;
    $camp_totals[$cid] += $cnt;

    if ($st === (int)$AFTER_STATUS) {
        if (!isset($camp_after_totals[$cid])) $camp_after_totals[$cid] = 0;
        $camp_after_totals[$cid] += $cnt;
    }
}

$camp_distinct_target_count = [];
$sql_camp_dt = "
    SELECT l.campaign_id, COUNT(DISTINCT l.target_id) AS cnt
      FROM call_log l
      {$join_target_stats}
      {$join_member_stats}
      {$where_sql}
     GROUP BY l.campaign_id
";
$res_camp_dt = sql_query($sql_camp_dt);
while ($r = sql_fetch_array($res_camp_dt)) {
    $cid = (int)$r['campaign_id'];
    $camp_distinct_target_count[$cid] = (int)$r['cnt'];
}

$camp_ac_state_totals = [];
$camp_dbconv_totals   = [];

$sql_ac_camp = "
    SELECT
        x.campaign_id,
        tk.state_id,
        COUNT(*) AS cnt
    FROM (
        SELECT DISTINCT l.campaign_id, l.mb_group, l.target_id
          FROM call_log l
          {$join_target_stats}
          {$join_member_stats}
          {$where_sql}
    ) x
    JOIN call_aftercall_ticket tk
      ON tk.campaign_id = x.campaign_id
     AND tk.mb_group    = x.mb_group
     AND tk.target_id   = x.target_id
    GROUP BY x.campaign_id, tk.state_id
";
$res_ac_camp = sql_query($sql_ac_camp);
while ($r = sql_fetch_array($res_ac_camp)) {
    $cid = (int)$r['campaign_id'];
    $sid = (int)$r['state_id'];
    $cnt = (int)$r['cnt'];

    if (!isset($camp_ac_state_totals[$cid])) $camp_ac_state_totals[$cid] = [];
    $camp_ac_state_totals[$cid][$sid] = $cnt;

    if ($sid === 10) {
        if (!isset($camp_dbconv_totals[$cid])) $camp_dbconv_totals[$cid] = 0;
        $camp_dbconv_totals[$cid] += $cnt;
    }
}

/* -----------------------------------------------------------
 * 11) 조직 드롭다운 옵션
 * --------------------------------------------------------- */
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
$company_options = $build_org_select_options['company_options'];
$group_options   = $build_org_select_options['group_options'];
$agent_options   = $build_org_select_options['agent_options'];

/* -----------------------------------------------------------
 * 12) 화면 출력 (이하 HTML은 첨부 코드 최대 유지)
 * --------------------------------------------------------- */
$token = get_token();
$g5['title'] = '통계확인';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
?>
<style>
.opt-sep { color:#888; font-style:italic; }
.status-chip { display:inline-block; padding:2px 6px; border-radius:10px; font-size:12px; vertical-align:middle; }
.btn-convert-after { padding:4px 8px; font-size:12px; }
.tbl_call_list td {max-width:200px;}
.status-success{ background:#e9f7ef; }
.status-warning{ background:#fff6e6; }
.status-danger{ background:#fdecea; }
.status-secondary{ background:#f4f6f8; }
.small-muted{ color:#777; font-size:12px; }
.sortable th {cursor:pointer}
</style>

<!-- 검색/필터 -->
<div class="local_sch01 local_sch">
    <form method="get" action="./call_stats.php" class="form-row" id="searchForm">
        <label for="start">기간</label>
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

        <label for="q_type">검색구분</label>
        <select name="q_type" id="q_type" style="width:100px">
            <option value="all"   <?php echo $q_type==='all'?'selected':'';?>>전체</option>
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" style="width:160px" placeholder="검색어 입력">

        <span>&nbsp;|&nbsp;</span>

        <label for="status">상태코드</label>
        <select name="status" id="status">
            <option value="0">전체</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)'); ?>
                </option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql) { ?><a href="./call_stats.php" class="btn btn_02">초기화</a><?php } ?>
        <span class="small-muted">권한:
            <?php
            if ($mb_level >= 8) echo '전체';
            elseif ($mb_level == 7) echo '조직';
            else echo '개인';
            ?>
        </span>

        <span class="row-split"></span>

        <?php if ($mb_level >= 9) { ?>
            <select name="company_id" id="company_id" style="width:120px">
                <option value="0"<?php echo $sel_company_id===0?' selected':'';?>>전체 회사</option>
                <?php foreach ($company_options as $c) { ?>
                    <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                        <?php echo get_text($c['company_name']); ?> (지점 <?php echo (int)$c['group_count']; ?>)
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="company_id" id="company_id" value="<?php echo (int)$sel_company_id; ?>">
        <?php } ?>

        <?php if ($mb_level >= 8) { ?>
            <select name="mb_group" id="mb_group" style="width:120px">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 지점</option>
                <?php
                if ($group_options) {
                    if ($mb_level >= 9 && $sel_company_id == 0) {
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

        <select name="agent" id="agent" style="width:120px">
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
<p>
    총 통화(콜 건수): <b id="stat_grand_total"><?php echo number_format($grand_total);?></b> 건
    &nbsp;|&nbsp;
    성공: <span class="badge badge-success"><span id="stat_success_total"><?php echo number_format($success_total);?></span></span>
    &nbsp;/&nbsp;
    실패: <span class="badge badge-fail"><span id="stat_fail_total"><?php echo number_format($fail_total);?></span></span>
    &nbsp;|&nbsp;
    접수전환율: <b><?php echo fmt_rate($after_total, $grand_total); ?></b>
    &nbsp;|&nbsp;
    대상수: <b><?php echo number_format($distinct_targets); ?></b> 건
    &nbsp;|&nbsp;
    DB전환수: <b><?php echo number_format($dbconv_total); ?></b> 건
    &nbsp;|&nbsp;
    DB전환율: <b><?php echo fmt_rate($dbconv_total, $distinct_targets); ?></b>
</p>

<!-- 피벗 요약 테이블 -->
<div class="tbl_head01 tbl_wrap" style="margin-top:10px;">
    <table style="table-layout:fixed" class="sortable">
        <caption><?php echo $g5['title']; ?></caption>
        <thead>
        <tr>
            <th scope="col"><?php echo ($dim_mode==='group'?'지점':'담당자'); ?></th>
            <th scope="col">총합</th>
            <th scope="col">접수전환율</th>
            <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
            <th scope="col" style="background:#eef7ff">DB대상수</th>
            <th scope="col" style="background:#eef7ff">DB전환수</th>
            <th scope="col" style="background:#eef7ff">DB전환율</th>
            <?php foreach ($ac_state_labels as $sid=>$nm) {
                echo '<th scope="col" style="background:#f6f8fa">'.get_text($nm).'</th>';
            } ?>
        </tr>
        </thead>
        <tbody>
        <tr style="background:#fafafa;font-weight:bold;">
            <td>합계</td>
            <td><?php echo number_format($grand_total); ?></td>
            <td><?php echo fmt_rate($after_total, $grand_total); ?></td>
            <?php
            foreach ($code_list_status as $k => $item) {
                $cnt = !empty($top_sum_by_status[$k]) ? number_format($top_sum_by_status[$k]) : '-';
                $ui = $item['ui_type'] ?? 'secondary';
                echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
            }
            ?>
            <td style="background:#eef7ff"><?php echo number_format($distinct_targets); ?></td>
            <td style="background:#eef7ff"><?php echo number_format($dbconv_total); ?></td>
            <td style="background:#eef7ff"><?php echo fmt_rate($dbconv_total, $distinct_targets); ?></td>
            <?php
            foreach ($ac_state_labels as $sid=>$nm) {
                $cnt = isset($ac_state_totals[$sid]) ? number_format($ac_state_totals[$sid]) : '-';
                echo '<td style="background:#f6f8fa">'.$cnt.'</td>';
            }
            ?>
        </tr>

        <?php
        if (empty($matrix)) {
            echo '<tr><td colspan="'.(6+count($code_list)+count($ac_state_labels)).'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            ksort($matrix, SORT_NUMERIC);
            foreach ($matrix as $did => $rowset) {
                $label = $dim_labels[$did] ?? (($dim_mode==='group')?('지점 '.$did):('담당자 '.$did));
                $row_total = (int)($dim_totals[$did] ?? 0);
                $row_after = (int)($dim_after_totals[$did] ?? 0);

                $d_distinct = (int)($dim_distinct_target_count[$did] ?? 0);
                $d_dbconv   = (int)($dim_dbconv_totals[$did] ?? 0);

                echo '<tr>';
                echo '<td>'.get_text($label).'</td>';
                echo '<td>'.number_format($row_total).'</td>';
                echo '<td>'.fmt_rate($row_after, $row_total).'</td>';
                foreach ($code_list_status as $k => $item) {
                    $cnt = isset($rowset[$k]) ? number_format($rowset[$k]) : '-';
                    $ui = $item['ui_type'] ?? 'secondary';
                    echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
                }
                echo '<td style="background:#eef7ff">'.($d_distinct?number_format($d_distinct):'-').'</td>';
                echo '<td style="background:#eef7ff">'.($d_dbconv?number_format($d_dbconv):'-').'</td>';
                echo '<td style="background:#eef7ff">'.fmt_rate($d_dbconv, $d_distinct).'</td>';

                foreach ($ac_state_labels as $sid=>$nm) {
                    $cnt = isset($dim_ac_state_totals[$did][$sid]) ? number_format($dim_ac_state_totals[$did][$sid]) : '-';
                    echo '<td style="background:#f6f8fa">'.$cnt.'</td>';
                }
                echo '</tr>';
            }
        }
        ?>
        </tbody>
    </table>
</div>

<!-- 캠페인별 통계 -->
<h3 style="margin-top:18px;">캠페인별 통계</h3>
<div class="tbl_head01 tbl_wrap" style="margin-top:8px;">
    <table style="table-layout:fixed" class="sortable">
        <caption>캠페인별 통계</caption>
        <thead>
        <tr>
            <th scope="col" style="width:200px;">캠페인</th>
            <th scope="col" style="width:80px;">총합</th>
            <th scope="col" style="width:90px;">접수전환율</th>
            <?php foreach ($code_list as $c) {
                $ui = $c['ui_type'] ?? 'secondary';
                echo '<th scope="col" class="status-col status-'.get_text($ui).'">'.get_text($c['name']).'</th>';
            } ?>
            <th scope="col" style="background:#eef7ff;width:90px;">DB대상수</th>
            <th scope="col" style="background:#eef7ff;width:90px;">DB전환수</th>
            <th scope="col" style="background:#eef7ff;width:90px;">DB전환율</th>
            <?php foreach ($ac_state_labels as $sid=>$nm) {
                echo '<th scope="col" style="background:#f6f8fa">'.get_text($nm).'</th>';
            } ?>
        </tr>
        </thead>
        <tbody>
        <?php
        if (empty($camp_labels)) {
            $colspan = 6 + count($code_list) + count($ac_state_labels);
            echo '<tr><td colspan="'.$colspan.'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            $camp_ids = array_keys($camp_labels);
            usort($camp_ids, function($a, $b) use($camp_labels){
                return strcmp($camp_labels[$a], $camp_labels[$b]);
            });

            foreach ($camp_ids as $cid) {
                $label_full = $camp_labels[$cid];
                $label_short = cut_str($label_full, 16);
                $row_total = (int)($camp_totals[$cid] ?? 0);
                $row_after = (int)($camp_after_totals[$cid] ?? 0);
                $dist_cnt  = (int)($camp_distinct_target_count[$cid] ?? 0);
                $dbconv_cnt= (int)($camp_dbconv_totals[$cid] ?? 0);
                $status_row= $camp_status_matrix[$cid] ?? [];
                $state_row = $camp_ac_state_totals[$cid] ?? [];

                echo '<tr>';
                echo '<td title="'.get_text($label_full).'" class="td_left">'.get_text($label_short).'</td>';
                echo '<td>'.($row_total?number_format($row_total):'-').'</td>';
                echo '<td>'.fmt_rate($row_after, $row_total).'</td>';

                foreach ($code_list_status as $st => $item) {
                    $cnt = isset($status_row[$st]) ? number_format($status_row[$st]) : '-';
                    $ui  = $item['ui_type'] ?? 'secondary';
                    echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
                }

                echo '<td style="background:#eef7ff">'.($dist_cnt?number_format($dist_cnt):'-').'</td>';
                echo '<td style="background:#eef7ff">'.($dbconv_cnt?number_format($dbconv_cnt):'-').'</td>';
                echo '<td style="background:#eef7ff">'.fmt_rate($dbconv_cnt, $dist_cnt).'</td>';

                foreach ($ac_state_labels as $sid => $nm) {
                    $cnt = isset($state_row[$sid]) ? number_format($state_row[$sid]) : '-';
                    echo '<td style="background:#f6f8fa">'.$cnt.'</td>';
                }

                echo '</tr>';
            }
        }
        ?>
        </tbody>
    </table>
</div>

<!-- 지점 미선택 시: 지점별 담당자 통계 -->
<?php if ($sel_mb_group === 0) { ?>
    <h3 style="margin-top:18px;">지점별 담당자 통계</h3>

    <?php if (empty($group_agent_matrix)) { ?>
        <div class="tbl_head01 tbl_wrap" style="margin-top:8px;">
            <table><tbody><tr><td class="empty_table">데이터가 없습니다.</td></tr></tbody></table>
        </div>
    <?php } else { ?>
        <?php foreach ($group_agent_matrix as $gid => $agents) { ?>
        <div class="tbl_head01 tbl_wrap" style="margin-top:10px;">
            <table style="table-layout:fixed" class="sortable">
                <caption><?php echo get_text($group_labels[$gid] ?? ('지점 '.$gid)); ?></caption>
                <thead>
                    <tr>
                        <th scope="col">담당자</th>
                        <th scope="col">총합</th>
                        <th scope="col">접수전환율</th>
                        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
                        <th scope="col" style="background:#eef7ff">DB대상수</th>
                        <th scope="col" style="background:#eef7ff">DB전환수</th>
                        <th scope="col" style="background:#eef7ff">DB전환율</th>
                        <?php foreach ($ac_state_labels as $sid=>$nm) {
                            echo '<th scope="col" style="background:#f6f8fa">'.get_text($nm).'</th>';
                        } ?>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#fafafa;font-weight:bold;">
                        <td><?php echo get_text($group_labels[$gid] ?? ('지점 '.$gid)); ?> 합계</td>
                        <?php $g_total = (int)($group_totals[$gid] ?? 0); ?>
                        <td><?php echo number_format($g_total); ?></td>
                        <td><?php echo fmt_rate((int)($group_after_totals[$gid] ?? 0), $g_total); ?></td>
                        <?php
                        $status_sum = [];
                        foreach ($agents as $aid => $rowset) {
                            foreach ($rowset as $st => $cnt) {
                                if (!isset($status_sum[$st])) $status_sum[$st] = 0;
                                $status_sum[$st] += (int)$cnt;
                            }
                        }
                        foreach ($code_list_status as $k => $item) {
                            $cnt = isset($status_sum[$k]) ? number_format($status_sum[$k]) : '-';
                            $ui = $item['ui_type'] ?? 'secondary';
                            echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
                        }

                        $g_distinct = (int)($group_distinct_target_count[$gid] ?? 0);
                        $g_dbconv   = (int)($group_dbconv_totals[$gid] ?? 0);
                        echo '<td style="background:#eef7ff">'.($g_distinct?number_format($g_distinct):'-').'</td>';
                        echo '<td style="background:#eef7ff">'.($g_dbconv?number_format($g_dbconv):'-').'</td>';
                        echo '<td style="background:#eef7ff">'.fmt_rate($g_dbconv, $g_distinct).'</td>';

                        $g_state_sum = [];
                        if (!empty($group_ac_state_totals[$gid])) $g_state_sum = $group_ac_state_totals[$gid];
                        foreach ($ac_state_labels as $sid=>$nm) {
                            $cnt = isset($g_state_sum[$sid]) ? number_format($g_state_sum[$sid]) : '-';
                            echo '<td style="background:#f6f8fa">'.$cnt.'</td>';
                        }
                        ?>
                    </tr>

                    <?php
                    ksort($agents, SORT_NUMERIC);
                    foreach ($agents as $aid => $rowset) {
                        $row_total = (int)($group_agent_totals[$gid][$aid] ?? 0);
                        $row_after = (int)($group_agent_after_totals[$gid][$aid] ?? 0);

                        $ga_distinct = (int)($group_agent_distinct_target_count[$gid][$aid] ?? 0);
                        $ga_dbconv   = (int)($group_agent_dbconv_totals[$gid][$aid] ?? 0);

                        $alabel = $agent_labels[$aid] ?? ('담당자 '.$aid);
                        echo '<tr>';
                        echo '<td>'.get_text($alabel).'</td>';
                        echo '<td>'.number_format($row_total).'</td>';
                        echo '<td>'.fmt_rate($row_after, $row_total).'</td>';
                        foreach ($code_list_status as $k => $item) {
                            $cnt = isset($rowset[$k]) ? number_format($rowset[$k]) : '-';
                            $ui = $item['ui_type'] ?? 'secondary';
                            echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
                        }

                        echo '<td style="background:#eef7ff">'.($ga_distinct?number_format($ga_distinct):'-').'</td>';
                        echo '<td style="background:#eef7ff">'.($ga_dbconv?number_format($ga_dbconv):'-').'</td>';
                        echo '<td style="background:#eef7ff">'.fmt_rate($ga_dbconv, $ga_distinct).'</td>';

                        foreach ($ac_state_labels as $sid=>$nm) {
                            $cnt = isset($group_agent_ac_state_totals[$gid][$aid][$sid]) ? number_format($group_agent_ac_state_totals[$gid][$aid][$sid]) : '-';
                            echo '<td style="background:#f6f8fa">'.$cnt.'</td>';
                        }
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    <?php } ?>
<?php } ?>

<!-- 상세 목록 : 50건 고정 -->
<div class="tbl_head01 tbl_wrap tbl_call_list" style="margin-top:14px;">
    <table class="table-fixed">
        <thead>
            <tr>
                <th>지점명</th>
                <th>아이디</th>
                <th>상담원명</th>
                <th>발신번호</th>
                <th>통화결과</th>
                <th>통화시작</th>
                <th>통화종료</th>
                <th>통화시간</th>
                <th>상담시간</th>
                <th>고객명</th>
                <th>생년월일</th>
                <th>만나이</th>
                <th>전화번호</th>
                <th>추가정보</th>
                <th>캠페인명</th>
                <th>처리</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="16" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                $talk_sec = is_null($row['talk_time']) ? '-' : fmt_hms((int)$row['talk_time']);
                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);
                $bday     = empty($row['birth_date']) ? '-' : get_text($row['birth_date']);
                $man_age  = is_null($row['man_age'])   ? '-' : ((int)$row['man_age']).'세';
                $agent    = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];
                $status   = $row['status_label'] ?: ('코드 '.$row['call_status']);
                $gname    = get_group_name_cached((int)$row['mb_group']);
                $meta     = '-';
                if (!is_null($row['meta_json']) && $row['meta_json'] !== '') {
                    $decoded = json_decode($row['meta_json'], true);
                    if (is_array($decoded)) {
                        $meta = implode(',', $decoded);
                    } else {
                        $meta = get_text($row['meta_json']);
                    }
                }
                $meta = cut_str($meta, 30);
                $ui = !empty($status_ui[$row['call_status']]) ? $status_ui[$row['call_status']] : 'secondary';
                $class = 'status-col status-'.get_text($ui);

                if ((int)$row['cc_is_open_number'] === 0 && (int)$row['sc_is_after_call'] !== 1 && $mb_level < 9) {
                    $hp_display = '(숨김처리)';
                } else {
                    $hp_display = get_text(format_korean_phone($row['call_hp']));
                }
                $agent_phone = '-';
                if($row['agent_phone']) {
                    $agent_phone = get_text(format_korean_phone($row['agent_phone']));
                    if(strlen($agent_phone) == 13) $agent_phone = substr($agent_phone, 4, 9);
                }

                $is_after = (int)$row['sc_is_after_call'] === 1;
                ?>
                <tr>
                    <td><?php echo get_text($gname); ?></td>
                    <td><?php echo get_text($row['agent_mb_id']); ?></td>
                    <td><?php echo get_text($agent); ?></td>
                    <td><?php echo $agent_phone; ?></td>
                    <td class="<?php echo $class ?>"><?php echo get_text($status); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_start']), 'mdhi'); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_end']), 'mdhi'); ?></td>
                    <td><?php echo $call_sec; ?></td>
                    <td><?php echo $talk_sec; ?></td>
                    <td><?php echo get_text($row['target_name'] ?: '-'); ?></td>
                    <td><?php echo $bday; ?></td>
                    <td><?php echo $man_age; ?></td>
                    <td><?php echo $hp_display; ?></td>
                    <td><?php echo $meta; ?></td>
                    <td><?php echo get_text($row['campaign_name'] ?: '-'); ?></td>
                    <td>
                        <?php if (!$is_after) { ?>
                            <button type="button"
                                class="btn btn_02 btn-convert-after"
                                data-call-id="<?php echo (int)$row['call_id'];?>"
                                data-cur-label="<?php echo get_text($status);?>">
                                접수변경
                            </button>
                        <?php } else { ?>
                            <span class="small-muted">-</span>
                        <?php } ?>
                    </td>
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
$base = './call_stats.php?'.http_build_query($qstr);
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
// 회사→지점 셀렉트 자동 전송
(function(){
    var companySel = document.getElementById('company_id');
    var groupSel   = document.getElementById('mb_group');
    if (!groupSel) return;

    <?php if ($mb_level >= 9) { ?>
    initCompanyGroupSelector(companySel, groupSel);
    if (companySel) {
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

<script>
// 접수로 변경 버튼 처리 (기존)
(function(){
  const table = document.querySelector('table.table-fixed');
  if (!table) return;
  const AFTER_LABEL = <?php echo json_encode($AFTER_LABEL, JSON_UNESCAPED_UNICODE); ?>;
  table.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-convert-after');
    if (!btn) return;
    const callId = parseInt(btn.getAttribute('data-call-id') || '0', 10);
    if (!callId) return;
    if (!confirm("정말 '" + AFTER_LABEL + "' 으로 변경하시겠습니까?")) return;
    btn.disabled = true;
    fetch(location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams({
        mode: 'convert_to_after',
        call_id: String(callId),
        token: '<?php echo $token; ?>'
      })
    })
    .then(res => res.json())
    .then(json => {
      if (!json.ok) throw new Error(json.message || '변경 실패');
      const tr = btn.closest('tr');
      const tdResult = tr ? tr.children[4] : null;
      if (tdResult) {
        tdResult.textContent = AFTER_LABEL;
        tdResult.classList.remove('status-secondary','status-warning','status-fail');
        tdResult.classList.add('status-success');
      }
      btn.replaceWith(document.createTextNode('완료'));
    })
    .catch(err => {
      alert(err.message);
      btn.disabled = false;
    });
  });
})();
</script>
<script src="https://cdn.jsdelivr.net/gh/stuartlangridge/sorttable/sorttable/sorttable.js"></script>
<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
