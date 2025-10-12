<?php
// /adm/call/call_monitor.php
$sub_menu = '700110';
require_once './_common.php';

// 접근 권한: 관리자 레벨 7 이상만
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// 기본 파라미터
$mb_no    = (int)($member['mb_no'] ?? 0);
$mb_level = (int)($member['mb_level'] ?? 0);
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;

// 기간(기본: 최근 24시간)
$now_ts   = time();
$default_end   = date('Y-m-d\TH:i', $now_ts);
$default_start = date('Y-m-d\TH:i', $now_ts - 24*3600);

$start = _g('start', $default_start); // HTML datetime-local 포맷
$end   = _g('end',   $default_end);

// 상태코드 선택(단일 선택; 필요시 멀티로 확장 가능)
$f_status = isset($_GET['status']) ? (int)$_GET['status'] : 0;

// AJAX 분기
$ajax = isset($_GET['ajax']) ? (int)$_GET['ajax'] : 0;
$type = _g('type', '');

function build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status) {
    $w = [];
    // datetime-local -> 'Y-m-d H:i:s'
    $start_sql = sql_escape_string(str_replace('T', ' ', $start).':00');
    $end_sql   = sql_escape_string(str_replace('T', ' ', $end).':59');

    $w[] = "l.call_start BETWEEN '{$start_sql}' AND '{$end_sql}'";

    if ($f_status > 0) {
        $w[] = "l.call_status = {$f_status}";
    }

    if ($mb_level == 7) {
        $w[] = "l.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        $w[] = "l.mb_no = {$mb_no}";
    }
    return $w ? ('WHERE '.implode(' AND ', $w)) : '';
}

