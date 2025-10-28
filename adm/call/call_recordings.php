<?php
// /adm/call/call_recordings.php
$sub_menu = '700300';
require_once './_common.php';

// -----------------------------
// 접근 권한: 레벨 7 미만 금지
// -----------------------------
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// -----------------------------
// 내 정보
// -----------------------------
$mb_no        = (int)($member['mb_no'] ?? 0);
$mb_level     = (int)($member['mb_level'] ?? 0);
$my_group     = (int)($member['mb_group'] ?? 0);
$my_company_id= (int)($member['company_id'] ?? 0);
$member_table = $g5['member_table']; // g5_member

$today      = date('Y-m-d');
$default_start = $today;
$default_end   = $today;

// -----------------------------
// 입력 파라미터
// -----------------------------
$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

//
// ★ 변경된 권한 스코프에 따른 "회사/그룹" 선택 값
//
if ($mb_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체 회사
} else {
    $sel_company_id = $my_company_id; // 8/7은 자기 회사 고정
}

$sel_mb_group = ($mb_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // 8+=전체/특정그룹, 7=본인그룹
$sel_agent_no = (int)($_GET['agent'] ?? 0); // 상담원 선택(선택사항)

// 검색/필터
$q         = _g('q', '');
$q_type    = _g('q_type', '');           // name | last4 | full | all
$f_status  = isset($_GET['status']) ? (int)$_GET['status'] : 0;  // 0=전체
$page      = max(1, (int)(_g('page', '1')));
$page_rows = 30;
$offset    = ($page - 1) * $page_rows;

// -----------------------------
// WHERE 구성 (기간: 녹취 생성 r.created_at 기준)
// -----------------------------
$where = [];

$start_esc = sql_escape_string($start_date.' 00:00:00');
$end_esc   = sql_escape_string($end_date.' 23:59:59');
$where[]   = "r.created_at BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 상태코드 필터
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
        $q4 = preg_replace('/\D+/', '', $q);
        $q4 = substr($q4, -4);
        $hp = preg_replace('/\D+/', '', $q);

        $conds = [];
        $conds[] = "t.name LIKE '%{$q_esc}%'";
        if ($q4 !== '') {
            $q4_esc = sql_escape_string($q4);
            $conds[] = "t.hp_last4 = '{$q4_esc}'";
        }
        if ($hp !== '') {
            $hp_esc = sql_escape_string($hp);
            $conds[] = "l.call_hp = '{$hp_esc}'";
        }
        if (!empty($conds)) {
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
    }
}

//
// ★ 권한/선택 필터 (회사/그룹 스코프 적용)
// - 회사 스코프는 agent 조인(m) 의 company_id 기준
//
if ($mb_level == 7) {
    $where[] = "l.mb_group = {$my_group}";
    // 회사 스코프는 자연스럽게 그룹에 종속되어 생략
} elseif ($mb_level < 7) {
    $where[] = "l.mb_no = {$mb_no}";
} else {
    // 8+: 회사 스코프
    if ($mb_level == 8) {
        $where[] = "m.company_id = {$my_company_id}";
    } elseif ($mb_level >= 9) {
        if ($sel_company_id > 0) {
            $where[] = "m.company_id = {$sel_company_id}";
        }
    }
    // 그룹 선택
    if ($sel_mb_group > 0) {
        $where[] = "l.mb_group = {$sel_mb_group}";
    }
}

