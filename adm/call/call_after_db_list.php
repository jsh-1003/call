<?php
// /adm/call/call_after_db_list.php
$sub_menu = '700420';
require_once './_common.php';

// 접근 권한: 관리자 레벨 5 이상
if ($is_admin !== 'super' && (int)$member['mb_level'] < 10) {
    alert('접근 권한이 없습니다~!'.$member['mb_level']);
}

/** 일정 표시 포맷터 */
function format_schedule_display($scheduled_at, $schedule_note) {
    $when = $scheduled_at ? fmt_datetime(get_text($scheduled_at), 'mdhi') : '';
    $note = $schedule_note ? get_text($schedule_note) : '';
    if (!$when && !$note) return '-';
    if ($when && $note) return $when.' / '.$note;
    return $when ?: $note;
}

/** 정렬 th 링크 유틸 */
function sort_th($key, $label){
    global $cur_sort, $cur_dir, $qparams_for_sort;
    $nextDir = ($cur_sort === $key && $cur_dir === 'desc') ? 'asc' : 'desc';
    $params = $qparams_for_sort;
    $params['sort'] = $key;
    $params['dir']  = $nextDir;
    $params['page'] = 1;
    $url = './call_after_db_list.php?'.http_build_query($params);
    $arrow = '';
    if ($cur_sort === $key) $arrow = ($cur_dir === 'asc') ? ' ▲' : ' ▼';
    return '<a href="'.htmlspecialchars($url, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'" class="th-sort">'.get_text($label).$arrow.'</a>';
}

$mb_no          = (int)($member['mb_no'] ?? 0);
$mb_level       = (int)($member['mb_level'] ?? 0);
$my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;
$member_table   = $g5['member_table'];

$today      = date('Y-m-d');
$seven_ago  = date('Y-m-d', strtotime('-7 days'));
$start_date = _g('start', $seven_ago);
$end_date   = _g('end',   $today);

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

// ===== 검색/필터 =====
$q         = _g('q', '');
$q_type    = _g('q_type', '');              // name | last4 | full | all
$f_acstate = 10;
$page      = max(1, (int)(_g('page', '1')));
$page_rows = max(10, min(200, (int)_g('rows','50')));
$offset    = ($page - 1) * $page_rows;

/* ===== 정렬 파라미터 ===== */
$cur_sort = _g('sort', 'scheduled_at');
$cur_dir  = strtolower((string)_g('dir', 'desc'));
$cur_dir  = in_array($cur_dir, ['asc','desc'], true) ? $cur_dir : 'desc';
$SORT_MAP = [
    'agent_name'        => 'agent_sort',
    'after_agent_name'  => 'after_agent_sort',
    'call_start'        => 'b.call_start',
    'call_end'          => 'b.call_end',
    'target_name'       => 't.name',
    'man_age'           => 'man_age',
    'call_hp'           => 'b.call_hp',
    'ac_state'          => 's.sort_order, s.state_id',
    'scheduled_at'      => 'tk.scheduled_at',
    'created_at'        => 'info.created_at',
];
if (!isset($SORT_MAP[$cur_sort])) $cur_sort = 'created_at';
$DIR_SQL = strtoupper($cur_dir);
$__parts = array_map('trim', explode(',', $SORT_MAP[$cur_sort]));
$__orders = [];
foreach ($__parts as $__p) { if ($__p !== '') $__orders[] = $__p.' '.$DIR_SQL; }
$__orders[] = 'b.call_start DESC';
$__orders[] = 'b.call_id DESC';
$order_sql = implode(', ', $__orders);

/* ==========================
   WHERE (리스트) + 회사/지점/상담원 필터
   ========================== */
$where = [];
$start_esc = sql_escape_string($start_date.' 00:00:00');
$end_esc   = sql_escape_string($end_date.' 23:59:59');
$where[]   = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";
$where[]   = "sc.is_after_call = 1";

if ($q !== '' && $q_type !== '') {
    if ($q_type === 'name') {
        $where[] = "t.name LIKE '%".sql_escape_string($q)."%'";
    } elseif ($q_type === 'last4') {
        $q4 = substr(preg_replace('/\D+/', '', $q), -4);
        if ($q4 !== '') $where[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
    } elseif ($q_type === 'full') {
        $hp = preg_replace('/\D+/', '', $q);
        if ($hp !== '') $where[] = "l.call_hp = '".sql_escape_string($hp)."'";
    } elseif ($q_type === 'all') {
        $q_esc = sql_escape_string($q);
        $q4 = substr(preg_replace('/\D+/', '', $q), -4);
        $hp = preg_replace('/\D+/', '', $q);
        $conds = ["t.name LIKE '%{$q_esc}%'"];
        if ($q4 !== '') $conds[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
        if ($hp !== '') $conds[] = "l.call_hp = '".sql_escape_string($hp)."'";
        $where[] = '('.implode(' OR ', $conds).')';
    }
}

// 권한 기반 / 조직 기반 범위
if ($mb_level == 7) {
    $where[] = "l.mb_group = {$my_group}";
} elseif ($mb_level == 5) {
    $where[] = "tk.assigned_after_mb_no = {$mb_no}";
} elseif ($mb_level < 5) {
    $where[] = "l.mb_no = {$mb_no}";
} else {
    // 8+
    if ($sel_mb_group > 0) {
        $where[] = "l.mb_group = {$sel_mb_group}";
    } else {
        if ($mb_level >= 9 && $sel_company_id > 0) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        } elseif ($mb_level == 8) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        }
    }
}

// 2차콜 상태 필터
if ($f_acstate >= 0) {
    if ($f_acstate === 0) {
        $where['state_id'] = "tk.state_id = 0";
    } else {
        $where['state_id'] = "tk.state_id = {$f_acstate}";
    }
}
// 2차콜 담당자 필터
if ($sel_after_agent_no > 0) {
    $where[] = "tk.assigned_after_mb_no = {$sel_after_agent_no}";
}

if ($sel_agent_no > 0) $where[] = "l.mb_no = {$sel_agent_no}";

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ==========================
   드롭다운/라벨 (상태/2차콜)
   ========================== */
$codes = [];
$rc = sql_query("SELECT call_status, name_ko, status, ui_type FROM call_status_code WHERE mb_group=0 ORDER BY sort_order ASC, call_status ASC");
while ($r = sql_fetch_array($rc)) $codes[] = $r;

$ac_codes = [];
$rac = sql_query("SELECT state_id, name_ko, ui_type, sort_order FROM call_aftercall_state_code WHERE status=1 ORDER BY sort_order ASC, state_id ASC");
while ($r = sql_fetch_array($rac)) $ac_codes[(int)$r['state_id']] = $r;

$status_ui = [];
$rui = sql_query("SELECT call_status, ui_type FROM call_status_code WHERE mb_group=0");
while ($v = sql_fetch_array($rui)) $status_ui[(int)$v['call_status']] = ($v['ui_type'] ?: 'secondary');


/**
 * ========================
 * 회사/지점/담당자 드롭다운 옵션
 * ========================
 */
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
$company_options = $build_org_select_options['company_options']; // 9+
$group_options   = $build_org_select_options['group_options'];   // 8+
$agent_options   = $build_org_select_options['agent_options'];   // 레벨3 상담원
/**
 * ========================
 * // 회사/지점/담당자 드롭다운 옵션
 * ========================
 */


/* ==========================
   총 건수
   ========================== */
$sql_cnt = "
    SELECT COUNT(*) AS cnt FROM (
      SELECT ROW_NUMBER() OVER (PARTITION BY l.mb_group, l.campaign_id, l.target_id
                                ORDER BY l.call_start DESC, l.call_id DESC) AS rn
        FROM call_log l
        JOIN call_target t ON t.target_id = l.target_id
        JOIN call_status_code sc ON sc.call_status = l.call_status AND sc.mb_group=0 AND sc.is_after_call=1
        LEFT JOIN call_aftercall_ticket tk
          ON tk.campaign_id = l.campaign_id AND tk.mb_group = l.mb_group AND tk.target_id = l.target_id
        {$where_sql}
    ) x
    WHERE x.rn=1
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

/* ==========================
   상세 목록 (중복 제거: rn=1만)
   ========================== */
$sql_list = "SELECT
    b.call_id, b.mb_group as b_mb_group, b.campaign_id, b.target_id as b_target_id, b.mb_no AS agent_id,
    b.call_status, b.call_start, b.call_end, b.call_time, b.call_hp,

    m.mb_name AS agent_name, m.mb_id AS agent_mb_id,
    COALESCE(NULLIF(m.mb_name,''), m.mb_id) AS agent_sort,
    sc.name_ko AS status_label,

    t.name AS target_name, t.birth_date, t.meta_json,
    CASE
      WHEN t.birth_date IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
           - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
    END AS man_age,

    cc.name AS campaign_name,

    COALESCE(tk.state_id,0) AS ac_state_id,
    s.name_ko               AS ac_state_label,
    s.ui_type               AS ac_state_ui,
    s.sort_order            AS ac_state_sort,
    tk.scheduled_at         AS ac_scheduled_at,
    tk.schedule_note        AS ac_schedule_note,
    tk.updated_at           AS ac_updated_at,

    /* 2차담당자 */
    tk.assigned_after_mb_no,
    ma.mb_name AS after_agent_name,
    ma.mb_id   AS after_agent_mb_id,
    COALESCE(NULLIF(ma.mb_name,''), ma.mb_id) AS after_agent_sort,
    COALESCE(ma.is_after_call,0) AS after_is_on,
    /* 디테일 정보 */
    info.*
  FROM (
    SELECT l.*,
           ROW_NUMBER() OVER (PARTITION BY l.mb_group, l.campaign_id, l.target_id
                              ORDER BY l.call_start DESC, l.call_id DESC) AS rn
      FROM call_log l
      JOIN call_target t ON t.target_id = l.target_id
      JOIN call_status_code sc ON sc.call_status = l.call_status AND sc.mb_group=0 AND sc.is_after_call=1
      LEFT JOIN call_aftercall_ticket tk
        ON tk.campaign_id = l.campaign_id AND tk.mb_group = l.mb_group AND tk.target_id = l.target_id
      {$where_sql}
  ) AS b
  JOIN call_target t ON t.target_id = b.target_id
  JOIN call_status_code sc ON sc.call_status=b.call_status AND sc.mb_group=0
  LEFT JOIN {$member_table} m  ON m.mb_no  = b.mb_no
  LEFT JOIN (
      SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('지점 ', mb_group))) AS mv_group_name
        FROM {$member_table} WHERE mb_group>0 GROUP BY mb_group
  ) g ON g.mb_group = b.mb_group
  JOIN call_campaign cc ON cc.campaign_id=b.campaign_id AND cc.mb_group=b.mb_group
  LEFT JOIN call_aftercall_ticket tk
    ON tk.campaign_id=b.campaign_id AND tk.mb_group=b.mb_group AND tk.target_id=b.target_id
  LEFT JOIN call_aftercall_db_info info ON info.target_id=tk.target_id
  LEFT JOIN call_aftercall_state_code s ON s.state_id=COALESCE(tk.state_id,0)
  LEFT JOIN {$member_table} ma ON ma.mb_no = tk.assigned_after_mb_no
  WHERE b.rn=1
  ORDER BY {$order_sql}
  LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

