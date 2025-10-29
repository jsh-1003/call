<?php
// /adm/call/call_stats.php
$sub_menu = '700200';
require_once './_common.php';

// -----------------------------
// 접근 권한: 레벨 7 미만 금지
// -----------------------------
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// --------------------------------------------------
// 기본/입력 파라미터
// --------------------------------------------------
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$member_table  = $g5['member_table']; // g5_member

$today      = date('Y-m-d');
$default_start = $today;
$default_end   = $today;

$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

// ★ 권한 스코프에 따른 회사/지점/담당자 선택값
if ($mb_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체 회사
} else {
    $sel_company_id = $my_company_id; // 8/7 고정
}
$sel_mb_group = ($mb_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // 8+=선택, 7=고정
$sel_agent_no = (int)($_GET['agent'] ?? 0);

// 검색/필터
$q         = _g('q', '');
$q_type    = _g('q_type', '');           // name | last4 | full | all
$f_status  = isset($_GET['status']) ? (int)$_GET['status'] : 0;  // 0=전체
$page      = max(1, (int)(_g('page', '1')));
$page_rows = 50; // 상세 리스트 50건 고정
$offset    = ($page - 1) * $page_rows;

// --------------------------------------------------
// 전역: 상태코드 목록
// --------------------------------------------------
$codes = [];
$rc = sql_query("
    SELECT call_status, name_ko, status
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
");
while ($r = sql_fetch_array($rc)) $codes[] = $r;

// ★ 단일 after-call 코드 조회 (is_after_call=1, status=1 우선)
$after_code_row = sql_fetch("
    SELECT call_status, name_ko
      FROM call_status_code
     WHERE mb_group=0 AND is_after_call=1
     ORDER BY status DESC, sort_order ASC, call_status ASC
     LIMIT 1
");
$AFTER_STATUS = (int)($after_code_row['call_status'] ?? 0);
$AFTER_LABEL  = $after_code_row['name_ko'] ?? '접수(후처리)';

// ===============================
// AJAX: 접수(후처리)로 변경
// ===============================
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

    // 대상 로드 + 권한 스코프 확인
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

    // 권한 스코프
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

    // 이미 after-call 상태면 불가
    if ((int)$row['cur_is_after'] === 1) {
        echo json_encode(['ok'=>false,'message'=>'이미 접수(후처리) 상태입니다.']); exit;
    }

    // 트랜잭션
    sql_query("START TRANSACTION");
    try {
        $target_id     = (int)$row['target_id'];
        $mb_group      = (int)$row['mb_group'];
        $campaign_id   = (int)$row['campaign_id'];

        // 이전/이후 상태와 라벨 준비
        $before_status = (int)$row['cur_status'];
        $before_label  = get_text($row['status_label'] ?? ''); // 현재 라벨
        $after_status  = $AFTER_STATUS;
        $after_label   = $AFTER_LABEL;

        // SQL 안전 문자열로
        $before_label_esc = sql_escape_string($before_label);
        $after_label_esc  = sql_escape_string($after_label);

        // (선택) 조작자 표기
        $operator_name    = get_text($member['mb_name'] ?? $member['mb_id'] ?? '');
        $operator_name_esc= sql_escape_string($operator_name);
        $operator_no      = (int)$mb_no;

        // 메모 한 줄(타임스탬프 포함)
        $memo_line = "[상태변경 ".date('Y-m-d H:i:s')." by {$operator_no}/{$operator_name}] "
                . "{$before_status}({$before_label}) → {$after_status}({$after_label})";

        // 업데이트: call_memo에 안전하게 prepend
        sql_query("
        UPDATE call_log
            SET call_status   = {$after_status},
                call_updatedat= NOW(),
                memo     = CONCAT_WS('\n',
                                    '".sql_escape_string($memo_line)."',
                                    IFNULL(memo,'')
                                )
        WHERE call_id = {$call_id}
        ");
        
        // 상태 변경
        sql_query("UPDATE call_target SET last_result={$AFTER_STATUS}, updated_at=NOW() WHERE target_id={$target_id} AND mb_group={$mb_group} AND campaign_id={$campaign_id}");

        // aftercall 티켓 발급 + 배정
        $initial_after_state = 1;
        $ac_result = aftercall_issue_and_assign_one(
            $campaign_id,
            $mb_group,
            $target_id,
            $initial_after_state,
            $mb_no,          // 조작자
            null,            // scheduled_at
            null,            // schedule_note
            '[SYSTEM] 1차 상담 전환 - 관리자 상태 변경',
            false            // force_reassign
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

// --------------------------------------------------
// WHERE 구성 (company/group/agent/기간/검색)
// --------------------------------------------------
$where = [];

// 기간 (통계는 call_start 기준)
$start_esc = sql_escape_string($start_date.' 00:00:00');
$end_esc   = sql_escape_string($end_date.' 23:59:59');
$where[]   = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 상태
if ($f_status > 0) {
    $where[] = "l.call_status = {$f_status}";
}

// 검색어
if ($q !== '' && $q_type !== '') {
    if ($q_type === 'name') {
        $q_esc = sql_escape_string($q);
        $where[] = "t.name LIKE '%{$q_esc}%'";
    } elseif ($q_type === 'last4') {
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
    } elseif ($q_type === 'all') {
        $q_esc = sql_escape_string($q);
        $q4    = substr(preg_replace('/\D+/', '', $q), -4);
        $hp    = preg_replace('/\D+/', '', $q);

        $conds = ["t.name LIKE '%{$q_esc}%'"];
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

// --------------------------------------------------
// 코드 리스트(요약 헤더용)
// --------------------------------------------------
$code_list = get_code_list($sel_mb_group);
$code_list_status = [];
$status_ui = [];
foreach($code_list as $v) {
    $code_list_status[(int)$v['call_status']] = $v;
    $status_ui[(int)$v['call_status']] = $v['ui_type'] ?? 'secondary';
}

// --------------------------------------------------
// (공통) 통계 계산 함수
// --------------------------------------------------
function build_stats($where_sql, $member_table, $code_list_status, $mb_level, $sel_mb_group) {
    $result = [
        'top_sum_by_status' => [],
        'success_total' => 0,
        'fail_total' => 0,
        'grand_total' => 0,
        'dim_mode' => 'group',
        'matrix' => [],
        'dim_totals' => [],
        'dim_labels' => [],
        'group_agent_matrix' => [],
        'group_agent_totals' => [],
        'group_totals' => [],
        'group_labels' => [],
        'agent_labels' => [],
    ];

    // 상단 총합
    $sql_top_sum = "
        SELECT l.call_status, COUNT(*) AS cnt
          FROM call_log l
          JOIN call_target t ON t.target_id = l.target_id
     LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
        {$where_sql}
         GROUP BY l.call_status
    ";
    $res_top_sum = sql_query($sql_top_sum);
    while ($r = sql_fetch_array($res_top_sum)) {
        $st = (int)$r['call_status'];
        $c  = (int)$r['cnt'];
        $result['top_sum_by_status'][$st] = $c;
        $result['grand_total'] += $c;

        $row = sql_fetch("
            SELECT COALESCE(result_group, CASE WHEN {$st} BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS rg
              FROM call_status_code
             WHERE call_status={$st} AND mb_group=0
             LIMIT 1
        ");
        $rg = isset($row['rg']) ? (int)$row['rg'] : (($st>=200 && $st<300)?1:0);
        if ($rg === 1) $result['success_total'] += $c; else $result['fail_total'] += $c;
    }

    // 피벗
    $dim_mode = ($mb_level >= 8 && $sel_mb_group === 0) ? 'group'
              : (($sel_mb_group > 0) ? 'agent' : 'group');
    $result['dim_mode'] = $dim_mode;

    $dim_select = ($dim_mode === 'group') ? 'l.mb_group' : 'l.mb_no';
    $sql_pivot = "
        SELECT {$dim_select} AS dim_id, l.call_status, COUNT(*) AS cnt
          FROM call_log l
          JOIN call_target t ON t.target_id = l.target_id
     LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
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
    }

    // 라벨
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
        if ($ids) {
            foreach ($ids as $gid) {
                $gid = (int)$gid;
                $result['dim_labels'][$gid] = get_group_name_cached($gid);
            }
        }
    }

    // 지점 미선택 시: 지점별 담당자
    if ($sel_mb_group === 0) {
        $sql_ga = "
            SELECT l.mb_group AS gid, l.mb_no AS agent_id, l.call_status, COUNT(*) AS cnt
              FROM call_log l
              JOIN call_target t ON t.target_id = l.target_id
         LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
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

    return $result;
}

// --------------------------------------------------
// 총 건수 (상세 리스트 페이징용)
// --------------------------------------------------
$sql_cnt = "
    SELECT COUNT(*) AS cnt
      FROM call_log l
      JOIN call_target t ON t.target_id = l.target_id
 LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
    {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// --------------------------------------------------
// 상세 목록 쿼리
//   + sc.is_after_call 컬럼 포함
// --------------------------------------------------
$sql_list = "
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
        l.call_time,                                                   -- 통화시간(초)
        l.agent_phone,                                                 -- 발신전화번호
        rec.duration_sec                                               AS talk_time,          -- 상담시간
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
    FROM call_log l
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
    {$where_sql}
    ORDER BY l.call_start DESC, l.call_id DESC
    LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

// --------------------------------------------------
// 통계 계산 (상단/피벗/지점별담당자)
// --------------------------------------------------
$stats = build_stats($where_sql, $member_table, $code_list_status, $mb_level, $sel_mb_group);
$top_sum_by_status = $stats['top_sum_by_status'];
$success_total = $stats['success_total'];
$fail_total = $stats['fail_total'];
$grand_total = $stats['grand_total'];

$dim_mode    = $stats['dim_mode'];
$matrix      = $stats['matrix'];
$dim_totals  = $stats['dim_totals'];
$dim_labels  = $stats['dim_labels'];

$group_agent_matrix  = $stats['group_agent_matrix'];
$group_agent_totals  = $stats['group_agent_totals'];
$group_totals        = $stats['group_totals'];
$group_labels        = $stats['group_labels'];
$agent_labels        = $stats['agent_labels'];

/**
 * ========================
 * 회사/지점/담당자 드롭다운 옵션
 * ========================
 */
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
$company_options = $build_org_select_options['company_options'];
$group_options   = $build_org_select_options['group_options'];
$agent_options   = $build_org_select_options['agent_options'];
/**
 * ========================
 */

// --------------------------------------------------
// 화면 출력
// --------------------------------------------------
$token = get_token();
$g5['title'] = '통계확인';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
?>
<style>
.opt-sep { color:#888; font-style:italic; }
.status-chip { display:inline-block; padding:2px 6px; border-radius:10px; font-size:12px; vertical-align:middle; }
.btn-convert-after { padding:4px 8px; font-size:12px; }
</style>

<!-- 검색/필터 -->
<div class="local_sch01 local_sch">
    <form method="get" action="./call_stats.php" class="form-row" id="searchForm">
        <label for="start">기간</label>
        <input type="date" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input">
        <span class="tilde">~</span>
        <input type="date" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input">

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
            <option value="all">전체</option>
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
    총 통화: <b id="stat_grand_total"><?php echo number_format($grand_total);?></b> 건
    &nbsp;|&nbsp;
    성공: <span class="badge badge-success"><span id="stat_success_total"><?php echo number_format($success_total);?></span></span>
    &nbsp;/&nbsp;
    실패: <span class="badge badge-fail"><span id="stat_fail_total"><?php echo number_format($fail_total);?></span></span>
</p>

<!-- 피벗 요약 테이블 -->
<div class="tbl_head01 tbl_wrap" style="margin-top:10px;">
    <table style="table-layout:fixed">
        <caption><?php echo $g5['title']; ?></caption>
        <thead>
        <tr>
            <th scope="col"><?php echo ($dim_mode==='group'?'지점':'담당자'); ?></th>
            <th scope="col">총합</th>
            <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
        </tr>
        </thead>
        <tbody>
        <tr style="background:#fafafa;font-weight:bold;">
            <td>합계</td>
            <td><?php echo number_format($grand_total); ?></td>
            <?php
            foreach ($code_list_status as $k => $item) {
                $cnt = !empty($top_sum_by_status[$k]) ? number_format($top_sum_by_status[$k]) : '-';
                $ui = $item['ui_type'] ?? 'secondary';
                echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
            }
            ?>
        </tr>

        <?php
        if (empty($matrix)) {
            echo '<tr><td colspan="'.(2+count($code_list)).'" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            ksort($matrix, SORT_NUMERIC);
            foreach ($matrix as $did => $rowset) {
                $label = $dim_labels[$did] ?? (($dim_mode==='group')?('지점 '.$did):('담당자 '.$did));
                $row_total = (int)($dim_totals[$did] ?? 0);
                echo '<tr>';
                echo '<td>'.get_text($label).'</td>';
                echo '<td>'.number_format($row_total).'</td>';
                foreach ($code_list_status as $k => $item) {
                    $cnt = isset($rowset[$k]) ? number_format($rowset[$k]) : '-';
                    $ui = $item['ui_type'] ?? 'secondary';
                    echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
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
            <table style="table-layout:fixed">
                <caption><?php echo get_text($group_labels[$gid] ?? ('지점 '.$gid)); ?></caption>
                <thead>
                    <tr>
                        <th scope="col">담당자</th>
                        <th scope="col">총합</th>
                        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#fafafa;font-weight:bold;">
                        <td><?php echo get_text($group_labels[$gid] ?? ('지점 '.$gid)); ?> 합계</td>
                        <td><?php echo number_format((int)($group_totals[$gid] ?? 0)); ?></td>
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
                        ?>
                    </tr>

                    <?php
                    ksort($agents, SORT_NUMERIC);
                    foreach ($agents as $aid => $rowset) {
                        $row_total = (int)($group_agent_totals[$gid][$aid] ?? 0);
                        $alabel = $agent_labels[$aid] ?? ('담당자 '.$aid);
                        echo '<tr>';
                        echo '<td>'.get_text($alabel).'</td>';
                        echo '<td>'.number_format($row_total).'</td>';
                        foreach ($code_list_status as $k => $item) {
                            $cnt = isset($rowset[$k]) ? number_format($rowset[$k]) : '-';
                            $ui = $item['ui_type'] ?? 'secondary';
                            echo '<td class="status-col status-'.get_text($ui).'">'.$cnt.'</td>';
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
<div class="tbl_head01 tbl_wrap" style="margin-top:14px;">
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
                <th>처리</th><!-- ★ 최우측 -->
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="16" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                // 포맷팅
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
                        // $kv = [];
                        // foreach ($decoded as $k=>$v) $kv[] = $k.': '.$v;
                        // $meta = implode(', ', $kv);
                        $meta = implode(',', $decoded);
                    } else {
                        $meta = get_text($row['meta_json']);
                    }
                }
                $meta = cut_str($meta, 30);
                $ui = !empty($status_ui[$row['call_status']]) ? $status_ui[$row['call_status']] : 'secondary';
                $class = 'status-col status-'.get_text($ui);

                // 전화번호 숨김 규칙
                $hp_display = '';
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
                                접수로 변경
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
// ===============================
// 비동기 조직(회사→지점) 셀렉트
// ===============================
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
    const mbGroup = document.getElementById('mb_group');
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
// ===============================
// 접수로 변경 버튼 처리
// ===============================
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

      // UI 업데이트: 같은 행의 통화결과 텍스트만 교체, 버튼 숨김
      const tr = btn.closest('tr');
      const tdResult = tr ? tr.children[4] : null; // 통화결과 칸
      if (tdResult) {
        tdResult.textContent = AFTER_LABEL;
        tdResult.classList.remove('status-secondary','status-warning','status-fail');
        tdResult.classList.add('status-success'); // 라벨 색상은 프로젝트 UI 규칙에 맞춰 조정
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

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
