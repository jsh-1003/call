<?php
// /adm/call/ajax_call_monitor.php
include_once('./_common.php');

// JSON 응답
header('Content-Type: application/json; charset=utf-8');

// 접근 권한: 관리자 레벨 7 이상만
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    die('접근 권한이 없습니다.');
}

/* ==========================
   기본 파라미터/공통
   ========================== */
$mb_no          = (int)($member['mb_no'] ?? 0);
$mb_level       = (int)($member['mb_level'] ?? 0);
$my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;

$now_ts        = time();
$default_end   = date('Y-m-d\TH:i', $now_ts);
$default_start = date('Y-m-d\TH:i', $now_ts - 24*3600);

$start = _g('start', $default_start); // datetime-local
$end   = _g('end',   $default_end);

$f_status = isset($_GET['status']) ? (int)$_GET['status'] : 0;

// 조직 선택(레벨 규칙)
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
$type = _g('type', '');

// 공통 테이블
$member_table = $g5['member_table']; // g5_member

/* ==========================
   마이크로 캐시 (APCu)
   ========================== */
function microcache_get($key) { if (function_exists('apcu_fetch')) { $v = apcu_fetch($key, $ok); if ($ok) return $v; } return null; }
function microcache_set($key, $val, $ttl=8) { if (function_exists('apcu_store')) apcu_store($key, $val, $ttl); }
function cm_cache_key($suffix='') {
    $params = [
        'start'=>$_GET['start']??'',
        'end'=>$_GET['end']??'',
        'status'=>$_GET['status']??'0',
        'company_id'=>$_GET['company_id']??'0',
        'mb_group'=>$_GET['mb_group']??'0',
        'agent'=>$_GET['agent']??'0',
        'role'=>$GLOBALS['member']['mb_level']??0,
        'myg'=>$GLOBALS['member']['mb_group']??0,
    ];
    return 'callmonitor:'.md5(json_encode($params).':'.$suffix);
}

/* ==========================
   WHERE 빌더
   ========================== */