// 담당자 선택
if ($sel_agent_no > 0) {
    $where[] = "l.mb_no = {$sel_agent_no}";
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// -----------------------------
// 상태코드 목록 (셀렉트용)
// -----------------------------
$codes = [];
$qc = "
    SELECT call_status, name_ko, status
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
";
$rc = sql_query($qc);
while ($r = sql_fetch_array($rc)) $codes[] = $r;

// 상태 라벨/컬러 매핑
$code_list_status = [];
$status_ui = [];
$qcl = "
    SELECT call_status, name_ko, ui_type
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
";
$rcl = sql_query($qcl);
while ($v = sql_fetch_array($rcl)) {
    $code_list_status[(int)$v['call_status']] = $v;
    $status_ui[(int)$v['call_status']] = $v['ui_type'] ?? 'secondary';
}

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


// -----------------------------
// 총 건수
// - 회사 필터는 agent 조인(m.company_id) 기준으로 동작
// -----------------------------
$sql_cnt = "
    SELECT COUNT(*) AS cnt
      FROM call_recording r
      JOIN call_log l
        ON l.call_id = r.call_id
       AND l.campaign_id = r.campaign_id
       AND l.mb_group = r.mb_group
      JOIN call_target t
        ON t.target_id = l.target_id
      JOIN call_campaign cc
        ON cc.campaign_id = r.campaign_id
       AND cc.mb_group = r.mb_group
      LEFT JOIN {$member_table} m
        ON m.mb_no = l.mb_no
    {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// -----------------------------
// 상세 목록
// -----------------------------
$sql_list = "
    SELECT
        r.recording_id, 
        r.created_at        AS rec_created_at,
        r.file_size,
        r.duration_sec,
        r.s3_bucket,
        r.s3_key,
        r.content_type,

        l.call_id, 
        l.mb_group,
        l.campaign_id,
        l.mb_no             AS agent_id,
        l.call_status,
        sc.name_ko          AS status_label,
        sc.is_after_call    AS is_after_call,
        l.call_start, 
        l.call_end,
        l.call_time,
        l.call_hp,

        t.name              AS target_name,
        t.birth_date,
        t.hp_last4,
        t.meta_json,

        m.mb_name           AS agent_name,
        m.mb_id             AS agent_mb_id,
        m.company_id        AS agent_company_id,

        cc.name             AS campaign_name,
        cc.is_open_number   AS is_open_number
    FROM call_recording r
    JOIN call_log l
      ON l.call_id = r.call_id
     AND l.campaign_id = r.campaign_id
     AND l.mb_group = r.mb_group
    JOIN call_target t ON t.target_id = l.target_id
    LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
    /* 통화결과 라벨(공통셋) */
    LEFT JOIN call_status_code sc
      ON sc.call_status = l.call_status AND sc.mb_group = 0
    /* 캠페인명 */
    JOIN call_campaign cc
      ON cc.campaign_id = r.campaign_id
     AND cc.mb_group = r.mb_group
    {$where_sql}
    ORDER BY r.created_at DESC, r.recording_id DESC
    LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

// -----------------------------
// 화면 출력
// -----------------------------
$token = get_token();
$g5['title'] = '녹취확인';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
// 프록시(사인 URL/스트리밍)
function make_recording_url($row){ return './rec_proxy.php?rid='.(int)$row['recording_id']; }
?>
<style>
audio {max-width:260px;max-height:30px;}
</style>
<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<!-- 검색/필터 -->
<div class="local_sch01 local_sch">
    <form method="get" action="./call_recordings.php" class="form-row" id="searchForm">
        <!-- 1줄차: 기간/바로가기/검색 -->
        <label for="start">기간</label>
        <input type="date" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input">
        <span class="tilde">~</span>
        <input type="date" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input">

        <?php
        // 어제, 오늘, 지난주, 이번주, 지난달, 이번달 버튼
        render_date_range_buttons('dateRangeBtns');
        ?>
        <script>
          DateRangeButtons.init({
            container: '#dateRangeBtns', startInput: '#start', endInput: '#end', form: '#searchForm',
            autoSubmit: true, weekStart: 1, thisWeekEndToday: true, thisMonthEndToday: true
          });
        </script>

        <span class="pipe">|</span>

        <label for="q_type">검색구분</label>
        <select name="q_type" id="q_type">
            <option value="all">전체</option>
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" placeholder="검색어 입력">

        <span class="pipe">|</span>

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
        <a href="./call_recordings.php" class="btn btn_02">초기화</a>
        <?php } ?>
        <span class="small-muted">권한:
            <?php
            if     ($mb_level >= 8) echo '전체';
            elseif ($mb_level == 7) echo '조직';
            else                    echo '개인';
            ?>
        </span>

        <span class="row-split"></span>

        <!-- 2줄차: 회사/그룹/담당자 (권한별) -->
        <?php if ($mb_level >= 9) { ?>
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

<!-- 상세 목록 -->
<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <th>그룹명</th>
                <!-- <th>아이디</th> -->
                <th>상담원명</th>
                <th>통화결과</th>
                <th>통화시작</th>
                <th>통화종료</th>
                <th>통화시간</th>
                <th>상담시간</th>
                <th>고객명</th>
                <th>전화번호</th>
                <th>파일크기</th>
                <th>재생</th>
                <th>다운로드</th>
                <th>업로드시각</th>
                <th>캠페인명</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="15" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                // ★ 번호 노출 정책: is_open_number=0 이고 is_after_call!=1 이면 숨김
                $hp_fmt = '';
                if ((int)$row['is_open_number'] === 0 && (int)$row['is_after_call'] !== 1 && $mb_level < 9) {
                    $hp_fmt = '(숨김처리)';
                } else {
                    $hp_fmt = format_korean_phone($row['call_hp']);
                }
                $talk_sec = is_null($row['duration_sec']) ? '-' : fmt_hms((int)$row['duration_sec']);
                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);
                $agent    = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];
                $gname    = get_group_name_cached($row['mb_group']);
                $status   = $row['status_label'] ?: ('코드 '.$row['call_status']);
                $ui       = !empty($status_ui[$row['call_status']]) ? $status_ui[$row['call_status']] : 'secondary';
                $status_class = 'status-col status-'.get_text($ui);

                $dl_url   = make_recording_url($row);
                $mime     = guess_audio_mime($row['s3_key'], $row['content_type']);
                ?>
                <tr>
                    <td><?php echo get_text($gname); ?></td>
                    <!-- <td><?php echo get_text($row['agent_mb_id']); ?></td> -->
                    <td><?php echo get_text($agent); ?></td>
                    <td class="<?php echo $status_class; ?>"><?php echo get_text($status); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_start']), 'mdhi'); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_end']), 'mdhi'); ?></td>
                    <td><?php echo $call_sec; ?></td>
                    <td><?php echo $talk_sec; ?></td>
                    <td><?php echo get_text($row['target_name'] ?: '-'); ?></td>
                    <td><?php echo get_text($hp_fmt); ?></td>
                    <td><?php echo fmt_bytes($row['file_size']); ?></td>
                    <td class="audio-ctl">
                        <audio controls preload="none" class="audio">
                            <source src="<?php echo $dl_url; ?>" type="<?php echo get_text($mime); ?>">
                            브라우저가 audio 태그를 지원하지 않습니다.
                        </audio>
                    </td>
                    <td><a href="<?php echo $dl_url; ?>" class="btn btn_02">다운</a></td>
                    <td><?php echo fmt_datetime(get_text($row['rec_created_at']), 'mdhis'); ?></td>
                    <td style="font-size:11px;letter-spacing:-1px"><?php echo get_text($row['campaign_name'] ?: '-'); ?></td>
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
$base = './call_recordings.php?'.http_build_query($qstr);
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
