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
// 마이크로 캐시(APCu) 유틸
// --------------------------------------------------
function microcache_get($key) {
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($key, $ok);
        if ($ok) return $v;
    }
    return null;
}
function microcache_set($key, $val, $ttl=30) {
    if (function_exists('apcu_store')) {
        apcu_store($key, $val, $ttl);
    }
}
function stats_cache_key($suffix='') {
    $params = [
        'start'=>$_GET['start']??'',
        'end'=>$_GET['end']??'',
        'q'=>$_GET['q']??'',
        'q_type'=>$_GET['q_type']??'',
        'status'=>$_GET['status']??'',
        'company_id'=>$_GET['company_id']??'',
        'mb_group'=>$_GET['mb_group']??'',
        'agent'=>$_GET['agent']??'',
        'role'=>$GLOBALS['member']['mb_level']??0,
        'myg'=>$GLOBALS['member']['mb_group']??0,
    ];
    return 'callstats:'.md5(json_encode($params).':'.$suffix);
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

// ★ 권한 스코프에 따른 회사/그룹/담당자 선택값
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
$page_rows = 15; // 상세 리스트 15건 고정
$offset    = ($page - 1) * $page_rows;

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
    // 회사 스코프는 에이전트의 company_id 기준
    if ($mb_level == 8) {
        $where[] = "m.company_id = {$my_company_id}";
    } elseif ($mb_level >= 9 && $sel_company_id > 0) {
        $where[] = "m.company_id = {$sel_company_id}";
    }
    // 그룹 선택
    if ($sel_mb_group > 0) $where[] = "l.mb_group = {$sel_mb_group}";
}

