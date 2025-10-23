<?php
// /adm/call/call_monitor.php (개편: 최근 통화 리스트 = 통계 상세 스키마)
$sub_menu = '700110';
require_once './_common.php';

// 접근 권한: 관리자 레벨 7 이상만
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// --------------------------------------------------
// APCu 마이크로 캐시 (옵션)
// --------------------------------------------------
function microcache_get($key) {
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($key, $ok);
        if ($ok) return $v;
    }
    return null;
}
function microcache_set($key, $val, $ttl=10) {
    if (function_exists('apcu_store')) {
        apcu_store($key, $val, $ttl);
    }
}
function cm_cache_key($suffix='') {
    $params = [
        'start'=>$_GET['start']??'',
        'end'=>$_GET['end']??'',
        'status'=>$_GET['status']??'0',
        'mb_group'=>$_GET['mb_group']??'0',
        'agent'=>$_GET['agent']??'0',
        'role'=>$GLOBALS['member']['mb_level']??0,
        'myg'=>$GLOBALS['member']['mb_group']??0,
    ];
    return 'callmonitor:'.md5(json_encode($params).':'.$suffix);
}

// --------------------------------------------------
// 기본 파라미터
// --------------------------------------------------
$mb_no    = (int)($member['mb_no'] ?? 0);
$mb_level = (int)($member['mb_level'] ?? 0);
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;

$now_ts   = time();
$default_end   = date('Y-m-d\TH:i', $now_ts);
$default_start = date('Y-m-d\TH:i', $now_ts - 24*3600);

$start = _g('start', $default_start); // datetime-local
$end   = _g('end',   $default_end);

$f_status     = isset($_GET['status']) ? (int)$_GET['status'] : 0;
$sel_mb_group = ($mb_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // 8+: 전체/특정, 7: 고정
$sel_agent_no = (int)($_GET['agent'] ?? 0);

// Ajax 분기
$ajax = isset($_GET['ajax']) ? (int)$_GET['ajax'] : 0;
$type = _g('type', '');

// 공통 테이블
$member_table = $g5['member_table']; // g5_member

// --------------------------------------------------
// WHERE 빌더 (권한/필터 통합)
// --------------------------------------------------
function build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status, $sel_mb_group, $sel_agent_no) {
    $w = [];
    // datetime-local -> 'Y-m-d H:i:s'
    $start_sql = sql_escape_string(str_replace('T',' ',$start).':00');
    $end_sql   = sql_escape_string(str_replace('T',' ',$end).':59');
    $w[] = "l.call_start BETWEEN '{$start_sql}' AND '{$end_sql}'";

    if ($f_status > 0) $w[] = "l.call_status = {$f_status}";

    // 권한/그룹
    if ($mb_level == 7) {
        $w[] = "l.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        $w[] = "l.mb_no = {$mb_no}";
    } else {
        if ($sel_mb_group > 0) $w[] = "l.mb_group = {$sel_mb_group}";
    }

    // 상담사 선택
    if ($sel_agent_no > 0) $w[] = "l.mb_no = {$sel_agent_no}";

    return $w ? ('WHERE '.implode(' AND ', $w)) : '';
}