// AJAX 응답
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');

    $where_sql = build_common_where($mb_level, $my_group, $mb_no, $start, $end, $f_status);
    $member_table = $g5['member_table'];

    // 30분 버킷 시간 리스트 미리 생성
    $start_ts = strtotime(str_replace('T',' ',$start).':00');
    $end_ts   = strtotime(str_replace('T',' ',$end).':59');
    $start_bucket = floor($start_ts / 1800) * 1800;
    $end_bucket   = floor($end_ts   / 1800) * 1800;

    if ($type === 'timeseries') {
        // 30분 버킷 추이 (성공/실패/평균길이)
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

        // 빈 버킷 0 보정
        $map = [];
        while ($row = sql_fetch_array($res)) {
            $key = $row['bucket_start'];
            $map[$key] = [
                'success' => (int)$row['success_cnt'],
                'fail'    => (int)$row['fail_cnt'],
                'avg'     => is_null($row['avg_secs']) ? null : round((float)$row['avg_secs'],1),
            ];
        }
        $labels = [];
        $success = [];
        $fail = [];
        $avg = [];
        for ($t=$start_bucket; $t<=$end_bucket; $t+=1800) {
            $label = date('m/d H:i', $t);
            $labels[] = $label;
            if (isset($map[date('Y-m-d H:i:s', $t)])) {
                $success[] = $map[date('Y-m-d H:i:s', $t)]['success'];
                $fail[]    = $map[date('Y-m-d H:i:s', $t)]['fail'];
                $avg[]     = $map[date('Y-m-d H:i:s', $t)]['avg'];
            } else {
                $success[] = 0;
                $fail[]    = 0;
                $avg[]     = null;
            }
        }
        echo json_encode(['ok'=>true, 'labels'=>$labels, 'success'=>$success, 'fail'=>$fail, 'avg'=>$avg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    elseif ($type === 'status') {
        // 상태별 분포
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
        while ($r = sql_fetch_array($res)) $rows[] = $r;
        echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }
    elseif ($type === 'agents') {
        // 상담원 TOP 10
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
            LEFT JOIN {$member_table} m
              ON m.mb_no = l.mb_no
            {$where_sql}
            GROUP BY l.mb_no, m.mb_name
            ORDER BY call_cnt DESC
            LIMIT 10
        ";
        $res = sql_query($sql);
        $rows = [];
        while ($r = sql_fetch_array($res)) {
            $r['success_rate'] = ($r['call_cnt'] > 0) ? round($r['success_cnt']*100.0/$r['call_cnt'], 1) : 0.0;
            $r['avg_secs'] = is_null($r['avg_secs']) ? null : round((float)$r['avg_secs'], 1);
            $rows[] = $r;
        }
        echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }
    elseif ($type === 'kpi') {
        // KPI 카드
        $sql = "
            SELECT
              COUNT(*) AS total_cnt,
              SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END) AS success_cnt,
              SUM(CASE WHEN COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END)=0 THEN 1 ELSE 0 END) AS fail_cnt,
              AVG(l.call_time) AS avg_secs,
              SUM(CASE WHEN COALESCE(sc.is_do_not_call,0)=1 THEN 1 ELSE 0 END) AS dnc_cnt,
              COUNT(DISTINCT l.mb_no) AS active_agents
            FROM call_log l
            LEFT JOIN call_status_code sc
              ON sc.call_status = l.call_status AND sc.mb_group = 0
            {$where_sql}
        ";
        $r = sql_fetch($sql);
        $total = (int)$r['total_cnt'];
        $success = (int)$r['success_cnt'];
        $fail = (int)$r['fail_cnt'];
        $avg = is_null($r['avg_secs']) ? null : round((float)$r['avg_secs'],1);
        $rate = $total > 0 ? round($success*100.0/$total, 1) : 0.0;
        $dnc = (int)$r['dnc_cnt'];
        $agents = (int)$r['active_agents'];
        echo json_encode(['ok'=>true, 'total'=>$total, 'success'=>$success, 'fail'=>$fail, 'successRate'=>$rate, 'avgSecs'=>$avg, 'dnc'=>$dnc, 'agents'=>$agents], JSON_UNESCAPED_UNICODE);
        exit;
    }
    elseif ($type === 'recent') {
        // 최근 통화 50건
        $member_table = $g5['member_table'];
        $sql = "
            SELECT
              l.call_id, l.call_start, l.call_time, l.call_status, l.call_hp,
              t.name AS target_name, t.birth_date,
              m.mb_no, m.mb_name,
              COALESCE(sc.name_ko, CONCAT('코드 ', l.call_status)) AS label,
              COALESCE(sc.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS result_group,
              COALESCE(sc.is_do_not_call,0) AS is_dnc
            FROM call_log l
            JOIN call_target t ON t.target_id = l.target_id
            LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
            LEFT JOIN call_status_code sc
              ON sc.call_status = l.call_status AND sc.mb_group = 0
            {$where_sql}
            ORDER BY l.call_start DESC, l.call_id DESC
            LIMIT 50
        ";
        $res = sql_query($sql);
        $rows = [];
        while ($r = sql_fetch_array($res)) {
            $r['call_hp_fmt'] = format_korean_phone($r['call_hp']);
            $r['agent'] = ($r['mb_name'] ? $r['mb_name'].' ('.$r['mb_no'].')' : (string)$r['mb_no']);
            $r['age_years'] = calc_age_years($r['birth_date']);
            $rows[] = $r;
        }
        echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok'=>false, 'msg'=>'unknown type'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --------- HTML 렌더링 ---------
$today = date('Y-m-d\TH:i');
$codes = [];
$qc = "SELECT call_status, name_ko, status FROM call_status_code WHERE mb_group=0 ORDER BY sort_order ASC, call_status ASC";
$rc = sql_query($qc);
while ($r = sql_fetch_array($rc)) $codes[] = $r;
$g5['title'] = '모니터링';
include_once(G5_ADMIN_PATH.'/admin.head.php');
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.kpi { display:flex; gap:12px; flex-wrap:wrap; margin:10px 0; }
.kpi .card { padding:12px 16px; border:1px solid #e5e5e5; border-radius:6px; min-width:160px; text-align:center; }
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
</style>

<div class="local_ov01 local_ov">
    <h2>콜 모니터링 (30분 단위)</h2>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./call_monitor.php" class="form-row" id="filterForm">
        <label for="start">기간</label>
        <input type="datetime-local" id="start" name="start" value="<?php echo get_text(_g('start', $default_start));?>" class="frm_input" style="width:210px">
        ~
        <input type="datetime-local" id="end" name="end" value="<?php echo get_text(_g('end', $default_end));?>" class="frm_input" style="width:210px">

        <label for="status">상태코드</label>
        <select name="status" id="status">
            <option value="0">전체</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)');?>
                </option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">적용</button>

        <div class="auto-refresh">
            <label><input type="checkbox" id="autoRefresh" checked> 자동 새로고침</label>
            <select id="refreshSec">
                <option value="15">15초</option>
                <option value="30" selected>30초</option>
                <option value="60">60초</option>
            </select>
            <button type="button" class="btn btn_02" id="btnRefreshNow">지금 새로고침</button>
        </div>
    </form>
</div>

<div class="kpi" id="kpiWrap">
    <div class="card">
        <div>총 통화</div>
        <div class="big" id="kpiTotal">-</div>
    </div>
    <div class="card">
        <div>통화성공</div>
        <div class="big" id="kpiSuccess">-</div>
    </div>
    <div class="card">
        <div>통화실패</div>
        <div class="big" id="kpiFail">-</div>
    </div>
    <div class="card">
        <div>성공률</div>
        <div class="big" id="kpiRate">-</div>
    </div>
    <div class="card">
        <div>평균 통화(초)</div>
        <div class="big" id="kpiAvg">-</div>
    </div>
    <div class="card">
        <div>DNC 발생</div>
        <div class="big" id="kpiDnc">-</div>
    </div>
    <div class="card">
        <div>활성 상담원 수</div>
        <div class="big" id="kpiAgents">-</div>
    </div>
</div>

<div class="tbl_frm01 tbl_wrap" style="padding:10px;background:#fff;border:1px solid #eee;margin-bottom:12px;">
    <canvas id="chartTimeseries" height="120"></canvas>
</div>

<div class="tbl_frm01 tbl_wrap" style="display:flex; gap:12px; flex-wrap:wrap;">
    <div style="flex:1; min-width:320px; background:#fff; border:1px solid #eee; padding:10px;">
        <h3 style="margin:0 0 10px 0;">상태별 분포</h3>
        <canvas id="chartStatus" height="100"></canvas>
    </div>
    <div style="flex:1; min-width:320px; background:#fff; border:1px solid #eee; padding:10px;">
        <h3 style="margin:0 0 10px 0;">상담원 TOP 10</h3>
        <canvas id="chartAgents" height="280"></canvas>
    </div>
</div>

<div class="tbl_head01 tbl_wrap" style="margin-top:12px;">
    <table class="table-fixed" id="recentTable">
        <thead>
            <tr>
                <th style="width:90px">Call ID</th>
                <th style="width:160px">통화시작</th>
                <th style="width:80px">길이(초)</th>
                <th style="width:90px">상태코드</th>
                <th>라벨</th>
                <th style="width:110px">그룹</th>
                <th style="width:160px">전화번호</th>
                <th style="width:160px">대상자(만 나이)</th>
                <th style="width:160px">담당자</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="9" class="empty_table">로딩 중...</td></tr>
        </tbody>
    </table>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    const qs = new URLSearchParams(window.location.search);
    function param(name){ return qs.get(name); }

    const el = {
        kpiTotal:   document.getElementById('kpiTotal'),
        kpiSuccess: document.getElementById('kpiSuccess'),
        kpiFail:    document.getElementById('kpiFail'),
        kpiRate:    document.getElementById('kpiRate'),
        kpiAvg:     document.getElementById('kpiAvg'),
        kpiDnc:     document.getElementById('kpiDnc'),
        kpiAgents:  document.getElementById('kpiAgents'),
        tableBody:  document.querySelector('#recentTable tbody'),
        auto:       document.getElementById('autoRefresh'),
        sec:        document.getElementById('refreshSec'),
        btnNow:     document.getElementById('btnRefreshNow'),
        form:       document.getElementById('filterForm'),
        start:      document.getElementById('start'),
        end:        document.getElementById('end'),
        status:     document.getElementById('status'),
    };

    // Colors
    const COLOR_SUCCESS = '#28a745';
    const COLOR_FAIL    = '#6c757d';
    const COLOR_DANGER  = '#dc3545';
    const COLOR_INFO    = '#17a2b8';

    // Charts
    let chartTS, chartStatus, chartAgents;

    function buildParams() {
        const p = new URLSearchParams();
        p.set('start', el.start.value);
        p.set('end',   el.end.value);
        p.set('status', el.status.value || '0');
        p.set('ajax', '1');
        return p;
    }

    async function fetchJson(type){
        const p = buildParams();
        p.set('type', type);
        const res = await fetch('./call_monitor.php?'+p.toString(), {cache:'no-store'});
        return res.json();
    }

    async function loadKPI(){
        const r = await fetchJson('kpi');
        if (!r.ok) return;
        el.kpiTotal.textContent   = r.total.toLocaleString();
        el.kpiSuccess.textContent = r.success.toLocaleString();
        el.kpiFail.textContent    = r.fail.toLocaleString();
        el.kpiRate.textContent    = (r.successRate ?? 0)+'%';
        el.kpiAvg.textContent     = r.avgSecs ?? '-';
        el.kpiDnc.textContent     = r.dnc.toLocaleString();
        el.kpiAgents.textContent  = r.agents.toLocaleString();
    }

    async function loadTimeseries(){
        const r = await fetchJson('timeseries');
        if (!r.ok) return;
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
        if (chartTS) { chartTS.data = data; chartTS.options = opt; chartTS.update(); }
        else {
            chartTS = new Chart(document.getElementById('chartTimeseries'), { type:'bar', data, options: opt });
        }
    }

    async function loadStatus(){
        const r = await fetchJson('status');
        if (!r.ok) return;
        const labels = r.rows.map(x=> (x.call_status+' '+x.label));
        const data   = r.rows.map(x=> x.cnt);
        const colors = r.rows.map(x=> x.is_dnc==1 ? COLOR_DANGER : (x.result_group==1? COLOR_SUCCESS : COLOR_FAIL));
        const cfg = {
            type:'doughnut',
            data:{ labels, datasets:[{ data, backgroundColor: colors }] },
            options:{ plugins:{ legend:{ position:'right' } } }
        };
        if (chartStatus) { chartStatus.destroy(); }
        chartStatus = new Chart(document.getElementById('chartStatus'), cfg);
    }

    async function loadAgents(){
        const r = await fetchJson('agents');
        if (!r.ok) return;
        const labels = r.rows.map(x=> (x.mb_name ? x.mb_name+'('+x.mb_no+')' : String(x.mb_no)));
        const calls  = r.rows.map(x=> x.call_cnt);
        const rates  = r.rows.map(x=> x.success_rate);
        const cfg = {
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
        };
        if (chartAgents) { chartAgents.destroy(); }
        chartAgents = new Chart(document.getElementById('chartAgents'), cfg);
    }

    function fmtAge(age){
        if (age === null || age === undefined) return '-';
        return age + '세(만)';
    }

    async function loadRecent(){
        const r = await fetchJson('recent');
        if (!r.ok) return;
        const tb = el.tableBody;
        tb.innerHTML = '';
        if (!r.rows || !r.rows.length) {
            tb.innerHTML = '<tr><td colspan="9" class="empty_table">데이터가 없습니다.</td></tr>';
            return;
        }
        r.rows.forEach(row=>{
            const grp = (row.result_group==1) ? '성공' : '실패';
            const dnc = (row.is_dnc==1) ? '<span class="badge badge-dnc" style="margin-left:6px;">DNC</span>' : '';
            const agent = row.mb_name ? `${row.mb_name} (${row.mb_no})` : row.mb_no;
            const age = fmtAge(row.age_years);
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${row.call_id}</td>
                <td>${row.call_start}</td>
                <td>${row.call_time ?? ''}</td>
                <td>${row.call_status}</td>
                <td>${row.label}${dnc}</td>
                <td>${grp}</td>
                <td>${row.call_hp_fmt}</td>
                <td>${(row.target_name ?? '') ? (row.target_name + ' / ' + age) : age}</td>
                <td>${agent}</td>
            `;
            tb.appendChild(tr);
        });
    }

    async function refreshAll(){
        await Promise.all([loadKPI(), loadTimeseries(), loadStatus(), loadAgents(), loadRecent()]);
    }

    // 자동 리프레시 제어
    let timer = null;
    function startAuto(){
        stopAuto();
        if (!el.auto.checked) return;
        const sec = parseInt(el.sec.value || '30', 10);
        timer = setInterval(refreshAll, sec*1000);
    }
    function stopAuto(){ if (timer) { clearInterval(timer); timer = null; } }

    el.auto.addEventListener('change', startAuto);
    el.sec.addEventListener('change', startAuto);
    el.btnNow.addEventListener('click', refreshAll);

    // 최초 로드 + 자동시작
    refreshAll().then(startAuto);
})();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