// 현재 GET 그대로를 보존
$__q_all = $_GET;
$__q_all['mode'] = 'screen';     $href_screen    = './call_after_db_list_excel.php?acstate=10&'.http_build_query($__q_all);
$__q_all['mode'] = 'condition';  $href_condition = './call_after_db_list_excel.php?acstate=10&'.http_build_query($__q_all);
$__q_all['mode'] = 'all';        $href_all       = './call_after_db_list_excel.php?acstate=10&'.http_build_query($__q_all);

/* ==========================
   렌더
   ========================== */
$token = get_token();
$g5['title'] = '접수DB관리';
include_once(G5_ADMIN_PATH.'/admin.head.php');
// if(!empty($aa)) {
//   print_r2($stats_after);
//   print_r2($ac_codes);
// }
$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';
$qparams_for_sort = $_GET;
unset($qparams_for_sort['sort'], $qparams_for_sort['dir'], $qparams_for_sort['page']);
?>
<script src="./js/call_after_db_list.js?v=20251130_1"></script>
<style>
table.call-list-table td {min-width:65px}
table.call-list-table td.p_no {width:45px;min-width:45px;}
table.call-list-table td.td_mdhi {width:95px}
table.call-list-table td.td_hi {width:55px}
table.call-list-table td.small_txt {max-width:200px;}
table.call-list-table td.td_mini_hp {width:116px}
td.campaign_name {max-width:120px;}
.th-sort { text-decoration:none; color:inherit; }
.th-sort:hover { text-decoration:underline; }
.ac-badge.on { display:inline-block; padding:1px 6px; border-radius:10px; font-size:11px; background:#16a34a; color:#fff; }
.ac-badge.off{ display:inline-block; padding:1px 6px; border-radius:10px; font-size:11px; background:#9ca3af; color:#fff; }
a.ac-edit-btn {font-weight:700;color:#253aaf}
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./call_after_db_list.php" class="form-row" id="searchForm" autocomplete="off">
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

        <span class="pipe">|</span>

        <label for="q_type">검색구분</label>
        <select name="q_type" id="q_type">
            <option value="all"  <?php echo $q_type==='all'?'selected':'';?>>전체</option>
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" placeholder="검색어 입력">

        <span class="pipe">|</span>

        <label for="rows">표시건수</label>
        <select name="rows" id="rows">
            <?php foreach ([20,50,100,200] as $opt){ ?>
                <option value="<?php echo $opt;?>" <?php echo $page_rows==$opt?'selected':'';?>><?php echo $opt;?></option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql) { ?><a href="./call_after_db_list.php" class="btn btn_02">초기화</a><?php } ?>

        <span class="row-split"></span>

        <?php if ($mb_level >= 9) { ?>
            <select name="company_id" id="company_id">
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
            <select name="mb_group" id="mb_group">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 지점</option>
                <?php
                if ($group_options) {
                    if ($mb_level >= 9 && $sel_company_id == 0) {
                        $last_cid = null;
                        foreach ($group_options as $g) {
                            if ($last_cid !== (int)$g['company_id']) {
                                echo '<option value="" disabled>── '.get_text($g['company_name']).' ──</option>';
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
            <input type="hidden" name="mb_group" id="mb_group" value="<?php echo (int)$sel_mb_group; ?>">
        <?php } ?>

        <?php if ($sel_mb_group > 0 || $mb_level >= 7) { ?>
        <select name="agent" id="agent">
            <option value="0">전체 상담원</option>
            <?php echo render_agent_options($agent_options, $sel_agent_no); ?>
        </select>

        <?php $sel_after_agent_no = (int)(_g('ac_agent', 0)); ?>
        <!-- 2차담당자 -->
        <select name="ac_agent" id="ac_agent">
            <option value="0">전체 2차담당자</option>
            <?php
            // 화면 필터: 조직 범위 기준 (레벨7=내 지점 / 8=내 회사 / 9+=선택회사/지점)
            $cond = [];
            if ($mb_level == 7)               $cond[] = "m.mb_group = ".(int)$my_group;
            elseif ($sel_mb_group > 0)        $cond[] = "m.mb_group = ".(int)$sel_mb_group;
            elseif ($mb_level >= 9 && $sel_company_id > 0) $cond[] = "m.company_id = ".(int)$sel_company_id;
            elseif ($mb_level == 8)           $cond[] = "m.company_id = ".(int)$my_company_id;
            $cond[] = "m.mb_level IN (5,7)";
            $sql_ac_agents = "SELECT m.mb_no, m.mb_id, m.mb_name, COALESCE(m.is_after_call,0) AS is_after_call
                                FROM {$member_table} m
                               ".($cond?' WHERE '.implode(' AND ',$cond):'')."
                               ORDER BY (m.mb_name<>''), m.mb_name, m.mb_id";
            $rs_ac_agents = sql_query($sql_ac_agents);
            while($ag = sql_fetch_array($rs_ac_agents)) {
                $label = ($ag['mb_name']?:$ag['mb_id']).' '.((int)$ag['is_after_call']===1?'[ON]':'[OFF]');
                echo '<option value="'.(int)$ag['mb_no'].'" '.get_selected($sel_after_agent_no,(int)$ag['mb_no']).'>'.get_text($label).'</option>';
            }
            ?>
        </select>
        <?php } ?>
    </form>
</div>

<div class="tbl_head01 tbl_wrap" style="margin-top:20px;">
    <table class="table-fixed call-list-table">
        <thead>
            <tr>
                <th>P_No.</th>
                <th>업체명</th>
                <th>지점명</th>
                <th><?php echo sort_th('agent_name','상담원명'); ?></th>
                <!-- <th>통화결과</th> -->
                <th><?php echo sort_th('target_name','고객명'); ?></th>
                <th>생년월일</th>
                <th><?php echo sort_th('call_hp','전화번호'); ?></th>
                <th>주소1</th>
                <th>주소2</th>
                <th>성별</th>
                <th>만나이</th>
                <th>납입보험료</th>
                <th>DB권역</th>
                <th>DB유형</th>
                <th>통화희망일시</th>
                <th>방문희망일시</th>
                <th><?php echo sort_th('after_agent_name','2차팀장'); ?></th>
                <th><?php echo sort_th('created_at','생성일'); ?></th>
                <th>작업</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="18" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            $p_no = 0;
            while ($row = sql_fetch_array($res_list)) {
                $p_no++;
                $hp_fmt   = format_korean_phone($row['call_hp']);
                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);
                $agent    = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];

                $status_label = $row['status_label'] ?: ('코드 '.$row['call_status']);
                $ui_call = !empty($status_ui[$row['call_status']]) ? $status_ui[$row['call_status']] : 'secondary';

                $ac_label = $row['ac_state_label'] ?: '대기';
                $ac_ui    = $row['ac_state_ui'] ?: '';

                $bday = empty($row['birth_date']) ? '-' : str_replace('-', '', substr(get_text($row['birth_date']), 2, 8));
                $man_age = is_null($row['man_age']) ? '-' : ((int)$row['man_age']).'세';

                $sex_txt = '';
                if ((int)$row['sex'] === 1) $sex_txt = '남성';
                elseif ((int)$row['sex'] === 2) $sex_txt = '여성';

                $meta_txt = '';
                $meta_json = $row['meta_json'];
                if (is_string($meta_json)) {
                    $j = json_decode($meta_json, true);
                    if (json_last_error() === JSON_ERROR_NONE && $j && is_array($j)) {
                        $meta_txt .= ($meta_txt ? ', ' : '').implode(', ', array_values($j));
                    }
                } elseif (is_array($meta_json)) {
                    $meta_txt .= ($meta_txt ? ', ' : '').implode(', ', array_values($meta_json));
                }

                $after_label = '-';
                if (!empty($row['assigned_after_mb_no'])) {
                    $after_label = $row['after_agent_name'] ? $row['after_agent_name'] : $row['after_agent_mb_id'];
                }

                $sc_time  = $row['db_scheduled_at'] ? fmt_datetime(get_text($row['db_scheduled_at']), 'mdhis') : '-';
                $ac_time  = $row['created_at'] ? fmt_datetime(get_text($row['created_at']), 'mdhis') : '-';
                $schedule_disp = format_schedule_display($row['ac_scheduled_at'], $row['ac_schedule_note']);
                $db_area = $db_type = '';
                ?>
                <tr>
                    <td class="p_no"><?php echo $p_no; ?></td>
                    <td><?php echo get_company_name_from_group_id_cached($row['b_mb_group']); ?></td>
                    <td><?php echo get_group_name_cached($row['b_mb_group']); ?></td>
                    <td><?php echo get_text($agent); ?></td>
                    <!-- <td class="status-col status-<?php echo get_text($ui_call); ?>"><?php echo get_text($status_label); ?></td> -->
                    <td>
                          <a href="#this" class="ac-edit-btn"
                                data-campaign-id="<?php echo (int)$row['campaign_id'];?>"
                                data-mb-group="<?php echo (int)$row['b_mb_group'];?>"
                                data-target-id="<?php echo (int)$row['b_target_id'];?>"
                                data-target-name="<?php echo get_text($row['target_name'] ?: '-');?>"
                                data-call-hp="<?php echo get_text($hp_fmt);?>"
                                data-state-id="<?php echo (int)$row['ac_state_id'];?>"
                                data-birth="<?php echo $bday; ?>"
                                data-age="<?php echo is_null($row['man_age'])?'':(int)$row['man_age']; ?>"
                                data-meta="<?php echo $meta_txt; ?>"
                                data-after-mb-no="<?php echo (int)$row['assigned_after_mb_no'];?>"
                          ><?php echo get_text($row['target_name'] ?: '-'); ?></a>
                    </td>
                    <td><?php echo $bday; ?></td>
                    <td class="td_mini_hp"><?php echo get_text($hp_fmt); ?></td>             <!-- 전화번호 -->
                    <td><?php echo get_text($row['area1']); ?></td>
                    <td><?php echo get_text($row['area2']); ?></td>
                    <td><?php echo $sex_txt; ?></td>
                    <td><?php echo $man_age; ?></td>
                    <td><?php echo number_format($row['month_pay']); ?></td>
                    <td><?php echo get_db_area_from_area1($row['area1']); ?></td>
                    <td><?php echo get_db_type_from_man_age((int)$man_age); ?></td>
                    <td><?php echo $schedule_disp; ?></td>
                    <td class="td_mdhi"><?php echo $sc_time; ?></td>
                    <td><?php echo $after_label; ?></td>                  <!-- 2차팀장 -->
                    <td class="td_mdhi"><?php echo $ac_time; ?></td>
                    <td class="btn_one">
                        <button type="button"
                                class="btn btn_02 ac-edit-btn"
                                data-campaign-id="<?php echo (int)$row['campaign_id'];?>"
                                data-mb-group="<?php echo (int)$row['b_mb_group'];?>"
                                data-target-id="<?php echo (int)$row['b_target_id'];?>"
                                data-target-name="<?php echo get_text($row['target_name'] ?: '-');?>"
                                data-call-hp="<?php echo get_text($hp_fmt);?>"
                                data-state-id="<?php echo (int)$row['ac_state_id'];?>"
                                data-birth="<?php echo $bday; ?>"
                                data-age="<?php echo is_null($row['man_age'])?'':(int)$row['man_age']; ?>"
                                data-meta="<?php echo $meta_txt; ?>"
                                data-after-mb-no="<?php echo (int)$row['assigned_after_mb_no'];?>"
                                >
                            처리
                        </button>
                    </td>
                </tr>
                <?php
            }
        }
        ?>
        </tbody>
    </table>
</div>

<?php
$total_page = max(1, (int)ceil($total_count / $page_rows));
$qstr = $_GET; unset($qstr['page']);
$base = './call_after_db_list.php?'.http_build_query($qstr);
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

<!-- 팝업 템플릿 -->
<div id="acOverlay" class="ac-overlay" hidden></div>
<div class="ac-panel" id="acPanel" aria-hidden="true" hidden>
  <div class="ac-panel__head">
    <strong>접수DB관리 - 각각 저장</strong>
    <div style="display:flex;gap:7px;align-items:center;margin-left:auto;">
        <button id="acPrev" class="btn btn-mini2"><i class="fa fa-chevron-left"></i> 이전</button>
        <button id="acNext" class="btn btn-mini2">다음 <i class="fa fa-chevron-right"></i></button>
        <button type="button" class="ac-panel__close" id="acClose" aria-label="닫기">×</button>
    </div>
  </div>
  <div class="ac-panel__body">
    <div class="ac-summary">
      <div><b id="s_target_name">-</b> / <span id="s_hp"></span> / 생년월일: <span id="s_birth">-</span> / 만나이: <span id="s_age">-</span></div>
      <div>추가정보: <span id="s_meta">-</span></div>
    </div>

    <?php
    include_once('./call_after_db_sub_form.php');
    ?>

    <form id="acForm" method="post" action="./ajax_call_after_list.php" autocomplete="off">
    <input type="hidden" name="ajax" value="save">
    <input type="hidden" name="token" value="<?php echo get_token();?>">
    <input type="hidden" name="campaign_id" id="f_campaign_id" value="">
    <input type="hidden" name="mb_group" id="f_mb_group" value="">
    <input type="hidden" name="target_id" id="f_target_id" value="">
    <input type="hidden" name="db_id" id="f_db_id" value="">
    <input type="hidden" name="schedule_clear" id="f_schedule_clear" value="0">

      <div class="ac-field">
        <label for="f_state_id">처리상태</label>
        <select name="state_id" id="f_state_id" class="ac-input">
          <?php foreach ($ac_codes as $sid=>$code) { ?>
            <option value="<?php echo $sid; ?>"><?php echo get_text($code['name_ko']); ?></option>
          <?php } ?>
        </select>
      </div>

      <?php $can_edit_after = ($mb_level >= 7); ?>
      <div class="ac-field">
        <label for="f_after_agent">2차담당자</label>
        <select name="assigned_after_mb_no" id="f_after_agent" class="ac-input" <?php echo $can_edit_after?'':'disabled'; ?>>
          <option value="0">미지정</option>
        </select>
        <?php if(!$can_edit_after){ ?><div class="small-muted">관리자만 변경할 수 있습니다.</div><?php } ?>
      </div>

      <div class="ac-field">
        <label>일정 메모</label>
        <div class="ac-inline">
            <input type="date" name="schedule_date" id="f_schedule_date" class="frm_input">
            <input type="time" name="schedule_time" id="f_schedule_time" class="frm_input" step="60">
            <input type="text" name="schedule_note" id="f_schedule_note" class="ac-input frm_input" placeholder="예) 재통화(점심시간 피해서)">
            <button type="button" class="btn btn-mini2" id="btnSchedToday">오늘</button>
            <button type="button" class="btn btn-mini2" id="btnSchedTomorrow">내일</button>
            <button type="button" class="btn btn-mini2" id="btnSchedClear">일정삭제</button>
        </div>
      </div>

      <div class="ac-field">
        <label for="f_memo">처리 메모</label>
        <textarea name="memo" id="f_memo" class="ac-input" rows="4" placeholder="고객 메모를 입력하세요. (저장 시 작성자/시간과 함께 1행으로 기록됩니다)"></textarea>
      </div>

      <div class="ac-actions">
        <button type="submit" class="btn btn_01">저장</button>
        <!-- <button type="button" class="btn btn_02" id="acCancel">닫기</button> -->
      </div>

      <div class="ac-field">
        <label>활동 로그</label>
        <div id="f_timeline" class="ac-timeline"></div>
      </div>

    </form>
  </div>
</div>

<div class="btn_fixed_top">
    <a href="<?php echo $href_all;       ?>" class="btn btn_02">전체 엑셀다운</a>&nbsp;&nbsp;&nbsp;
    <a href="<?php echo $href_condition; ?>" class="btn btn_02">현재조건 엑셀다운</a>&nbsp;&nbsp;&nbsp;
    <a href="<?php echo $href_screen;    ?>" class="btn btn_02" style="background:#e5e7eb !important">현재화면 엑셀다운</a>
</div>

<script>
(function() {
  // ============================================================
  // [전역 변수 및 초기화]
  // ============================================================
  var form     = document.getElementById(AC_SETTINGS.ids.form);
  // 페이지 내 모든 팝업 호출 버튼 (이름 링크 + 처리 버튼 포함)
  var rawBtns = Array.prototype.slice.call(
      document.querySelectorAll('.' + AC_SETTINGS.classes.editBtn)
  );
  // target_id 기준 대표 버튼 모음
  var editButtons = [];          // 이동에 사용할 "대표 버튼"
  var targetIndexMap = {};       // target_id → index
  var domToIndexMap = new WeakMap(); // 실제 DOM → index
  var currentIdx = -1;
  
  // ============================================================
  // target_id 로 묶어서 대표 버튼 만들기
  // ============================================================
  rawBtns.forEach(function(btn) {
      var target_id = btn.getAttribute('data-target-id');
      if (!target_id) return;

      if (targetIndexMap[target_id] === undefined) {
          var idx = editButtons.length;
          targetIndexMap[target_id] = idx;
          editButtons.push(btn);        // 이 target_id 의 대표 버튼
      }
      // 모든 버튼이 자기 target_id 의 대표 인덱스를 가리키게
      domToIndexMap.set(btn, targetIndexMap[target_id]);
  });

  // ============================================================
  // 인덱스로 팝업 열기 (핵심 함수)
  // ============================================================
  function openByIndex(idx) {
      var btnEl = editButtons[idx];
      if (!btnEl) return;

      currentIdx = idx;

      var campaign_id  = btnEl.getAttribute('data-campaign-id');
      var mb_group     = btnEl.getAttribute('data-mb-group');
      var target_id    = btnEl.getAttribute('data-target-id');
      var targetName   = btnEl.getAttribute('data-target-name') || '';
      var hp           = btnEl.getAttribute('data-call-hp') || '';
      var state_id     = btnEl.getAttribute('data-state-id') || 0;
      var curAfter     = parseInt(btnEl.getAttribute('data-after-mb-no') || '0', 10) || 0;

      // 고객 요약
      document.getElementById(AC_SETTINGS.ids.s_target_name).textContent = targetName || '-';
      document.getElementById(AC_SETTINGS.ids.s_hp).textContent          = hp || '';
      document.getElementById(AC_SETTINGS.ids.s_birth).textContent       = btnEl.getAttribute('data-birth') || '-';
      var age = btnEl.getAttribute('data-age') || '';
      document.getElementById(AC_SETTINGS.ids.s_age).textContent         = (age !== '' ? age + '세' : '-');
      document.getElementById(AC_SETTINGS.ids.s_meta).textContent        = btnEl.getAttribute('data-meta') || '-';

      // 폼 세팅
      after_db_resetPopupForm(campaign_id, mb_group, target_id, state_id);

      // 상세정보 Ajax
      after_db_FetchDetailInfo(target_id).then(function(res) {
          var secDetail = document.getElementById(AC_SETTINGS.ids.detailSection);
          if (res && res.use_detail && secDetail) {
              secDetail.hidden = false;
              if (res.data) after_db_fillDetailSection(res.data);
          }
      });

      // 담당자
      after_db_loadAgentOptions(mb_group, curAfter);

      // 티켓
      after_db_loadTicketData(campaign_id, mb_group, target_id);

      // 팝업 열기
      after_db_openPanel();
  }

  // ============================================================
  // 이전/다음 이동
  // ============================================================
  function move(delta) {
      if (currentIdx < 0) return;
      var nextIdx = currentIdx + delta;
      if (nextIdx < 0 || nextIdx >= editButtons.length) return;
      openByIndex(nextIdx);
  }
  
  // ============================================================
  // 버튼 바인딩
  // ============================================================

  // 1. 팝업 기본 이벤트
  after_db_initPopupEvents();

  // 2. 모든 호출 버튼 클릭 → 해당 target_id 인덱스로 매핑
  rawBtns.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
          e.preventDefault();
          var idx = domToIndexMap.get(this);
          if (idx !== undefined) openByIndex(idx);
      });
  });

  // 3. 이전/다음 버튼 이벤트
  var btnPrev = document.getElementById('acPrev');
  var btnNext = document.getElementById('acNext');
  if (btnPrev) btnPrev.addEventListener('click', function(e){ e.preventDefault(); move(-1); });
  if (btnNext) btnNext.addEventListener('click', function(e){ e.preventDefault(); move(1); });

  // 4. 일정 퀵버튼
  var btnToday = document.getElementById(AC_SETTINGS.ids.btnSchedToday);
  var btnTomorrow = document.getElementById(AC_SETTINGS.ids.btnSchedTomorrow);
  var btnClear = document.getElementById(AC_SETTINGS.ids.btnSchedClear);
  if (btnToday) btnToday.addEventListener('click', function(){ after_db_setDateInput(0); if(!document.getElementById(AC_SETTINGS.ids.f_schedule_time).value) document.getElementById(AC_SETTINGS.ids.f_schedule_time).value='14:00'; });
  if (btnTomorrow) btnTomorrow.addEventListener('click', function(){ after_db_setDateInput(1); if(!document.getElementById(AC_SETTINGS.ids.f_schedule_time).value) document.getElementById(AC_SETTINGS.ids.f_schedule_time).value='10:00'; });
  if (btnClear) btnClear.addEventListener('click', function(){
      document.getElementById(AC_SETTINGS.ids.f_schedule_date).value='';
      document.getElementById(AC_SETTINGS.ids.f_schedule_time).value='';
      document.getElementById(AC_SETTINGS.ids.f_schedule_note).value='';
      document.getElementById(AC_SETTINGS.ids.f_schedule_clear).value='1';
  });

  // 5. 폼 저장
  if (form) {
      form.addEventListener('submit', function(e){
        e.preventDefault();
        var fd = new FormData(form);

        // // ✅ FormData 내용 출력
        // console.group('FormData Dump');
        // for (const [key, value] of fd.entries()) {
        //     console.log(key, value);
        // }
        // console.groupEnd();

        fetch('./ajax_call_after_list.php', {method:'POST', body:fd, credentials:'same-origin'})
          .then(r=>r.json())
          .then(j=>{
            if (j && j.success) {
              after_db_renderTimeline(j.history || [], j.notes || []);
              location.reload();
            } else {
              console.log(j);
              alert('저장 실패: '+(j && j.message ? j.message : ''));
            }
          })
          .catch(err=>{ console.error(err); alert('저장 중 오류'); });
      });
  }

})();


(function(){
    var $form = document.getElementById('searchForm');
    // ★ 회사 변경 시 지점/담당자 초기화 후 자동검색
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

    // 지점 변경 시 담당자 초기화 후 자동검색
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