// 담당자 선택
if ($sel_agent_no > 0) {
    $where[] = "l.mb_no = {$sel_agent_no}";
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// --------------------------------------------------
// 전역: 상태코드 목록 (셀렉트 박스용)
// --------------------------------------------------
$codes = [];
$rc = sql_query("
    SELECT call_status, name_ko, status
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
");
while ($r = sql_fetch_array($rc)) $codes[] = $r;

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
//  ※ 회사 스코프 조건이 where_sql에 포함되므로
//    반드시 g5_member m 조인이 필요
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
            $id_list = implode(',', array_map('intval', $ids));
            $rs = sql_query("
                SELECT DISTINCT mb_group,
                       COALESCE(mb_group_name, CONCAT('그룹 ', mb_group)) AS nm
                  FROM {$member_table}
                 WHERE mb_group IN ({$id_list})
            ");
            while ($r = sql_fetch_array($rs)) {
                $result['dim_labels'][(int)$r['mb_group']] = get_text($r['nm']);
            }
            foreach ($ids as $gid) if (!isset($result['dim_labels'][$gid])) $result['dim_labels'][$gid] = '그룹 '.$gid;
        }
    }

    // 그룹 미선택 시: 그룹별 담당자
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
            $glist = implode(',', $gids);
            $rqg = sql_query("
                SELECT DISTINCT mb_group,
                       COALESCE(mb_group_name, CONCAT('그룹 ', mb_group)) AS nm
                  FROM {$member_table}
                 WHERE mb_group IN ({$glist})
            ");
            while ($r = sql_fetch_array($rqg)) {
                $result['group_labels'][(int)$r['mb_group']] = get_text($r['nm']);
            }
            foreach ($gids as $gid) if (!isset($result['group_labels'][$gid])) $result['group_labels'][$gid] = '그룹 '.$gid;

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
// 총 건수 (상세 리스트 페이징용) — 회사 스코프 반영을 위해 m 조인
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
// 상세 목록 쿼리 (15건 고정)
//  + 전화번호 숨김 규칙 적용을 위해 cc.is_open_number, sc.is_after_call 선택
// --------------------------------------------------
$sql_list = "
    SELECT
        l.call_id, 
        l.mb_group,
        COALESCE(g.mv_group_name, CONCAT('그룹 ', l.mb_group))          AS group_name,
        l.mb_no                                                        AS agent_id,
        m.mb_name                                                      AS agent_name,
        m.mb_id                                                        AS agent_mb_id,
        m.company_id                                                   AS agent_company_id,
        l.call_status,
        sc.name_ko                                                     AS status_label,
        sc.is_after_call                                               AS sc_is_after_call,   -- ★ 추가
        l.call_start, 
        l.call_end,
        l.call_time,                                                   -- 통화시간(초)
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
        cc.is_open_number                                              AS cc_is_open_number   -- ★ 추가
    FROM call_log l
    JOIN call_target t 
      ON t.target_id = l.target_id
 LEFT JOIN {$member_table} m 
      ON m.mb_no = l.mb_no
    /* 그룹명: 그룹별 대표명 파생 */
 LEFT JOIN (
        SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹 ', mb_group))) AS mv_group_name
          FROM {$member_table}
         WHERE mb_group > 0
         GROUP BY mb_group
 ) AS g ON g.mb_group = l.mb_group
    /* 통화결과 라벨(공통셋) */
 LEFT JOIN call_status_code sc
      ON sc.call_status = l.call_status AND sc.mb_group = 0
    /* 상담시간(녹취 길이) */
 LEFT JOIN call_recording rec
      ON rec.call_id = l.call_id 
     AND rec.mb_group = l.mb_group
     AND rec.campaign_id = l.campaign_id
    /* 캠페인명/옵션 */
  JOIN call_campaign cc
      ON cc.campaign_id = l.campaign_id
     AND cc.mb_group = l.mb_group
    {$where_sql}
    ORDER BY l.call_start DESC, l.call_id DESC
    LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

// --------------------------------------------------
// 상단/피벗/그룹별담당자 통계 계산 (HTML 렌더용)
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

// --------------------------------------------------
// 회사/그룹/담당자 드롭다운 옵션
// --------------------------------------------------

// (1) 회사 옵션 (레벨 9+)
$company_options = [];
if ($mb_level >= 9) {
    $res = sql_query("
        SELECT m.company_id
          FROM {$member_table} m
         WHERE m.mb_level = 8
         GROUP BY m.company_id
         ORDER BY COALESCE(NULLIF(m.company_name,''), CONCAT('회사-', m.company_id)) ASC, m.company_id ASC
    ");
    while ($r = sql_fetch_array($res)) {
        $cid   = (int)$r['company_id'];
        $cname = get_company_name_cached($cid);
        $company_options[] = [
            'company_id'   => $cid,
            'company_name' => $cname,
        ];
    }
}

// (2) 그룹 옵션 (레벨 8+)
$group_options = [];
if ($mb_level >= 8) {
    $where_g = " WHERE m.mb_level = 7 ";
    if ($mb_level >= 9) {
        if ($sel_company_id > 0) $where_g .= " AND m.company_id = '{$sel_company_id}' ";
    } else {
        $where_g .= " AND m.company_id = '{$my_company_id}' ";
    }
    $rs = sql_query("
        SELECT m.mb_group,
               COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹 ', m.mb_group)) AS mb_group_name
          FROM {$member_table} m
          {$where_g}
         GROUP BY m.mb_group, mb_group_name
         ORDER BY mb_group_name ASC, m.mb_group ASC
    ");
    while ($row = sql_fetch_array($rs)) {
        $group_options[] = [
            'mb_group'      => (int)$row['mb_group'],
            'mb_group_name' => get_text($row['mb_group_name']),
        ];
    }
}

// (3) 담당자 옵션 (회사/그룹 스코프 반영)
$agent_options = [];
$agent_where = [];
$agent_order = " ORDER BY mb_group ASC, mb_name ASC, mb_no ASC ";

if ($mb_level >= 8) {
    if ($mb_level == 8) {
        $agent_where[] = "company_id = {$my_company_id}";
    } elseif ($mb_level >= 9 && $sel_company_id > 0) {
        $agent_where[] = "company_id = {$sel_company_id}";
    }
    if ($sel_mb_group > 0) $agent_where[] = "mb_group = {$sel_mb_group}";
    else $agent_where[] = "mb_group > 0";
} else {
    $agent_where[] = "mb_group = {$my_group}";
}
$agent_where_sql = $agent_where ? ('WHERE '.implode(' AND ', $agent_where)) : '';

$qr = sql_query("
    SELECT 
        mb_no, 
        mb_name,
        mb_group,
        COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹 ', mb_group)) AS mb_group_name
    FROM {$member_table}
    {$agent_where_sql}
    {$agent_order}
");
while ($r = sql_fetch_array($qr)) {
    $agent_options[] = [
        'mb_no'         => (int)$r['mb_no'],
        'mb_name'       => get_text($r['mb_name']),
        'mb_group'      => (int)$r['mb_group'],
        'mb_group_name' => get_text($r['mb_group_name']),
    ];
}

// --------------------------------------------------
// 화면 출력
// --------------------------------------------------
$token = get_token();
$g5['title'] = '통계확인';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
?>
<style>
/* 그룹 구분선 option */
.opt-sep { color:#888; font-style:italic; }
</style>

<!-- 검색/필터 -->
<div class="local_sch01 local_sch">
    <form method="get" action="./call_stats.php" class="form-row" id="searchForm">
        <!-- 1줄차: 기간/바로가기/검색기본 -->
        <label for="start">기간</label>
        <input type="date" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input" style="width:140px">
        ~
        <input type="date" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input" style="width:140px">

        <span class="btn-line">
            <button type="button" class="btn-mini" id="btnYesterday">어제</button>
            <button type="button" class="btn-mini" id="btnToday">오늘</button>
        </span>

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
        <?php if ($where_sql) { ?>
        <a href="./call_stats.php" class="btn btn_02">초기화</a>
        <?php } ?>
        <span class="small-muted">권한:
            <?php
            if ($mb_level >= 8) echo '전체';
            elseif ($mb_level == 7) echo '조직';
            else echo '개인';
            ?>
        </span>

        <span class="row-split"></span>

        <!-- 2줄차: 회사/그룹/담당자 -->
        <?php if ($mb_level >= 9) { ?>
            <label for="company_id">회사</label>
            <select name="company_id" id="company_id" style="width:200px">
                <option value="0"<?php echo $sel_company_id===0?' selected':'';?>>전체 회사</option>
                <?php foreach ($company_options as $c) { ?>
                    <option value="<?php echo (int)$c['company_id'];?>" <?php echo ($sel_company_id===(int)$c['company_id']?' selected':'');?>>
                        <?php echo get_text($c['company_name']);?>
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="company_id" value="<?php echo (int)$sel_company_id; ?>">
            <span class="small-muted">회사: <?php echo get_text(get_company_name_cached($sel_company_id));?></span>
        <?php } ?>

        <?php if ($mb_level >= 8) { ?>
            <label for="mb_group">그룹선택</label>
            <select name="mb_group" id="mb_group" style="width:200px">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 그룹</option>
                <?php foreach ($group_options as $g) { ?>
                    <option value="<?php echo (int)$g['mb_group'];?>"
                        <?php echo ($sel_mb_group===(int)$g['mb_group']?' selected':'');?>>
                        <?php echo get_text($g['mb_group_name']);?>
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="mb_group" value="<?php echo $sel_mb_group; ?>">
            <?php
            $sel_group_name = '';
            if ($sel_mb_group > 0) {
                $row = sql_fetch("
                    SELECT COALESCE(mb_group_name, CONCAT('그룹 ', mb_group)) AS nm
                      FROM {$member_table}
                     WHERE mb_group = {$sel_mb_group}
                     LIMIT 1
                ");
                $sel_group_name = $row ? $row['nm'] : ('그룹 '.$sel_mb_group);
            }
            ?>
            <span class="small-muted">그룹: <?php echo $sel_mb_group>0 ? get_text($sel_group_name) : '전체';?></span>
        <?php } ?>

        <?php if ($sel_mb_group > 0 || $mb_level >= 7) { ?>
        <label for="agent">담당자</label>
        <select name="agent" id="agent" style="width:220px">
            <option value="0">전체 담당자</option>
            <?php echo render_agent_options($agent_options, $sel_agent_no); ?>
        </select>
        <?php } ?>
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
            <th scope="col"><?php echo ($dim_mode==='group'?'그룹':'담당자'); ?></th>
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
                $label = $dim_labels[$did] ?? (($dim_mode==='group')?('그룹 '.$did):('담당자 '.$did));
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

<!-- 그룹 미선택 시: 그룹별 담당자 통계 -->
<?php if ($sel_mb_group === 0) { ?>
    <h3 style="margin-top:18px;">그룹별 담당자 통계</h3>

    <?php if (empty($group_agent_matrix)) { ?>
        <div class="tbl_head01 tbl_wrap" style="margin-top:8px;">
            <table><tbody><tr><td class="empty_table">데이터가 없습니다.</td></tr></tbody></table>
        </div>
    <?php } else { ?>
        <?php
        ksort($group_agent_matrix, SORT_NUMERIC);
        foreach ($group_agent_matrix as $gid => $agents) {
        ?>
        <div class="tbl_head01 tbl_wrap" style="margin-top:10px;">
            <table style="table-layout:fixed">
                <caption><?php echo get_text($group_labels[$gid] ?? ('그룹 '.$gid)); ?></caption>
                <thead>
                    <tr>
                        <th scope="col">담당자</th>
                        <th scope="col">총합</th>
                        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#fafafa;font-weight:bold;">
                        <td><?php echo get_text($group_labels[$gid] ?? ('그룹 '.$gid)); ?> 합계</td>
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

<!-- 상세 목록 : 15건 고정 -->
<div class="tbl_head01 tbl_wrap" style="margin-top:14px;">
    <table class="table-fixed">
        <thead>
            <tr>
                <th>그룹명</th>
                <th>아이디</th>
                <th>상담원명</th>
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
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="14" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                // 포맷팅
                $hp_fmt   = format_korean_phone($row['call_hp']);
                $talk_sec = is_null($row['talk_time']) ? '-' : fmt_hms((int)$row['talk_time']);
                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);
                $bday     = empty($row['birth_date']) ? '-' : get_text($row['birth_date']);
                $man_age  = is_null($row['man_age'])   ? '-' : ((int)$row['man_age']).'세';
                $agent    = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];
                $status   = $row['status_label'] ?: ('코드 '.$row['call_status']);
                $gname    = $row['group_name'] ?: ('그룹 '.(int)$row['mb_group']);
                $meta     = '-';
                if (!is_null($row['meta_json']) && $row['meta_json'] !== '') {
                    $decoded = json_decode($row['meta_json'], true);
                    if (is_array($decoded)) {
                        $kv = [];
                        foreach ($decoded as $k=>$v) $kv[] = $k.': '.$v;
                        $meta = implode(', ', $kv);
                    } else {
                        $meta = get_text($row['meta_json']);
                    }
                }
                $ui = !empty($status_ui[$row['call_status']]) ? $status_ui[$row['call_status']] : 'secondary';
                $class = 'status-col status-'.get_text($ui);

                // ★ 전화번호 숨김 규칙:
                //   - 캠페인 cc.is_open_number == 0 이고
                //   - 상태코드 sc.is_after_call != 1 이면, 번호는 "(숨김처리)"
                $hp_display = $hp_fmt;
                if ((int)$row['cc_is_open_number'] === 0 && (int)$row['sc_is_after_call'] !== 1) {
                    $hp_display = '(숨김처리)';
                }
                ?>
                <tr>
                    <td><?php echo get_text($gname); ?></td>
                    <td><?php echo get_text($row['agent_mb_id']); ?></td>
                    <td><?php echo get_text($agent); ?></td>
                    <td class="<?php echo $class ?>"><?php echo get_text($status); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_start']), 'mdhi'); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_end']), 'mdhi'); ?></td>
                    <td><?php echo $call_sec; ?></td>
                    <td><?php echo $talk_sec; ?></td>
                    <td><?php echo get_text($row['target_name'] ?: '-'); ?></td>
                    <td><?php echo $bday; ?></td>
                    <td><?php echo $man_age; ?></td>
                    <td><?php echo get_text($hp_display); ?></td>
                    <td><?php echo $meta; ?></td>
                    <td><?php echo get_text($row['campaign_name'] ?: '-'); ?></td>
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
// 날짜 유틸
function pad2(n){ return (n<10?'0':'')+n; }
function fmt(d){ return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); }

// 어제/오늘 버튼 및 자동검색 + 회사/그룹/담당자 연동
(function(){
    var $start = document.getElementById('start');
    var $end   = document.getElementById('end');
    var $form  = document.getElementById('searchForm');

    document.getElementById('btnYesterday').addEventListener('click', function(){
        var now = new Date(); now.setDate(now.getDate()-1);
        var y = fmt(now);
        $start.value = y;
        $end.value   = y;
        $form.submit();
    });

    document.getElementById('btnToday').addEventListener('click', function(){
        var now = new Date();
        var t = fmt(now);
        $start.value = t;
        $end.value   = t;
        $form.submit();
    });

    const today = fmt(new Date());
    const yestDate = new Date(); yestDate.setDate(yestDate.getDate() - 1);
    const yesterday = fmt(yestDate);
    if ($start.value === yesterday && $end.value === yesterday) {
        btnYesterday.classList.add('active');
    } else if ($start.value === today && $end.value === today) {
        btnToday.classList.add('active');
    }

    // ★ 회사 변경 시 그룹/담당자 초기화 후 자동검색
    var companySel = document.getElementById('company_id');
    if (companySel) {
        companySel.addEventListener('change', function(){
            var g = document.getElementById('mb_group');
            if (g) g.selectedIndex = 0;
            var a = document.getElementById('agent');
            if (a) a.selectedIndex = 0;
            $form.submit();
        });
    }

    // 그룹 변경 시 담당자 초기화 후 자동검색
    var mbGroup = document.getElementById('mb_group');
    if (mbGroup) {
        mbGroup.addEventListener('change', function(){
            var agent = document.getElementById('agent');
            if (agent) agent.selectedIndex = 0;
            $form.submit();
        });
    }

    // 담당자 변경 시 자동검색
    var agentSel = document.getElementById('agent');
    if (agentSel) {
        agentSel.addEventListener('change', function(){
            $form.submit();
        });
    }
})();

// 상단 통계 Ajax(+APCu 캐시)
(function(){
  function refreshStats(){
    var url = new URL(location.href);
    url.searchParams.set('ajax','stats');
    fetch(url.toString(), {credentials:'same-origin'})
      .then(r=>r.json())
      .then(data=>{
        var nf = new Intl.NumberFormat();
        var elTotal = document.getElementById('stat_grand_total');
        var elSucc  = document.getElementById('stat_success_total');
        var elFail  = document.getElementById('stat_fail_total');
        if (elTotal) elTotal.textContent = nf.format(data.grand_total||0);
        if (elSucc)  elSucc.textContent  = nf.format(data.success_total||0);
        if (elFail)  elFail.textContent  = nf.format(data.fail_total||0);
      })
      .catch(console.error);
  }
  document.addEventListener('DOMContentLoaded', refreshStats);
})();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
