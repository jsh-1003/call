<?php
// /adm/call/call_manual_recordings.php
$sub_menu = '700320';
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
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$member_table  = $g5['member_table']; // g5_member

$today         = date('Y-m-d');
$default_start = $today;
$default_end   = $today;

// -----------------------------
// 입력 파라미터
// -----------------------------
$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

if ($mb_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체 회사
} else {
    $sel_company_id = $my_company_id; // 8/7은 자기 회사 고정
}
$sel_mb_group = ($mb_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // 8+=전체/특정지점, 7=본인지점
$sel_agent_no = (int)($_GET['agent'] ?? 0);

$q      = _g('q', '');
$q_type = _g('q_type', ''); // name | last4 | full | all (수동은 name 없음, but agent 검색은 유지 가능)
$f_status = isset($_GET['status']) ? (int)$_GET['status'] : 0; // 0=전체, -1=NULL
$page      = max(1, (int)(_g('page', '1')));
$page_rows = 30;
$offset    = ($page - 1) * $page_rows;

// -----------------------------
// WHERE 구성 (기간: mr.created_at 기준)
// -----------------------------
$where = [];
$start_esc = sql_escape_string($start_date.' 00:00:00');
$end_esc   = sql_escape_string($end_date.' 23:59:59');
$where[]   = "mr.created_at BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 상태코드 필터 (NULL 포함)
if ($f_status === -1) {
    $where[] = "ml.call_status IS NULL";
} elseif ($f_status > 0) {
    $where[] = "ml.call_status = {$f_status}";
}

// 검색어(수동: 전화번호 위주로)
if ($q !== '' && $q_type !== '') {
    if ($q_type === 'full') {
        $hp = preg_replace('/\D+/', '', $q);
        if ($hp !== '') {
            $hp_esc = sql_escape_string($hp);
            $where[] = "ml.call_hp = '{$hp_esc}'";
        }
    } elseif ($q_type === 'last4') {
        $q4 = preg_replace('/\D+/', '', $q);
        $q4 = substr($q4, -4);
        if ($q4 !== '') {
            $q4_esc = sql_escape_string($q4);
            $where[] = "RIGHT(ml.call_hp,4) = '{$q4_esc}'";
        }
    } elseif ($q_type === 'agent') {
        $q_esc = sql_escape_string($q);
        $where[] = "(m.mb_name LIKE '%{$q_esc}%' OR m.mb_id LIKE '%{$q_esc}%')";
    } elseif ($q_type === 'all') {
        $q_esc = sql_escape_string($q);
        $hp = preg_replace('/\D+/', '', $q);
        $q4 = substr($hp, -4);

        $conds = [];
        if ($q_esc !== '') $conds[] = "(m.mb_name LIKE '%{$q_esc}%' OR m.mb_id LIKE '%{$q_esc}%')";
        if ($hp !== '')    $conds[] = "ml.call_hp = '".sql_escape_string($hp)."'";
        if ($q4 !== '')    $conds[] = "RIGHT(ml.call_hp,4) = '".sql_escape_string($q4)."'";
        if (!empty($conds)) $where[] = '(' . implode(' OR ', $conds) . ')';
    }
}

// 권한/선택 필터(회사/지점 스코프)
if ($mb_level == 7) {
    $where[] = "mr.mb_group = {$my_group}";
} else {
    if ($mb_level == 8) {
        $where[] = "m.company_id = {$my_company_id}";
    } elseif ($mb_level >= 9) {
        if ($sel_company_id > 0) {
            $where[] = "m.company_id = {$sel_company_id}";
        }
    }
    if ($sel_mb_group > 0) {
        $where[] = "mr.mb_group = {$sel_mb_group}";
    }
}

// 담당자 선택
if ($sel_agent_no > 0) {
    $where[] = "ml.mb_no = {$sel_agent_no}";
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
$status_ui = [];
$code_list_status = [];
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
 * 회사/지점/담당자 드롭다운 옵션
 * (기존 유틸 그대로 사용)
 */
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
$company_options = $build_org_select_options['company_options'];
$group_options   = $build_org_select_options['group_options'];
$agent_options   = $build_org_select_options['agent_options'];

// -----------------------------
// 총 건수
// -----------------------------
$sql_cnt = "
    SELECT COUNT(*) AS cnt
      FROM call_manual_recording mr
      JOIN call_manual_log ml
        ON ml.manual_id = mr.manual_id
      LEFT JOIN {$member_table} m
        ON m.mb_no = ml.mb_no
      {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// -----------------------------
// 상세 목록
// -----------------------------
$sql_list = "
    SELECT
        mr.manual_recording_id,
        mr.created_at AS rec_created_at,
        mr.file_size,
        mr.duration_sec AS rec_duration_sec,
        mr.s3_bucket,
        mr.s3_key,
        mr.content_type,

        ml.manual_id,
        ml.mb_group,
        ml.mb_no AS agent_id,
        ml.call_status,
        sc.name_ko AS status_label,
        ml.call_start,
        ml.call_end,
        ml.call_time,
        ml.call_hp,
        ml.agent_phone,

        m.mb_name AS agent_name,
        m.mb_id   AS agent_mb_id,
        m.company_id AS agent_company_id
    FROM call_manual_recording mr
    JOIN call_manual_log ml
      ON ml.manual_id = mr.manual_id
    LEFT JOIN {$member_table} m
      ON m.mb_no = ml.mb_no
    LEFT JOIN call_status_code sc
      ON sc.call_status = ml.call_status AND sc.mb_group = 0
    {$where_sql}
    ORDER BY mr.created_at DESC, mr.manual_recording_id DESC
    LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

// -----------------------------
// 화면 출력
// -----------------------------
$token = get_token();
$g5['title'] = '수동통화 녹취확인';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
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

<div class="local_sch01 local_sch">
    <form method="get" action="./call_manual_recordings.php" class="form-row" id="searchForm">
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
            <option value="full" <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
            <option value="last4"<?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="agent"<?php echo $q_type==='agent'?'selected':'';?>>상담원</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" placeholder="검색어 입력">

        <span class="pipe">|</span>

        <label for="status">상태코드</label>
        <select name="status" id="status">
            <option value="0"<?php echo $f_status===0?' selected':'';?>>전체</option>
            <option value="-1"<?php echo $f_status===-1?' selected':'';?>>미지정(NULL)</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)'); ?>
                </option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql) { ?>
        <a href="./call_manual_recordings.php" class="btn btn_02">초기화</a>
        <?php } ?>

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
                $last_cid = null;
                $last_gid = null;
                foreach ($agent_options as $a) {
                    if ($last_cid !== $a['company_id']) {
                        echo '<option value="" disabled class="opt-sep">[── '.get_text($a['company_name']).' ──]</option>';
                        $last_cid = $a['company_id'];
                        $last_gid = null;
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

<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <th>지점명</th>
                <th>상담원명</th>
                <th>통화결과</th>
                <th>통화시작</th>
                <th>통화종료</th>
                <th>통화시간</th>
                <th>상담시간</th>
                <th>발신번호</th>
                <th>전화번호</th>
                <th>파일크기</th>
                <th>재생</th>
                <th>다운로드</th>
                <th>업로드시각</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="12" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            while ($row = sql_fetch_array($res_list)) {
                $talk_sec = is_null($row['rec_duration_sec']) ? '-' : fmt_hms((int)$row['rec_duration_sec']);
                $call_sec = is_null($row['call_time']) ? '-' : fmt_hms((int)$row['call_time']);
                $agent    = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];
                $gname    = get_group_name_cached((int)$row['mb_group']);

                $status_val = $row['call_status'];
                if ($status_val === null || $status_val === '') {
                    $status = '미지정';
                    $ui = 'secondary';
                } else {
                    $status = $row['status_label'] ?: ('코드 '.(int)$status_val);
                    $ui = !empty($status_ui[(int)$status_val]) ? $status_ui[(int)$status_val] : 'secondary';
                }
                $status_class = 'status-col status-'.get_text($ui);

                $dl_url  = './rec_proxy_manual.php?mrid='.(int)$row['manual_recording_id'];
                $dl_file = './rec_proxy_manual.php?mrid='.(int)$row['manual_recording_id'].'&dl=1';
                $mime     = guess_audio_mime($row['s3_key'], $row['content_type']);

                $from_hp_fmt = format_korean_phone((string)$row['agent_phone']);
                $hp_fmt = format_korean_phone((string)$row['call_hp']);
                ?>
                <tr>
                    <td><?php echo get_text($gname); ?></td>
                    <td><?php echo get_text($agent); ?></td>
                    <td class="<?php echo $status_class; ?>"><?php echo get_text($status); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_start']), 'mdhi'); ?></td>
                    <td><?php echo fmt_datetime(get_text($row['call_end']), 'mdhi'); ?></td>
                    <td><?php echo $call_sec; ?></td>
                    <td><?php echo $talk_sec; ?></td>
                    <td><?php echo get_text($from_hp_fmt); ?></td>
                    <td><?php echo get_text($hp_fmt); ?></td>
                    <td><?php echo fmt_bytes($row['file_size']); ?></td>
                    <td class="audio-ctl">
                        <audio controls preload="none" class="audio">
                            <source src="<?php echo $dl_url; ?>" type="<?php echo get_text($mime); ?>">
                            브라우저가 audio 태그를 지원하지 않습니다.
                        </audio>
                    </td>
                    <td><a href="<?php echo $dl_file; ?>" class="btn btn_02">다운</a></td>
                    <td><?php echo fmt_datetime(get_text($row['rec_created_at']), 'mdhis'); ?></td>
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
$base = './call_manual_recordings.php?'.http_build_query($qstr);
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

    var mbGroup = document.getElementById('mb_group');
    if (mbGroup) {
        mbGroup.addEventListener('change', function(){
            var agent = document.getElementById('agent');
            if (agent) agent.selectedIndex = 0;
            $form.submit();
        });
    }

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
