<?php
// /adm/call/index.php
$sub_menu = '700100';
require_once './_common.php';

// 접근 권한
if ((int)$member['mb_level'] < 3) {
    alert('접근 권한이 없습니다.');
}

if (!isset($_SESSION['chk_token'])) {
    $_SESSION['chk_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['chk_token'];

// ----------------------------------------------------------------------------------
// 멤버/권한
// ----------------------------------------------------------------------------------
$mb_no        = (int)($member['mb_no'] ?? 0);
$mb_level     = (int)($member['mb_level'] ?? 0);
$my_group     = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id= isset($member['company_id']) ? (int)$member['company_id'] : 0;

// ----------------------------------------------------------------------------------
// 검색/필터 파라미터 (+ 조직 필터 추가)
// ----------------------------------------------------------------------------------
$q       = _g('q', '');
$q_type  = _g('q_type', ''); // name | last4 | full
$f_dnc   = _g('dnc', '');    // '', '0', '1'
$f_asgn  = _g('as', '');     // 배정상태 필터: '', '0','1','2','3'
$page    = max(1, (int)_g('page','1'));
$rows    = max(10, min(200, (int)_g('rows','50')));
$offset  = ($page-1) * $rows;

// 조직 필터 (회사/그룹)
if ($mb_level >= 9) {
    $sel_company_id = (int)_g('company_id', 0); // 0=전체
    $sel_mb_group   = (int)_g('mb_group', 0);   // 0=전체
} elseif ($mb_level >= 8) {
    $sel_company_id = $my_company_id;           // 고정
    $sel_mb_group   = (int)_g('mb_group', 0);   // 0=회사 내 전체
} else {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

// 배정상태 라벨
$ASSIGN_LABEL = [ 0=>'미배정', 1=>'배정', 3=>'완료', 4=>'거절', 9=>'블랙' ]; // 2=>'진행중', 

// ----------------------------------------------------------------------------------
// WHERE 구성 (+ 삭제 캠페인 제외 조건)
// ----------------------------------------------------------------------------------
$where = [];
// 권한별 기본 범위
if ($mb_level >= 8) {
    // 상단 조직 필터에서 제한
} elseif ($mb_level == 7) {
    $where[] = "t.mb_group = {$my_group}";
} else {
    $where[] = "t.assigned_mb_no = {$mb_no}";
}

// 조직 필터 적용
if ($mb_level >= 8) {
    if ($sel_mb_group > 0) {
        $where[] = "t.mb_group = {$sel_mb_group}";
    } else {
        if ($mb_level >= 9 && $sel_company_id > 0) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? "t.mb_group IN (".implode(',', $grp_ids).")" : "1=0";
        }
        // ★ 여기 추가: 레벨 8이 전체 그룹일 때 자기 회사 범위 강제
        elseif ($mb_level == 8) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? "t.mb_group IN (".implode(',', $grp_ids).")" : "1=0";
        }
    }
}

// 검색
if ($q !== '' && $q_type !== '') {
    if ($q_type === 'name') {
        $q_esc = sql_escape_string($q);
        $where[] = "t.name LIKE '%{$q_esc}%'";
    } elseif ($q_type === 'last4') {
        $last4 = substr(preg_replace('/\D+/', '', $q), -4);
        if ($last4 !== '') {
            $l4 = sql_escape_string($last4);
            $where[] = "t.hp_last4 = '{$l4}'";
        }
    } elseif ($q_type === 'full') {
        $full = preg_replace('/\D+/', '', $q);
        if ($full !== '') {
            $full_esc = sql_escape_string($full);
            $where[] = "t.call_hp = '{$full_esc}'";
        }
    }
}
// DNC 필터
if ($f_dnc === '0' || $f_dnc === '1') {
    $where[] = "t.do_not_call = ".(int)$f_dnc;
}
// 배정상태 필터
if ($f_asgn !== '' && in_array($f_asgn, ['0','1','2','3', '4'], true)) {
    $where[] = "t.assigned_status = ".(int)$f_asgn;
}

// ★ 삭제 캠페인 제외
$where[] = "c.status <> 9";

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// ----------------------------------------------------------------------------------
// 회사/그룹명 매핑에 필요한 group_ids 수집을 위해 먼저 count 후 목록 조회
// ----------------------------------------------------------------------------------
$sql_cnt = "
    SELECT COUNT(*) AS cnt
    FROM call_target t
    JOIN call_campaign c ON c.campaign_id = t.campaign_id
    {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// ----------------------------------------------------------------------------------
// 목록 (캠페인 조인 + is_open_number 포함)
// ----------------------------------------------------------------------------------
$sql_list = "
    SELECT
        t.target_id, t.campaign_id, t.mb_group, t.call_hp, t.hp_last4,
        t.name, t.birth_date, t.meta_json, t.sex,
        t.assigned_status, t.assigned_mb_no, t.assigned_at, t.assign_lease_until, t.assign_batch_id,
        t.do_not_call, t.last_call_at, t.last_result, t.attempt_count, t.next_try_at,
        t.created_at, t.updated_at,
        c.status AS campaign_status,
        c.name AS campaign_name,
        c.is_open_number
    FROM call_target t
    JOIN call_campaign c ON c.campaign_id = t.campaign_id
    {$where_sql}
    ORDER BY t.target_id DESC
    LIMIT {$offset}, {$rows}
";
$res = sql_query($sql_list);

$__q = $_GET;
$__q['mode'] = 'screen';    $href_xls_screen    = './index_excel.php?'.http_build_query($__q);
$__q['mode'] = 'condition'; $href_xls_condition = './index_excel.php?'.http_build_query($__q);
$__q['mode'] = 'all';       $href_xls_all       = './index_excel.php?'.http_build_query($__q);

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
/**
 * ========================
 * // 회사/그룹/담당자 드롭다운 옵션
 * ========================
 */


// ----------------------------------------------------------------------------------
// 화면
// ----------------------------------------------------------------------------------
$g5['title'] = 'DB리스트';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.tbl_head01 th, .tbl_head01 td { text-align:center; vertical-align:middle; }
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; }
.badge-dnc { background:#dc3545; color:#fff; }
.badge-ok  { background:#28a745; color:#fff; }
.badge-warn{ background:#ffc107; color:#222; }
.badge-camp-inactive { background:#eaeaea; color:#666; border:1px solid #d0d0d0; }
.small-muted { color:#888; font-size:12px; }
pre.json { text-align:left; white-space:pre-wrap; background:#f8f9fa; padding:8px; border:1px solid #eee; border-radius:4px; }
td.meta { text-align:left !important; }
td.camp-cell, td.org-cell { text-align:left !important; font-size:11px; letter-spacing: -1px; }
tr.camp-inactive td { background-image: linear-gradient(to right, rgba(0,0,0,0.025), rgba(0,0,0,0.025)); }
.meta-sex { font-weight:bold; margin-right:8px; }
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./index.php" class="form-row" autocomplete="off" id="searchForm">
        <?php if ($mb_level >= 9) { ?>
            <select name="company_id" id="company_id">
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
            <select name="mb_group" id="mb_group">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 그룹</option>
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

        <select name="q_type" id="q_type">
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo _h($q);?>" class="frm_input" style="width:220px" placeholder="검색어 입력">

        <!-- <label for="dnc">블랙고객</label>
        <select name="dnc" id="dnc">
            <option value=""  <?php echo $f_dnc===''?'selected':'';?>>전체</option>
            <option value="0" <?php echo $f_dnc==='0'?'selected':'';?>>N</option>
            <option value="1" <?php echo $f_dnc==='1'?'selected':'';?>>Y</option>
        </select> -->

        <label for="as">배정상태</label>
        <select name="as" id="as">
            <option value=""  <?php echo $f_asgn===''?'selected':'';?>>전체</option>
            <?php foreach ($ASSIGN_LABEL as $k=>$v){ ?>
                <option value="<?php echo $k;?>" <?php echo $f_asgn!=='' && (int)$f_asgn===$k?'selected':'';?>><?php echo _h($v);?></option>
            <?php } ?>
        </select>

        <label for="rows">표시건수</label>
        <select name="rows" id="rows">
            <?php foreach ([20,50,100,200] as $opt){ ?>
                <option value="<?php echo $opt;?>" <?php echo $rows==$opt?'selected':'';?>><?php echo $opt;?></option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql){ ?><a href="./index.php" class="btn btn_02">초기화</a><?php } ?>

        <span class="small-muted" style="margin-left:auto">
        권한:
        <?php
            if ($mb_level >= 9) echo '전사 조회(최고관리자)';
            elseif ($mb_level >= 8) echo '회사 조회';
            elseif ($mb_level == 7) echo '그룹 제한';
            else echo '개인 제한';
        ?>
        </span>
    </form>
</div>

<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <th style="width:90px">회사</th>
                <th style="width:90px">그룹</th>
                <th style="width:220px">캠페인</th>
                <th style="width:140px">전화번호</th>
                <th style="width:140px">이름/나이</th>
                <th>추가정보</th>
                <th style="width:110px">배정상태</th>
                <th style="width:120px">담당자</th>
                <th style="width:80px">DNC</th>
                <th style="width:130px">통화결과</th>
                <th style="width:150px">업데이트</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0){
            echo '<tr><td colspan="11" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            // 결과셋을 다시 처음부터 읽기
            sql_data_seek($res, 0);
            while ($row = sql_fetch_array($res)) {
                $gid      = (int)$row['mb_group'];
                $ginfo    = $group_map[$gid] ?? ['group_name'=>'-', 'company_id'=>0];
                $cname    = get_company_name_from_group_id_cached($gid);
                $gname    = get_group_name_cached($gid);

                // 전화번호: is_open_number=0이면 숨김
                $hp_fmt = ((int)$row['is_open_number'] === 0 && $mb_level < 9) ? '(숨김처리)' : _h(format_korean_phone($row['call_hp']));

                $age     = calc_age_years($row['birth_date']);
                $age_txt = is_null($age) ? '-' : ($age.'세(만)');
                $as      = (int)$row['assigned_status'];
                $as_label= isset($ASSIGN_LABEL[$as]) ? $ASSIGN_LABEL[$as] : (string)$as;
                $dnc     = (int)$row['do_not_call']===1;
                $last_result = (int)$row['last_result'];
                $last_label  = $last_result ? status_label($last_result) : '';

                // 추가 정보 표시
                $sex_txt = '';
                if ((int)$row['sex'] === 1) $sex_txt = '남성';
                elseif ((int)$row['sex'] === 2) $sex_txt = '여성';                
                $meta_json = [];
                if($row['meta_json']) {
                    $meta_json = json_decode($row['meta_json'], true);
                    foreach($meta_json as $k => $v) {
                        if(!$v) unset($meta_json[$k]);
                    }
                }
                $meta_txt  = '';
                if ($sex_txt !== '') $meta_txt .= $sex_txt;
                if ($meta_json) {
                    if ($meta_txt !== '') $meta_txt .= ', ';
                    $meta_txt .= implode(', ', $meta_json);
                }

                $agent_txt   = $row['assigned_mb_no'] ? get_agent_name_cached((int)$row['assigned_mb_no']) : '-';
                $camp_inactive = ((int)$row['campaign_status'] === 0);
                $tr_class = $camp_inactive ? 'camp-inactive' : '';
                ?>
                <tr class="<?php echo $tr_class; ?>">
                    <td class="org-cell"><?php echo _h($cname); ?></td>
                    <td><?php echo _h($gname); ?></td>

                    <td class="camp-cell">
                        <?php echo _h($row['campaign_name']); ?>
                        <?php if ($camp_inactive) { ?>
                            <span class="badge badge-camp-inactive">비활성 캠페인</span>
                        <?php } ?>
                    </td>

                    <td><?php echo $hp_fmt; ?></td>

                    <td><?php echo _h($row['name']);?> / <?php echo _h($age_txt);?></td>
                    <td class="meta"><?php echo $meta_txt; ?></td>
                    
                    <td><?php echo _h($as_label);?></td>
                    <td><?php echo _h($agent_txt);?></td>
                    <td>
                        <?php if ($dnc){ ?>
                            <span class="badge badge-dnc">Y</span>
                        <?php } else { ?>
                            <span class="badge badge-ok">N</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($last_result){ echo (int)$last_result.' - '._h($last_label); } ?>
                        <?php if ($row['last_call_at']){ echo '<div class="small-muted">'._h($row['last_call_at']).'</div>'; } ?>
                    </td>
                    <td><?php echo substr(_h($row['updated_at']), 2, 17);?></td>
                </tr>
                <?php
            }
        }
        ?>
        </tbody>
    </table>
</div>

<div class="btn_fixed_top">
    <!-- <a href="<?php echo $href_xls_all;       ?>" class="btn btn_02" target="_blank">전체 엑셀다운</a>&nbsp;&nbsp;&nbsp; -->
    <a href="<?php echo $href_xls_condition; ?>" class="btn btn_02" target="_blank">현재조건 엑셀다운</a>&nbsp;&nbsp;&nbsp;
    <a href="<?php echo $href_xls_screen;    ?>" class="btn btn_02" target="_blank" style="background:#e5e7eb !important">현재화면 엑셀다운</a>
</div>

<?php
// 페이징 (그누보드 get_paging 사용)
$total_page = max(1, (int)ceil($total_count / $rows));

// 기존 쿼리에서 page만 제거해 보존
$qstr_arr = $_GET;
unset($qstr_arr['page']);
$qstr = http_build_query($qstr_arr);

// get_paging 출력 (모바일/웹 설정값 반영)
echo '<div class="pg_wrap">';
echo get_paging(
    $config['cf_write_pages'],
    $page,
    $total_page,
    "./index.php?{$qstr}&amp;page="
);
echo '</div>';
?>

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
})();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