// --------------------------------------------------
// Ajax 응답
// --------------------------------------------------
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    $where_sql = build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status, $sel_mb_group, $sel_agent_no);

    // 버킷
    $start_ts = strtotime(str_replace('T',' ',$start).':00');
    $end_ts   = strtotime(str_replace('T',' ',$end).':59');
    $start_bucket = floor($start_ts / 1800) * 1800;
    $end_bucket   = floor($end_ts   / 1800) * 1800;

    $cacheKey = cm_cache_key($type);
    if (!in_array($type, ['recent_detail'])) {
        $cached = microcache_get($cacheKey);
        if ($cached !== null) { echo $cached; exit; }
    }

    if ($type === 'kpi') {
        $sql = "
            SELECT
              COUNT(*) AS total_cnt,
              SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
              SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=0 THEN 1 ELSE 0 END) AS fail_cnt,
              AVG(l.call_time) AS avg_secs,
              SUM(CASE WHEN COALESCE(sc.is_do_not_call,0)=1 THEN 1 ELSE 0 END) AS dnc_cnt,
              COUNT(DISTINCT l.mb_no) AS active_agents,
              COUNT(DISTINCT l.mb_group) AS active_groups
            FROM call_log l
            LEFT JOIN call_status_code sc
              ON sc.call_status = l.call_status AND sc.mb_group = 0
            {$where_sql}
        ";
        $r = sql_fetch($sql);
        $total   = (int)($r['total_cnt'] ?? 0);
        $success = (int)($r['success_cnt'] ?? 0);
        $fail    = (int)($r['fail_cnt'] ?? 0);
        $avg     = is_null($r['avg_secs']) ? null : round((float)$r['avg_secs'],1);
        $rate    = $total > 0 ? round($success*100.0/$total, 1) : 0.0;
        $dnc     = (int)($r['dnc_cnt'] ?? 0);
        $agents  = (int)($r['active_agents'] ?? 0);
        $groups  = (int)($r['active_groups'] ?? 0);
        $json = json_encode(['ok'=>true, 'total'=>$total, 'success'=>$success, 'fail'=>$fail, 'successRate'=>$rate, 'avgSecs'=>$avg, 'dnc'=>$dnc, 'agents'=>$agents, 'groups'=>$groups], JSON_UNESCAPED_UNICODE);
        microcache_set($cacheKey, $json, 10);
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
        microcache_set($cacheKey, $json, 10);
        echo $json; exit;
    }
    elseif ($type === 'status') {
        $sql = "
            SELECT
              l.call_status,
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
        ];
        $json = json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        microcache_set($cacheKey, $json, 10);
        echo $json; exit;
    }
    elseif ($type === 'agents') {
        $sql = "
            SELECT
              l.mb_no,
              m.mb_name,
              COUNT(*) AS call_cnt,
              SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
              AVG(l.call_time) AS avg_secs
            FROM call_log l
            LEFT JOIN call_status_code sc
              ON sc.call_status = l.call_status AND sc.mb_group = 0
            LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
            {$where_sql}
            GROUP BY l.mb_no, m.mb_name
            ORDER BY call_cnt DESC
            LIMIT 10
        ";
        $res = sql_query($sql);
        $rows = [];
        while ($r = sql_fetch_array($res)) {
            $call_cnt = (int)$r['call_cnt'];
            $success  = (int)$r['success_cnt'];
            $rows[] = [
                'mb_no'=>(int)$r['mb_no'],
                'mb_name'=>get_text($r['mb_name']),
                'call_cnt'=>$call_cnt,
                'success_cnt'=>$success,
                'success_rate'=>($call_cnt>0? round($success*100.0/$call_cnt,1):0.0),
                'avg_secs'=> is_null($r['avg_secs'])?null:round((float)$r['avg_secs'],1),
            ];
        }
        $json = json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        microcache_set($cacheKey, $json, 10);
        echo $json; exit;
    }
    elseif ($type === 'groups_table') {
        $sql = "
            SELECT
              l.mb_group,
              COALESCE(g.mv_group_name, CONCAT('그룹 ', l.mb_group)) AS group_name,
              COUNT(*) AS call_cnt,
              SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
              AVG(l.call_time) AS avg_secs
            FROM call_log l
            LEFT JOIN call_status_code sc
              ON sc.call_status = l.call_status AND sc.mb_group = 0
            LEFT JOIN (
                SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹 ', mb_group))) AS mv_group_name
                  FROM {$member_table}
                 WHERE mb_group > 0
                 GROUP BY mb_group
            ) AS g ON g.mb_group = l.mb_group
            {$where_sql}
            GROUP BY l.mb_group, group_name
            ORDER BY call_cnt DESC, l.mb_group ASC
        ";
        $res = sql_query($sql);
        $rows = [];
        while ($r = sql_fetch_array($res)) {
            $call_cnt = (int)$r['call_cnt'];
            $success  = (int)$r['success_cnt'];
            $rows[] = [
                'mb_group'=>(int)$r['mb_group'],
                'group_name'=>get_text($r['group_name']),
                'call_cnt'=>$call_cnt,
                'success_cnt'=>$success,
                'fail_cnt'=>$call_cnt - $success,
                'success_rate'=>($call_cnt>0? round($success*100.0/$call_cnt,1):0.0),
                'avg_secs'=> is_null($r['avg_secs'])?null:round((float)$r['avg_secs'],1),
            ];
        }
        $json = json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        microcache_set($cacheKey, $json, 10);
        echo $json; exit;
    }
    elseif ($type === 'recent_detail') {
        // ✅ 통계 상세 리스트 스키마로 최근 20건
        // - 그룹명: g5_member 파생
        // - 통화결과 라벨: call_status_code (mb_group=0)
        // - 상담시간: call_recording.duration_sec
        // - 캠페인명: call_campaign.name (status=1 only)  ※ 비활성 캠페인 통화도 보려면 LEFT JOIN으로 바꿔도 됨
        $sql = "
            SELECT
                l.call_id, 
                l.mb_group,
                COALESCE(g.mv_group_name, CONCAT('그룹 ', l.mb_group))         AS group_name,
                l.mb_no                                                        AS agent_id,
                m.mb_name                                                      AS agent_name,
                m.mb_id                                                        AS agent_mb_id,
                l.call_status,
                sc.name_ko                                                     AS status_label,
                l.call_start, 
                l.call_end,
                l.call_time,                                                   -- 통화시간(초)
                rec.duration_sec                                               AS talk_time,    -- 상담시간
                t.name                                                         AS target_name,
                t.birth_date,
                -- 만나이 계산 (서버 도우미 대신 SQL 계산; 화면엔 문자열로 변환)
                CASE
                  WHEN t.birth_date IS NULL THEN NULL
                  ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
                       - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
                END AS man_age,
                l.call_hp,
                t.meta_json,
                cc.name                                                        AS campaign_name
            FROM call_log l
            JOIN call_target t 
              ON t.target_id = l.target_id
            LEFT JOIN {$member_table} m 
              ON m.mb_no = l.mb_no
            LEFT JOIN (
                SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹 ', mb_group))) AS mv_group_name
                  FROM {$member_table}
                 WHERE mb_group > 0
                 GROUP BY mb_group
            ) AS g ON g.mb_group = l.mb_group
            LEFT JOIN call_status_code sc
              ON sc.call_status = l.call_status AND sc.mb_group = 0
            LEFT JOIN call_recording rec
              ON rec.call_id = l.call_id 
             AND rec.mb_group = l.mb_group
             AND rec.campaign_id = l.campaign_id
            JOIN call_campaign cc
              ON cc.campaign_id = l.campaign_id
             AND cc.mb_group = l.mb_group
             AND cc.status = 1
            {$where_sql}
            ORDER BY l.call_start DESC, l.call_id DESC
            LIMIT 20
        ";
        $res = sql_query($sql);
        $rows = [];
        while ($r = sql_fetch_array($res)) {
            // 안전한 메타 축약
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
            $rows[] = [
                'group_name'   => get_text($r['group_name'] ?: ('그룹 '.(int)$r['mb_group'])),
                'agent_mb_id'  => get_text($r['agent_mb_id']),
                'agent_name'   => get_text($r['agent_name'] ?: (string)$r['agent_mb_id']),
                'status_label' => get_text($r['status_label'] ?: ('코드 '.(int)$r['call_status'])),
                'call_start'   => fmt_datetime(get_text($r['call_start']), 'mdhi'),
                'call_end'     => fmt_datetime(get_text($r['call_end']),   'mdhi'),
                'call_time'    => is_null($r['call_time']) ? '-' : fmt_hms((int)$r['call_time']),
                'talk_time'    => is_null($r['talk_time']) ? '-' : fmt_hms((int)$r['talk_time']),
                'target_name'  => get_text($r['target_name'] ?: '-'),
                'birth_date'   => get_text($r['birth_date'] ?: '-'),
                'man_age'      => is_null($r['man_age']) ? '-' : ((int)$r['man_age']).'세',
                'call_hp'      => get_text(format_korean_phone($r['call_hp'])),
                'meta'         => $meta,
                'campaign_name'=> get_text($r['campaign_name'] ?: '-'),
            ];
        }
        echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'unknown type'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------------------------------
// HTML 렌더링 준비
// --------------------------------------------------
$codes = [];
$qc = "SELECT call_status, name_ko, status FROM call_status_code WHERE mb_group=0 ORDER BY sort_order ASC, call_status ASC";
$rc = sql_query($qc);
while ($r = sql_fetch_array($rc)) $codes[] = $r;

$g5['title'] = '콜 모니터링';
include_once(G5_ADMIN_PATH.'/admin.head.php');

// 그룹 옵션(레벨 8+)
$group_options = [];
if ($mb_level >= 8) {
    $sql = "
        SELECT DISTINCT mb_group,
               COALESCE(mb_group_name, CONCAT('그룹 ', mb_group)) AS mb_group_name
          FROM {$member_table}
         WHERE mb_group > 0
           AND (mb_group_name IS NOT NULL AND mb_group_name <> '')
         ORDER BY mb_group_name ASC
    ";
    $res = sql_query($sql);
    while ($row = sql_fetch_array($res)) {
        $group_options[] = [
            'mb_group'=>(int)$row['mb_group'],
            'mb_group_name'=>get_text($row['mb_group_name']),
        ];
    }
}

// 상담사 옵션(선택 그룹 기준)
$agent_options = [];
$aw = [];
if ($mb_level >= 8) {
    if ($sel_mb_group > 0) $aw[] = "mb_group = {$sel_mb_group}";
    else $aw[] = "mb_group > 0";
} else { // 7
    $aw[] = "mb_group = {$my_group}";
}
$aw_sql = $aw ? 'WHERE '.implode(' AND ',$aw) : '';
$ar = sql_query("
    SELECT mb_no, mb_name, mb_group,
           COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹 ', mb_group)) AS mb_group_name
      FROM {$member_table}
     {$aw_sql}
     ORDER BY mb_group ASC, mb_name ASC, mb_no ASC
");
while ($r = sql_fetch_array($ar)) {
    $agent_options[] = [
        'mb_no'=>(int)$r['mb_no'],
        'mb_name'=>get_text($r['mb_name']),
        'mb_group'=>(int)$r['mb_group'],
        'mb_group_name'=>get_text($r['mb_group_name']),
    ];
}
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.kpi { display:flex; gap:12px; flex-wrap:wrap; margin:10px 0; }
.kpi .card { padding:12px 16px; border:1px solid #e5e5e5; border-radius:6px; min-width:160px; text-align:center; background:#fff; }
.kpi .big { font-size:20px; font-weight:bold; }
canvas { background:#fff; }
.tbl_head01 th, .tbl_head01 td { text-align:center; }
.badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; }
.badge-success { background:#28a745; color:#fff; }
.badge-fail    { background:#6c757d; color:#fff; }
.badge-dnc     { background:#dc3545; color:#fff; }
.small-muted { color:#888; font-size:12px; }
.table-fixed td { word-break:break-all; }
.auto-refresh { margin-left:auto; display:flex; align-items:center; gap:6px; }
#agent option.opt-sep { font-weight:bold; color:#495057; background:#f1f3f5; }
.section { background:#fff; border:1px solid #eee; padding:10px; }
.sticky-head { position: sticky; top: 0; background:#f8f9fb; z-index:1; }
.scrolling-body { max-height: 420px; overflow: auto; }
.status-col { transition: background-color .2s }
.status-success  { background: #eaf7ee; }
.status-primary  { background: #eef4ff; }
.status-secondary{ background: #f6f7f9; }
.status-warning  { background: #fff8e6; }
.status-danger   { background: #ffefef; }
</style>

<div class="local_ov01 local_ov">
    <h2>콜 모니터링 (준실시간)</h2>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./call_monitor2.php" class="form-row" id="filterForm">
        <label for="start">기간</label>
        <input type="datetime-local" id="start" name="start" value="<?php echo get_text(_g('start',$default_start));?>" class="frm_input" style="width:210px">
        ~
        <input type="datetime-local" id="end" name="end" value="<?php echo get_text(_g('end',$default_end));?>" class="frm_input" style="width:210px">

        <label for="status">상태코드</label>
        <select name="status" id="status">
            <option value="0">전체</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)');?>
                </option>
            <?php } ?>
        </select>

        <?php if ($mb_level >= 8) { ?>
            <label for="mb_group">그룹</label>
            <select name="mb_group" id="mb_group" style="width:200px">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 그룹</option>
                <?php foreach ($group_options as $g) { ?>
                    <option value="<?php echo (int)$g['mb_group'];?>"<?php echo ($sel_mb_group===(int)$g['mb_group']?' selected':'');?>>
                        <?php echo get_text($g['mb_group_name']);?>
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="mb_group" value="<?php echo $sel_mb_group; ?>">
            <span class="small-muted">그룹:
                <?php
                if ($sel_mb_group>0) {
                    $row = sql_fetch("
                        SELECT COALESCE(mb_group_name, CONCAT('그룹 ', mb_group)) AS nm
                          FROM {$member_table}
                         WHERE mb_group = {$sel_mb_group}
                         LIMIT 1
                    ");
                    echo get_text($row ? $row['nm'] : ('그룹 '.$sel_mb_group));
                } else echo '전체';
                ?>
            </span>
        <?php } ?>

        <label for="agent">상담사</label>
        <select name="agent" id="agent" style="width:220px">
            <option value="0">전체 상담사</option>
            <?php
            if (empty($agent_options)) {
                echo '<option value="" disabled>상담사가 없습니다</option>';
            } else {
                $last_gid = null;
                foreach ($agent_options as $a) {
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

        <button type="submit" class="btn btn_01">적용</button>

        <div class="auto-refresh">
            <label><input type="checkbox" id="autoRefresh" checked> 자동 새로고침</label>
            <select id="refreshSec">
                <option value="10">10초</option>
                <option value="15">15초</option>
                <option value="30" selected>30초</option>
                <option value="60">60초</option>
            </select>
            <button type="button" class="btn btn_02" id="btnRefreshNow">지금 새로고침</button>
        </div>
    </form>
</div>

<!-- KPI -->
<div class="kpi" id="kpiWrap">
    <div class="card"><div>총 통화</div><div class="big" id="kpiTotal">-</div></div>
    <div class="card"><div>통화성공</div><div class="big" id="kpiSuccess">-</div></div>
    <div class="card"><div>통화실패</div><div class="big" id="kpiFail">-</div></div>
    <div class="card"><div>성공률</div><div class="big" id="kpiRate">-</div></div>
    <div class="card"><div>평균 통화(초)</div><div class="big" id="kpiAvg">-</div></div>
    <div class="card"><div>DNC 발생</div><div class="big" id="kpiDnc">-</div></div>
    <div class="card"><div>활성 상담원 수</div><div class="big" id="kpiAgents">-</div></div>
    <div class="card"><div>활성 그룹 수</div><div class="big" id="kpiGroups">-</div></div>
</div>

<!-- 시계열 & 분포/랭킹 -->
<div class="tbl_frm01 tbl_wrap section" style="margin-bottom:12px;">
    <canvas id="chartTimeseries" height="120"></canvas>
</div>
<div class="tbl_frm01 tbl_wrap" style="display:flex; gap:12px; flex-wrap:wrap;">
    <div class="section" style="flex:1; min-width:320px;">
        <h3 style="margin:0 0 10px 0;">상태별 분포</h3>
        <canvas id="chartStatus" height="100"></canvas>
    </div>
    <div class="section" style="flex:1; min-width:320px;">
        <h3 style="margin:0 0 10px 0;">상담사 TOP 10</h3>
        <canvas id="chartAgents" height="280"></canvas>
    </div>
</div>

<!-- 그룹/상담사 표 -->
<div class="tbl_head01 tbl_wrap section" style="margin-top:12px;">
    <h3 style="margin:0 0 10px 0;">그룹 통계</h3>
    <table class="table-fixed" id="groupsTable">
        <thead class="sticky-head">
            <tr>
                <th style="width:120px;">그룹</th>
                <th style="width:100px;">총 통화</th>
                <th style="width:100px;">성공</th>
                <th style="width:100px;">실패</th>
                <th style="width:110px;">성공률</th>
                <th style="width:140px;">평균 통화(초)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="6" class="empty_table">로딩 중...</td></tr>
        </tbody>
    </table>
</div>

<div class="tbl_head01 tbl_wrap section" style="margin-top:12px;">
    <h3 style="margin:0 0 10px 0;">상담사 통계</h3>
    <table class="table-fixed" id="agentsTable">
        <thead class="sticky-head">
            <tr>
                <th style="width:160px;">상담사</th>
                <th style="width:100px;">총 통화</th>
                <th style="width:100px;">성공</th>
                <th style="width:100px;">실패</th>
                <th style="width:110px;">성공률</th>
                <th style="width:140px;">평균 통화(초)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="6" class="empty_table">로딩 중...</td></tr>
        </tbody>
    </table>
</div>

<!-- ✅ 최근 통화 상세 (통계 스키마·최근 20건) -->
<div class="tbl_head01 tbl_wrap section" style="margin-top:12px;">
    <h3 style="margin:0 8px 10px 0; display:flex; align-items:center; gap:8px;">
        최근 통화 상세 (최근 20건)
        <span class="small-muted">통계 페이지와 동일 컬럼</span>
    </h3>
    <div class="scrolling-body">
        <table class="table-fixed" id="recentDetailTable" style="min-width:1200px;">
            <thead class="sticky-head">
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
                <tr><td colspan="14" class="empty_table">로딩 중...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    const el = {
        kpiTotal:   document.getElementById('kpiTotal'),
        kpiSuccess: document.getElementById('kpiSuccess'),
        kpiFail:    document.getElementById('kpiFail'),
        kpiRate:    document.getElementById('kpiRate'),
        kpiAvg:     document.getElementById('kpiAvg'),
        kpiDnc:     document.getElementById('kpiDnc'),
        kpiAgents:  document.getElementById('kpiAgents'),
        kpiGroups:  document.getElementById('kpiGroups'),
        tableGroups: document.querySelector('#groupsTable tbody'),
        tableAgents: document.querySelector('#agentsTable tbody'),
        tableRecentD: document.querySelector('#recentDetailTable tbody'),
        auto:       document.getElementById('autoRefresh'),
        sec:        document.getElementById('refreshSec'),
        btnNow:     document.getElementById('btnRefreshNow'),
        form:       document.getElementById('filterForm'),
        start:      document.getElementById('start'),
        end:        document.getElementById('end'),
        status:     document.getElementById('status'),
        mb_group:   document.getElementById('mb_group'),
        agent:      document.getElementById('agent'),
    };

    // 컬러
    const COLOR_SUCCESS = '#28a745';
    const COLOR_FAIL    = '#6c757d';
    const COLOR_INFO    = '#17a2b8';

    // 차트
    let chartTS, chartStatus, chartAgents;

    function buildParams() {
        const p = new URLSearchParams();
        p.set('start', el.start.value);
        p.set('end',   el.end.value);
        p.set('status', el.status.value || '0');
        p.set('mb_group', el.mb_group ? (el.mb_group.value || '0') : (new URLSearchParams(location.search).get('mb_group') || '0'));
        p.set('agent', el.agent ? (el.agent.value || '0') : (new URLSearchParams(location.search).get('agent') || '0'));
        p.set('ajax','1');
        return p;
    }
    async function fetchJson(type){
        const p = buildParams(); p.set('type', type);
        const res = await fetch('./call_monitor2.php?'+p.toString(), {cache:'no-store'});
        return res.json();
    }

    async function loadKPI(){
        const r = await fetchJson('kpi'); if (!r.ok) return;
        el.kpiTotal.textContent   = r.total.toLocaleString();
        el.kpiSuccess.textContent = r.success.toLocaleString();
        el.kpiFail.textContent    = r.fail.toLocaleString();
        el.kpiRate.textContent    = (r.successRate ?? 0)+'%';
        el.kpiAvg.textContent     = r.avgSecs ?? '-';
        el.kpiDnc.textContent     = r.dnc.toLocaleString();
        el.kpiAgents.textContent  = r.agents.toLocaleString();
        el.kpiGroups.textContent  = r.groups.toLocaleString();
    }

    async function loadTimeseries(){
        const r = await fetchJson('timeseries'); if (!r.ok) return;
        const data = {
            labels: r.labels,
            datasets: [
                { type:'bar', label:'성공', data:r.success, backgroundColor: COLOR_SUCCESS, stack:'calls' },
                { type:'bar', label:'실패', data:r.fail,    backgroundColor: COLOR_FAIL,    stack:'calls' },
                { type:'line', label:'평균 통화(초)', data:r.avg, borderColor: COLOR_INFO, tension:0.2, yAxisID:'y1' }
            ]
        };
        const opt = {
            responsive:true,
            scales:{
                y:{ beginAtZero:true, stacked:true, title:{display:true, text:'통화 건수'} },
                y1:{ beginAtZero:true, position:'right', grid:{drawOnChartArea:false}, title:{display:true, text:'평균(초)'} }
            },
            plugins:{ legend:{ position:'top' } }
        };
        if (chartTS) { chartTS.data=data; chartTS.options=opt; chartTS.update(); }
        else { chartTS = new Chart(document.getElementById('chartTimeseries'), { type:'bar', data, options: opt }); }
    }

    async function loadStatus(){
        const r = await fetchJson('status'); if (!r.ok) return;
        const labels = r.rows.map(x=> (x.call_status+' '+x.label));
        const data   = r.rows.map(x=> x.cnt);
        const colors = r.rows.map(x=> x.is_dnc==1 ? '#dc3545' : (x.result_group==1? '#28a745' : '#6c757d'));
        if (chartStatus) chartStatus.destroy();
        chartStatus = new Chart(document.getElementById('chartStatus'), {
            type:'doughnut',
            data:{ labels, datasets:[{ data, backgroundColor: colors }] },
            options:{ plugins:{ legend:{ position:'right' } } }
        });
    }

    async function loadAgentsChart(){
        const r = await fetchJson('agents'); if (!r.ok) return;
        const labels = r.rows.map(x=> (x.mb_name ? x.mb_name+'('+x.mb_no+')' : String(x.mb_no)));
        const calls  = r.rows.map(x=> x.call_cnt);
        const rates  = r.rows.map(x=> x.success_rate);
        if (chartAgents) chartAgents.destroy();
        chartAgents = new Chart(document.getElementById('chartAgents'), {
            type:'bar',
            data:{ labels, datasets:[
                { type:'bar',  label:'통화수',   data:calls, backgroundColor: COLOR_INFO, yAxisID:'y' },
                { type:'line', label:'성공률(%)', data:rates, borderColor: COLOR_SUCCESS, tension:0.2, yAxisID:'y1' },
            ]},
            options:{
                plugins:{ legend:{ position:'top' } },
                scales:{
                    y:{ beginAtZero:true, title:{display:true, text:'통화수'} },
                    y1:{ beginAtZero:true, position:'right', grid:{drawOnChartArea:false}, title:{display:true, text:'성공률(%)'}, suggestedMax:100 }
                }
            }
        });
    }

    function td(v){ return `<td>${v}</td>`; }

    async function loadGroupsTable(){
        const r = await fetchJson('groups_table'); if (!r.ok) return;
        const tb = el.tableGroups;
        tb.innerHTML = '';
        if (!r.rows || !r.rows.length) { tb.innerHTML = '<tr><td colspan="6" class="empty_table">데이터가 없습니다.</td></tr>'; return; }
        r.rows.forEach(row=>{
            const tr = document.createElement('tr');
            tr.innerHTML =
                td(row.group_name) +
                td((row.call_cnt||0).toLocaleString()) +
                td((row.success_cnt||0).toLocaleString()) +
                td((row.fail_cnt||0).toLocaleString()) +
                td((row.success_rate||0)+'%') +
                td(row.avg_secs ?? '-');
            tb.appendChild(tr);
        });
    }

    async function loadAgentsTable(){
        const r = await fetchJson('agents'); if (!r.ok) return;
        const tb = el.tableAgents;
        tb.innerHTML = '';
        if (!r.rows || !r.rows.length) { tb.innerHTML = '<tr><td colspan="6" class="empty_table">데이터가 없습니다.</td></tr>'; return; }
        r.rows.forEach(row=>{
            const name = (row.mb_name ? `${row.mb_name} (${row.mb_no})` : row.mb_no);
            const tr = document.createElement('tr');
            tr.innerHTML =
                td(name) +
                td((row.call_cnt||0).toLocaleString()) +
                td((row.success_cnt||0).toLocaleString()) +
                td(((row.call_cnt||0)-(row.success_cnt||0)).toLocaleString()) +
                td((row.success_rate||0)+'%') +
                td(row.avg_secs ?? '-');
            tb.appendChild(tr);
        });
    }

    // ✅ 최근 상세(통계 스키마)
    async function loadRecentDetail(){
        const r = await fetchJson('recent_detail'); if (!r.ok) return;
        const tb = el.tableRecentD;
        tb.innerHTML = '';
        if (!r.rows || !r.rows.length) { tb.innerHTML = '<tr><td colspan="14" class="empty_table">데이터가 없습니다.</td></tr>'; return; }
        r.rows.forEach(row=>{
            const tr = document.createElement('tr');
            tr.innerHTML =
                td(row.group_name) +
                td(row.agent_mb_id) +
                td(row.agent_name) +
                td(row.status_label) +
                td(row.call_start) +
                td(row.call_end) +
                td(row.call_time) +
                td(row.talk_time) +
                td(row.target_name) +
                td(row.birth_date) +
                td(row.man_age) +
                td(row.call_hp) +
                td(row.meta) +
                td(row.campaign_name);
            tb.appendChild(tr);
        });
    }

    async function refreshAll(){
        await Promise.all([
            loadKPI(),
            loadTimeseries(),
            loadStatus(),
            loadAgentsChart(),
            loadGroupsTable(),
            loadAgentsTable(),
            loadRecentDetail()
        ]);
    }

    // 자동 새로고침
    let timer=null;
    function startAuto(){ stopAuto(); if (!el.auto.checked) return; const s=parseInt(el.sec.value||'30',10); timer=setInterval(refreshAll, s*1000); }
    function stopAuto(){ if (timer){ clearInterval(timer); timer=null; } }
    document.getElementById('autoRefresh').addEventListener('change', startAuto);
    document.getElementById('refreshSec').addEventListener('change', startAuto);
    document.getElementById('btnRefreshNow').addEventListener('click', refreshAll);

    // 그룹 변경 시 상담사 초기화 후 제출
    const mbGroup = document.getElementById('mb_group');
    if (mbGroup) {
        mbGroup.addEventListener('change', function(){
            const agent = document.getElementById('agent');
            if (agent) agent.selectedIndex = 0;
            document.getElementById('filterForm').submit();
        });
    }
    const agentSel = document.getElementById('agent');
    if (agentSel) { agentSel.addEventListener('change', function(){ document.getElementById('filterForm').submit(); }); }

    // 최초 로드
    refreshAll().then(startAuto);
})();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
