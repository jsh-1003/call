<?php 
// /adm/call/call_monitor.php
$sub_menu = '700110';
require_once './_common.php';

// 접근 권한: 관리자 레벨 7 이상만
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

/* ==========================
   기본 파라미터
   ========================== */
$mb_no          = (int)($member['mb_no'] ?? 0);
$mb_level       = (int)($member['mb_level'] ?? 0);
$my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;

$now_ts        = time();
$default_end   = date('Y-m-d\TH:i', $now_ts);
$default_start = date('Y-m-d').'T00:00';

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

// Ajax 분기
$ajax = isset($_GET['ajax']) ? (int)$_GET['ajax'] : 0;
$type = _g('type', '');

// 공통 테이블
$member_table = $g5['member_table']; // g5_member

/* ==========================
   HTML 렌더링 준비
   ========================== */
$codes = [];
$qc = "SELECT call_status, name_ko, status FROM call_status_code WHERE mb_group=0 ORDER BY sort_order ASC, call_status ASC";
$rc = sql_query($qc);
while ($r = sql_fetch_array($rc)) $codes[] = $r;

$g5['title'] = '콜 모니터링';
include_once(G5_ADMIN_PATH.'/admin.head.php');

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
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.kpi { display:flex; gap:12px; flex-wrap:wrap; margin:10px 0; }
.kpi .card { padding:12px 16px; border:1px solid #e5e5e5; border-radius:6px; min-width:160px; text-align:center; background:#fff; }
.kpi .big { font-size:20px; font-weight:bold; }
canvas { background:#fff; }
.tbl_head01 th, .tbl_head01 td { text-align:center; }
.small-muted { color:#888; font-size:12px; }
.table-fixed td { word-break:break-all; }
.auto-refresh { margin-left:auto; display:flex; align-items:center; gap:6px; }
#agent option.opt-sep { font-weight:bold; color:#495057; background:#f1f3f5; }
.section { background:#fff; border:1px solid #eee; padding:10px; }
.sticky-head { position: sticky; top: 0; background:#f8f9fb; z-index:1; }
.scrolling-body { max-height: 420px; overflow: auto; }
</style>

<div class="local_ov01 local_ov">
    <h2>콜 모니터링 (준실시간)</h2>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./call_monitor.php" class="form-row" id="searchForm" autocomplete="off">
        <label for="start">기간</label>
        <input type="datetime-local" id="start" name="start" value="<?php echo get_text(_g('start',$default_start));?>" class="frm_input" style="width:210px">
        ~
        <input type="datetime-local" id="end" name="end" value="<?php echo get_text(_g('end',$default_end));?>" class="frm_input" style="width:210px">

        <select name="status" id="status">
            <option value="0">전체</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)');?>
                </option>
            <?php } ?>
        </select>

        <!-- 2단: 회사 → 그룹 → 상담사 -->
        <?php if ($mb_level >= 9) { ?>
            <label for="company_id">회사</label>
            <select name="company_id" id="company_id" style="width:120px">
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
            <select name="mb_group" id="mb_group" style="width:120px">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 그룹</option>
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
            <span class="small-muted">그룹: <?php echo get_text(get_group_name_cached($sel_mb_group)); ?></span>
        <?php } ?>

        <select name="agent" id="agent" style="width:120px">
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

        <a href="./call_monitor.php" class="btn btn_02">초기화</a>

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
    <div class="card"><div>잔여DB</div><div class="big" id="kpiRemainDb">-</div></div>
    <div class="card"><div>총 통화</div><div class="big" id="kpiTotal">-</div></div>
    <div class="card"><div>성공</div><div class="big" id="kpiSuccess">-</div></div>
    <div class="card"><div>실패</div><div class="big" id="kpiFail">-</div></div>
    <div class="card"><div>성공률</div><div class="big" id="kpiRate">-</div></div>
    <div class="card"><div>평균 통화(초)</div><div class="big" id="kpiAvg">-</div></div>
    <div class="card"><div>총 통화시간</div><div class="big" id="kpiCallTime">-</div></div>   <!-- ★ 추가 -->
    <div class="card"><div>총 상담시간</div><div class="big" id="kpiTalkTime">-</div></div>   <!-- ★ 추가 -->
    <div class="card"><div>블랙고객 발생</div><div class="big" id="kpiDnc">-</div></div>
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
                <th style="width:140px;">총 통화시간</th>   <!-- ★ -->
                <th style="width:140px;">총 상담시간</th>   <!-- ★ -->
                <th style="width:140px;">평균 상담(초)</th> <!-- ★ -->
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="9" class="empty_table">로딩 중...</td></tr>
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
                <th style="width:140px;">총 통화시간</th>   <!-- ★ -->
                <th style="width:140px;">총 상담시간</th>   <!-- ★ -->
                <th style="width:140px;">평균 상담(초)</th> <!-- ★ -->
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="9" class="empty_table">로딩 중...</td></tr>
        </tbody>
    </table>
</div>

<!-- 최근 통화 상세 -->
<div class="tbl_head01 tbl_wrap section" style="margin-top:12px;">
    <h3 style="margin:0 8px 10px 0; display:flex; align-items:center; gap:8px;">
        최근 통화 상세 (최근 50건)
        <span class="small-muted"></span>
    </h3>
    <div class="scrolling-body">
        <table class="table-fixed" id="recentDetailTable" style="min-width:1200px;">
            <thead class="sticky-head">
                <tr>
                    <th>그룹명</th>
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
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="15" class="empty_table">로딩 중...</td></tr> <!-- ★ 15로 수정 -->
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    const el = {
        kpiRemainDb: document.getElementById('kpiRemainDb'),
        kpiTotal:   document.getElementById('kpiTotal'),
        kpiSuccess: document.getElementById('kpiSuccess'),
        kpiFail:    document.getElementById('kpiFail'),
        kpiRate:    document.getElementById('kpiRate'),
        kpiAvg:     document.getElementById('kpiAvg'),
        kpiCallTime:document.getElementById('kpiCallTime'), // ★
        kpiTalkTime:document.getElementById('kpiTalkTime'), // ★
        kpiDnc:     document.getElementById('kpiDnc'),
        kpiAgents:  document.getElementById('kpiAgents'),
        kpiGroups:  document.getElementById('kpiGroups'),
        tableGroups: document.querySelector('#groupsTable tbody'),
        tableAgents: document.querySelector('#agentsTable tbody'),
        tableRecentD: document.querySelector('#recentDetailTable tbody'),
        auto:       document.getElementById('autoRefresh'),
        sec:        document.getElementById('refreshSec'),
        btnNow:     document.getElementById('btnRefreshNow'),
        form:       document.getElementById('searchForm'),
        start:      document.getElementById('start'),
        end:        document.getElementById('end'),
        status:     document.getElementById('status'),
        company:    document.getElementById('company_id'),
        mb_group:   document.getElementById('mb_group'),
        agent:      document.getElementById('agent'),
    };

    const COLOR_SUCCESS = '#28a745';
    const COLOR_FAIL    = '#6c757d';
    const COLOR_INFO    = '#17a2b8';

    let chartTS, chartStatus, chartAgents;

    function buildParams() {
        const p = new URLSearchParams();
        p.set('start', el.start.value);
        p.set('end',   el.end.value);
        p.set('status', el.status.value || '0');
        p.set('company_id', el.company ? (el.company.value || '0') : (new URLSearchParams(location.search).get('company_id') || '0'));
        p.set('mb_group', el.mb_group ? (el.mb_group.value || '0') : (new URLSearchParams(location.search).get('mb_group') || '0'));
        p.set('agent', el.agent ? (el.agent.value || '0') : (new URLSearchParams(location.search).get('agent') || '0'));
        p.set('ajax','1');
        return p;
    }
    async function fetchJson(type){
        const p = buildParams(); p.set('type', type);
        const res = await fetch('./ajax_call_monitor.php?'+p.toString(), {cache:'no-store'});
        return res.json();
    }

    // 초 → H:MM:SS (1시간 미만이면 M:SS)
    function secToHms(sec){
        if (sec == null || isNaN(sec)) return '-';
        sec = Math.max(0, parseInt(sec,10));
        const h = Math.floor(sec/3600);
        const m = Math.floor((sec%3600)/60);
        const s = sec%60;
        const z = n => (n<10?'0':'')+n;
        return (h>0? (h+':'+z(m)+':'+z(s)) : (m+':'+z(s)));
    }

    async function loadKPI(){
        const r = await fetchJson('kpi'); if (!r.ok) return;
        el.kpiRemainDb.textContent = (r.remainDb ?? 0).toLocaleString();
        el.kpiTotal.textContent   = r.total.toLocaleString();
        el.kpiSuccess.textContent = r.success.toLocaleString();
        el.kpiFail.textContent    = r.fail.toLocaleString();
        el.kpiRate.textContent    = (r.successRate ?? 0)+'%';
        el.kpiAvg.textContent     = r.avgSecs ?? '-';
        el.kpiCallTime.textContent= secToHms(r.sumCallSecs);  // ★ 총 통화시간
        el.kpiTalkTime.textContent= secToHms(r.sumTalkSecs);  // ★ 총 상담시간
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
        if (!r.rows || !r.rows.length) { tb.innerHTML = '<tr><td colspan="9" class="empty_table">데이터가 없습니다.</td></tr>'; return; }
        r.rows.forEach(row=>{
            const tr = document.createElement('tr');
            tr.innerHTML =
                td(row.group_name) +
                td((row.call_cnt||0).toLocaleString()) +
                td((row.success_cnt||0).toLocaleString()) +
                td((row.fail_cnt||0).toLocaleString()) +
                td((row.success_rate||0)+'%') +
                td(row.avg_secs ?? '-') +
                td(row.sum_call_hms ?? '-') +
                td(row.sum_talk_hms ?? '-') +
                td(row.avg_talk_secs ?? '-');
            tb.appendChild(tr);
        });
    }

    async function loadAgentsTable(){
        const r = await fetchJson('agents'); if (!r.ok) return;
        const tb = el.tableAgents;
        tb.innerHTML = '';
        if (!r.rows || !r.rows.length) { tb.innerHTML = '<tr><td colspan="9" class="empty_table">데이터가 없습니다.</td></tr>'; return; }
        r.rows.forEach(row=>{
            const name = (row.mb_name ? `${row.mb_name} (${row.mb_no})` : row.mb_no);
            const tr = document.createElement('tr');
            tr.innerHTML =
                td(name) +
                td((row.call_cnt||0).toLocaleString()) +
                td((row.success_cnt||0).toLocaleString()) +
                td(((row.call_cnt||0)-(row.success_cnt||0)).toLocaleString()) +
                td((row.success_rate||0)+'%') +
                td(row.avg_secs ?? '-') +
                td(row.sum_call_hms ?? '-') +
                td(row.sum_talk_hms ?? '-') +
                td(row.avg_talk_secs ?? '-');
            tb.appendChild(tr);
        });
    }

    async function loadRecentDetail(){
        const r = await fetchJson('recent_detail'); if (!r.ok) return;
        const tb = el.tableRecentD;
        tb.innerHTML = '';
        if (!r.rows || !r.rows.length) { tb.innerHTML = '<tr><td colspan="15" class="empty_table">데이터가 없습니다.</td></tr>'; return; }
        r.rows.forEach(row=>{
            const tr = document.createElement('tr');
            tr.innerHTML =
                td(row.group_name) +
                td(row.agent_mb_id) +
                td(row.agent_name) +
                td(row.agent_phone) +
                `<td class="${row.class_name ?? ''}">${row.status_label}</td>` +
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

    // 회사 → 그룹 AJAX (9+만)
    const companySel = document.getElementById('company_id');
    if (companySel) {
        companySel.addEventListener('change', function(){
            const groupSel = document.getElementById('mb_group');
            if (!groupSel) return;
            groupSel.innerHTML = '<option value="">로딩 중...</option>';
            const agent = document.getElementById('agent'); if (agent) agent.selectedIndex = 0;

            fetch('./ajax_group_options.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ company_id: parseInt(this.value||'0',10)||0 }),
                credentials: 'same-origin'
            })
            .then(res => { if(!res.ok) throw new Error('네트워크 오류'); return res.json(); })
            .then(json => {
                if (!json.success) throw new Error(json.message || '가져오기 실패');
                const opts = [];
                opts.push(new Option('전체 그룹', 0));
                json.items.forEach(function(item){
                    if (item.separator) {
                        const sep = document.createElement('option');
                        sep.textContent = '── ' + item.separator + ' ──';
                        sep.disabled = true;
                        sep.className = 'opt-sep';
                        opts.push(sep);
                    } else {
                        opts.push(new Option(item.label, item.value));
                    }
                });
                groupSel.innerHTML = '';
                opts.forEach(o => groupSel.appendChild(o));
                groupSel.value = '0';
            })
            .catch(err=>{
                alert('그룹 목록을 불러오지 못했습니다: ' + err.message);
                groupSel.innerHTML = '<option value="0">전체 그룹</option>';
            });
        });
    }

    // 최초 로드
    refreshAll().then(startAuto);
})();

(function(){
    var $form = document.getElementById('searchForm');
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
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