// 활성 캠페인/타깃 기준 지점(+회사) 필터 (날짜와 무관, cc 별칭 기준)
function build_campaign_group_where($mb_level, $my_group, $sel_mb_group, $sel_company_id) {
    global $g5, $my_company_id;
    $w = [];
    if ($mb_level == 7) {
        $w[] = "cc.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        $w[] = "cc.mb_group = {$my_group}";
    } else {
        if ($sel_mb_group > 0) {
            $w[] = "cc.mb_group = {$sel_mb_group}";
        } elseif ($mb_level >= 9 && $sel_company_id > 0) {
            $grp_ids = [];
            $res = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($r = sql_fetch_array($res)) $grp_ids[] = (int)$r['mb_no'];
            $w[] = $grp_ids ? ("cc.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        } elseif ($mb_level == 8) {
            $grp_ids = [];
            $res = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($r = sql_fetch_array($res)) $grp_ids[] = (int)$r['mb_no'];
            $w[] = $grp_ids ? ("cc.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        }
    }
    return $w ? (' AND '.implode(' AND ', $w)) : '';
}

// 통화 로그 스코프 (l 별칭)
function build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status, $sel_company_id, $sel_mb_group, $sel_agent_no) {
    global $g5, $my_company_id;
    $w = [];
    $start_sql = sql_escape_string(str_replace('T',' ',$start).':00');
    $end_sql   = sql_escape_string(str_replace('T',' ',$end).':59');
    $w[] = "l.call_start BETWEEN '{$start_sql}' AND '{$end_sql}'";

    if ($f_status > 0) $w[] = "l.call_status = {$f_status}";

    if ($mb_level == 7) {
        $w[] = "l.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        $w[] = "l.mb_no = {$mb_no}";
    } else {
        if ($sel_mb_group > 0) {
            $w[] = "l.mb_group = {$sel_mb_group}";
        } else {
            if ($mb_level >= 9 && $sel_company_id > 0) {
                $grp_ids = [];
                $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
                while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
                $w[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
            } elseif ($mb_level == 8) {
                $grp_ids = [];
                $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
                while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
                $w[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
            }
        }
    }
    if ($sel_agent_no > 0) $w[] = "l.mb_no = {$sel_agent_no}";
    return $w ? ('WHERE '.implode(' AND ', $w)) : '';
}

function build_presence_agent_where($mb_level, $my_group, $sel_company_id, $sel_mb_group, $sel_agent_no) {
    global $my_company_id;
    $w = ["a.mb_level = 3"];

    if ($mb_level == 7) {
        $w[] = "a.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        return '1=0';
    } elseif ($sel_mb_group > 0) {
        $w[] = "a.mb_group = {$sel_mb_group}";
    } elseif ($mb_level >= 9) {
        if ($sel_company_id > 0) {
            $w[] = "a.company_id = {$sel_company_id}";
        } else {
            return '1=0';
        }
    } elseif ($mb_level == 8) {
        if ((int)$my_company_id > 0) {
            $w[] = "a.company_id = ".(int)$my_company_id;
        } else {
            return '1=0';
        }
    }

    if ($sel_agent_no > 0) $w[] = "a.mb_no = {$sel_agent_no}";

    return implode(' AND ', $w);
}

function normalize_presence_state($state, $last_heartbeat_at, $now_ts) {
    $state = strtoupper(trim((string)$state));
    $allowed = ['READY', 'DIALING', 'RINGING', 'CONNECTED', 'WRAPUP', 'OFFLINE'];

    if ($state === '' || !in_array($state, $allowed, true)) {
        $state = 'OFFLINE';
    }

    if ($state !== 'OFFLINE') {
        $hb_ts = $last_heartbeat_at ? strtotime((string)$last_heartbeat_at) : false;
        if (!$hb_ts || ($now_ts - $hb_ts) > 20) {
            $state = 'OFFLINE';
        }
    }

    return $state;
}

function presence_state_label($state) {
    switch (strtoupper((string)$state)) {
        case 'READY': return '대기중';
        case 'DIALING': return '발신중';
        case 'RINGING': return '연결대기';
        case 'CONNECTED': return '통화중';
        case 'WRAPUP': return '후처리중';
        case 'OFFLINE': return '오프라인';
        default: return '미확인';
    }
}

function presence_sort_priority($state) {
    switch (strtoupper((string)$state)) {
        case 'CONNECTED': return 1;
        case 'RINGING': return 2;
        case 'DIALING': return 3;
        case 'WRAPUP': return 4;
        case 'READY': return 5;
        case 'OFFLINE': return 6;
        default: return 7;
    }
}

/* ==========================
   상태 코드 UI 매핑
   ========================== */
$code_list = get_code_list($sel_mb_group);
$status_ui = [];
foreach($code_list as $v) {
    $status_ui[(int)$v['call_status']] = $v['ui_type'] ?? 'secondary';
}

/* ==========================
   캐시
   ========================== */
$where_sql = build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status, $sel_company_id, $sel_mb_group, $sel_agent_no);

// 버킷(30분)
$start_ts = strtotime(str_replace('T',' ',$start).':00');
$end_ts   = strtotime(str_replace('T',' ',$end).':59');
$start_bucket = floor($start_ts / 1800) * 1800;
$end_bucket   = floor($end_ts   / 1800) * 1800;

// recent_detail은 실시간성이 커서 캐시 제외
$cacheKey = cm_cache_key($type);
if (!in_array($type, ['recent_detail', 'presence'])) {
    $cached = microcache_get($cacheKey);
    if ($cached !== null) { echo $cached; exit; }
}

/* ==========================
   타입별 처리
   ========================== */
if ($type === 'kpi') {
    // call_recording은 (mb_group, campaign_id, call_id) 기준으로 유니크라고 가정
    $sql = "
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
            SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=0 THEN 1 ELSE 0 END) AS fail_cnt,
            AVG(l.call_time) AS avg_secs,
            SUM(l.call_time) AS sum_call_secs,                          -- 총 통화시간(초)
            SUM(COALESCE(r.duration_sec,0)) AS sum_talk_secs,           -- 총 상담시간(초)
            SUM(CASE WHEN COALESCE(sc.is_do_not_call,0)=1 THEN 1 ELSE 0 END) AS dnc_cnt,
            COUNT(DISTINCT l.mb_no) AS active_agents,
            COUNT(DISTINCT l.mb_group) AS active_groups
        FROM call_log l
        LEFT JOIN call_status_code sc
               ON sc.call_status = l.call_status AND sc.mb_group = 0
        LEFT JOIN call_recording r
               ON r.call_id = l.call_id
              AND r.mb_group = l.mb_group
              AND r.campaign_id = l.campaign_id
        {$where_sql}
    ";
    $r = sql_fetch($sql);

    // 잔여DB (캠페인+지점 동등조인, 조직 스코프 cc.mb_group)
    $pending_where = build_campaign_group_where($mb_level, $my_group, $sel_mb_group, $sel_company_id);
    $sql_pending = "
        SELECT COUNT(*) AS remain_cnt
        FROM call_target t
        JOIN call_campaign cc
          ON cc.campaign_id = t.campaign_id
         AND cc.mb_group    = t.mb_group
        WHERE cc.status IN (0,1)
          AND t.last_result IS NULL
        {$pending_where}
    ";
    $r2 = sql_fetch($sql_pending);

    $total   = (int)($r['total_cnt'] ?? 0);
    $success = (int)($r['success_cnt'] ?? 0);
    $fail    = (int)($r['fail_cnt'] ?? 0);
    $avg     = is_null($r['avg_secs']) ? null : round((float)$r['avg_secs'],1);
    $sumCall = (int)($r['sum_call_secs'] ?? 0);
    $sumTalk = (int)($r['sum_talk_secs'] ?? 0);
    $rate    = $total > 0 ? round($success*100.0/$total, 1) : 0.0;

    $json = json_encode([
        'ok'=>true,
        'remainDb'=>(int)($r2['remain_cnt'] ?? 0),
        'total'=>$total, 'success'=>$success, 'fail'=>$fail,
        'successRate'=>$rate,
        'avgSecs'=>$avg,
        'sumCallSecs'=>$sumCall,
        'sumTalkSecs'=>$sumTalk,
        'dnc'=>(int)($r['dnc_cnt'] ?? 0),
        'agents'=>(int)($r['active_agents'] ?? 0),
        'groups'=>(int)($r['active_groups'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    microcache_set($cacheKey, $json, 8);
    echo $json; exit;
}
elseif ($type === 'timeseries') {
    $sql = "
        SELECT
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(l.call_start)/1800)*1800) AS bucket_start,
            SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
            SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=0 THEN 1 ELSE 0 END) AS fail_cnt,
            AVG(l.call_time) AS avg_secs
        FROM call_log l
        LEFT JOIN call_status_code sc
          ON sc.call_status = l.call_status AND sc.mb_group = 0
        {$where_sql}
        GROUP BY bucket_start
        ORDER BY bucket_start ASC
    ";
    $res = sql_query($sql);
    $map = [];
    while ($row = sql_fetch_array($res)) {
        $k = $row['bucket_start'];
        $map[$k] = [
            's'=>(int)$row['success_cnt'],
            'f'=>(int)$row['fail_cnt'],
            'a'=>is_null($row['avg_secs'])?null:round((float)$row['avg_secs'],1),
        ];
    }
    $labels=[];$success=[];$fail=[];$avg=[];
    for ($t=$start_bucket; $t<=$end_bucket; $t+=1800) {
        $dt = date('Y-m-d H:i:s', $t);
        $labels[] = date('m/d H:i', $t);
        if (isset($map[$dt])) { $success[]=$map[$dt]['s']; $fail[]=$map[$dt]['f']; $avg[]=$map[$dt]['a']; }
        else { $success[]=0; $fail[]=0; $avg[]=null; }
    }
    $json = json_encode(['ok'=>true,'labels'=>$labels,'success'=>$success,'fail'=>$fail,'avg'=>$avg], JSON_UNESCAPED_UNICODE);
    microcache_set($cacheKey, $json, 8);
    echo $json; exit;
}
elseif ($type === 'status') {
    $sql = "
        SELECT
            l.call_status,
            sc.is_after_call,
            sc.ui_type,
            COALESCE(sc.name_ko, CONCAT('코드 ', l.call_status)) AS label,
            COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS result_group,
            COUNT(*) AS cnt,
            MAX(COALESCE(sc.is_do_not_call,0)) AS is_dnc
        FROM call_log l
        LEFT JOIN call_status_code sc
          ON sc.call_status = l.call_status AND sc.mb_group = 0
        {$where_sql}
        GROUP BY l.call_status, label, result_group
        ORDER BY cnt DESC
    ";
    $res = sql_query($sql);
    $rows = [];
    while ($r = sql_fetch_array($res)) $rows[] = [
        'call_status'=>(int)$r['call_status'],
        'label'=>get_text($r['label']),
        'result_group'=>(int)$r['result_group'],
        'cnt'=>(int)$r['cnt'],
        'is_dnc'=>(int)$r['is_dnc'],
        'ui_type'=>$r['ui_type'],
        'is_after_call'=>$r['is_after_call'],
    ];
    $json = json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
    microcache_set($cacheKey, $json, 8);
    echo $json; exit;
}
elseif ($type === 'agents') {
    $sql = "
        SELECT
            l.mb_no,
            m.mb_name,
            COUNT(*) AS call_cnt,
            SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
            AVG(l.call_time) AS avg_secs,
            SUM(l.call_time) AS sum_call_secs,
            AVG(r.duration_sec) AS avg_talk_secs,
            SUM(COALESCE(r.duration_sec,0)) AS sum_talk_secs
        FROM call_log l
        LEFT JOIN call_status_code sc
               ON sc.call_status = l.call_status AND sc.mb_group = 0
        LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
        LEFT JOIN call_recording r
               ON r.call_id = l.call_id
              AND r.mb_group = l.mb_group
              AND r.campaign_id = l.campaign_id
        {$where_sql}
        GROUP BY l.mb_no, m.mb_name
        ORDER BY call_cnt DESC
        LIMIT 200
    ";
    $res = sql_query($sql);
    $rows = [];
    while ($r = sql_fetch_array($res)) {
        $call_cnt = (int)$r['call_cnt'];
        $success  = (int)$r['success_cnt'];
        $sumCall  = (int)($r['sum_call_secs'] ?? 0);
        $sumTalk  = (int)($r['sum_talk_secs'] ?? 0);
        $rows[] = [
            'mb_no'         => (int)$r['mb_no'],
            'mb_name'       => get_text($r['mb_name']),
            'call_cnt'      => $call_cnt,
            'success_cnt'   => $success,
            'success_rate'  => ($call_cnt>0? round($success*100.0/$call_cnt,1):0.0),
            'avg_secs'      => is_null($r['avg_secs'])?null:round((float)$r['avg_secs'],1),
            'sum_call_secs' => $sumCall,
            'sum_talk_secs' => $sumTalk,
            'sum_call_hms'  => fmt_hms($sumCall),
            'sum_talk_hms'  => fmt_hms($sumTalk),
            'avg_talk_secs' => is_null($r['avg_talk_secs'])?null:round((float)$r['avg_talk_secs'],1),
        ];
    }
    $json = json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
    microcache_set($cacheKey, $json, 8);
    echo $json; exit;
}
elseif ($type === 'groups_table') {
    $sql = "
        SELECT
            l.mb_group,
            COALESCE(g.mv_group_name, CONCAT('지점 ', l.mb_group)) AS group_name,
            COUNT(*) AS call_cnt,
            SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
            AVG(l.call_time) AS avg_secs,
            SUM(l.call_time) AS sum_call_secs,
            AVG(r.duration_sec) AS avg_talk_secs,
            SUM(COALESCE(r.duration_sec,0)) AS sum_talk_secs
        FROM call_log l
        LEFT JOIN call_status_code sc
               ON sc.call_status = l.call_status AND sc.mb_group = 0
        LEFT JOIN (
            SELECT mb_no AS mb_group,
                   MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('지점 ', mb_no))) AS mv_group_name
            FROM {$member_table}
            WHERE mb_level = 7
            GROUP BY mb_no
        ) AS g ON g.mb_group = l.mb_group
        LEFT JOIN call_recording r
               ON r.call_id = l.call_id
              AND r.mb_group = l.mb_group
              AND r.campaign_id = l.campaign_id
        {$where_sql}
        GROUP BY l.mb_group, group_name
        ORDER BY call_cnt DESC, l.mb_group ASC
        LIMIT 200
    ";
    $res = sql_query($sql);
    $rows = [];
    while ($r = sql_fetch_array($res)) {
        $call_cnt = (int)$r['call_cnt'];
        $success  = (int)$r['success_cnt'];
        $sumCall  = (int)($r['sum_call_secs'] ?? 0);
        $sumTalk  = (int)($r['sum_talk_secs'] ?? 0);
        $rows[] = [
            'mb_group'      => (int)$r['mb_group'],
            'group_name'    => get_text($r['group_name']),
            'call_cnt'      => $call_cnt,
            'success_cnt'   => $success,
            'fail_cnt'      => max(0, $call_cnt - $success),
            'success_rate'  => ($call_cnt>0? round($success*100.0/$call_cnt,1):0.0),
            'avg_secs'      => is_null($r['avg_secs'])?null:round((float)$r['avg_secs'],1),
            'sum_call_secs' => $sumCall,
            'sum_talk_secs' => $sumTalk,
            'sum_call_hms'  => fmt_hms($sumCall),
            'sum_talk_hms'  => fmt_hms($sumTalk),
            'avg_talk_secs' => is_null($r['avg_talk_secs'])?null:round((float)$r['avg_talk_secs'],1),
        ];
    }
    $json = json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
    microcache_set($cacheKey, $json, 8);
    echo $json; exit;
}
elseif ($type === 'presence') {
    $agent_where = build_presence_agent_where($mb_level, $my_group, $sel_company_id, $sel_mb_group, $sel_agent_no);
    if ($agent_where === '1=0') {
        echo json_encode(['ok'=>true, 'rows'=>[], 'server_time'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql_agents = "
        SELECT
            a.mb_no AS agent_no,
            a.mb_id AS agent_mb_id,
            a.mb_name AS agent_name,
            a.mb_group,
            COALESCE(g.mv_group_name, CONCAT('지점 ', a.mb_group)) AS group_name
        FROM {$member_table} a
        LEFT JOIN (
            SELECT
                mb_no,
                MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('지점 ', mb_no))) AS mv_group_name
            FROM {$member_table}
            WHERE mb_level = 7
            GROUP BY mb_no
        ) AS g ON g.mb_no = a.mb_group
        WHERE {$agent_where}
        ORDER BY group_name ASC, a.mb_name ASC, a.mb_no ASC
    ";
    $res_agents = sql_query($sql_agents);
    $agents = [];
    $agent_ids = [];
    while ($r = sql_fetch_array($res_agents)) {
        $agent_no = (int)$r['agent_no'];
        $agents[$agent_no] = [
            'agent_no'    => $agent_no,
            'agent_mb_id' => get_text($r['agent_mb_id']),
            'agent_name'  => get_text($r['agent_name']),
            'mb_group'    => (int)$r['mb_group'],
            'group_name'  => get_text($r['group_name'] ?: ('지점 '.(int)$r['mb_group'])),
        ];
        $agent_ids[] = $agent_no;
    }

    if (!$agents) {
        echo json_encode(['ok'=>true, 'rows'=>[], 'server_time'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $presence_map = [];
    $sql_presence = "
        SELECT
            id,
            user_id,
            state,
            target_id,
            phone_number,
            mode,
            last_event_at,
            last_heartbeat_at,
            updated_at
        FROM call_agent_presence
        WHERE user_id IN (".implode(',', $agent_ids).")
        ORDER BY user_id ASC, updated_at DESC, id DESC
    ";
    $res_presence = sql_query($sql_presence);
    while ($r = sql_fetch_array($res_presence)) {
        $user_id = (int)$r['user_id'];
        if (!isset($presence_map[$user_id])) {
            $presence_map[$user_id] = $r;
        }
    }

    $server_time = date('Y-m-d H:i:s');
    $server_ts   = strtotime($server_time);
    $rows = [];
    foreach ($agents as $agent_no => $agent) {
        $presence = $presence_map[$agent_no] ?? null;
        $raw_state = $presence['state'] ?? 'OFFLINE';
        $last_hb = $presence['last_heartbeat_at'] ?? '';
        $state = normalize_presence_state($raw_state, $last_hb, $server_ts);

        $phone = '';
        if (!empty($presence['phone_number'])) {
            $phone = get_text(format_korean_phone((string)$presence['phone_number']));
        }

        $detail = '-';
        if (!empty($presence['target_id']) || $phone !== '') {
            $detail_parts = [];
            if (!empty($presence['target_id'])) $detail_parts[] = '대상 #'.(int)$presence['target_id'];
            if ($phone !== '') $detail_parts[] = $phone;
            $detail = implode(' · ', $detail_parts);
        }

        $rows[] = [
            'agent_no'      => $agent_no,
            'agent_mb_id'   => $agent['agent_mb_id'],
            'agent_name'    => $agent['agent_name'] ?: $agent['agent_mb_id'],
            'group_name'    => $agent['group_name'],
            'state'         => $state,
            'state_label'   => presence_state_label($state),
            'phone_number'  => $phone ?: '-',
            'last_detail'   => $detail,
            'last_event_at' => $presence['last_event_at'] ?? '',
            'updated_at'    => $presence['updated_at'] ?? '',
            'is_stale'      => ($state === 'OFFLINE'),
        ];
    }

    usort($rows, function($a, $b) {
        $pa = presence_sort_priority($a['state']);
        $pb = presence_sort_priority($b['state']);
        if ($pa !== $pb) return $pa <=> $pb;
        if ($a['group_name'] !== $b['group_name']) return strcmp((string)$a['group_name'], (string)$b['group_name']);
        return strcmp((string)$a['agent_name'], (string)$b['agent_name']);
    });

    $json = json_encode([
        'ok'          => true,
        'rows'        => $rows,
        'server_time' => $server_time,
    ], JSON_UNESCAPED_UNICODE);
    microcache_set($cacheKey, $json, 2);
    echo $json;
    exit;
}
elseif ($type === 'recent_detail') {
    // 최근 50건 상세. 레코딩은 call_id 유니크라고 했으므로 직접 조인
    $sql = "
        SELECT
            l.call_id, 
            l.mb_group,
            COALESCE(g.mv_group_name, CONCAT('지점 ', l.mb_group))   AS group_name,
            l.mb_no                                                AS agent_id,
            m.mb_name                                              AS agent_name,
            m.mb_id                                                AS agent_mb_id,
            l.call_status,
            sc.name_ko                                             AS status_label,
            COALESCE(sc.is_after_call,0)                           AS is_after_call,
            l.call_start, 
            l.call_end,
            l.call_time,
            l.agent_phone,
            r.duration_sec                                         AS talk_time,
            t.name                                                 AS target_name,
            t.birth_date,
            CASE
                WHEN t.birth_date IS NULL THEN NULL
                ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
                     - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
            END AS man_age,
            l.call_hp,
            t.meta_json,
            cc.name                                                AS campaign_name,
            COALESCE(cc.is_open_number,1)                          AS is_open_number
        FROM call_log l
        JOIN call_target t 
          ON t.target_id = l.target_id
        LEFT JOIN {$member_table} m 
          ON m.mb_no = l.mb_no
        LEFT JOIN (
            SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('지점 ', mb_group))) AS mv_group_name
              FROM {$member_table}
             WHERE mb_group > 0
             GROUP BY mb_group
        ) AS g ON g.mb_group = l.mb_group
        LEFT JOIN call_status_code sc
          ON sc.call_status = l.call_status AND sc.mb_group = 0
        LEFT JOIN call_recording r
          ON r.call_id = l.call_id
         AND r.mb_group = l.mb_group
         AND r.campaign_id = l.campaign_id
        JOIN call_campaign cc
          ON cc.campaign_id = l.campaign_id
         AND (cc.mb_group    = l.mb_group OR (cc.is_paid_db = 1 AND cc.mb_group = 0))
         AND cc.status      IN (0,1)
        ".build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status, $sel_company_id, $sel_mb_group, $sel_agent_no)."
        ORDER BY l.call_start DESC, l.call_id DESC
        LIMIT 50
    ";
    $res = sql_query($sql);
    if(!empty($aaa)) {
        echo $sql;
    }
    $rows = [];
    while ($r = sql_fetch_array($res)) {
        // 메타 축약
        $meta = '-';
        if (!is_null($r['meta_json']) && $r['meta_json'] !== '') {
            $metaArr = json_decode($r['meta_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($metaArr)) {
                $vals = [];
                foreach ($metaArr as $k=>$v) {
                    if (is_scalar($v)) $vals[] = (string)$v;
                    elseif (is_array($v)) $vals[] = json_encode($v, JSON_UNESCAPED_UNICODE);
                    else $vals[] = strval($v);
                }
                $metaStr = implode(', ', $vals);
                if (mb_strlen($metaStr,'UTF-8') > 120) $metaStr = mb_substr($metaStr,0,120,'UTF-8').'…';
                $meta = get_text($metaStr);
            }
        }

        // 번호 노출 정책
        if ((int)$r['is_open_number'] === 0 && (int)$r['is_after_call'] !== 1 && $mb_level < 9) {
            $hp_display = '(숨김처리)';
        } else {
            $hp_display = get_text(format_korean_phone($r['call_hp']));
        }

        // 발신 번호 표시
        $agent_phone = '-';
        if($r['agent_phone']) {
            $agent_phone = get_text(format_korean_phone($r['agent_phone']));
            if(strlen($agent_phone) == 13) $agent_phone = substr($agent_phone, 4, 9);
        }

        $ui = !empty($status_ui[$r['call_status']]) ? $status_ui[$r['call_status']] : 'secondary';
        $class_name = 'status-col status-'.get_text($ui);

        $rows[] = [
            'group_name'   => get_text($r['group_name'] ?: ('지점 '.(int)$r['mb_group'])),
            'agent_mb_id'  => get_text($r['agent_mb_id']),
            'agent_name'   => get_text($r['agent_name'] ?: (string)$r['agent_mb_id']),
            'agent_phone'  => $agent_phone,
            'status_label' => get_text($r['status_label'] ?: ('코드 '.(int)$r['call_status'])),
            'call_start'   => fmt_datetime(get_text($r['call_start']), 'mdhi'),
            'call_end'     => fmt_datetime(get_text($r['call_end']),   'mdhi'),
            'call_time'    => is_null($r['call_time']) ? '-' : fmt_hms((int)$r['call_time']),
            'talk_time'    => is_null($r['talk_time']) ? '-' : fmt_hms((int)$r['talk_time']),
            'target_name'  => get_text($r['target_name'] ?: '-'),
            'birth_date'   => get_text($r['birth_date'] ?: '-'),
            'man_age'      => is_null($r['man_age']) ? '-' : ((int)$r['man_age']).'세',
            'call_hp'      => $hp_display,
            'meta'         => $meta,
            'campaign_name'=> get_text($r['campaign_name'] ?: '-'),
            'class_name'   => $class_name
        ];
    }
    echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// unknown
echo json_encode(['ok'=>false,'msg'=>'unknown type'], JSON_UNESCAPED_UNICODE);
exit;
