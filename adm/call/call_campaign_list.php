<?php
// /adm/call_campaign_list.php
$sub_menu = '700700';
include_once('./_common.php');

if ($member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// --------------------------------------------------------
// 권한/필터
// --------------------------------------------------------
$my_level      = (int)$member['mb_level'];
$my_group      = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id = isset($member['company_id']) ? (int)$member['company_id'] : 0;

// 검색 파라미터
$sfl  = isset($_GET['sfl']) ? preg_replace('/[^a-z0-9_]/i','', $_GET['sfl']) : 'name';
$stx  = isset($_GET['stx']) ? trim($_GET['stx']) : '';
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
if (!in_array($status_filter, ['all','active','inactive'], true)) $status_filter = 'all';

// 조직 선택값: 9+는 회사/그룹 선택 가능, 8은 회사 고정+그룹 선택, 7은 그룹 고정
if ($my_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0);  // 0=전체
    $sel_mb_group   = (int)($_GET['mb_group'] ?? 0);    // 0=전체
} elseif ($my_level >= 8) {
    $sel_company_id = $my_company_id;                   // 고정
    $sel_mb_group   = (int)($_GET['mb_group'] ?? 0);    // 0=회사 내 전체
} else {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

// --------------------------------------------------------
// 회사 옵션(레벨9+만)
// --------------------------------------------------------
$company_options = [];
if ($my_level >= 9) {
    $res = sql_query("
        SELECT m.mb_no AS company_id
        FROM {$g5['member_table']} m
        WHERE m.mb_level = 8
        ORDER BY COALESCE(NULLIF(m.company_name,''), CONCAT('회사-', m.mb_no)) ASC, m.mb_no ASC
    ");
    while ($r = sql_fetch_array($res)) {
        $cid   = (int)$r['company_id'];
        $cname = get_company_name_cached($cid);
        $gcnt  = count_groups_by_company_cached($cid);
        $company_options[] = [
            'company_id'   => $cid,
            'company_name' => $cname,
            'group_count'  => $gcnt,
        ];
    }
}

// --------------------------------------------------------
// 검색키 목록 (선택한 mb_group 기준으로 코드를 가져온다는 가정)
// --------------------------------------------------------
$code_list = get_code_list($sel_mb_group);

// --------------------------------------------------------
// 캠페인 목록 필터링 조건
//  - status
//  - 권한별 조직 범위
//  - 검색어
//  - 회사/그룹 필터(회사만 선택된 경우: 그 회사에 속한 모든 그룹)
// --------------------------------------------------------
if     ($status_filter === 'active')   $status_cond = " c.status=1 ";
elseif ($status_filter === 'inactive') $status_cond = " c.status=0 ";
else                                   $status_cond = " c.status IN (0,1) ";

$sql_search = " WHERE {$status_cond} ";

// 회사/그룹 필터 SQL (c.mb_group 기준)
$group_filter_sql = '';
if ($my_level < 8) {
    $group_filter_sql = " AND c.mb_group='".(int)$my_group."' ";
} else {
    if ($sel_mb_group > 0) {
        $group_filter_sql = " AND c.mb_group='".(int)$sel_mb_group."' ";
    } else {
        if ($my_level >= 9 && $sel_company_id > 0) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $group_filter_sql = $grp_ids ? " AND c.mb_group IN (".implode(',', $grp_ids).") " : " AND 1=0 ";
        }
        // ★ 레벨 8: 그룹=0이면 '내 회사 전체 그룹'으로 강제 제한
        elseif ($my_level == 8) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $group_filter_sql = $grp_ids ? " AND c.mb_group IN (".implode(',', $grp_ids).") " : " AND 1=0 ";
        }
    }
}
$sql_search .= $group_filter_sql;

// 검색어
if ($stx !== '') {
    $safe_stx = sql_escape_string($stx);
    switch ($sfl) {
        case 'campaign_id':
            $sql_search .= " AND c.campaign_id='".(int)$safe_stx."' ";
            break;
        case 'campaign_memo':
            $sql_search .= " AND c.campaign_memo LIKE '%{$safe_stx}%' ";
            break;
        default: // name
            $sql_search .= " AND c.`name` LIKE '%{$safe_stx}%' ";
            break;
    }
}

// 정렬
$sst = $_GET['sst'] ?? 'campaign_id';
$sod = $_GET['sod'] ?? 'desc';
$allowed_sort = ['campaign_id','name','created_at'];
if (!in_array($sst, $allowed_sort, true)) $sst = 'campaign_id';
$sod = strtolower($sod)==='asc' ? 'asc' : 'desc';
$sql_order = " ORDER BY c.{$sst} {$sod} ";

// 카운트
$row = sql_fetch("SELECT COUNT(*) AS cnt FROM call_campaign c {$sql_search}");
$total_count = (int)$row['cnt'];
$rows = $config['cf_page_rows'];
$total_page  = $rows ? (int)ceil($total_count / $rows) : 1;
$from_record = ($page - 1) * $rows;

// 목록 조회 (is_open_number 포함)
$result = sql_query("
  SELECT c.campaign_id, c.mb_group, c.name, c.campaign_memo, c.is_open_number, c.status, c.created_at
  FROM call_campaign c
  {$sql_search}
  {$sql_order}
  LIMIT {$from_record}, {$rows}
");

// --------------------------------------------------------
// 회사/그룹 이름 매핑 (현재 페이지에 보이는 캠페인만)
//  - 그룹 ID -> (그룹명, 회사ID)
//  - 회사ID   -> 회사명
// --------------------------------------------------------
$campaign_rows = [];
$campaign_ids  = [];
$group_ids     = [];
while ($r = sql_fetch_array($result)) {
    $campaign_rows[] = $r;
    $campaign_ids[]  = (int)$r['campaign_id'];
    $group_ids[]     = (int)$r['mb_group'];
}
$group_ids = array_values(array_unique($group_ids));

$group_map = []; // gid => ['group_name'=>..., 'company_id'=>...]
if ($group_ids) {
    $gid_csv = implode(',', array_map('intval',$group_ids));
    $gr = sql_query("SELECT m.mb_no AS gid, m.mb_group_name, m.company_id FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.mb_no IN ({$gid_csv})");
    while ($rr = sql_fetch_array($gr)) {
        $gid = (int)$rr['gid'];
        $group_map[$gid] = [
            'group_name' => get_group_name_cached($gid) ?: k_nfc((string)$rr['mb_group_name']),
            'company_id' => (int)$rr['company_id']
        ];
    }
}
$company_name_cache = [];
function _company_name($cid){
    global $company_name_cache;
    if (!isset($company_name_cache[$cid])) {
        $company_name_cache[$cid] = get_company_name_cached($cid);
    }
    return $company_name_cache[$cid];
}

// --------------------------------------------------------
// 통계 집계(call_target.last_result)
// --------------------------------------------------------
$stats_by_campaign = [];
if ($campaign_ids) {
    $ids_csv = implode(',', array_map('intval',$campaign_ids));
    // 그룹 필터링: 상단에서 이미 c.mb_group로 필터했으므로 여기선 선택 그룹이 있다면 동일하게 제한
    $mb_cond = '';
    if ($sel_mb_group > 0) {
        $mb_cond = " AND t.mb_group='".(int)$sel_mb_group."' ";
    } else {
        if ($my_level >= 9 && $sel_company_id > 0) {
            $mb_cond = " AND t.mb_group IN (SELECT mb_no FROM {$g5['member_table']} WHERE mb_level=7 AND company_id='".(int)$sel_company_id."') ";
        }
        // ★ 레벨 8: 그룹=0이면 자기 회사 전체 그룹으로 제한
        elseif ($my_level == 8) {
            $mb_cond = " AND t.mb_group IN (SELECT mb_no FROM {$g5['member_table']} WHERE mb_level=7 AND company_id='".(int)$my_company_id."') ";
        }
    }

    // last_result 분포
    $res = sql_query("
      SELECT t.campaign_id, t.last_result, COUNT(*) AS cnt
      FROM call_target t
      WHERE t.campaign_id IN ({$ids_csv}) {$mb_cond}
      GROUP BY t.campaign_id, t.last_result
    ");
    while ($r = sql_fetch_array($res)) {
        $cid = (int)$r['campaign_id'];
        if (!isset($stats_by_campaign[$cid])) $stats_by_campaign[$cid] = ['preassign'=>0];
        if (is_null($r['last_result'])) {
            $stats_by_campaign[$cid]['preassign'] += (int)$r['cnt'];
        } else {
            $cs = (int)$r['last_result'];
            $stats_by_campaign[$cid][$cs] = ((int)($stats_by_campaign[$cid][$cs] ?? 0)) + (int)$r['cnt'];
        }
    }

    // 총합
    $res = sql_query("
      SELECT t.campaign_id, COUNT(*) AS cnt
      FROM call_target t
      WHERE t.campaign_id IN ({$ids_csv}) {$mb_cond}
      GROUP BY t.campaign_id
    ");
    while ($r = sql_fetch_array($res)) {
        $cid = (int)$r['campaign_id'];
        if (!isset($stats_by_campaign[$cid])) $stats_by_campaign[$cid] = ['preassign'=>0];
        $stats_by_campaign[$cid]['__total__'] = (int)$r['cnt'];
    }
}

// --------------------------------------------------------
$g5['title'] = 'DB파일관리';
include_once (G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';

// 테이블 컬럼 구성 업데이트
// [체크, 파일명, 회사/그룹, 메모, 잔여율, 총합, 잔여] = 7개 (기존 6개에서 회사/그룹 1개 추가)
$base_cols = 7;
$dyn_cols  = 1 + count($code_list) + 1;  // 배정전 + 상태코드 수 + 행별 액션
$colspan   = $base_cols + $dyn_cols;

// CSRF 토큰
$admin_token = get_admin_token();

// 현재 쿼리스트링(페이지 유지)
$qstr = http_build_query([
    'company_id'=>$sel_company_id,
    'mb_group'=>$sel_mb_group,
    'sfl'=>$sfl,
    'stx'=>$stx,
    'sst'=>$sst,
    'sod'=>$sod,
    'status'=>$status_filter,
    'page'=>$page
]);
?>
<style>
.td_cntsmall {width:60px}
.td_cnt {font-weight:bold}
:root {
    --remain-low:   #f8bfbf;
    --remain-mid:   #f9d9a8;
    --remain-high:  #bfe3b4;
    --remain-full:  #b4d8f0;
}
.badge {
    display:inline-block; padding:2px 6px; font-size:11px; border-radius:10px; line-height:1.4;
    vertical-align:middle; margin-left:6px; border:1px solid transparent;
}
.badge-active   { background:#e7f7ed; color:#137a2a; border-color:#bfe8cb;}
.badge-inactive { background:#f5f5f5; color:#666;   border-color:#ddd;}
.badge-private  { background:#ffe9e9; color:#c0392b; border-color:#f5c2c2;} /* is_open_number=0 */
tr.row-inactive td { color:#888; background-image: linear-gradient(to right, rgba(0,0,0,0.03), rgba(0,0,0,0.03)); }
tr.row-inactive td .name-text { text-decoration: line-through; }
.btn-xs { padding:4px 7px; font-size:12px; border-radius:4px; }
.btn-inline { margin:0 2px; }
.td_actions { white-space:nowrap; }
.status-toggle { margin-left:8px; }
.status-toggle label { margin-right:10px; }
.opt-sep{font-weight:bold;color:#666}
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<form name="fsearch" id="fsearch" class="local_sch01 local_sch" method="get" autocomplete="off">
    <?php if ($my_level >= 9) { ?>
        <!-- 회사 선택(9+): AJAX로 그룹 동기화 -->
        <select name="company_id" id="company_id" title="회사선택">
            <option value="0"<?php echo get_selected($sel_company_id, 0); ?>>-- 전체 회사 --</option>
            <?php foreach ($company_options as $c) { ?>
                <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                    <?php echo get_text($c['company_name']); ?> (그룹 <?php echo (int)$c['group_count']; ?>)
                </option>
            <?php } ?>
        </select>
    <?php } else { ?>
        <input type="hidden" name="company_id" id="company_id" value="<?php echo (int)$sel_company_id; ?>">
    <?php } ?>

    <?php if ($my_level >= 8) { ?>
        <!-- 그룹 선택(8+): 회사 변경 시 비동기 갱신 -->
        <select name="mb_group" id="mb_group" title="그룹선택">
            <option value="0"<?php echo get_selected($sel_mb_group, 0); ?>>-- 전체 그룹 --</option>
            <?php
            // 초기 렌더(첫 진입/새로고침)용, 선택 회사/권한에 맞춰 서버에서 구성
            $where = " WHERE m.mb_level=7 ";
            if ($my_level >= 9) {
                if ($sel_company_id > 0) $where .= " AND m.company_id='{$sel_company_id}' ";
            } else {
                $where .= " AND m.company_id='{$sel_company_id}' ";
            }
            $gr = sql_query("
                SELECT m.mb_no AS mb_group, m.company_id
                FROM {$g5['member_table']} m
                {$where}
                ORDER BY m.company_id ASC,
                         COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹-', m.mb_no)) ASC,
                         m.mb_no ASC
            ");
            $last_c = null;
            if ($my_level >= 9 && $sel_company_id == 0) {
                // 회사별 구분선
                while ($g = sql_fetch_array($gr)) {
                    $gid = (int)$g['mb_group'];
                    $cid = (int)$g['company_id'];
                    if ($last_c !== $cid) {
                        if ($last_c !== null) echo ''; // 구분만
                        echo '<option value="" disabled class="opt-sep">── '.get_text(_company_name($cid)).' ──</option>';
                        $last_c = $cid;
                    }
                    echo '<option value="'.$gid.'" '.get_selected($sel_mb_group, $gid).'>'.get_text(get_group_name_cached($gid)).'</option>';
                }
            } else {
                while ($g = sql_fetch_array($gr)) {
                    $gid = (int)$g['mb_group'];
                    echo '<option value="'.$gid.'" '.get_selected($sel_mb_group, $gid).'>'.get_text(get_group_name_cached($gid)).'</option>';
                }
            }
            ?>
        </select>
    <?php } else { ?>
        <input type="hidden" name="mb_group" id="mb_group" value="<?php echo (int)$sel_mb_group; ?>">
    <?php } ?>

    <select name="sfl" title="검색대상">
        <option value="name"<?php echo get_selected($sfl, "name"); ?>>파일명</option>
        <option value="campaign_memo"<?php echo get_selected($sfl, "campaign_memo"); ?>>메모</option>
        <option value="campaign_id"<?php echo get_selected($sfl, "campaign_id"); ?>>캠페인ID</option>
    </select>
    <label for="stx" class="sound_only">검색어<strong class="sound_only"> 필수</strong></label>
    <input type="text" name="stx" value="<?php echo get_text($stx); ?>" id="stx" class="frm_input">

    <!-- 상태 토글 -->
    <span class="status-toggle">
        <label><input type="radio" name="status" value="all" <?php echo $status_filter==='all' ? 'checked' : ''; ?>> 전체</label>
        <label><input type="radio" name="status" value="active" <?php echo $status_filter==='active' ? 'checked' : ''; ?>> 활성</label>
        <label><input type="radio" name="status" value="inactive" <?php echo $status_filter==='inactive' ? 'checked' : ''; ?>> 비활성</label>
    </span>

    <button type="submit" class="btn btn_01">검색</button>
</form>

<!-- 선택 액션 폼 (테이블+상단 버튼 래핑) -->
<form id="fcampaignlist" name="fcampaignlist" method="post" action="./call_campaign_list_update.php" onsubmit="return false;">
<input type="hidden" name="token" value="<?php echo $admin_token; ?>">
<input type="hidden" name="qstr" value="<?php echo get_text($qstr); ?>">

<div class="tbl_head01 tbl_wrap">
    <table>
    <caption><?php echo $g5['title']; ?></caption>
    <thead>
    <tr>
        <th scope="col">
            <label for="chkall" class="sound_only">전체</label>
            <input type="checkbox" id="chkall" onclick="check_all(this.form)">
        </th>
        <th scope="col"><?php echo subject_sort_link('name', "company_id={$sel_company_id}&mb_group={$sel_mb_group}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>파일명</a></th>
        <th scope="col">회사 / 그룹</th>
        <th scope="col">메모</th>
        <th scope="col">잔여율</th>
        <th scope="col">총합</th>
        <th scope="col" style="background-color:#137a2a !important">잔여</th>
        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
        <th scope="col"><?php echo subject_sort_link('created_at', "company_id={$sel_company_id}&mb_group={$sel_mb_group}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>등록일</a></th>
        <th scope="col">관리</th>
    </tr>
    </thead>
    <tbody>
    <?php
    if (!$campaign_rows) {
        echo '<tr><td colspan="'.$colspan.'" class="empty_table">자료가 없습니다.</td></tr>';
    } else {
        for ($i=0; $i<count($campaign_rows); $i++) {
            $r = $campaign_rows[$i];
            $cid = (int)$r['campaign_id'];
            $bg = 'bg'.($i%2);
            $inactive = ((int)$r['status'] === 0);

            // 통계
            $stat = $stats_by_campaign[$cid] ?? [];
            $total = (int)($stat['__total__'] ?? 0);
            $preassign = (int)($stat['preassign'] ?? 0);
            $rate = ($total) ? round($preassign/$total*100,2) : 0;
            if ($rate <= 25)      $color = 'var(--remain-low)';
            elseif ($rate <= 50)  $color = 'var(--remain-mid)';
            elseif ($rate <= 75)  $color = 'var(--remain-high)';
            else                  $color = 'var(--remain-full)';
            $bg_rate = "linear-gradient(to right, {$color} {$rate}%, #ffffff {$rate}%)";

            // 회사/그룹명
            $gid = (int)$r['mb_group'];
            $ginfo = $group_map[$gid] ?? ['group_name'=>'-', 'company_id'=>0];
            $cname = ($ginfo['company_id']>0) ? _company_name((int)$ginfo['company_id']) : '-';
            $gname = $ginfo['group_name'] ?: '-';
            ?>
            <tr class="<?php echo $bg; ?> <?php echo $inactive ? 'row-inactive' : ''; ?>">
                <td class="td_chk">
                    <input type="checkbox" name="chk[]" value="<?php echo $cid; ?>" title="선택">
                </td>
                <td class="td_left">
                    <span class="name-text"><?php echo get_text($r['name']); ?></span>
                    <?php if ($inactive) { ?>
                        <span class="badge badge-inactive">비활성</span>
                    <?php } else { ?>
                        <span class="badge badge-active">활성</span>
                    <?php } ?>
                    <?php
                    // is_open_number 배지 노출
                    if ((int)$r['is_open_number'] === 0) {
                        echo '<span class="badge badge-private">1차 비공개</span>';
                    }
                    ?>
                </td>
                <td class="td_left">
                    <div><b><?php echo get_text($cname); ?></b></div>
                    <div><?php echo get_text($gname); ?></div>
                </td>
                <td class="td_left"><?php echo get_text($r['campaign_memo']); ?></td>
                <td class="td_cnt" style='background:<?php echo $bg_rate ?>;'><?php echo $rate ?>%</td>
                <td class="td_cntsmall"><?php echo number_format($total); ?></td>
                <td class="td_cntsmall"><?php echo number_format($preassign); ?></td>
                <?php
                foreach ($code_list as $c) {
                    $cs = (int)$c['call_status'];
                    $val = (int)($stat[$cs] ?? 0);
                    echo '<td class="td_cntsmall">'.number_format($val).'</td>';
                }
                ?>
                <td class="td_datetime"><?php echo substr($r['created_at'], 2, 14); ?></td>
                <td class="td_actions">
                    <?php if ($inactive) { ?>
                        <button type="button" class="btn btn_01 btn-xs btn-inline" onclick="rowAction('activate', <?php echo $cid; ?>);">활성화</button>
                    <?php } else { ?>
                        <button type="button" class="btn btn_02 btn-xs btn-inline" onclick="rowAction('deactivate', <?php echo $cid; ?>);">비활성</button>
                    <?php } ?>
                    <button type="button" class="btn btn_03 btn-xs btn-inline" onclick="rowAction('delete', <?php echo $cid; ?>);">삭제</button>
                </td>
            </tr>
            <?php
        }
    }
    ?>
    </tbody>
    </table>
</div>

<div class="btn_fixed_top">
    <a href="javascript:void(0)" class="btn btn_01" onclick="open_upload_popup();">엑셀파일 등록</a>
    <!-- 선택 액션 버튼들 -->
    <button type="button" class="btn btn_02" onclick="submitSelected('선택활성화')">선택활성화</button>
    <button type="button" class="btn btn_02" onclick="submitSelected('선택비활성화')">선택비활성화</button>
    <button type="button" class="btn btn_03" onclick="submitSelectedDelete()">선택삭제</button>
</div>
</form>

<!-- 행 단일 액션 전송용 숨은 폼 -->
<form id="frow_action" method="post" action="./call_campaign_list_update.php" style="display:none;">
    <input type="hidden" name="token" value="<?php echo $admin_token; ?>">
    <input type="hidden" name="qstr"  value="<?php echo get_text($qstr); ?>">
    <input type="hidden" name="action" id="row_action_value" value="">
</form>

<?php
// 페이징
$qstr_for_paging = http_build_query([
    'company_id'=>$sel_company_id,
    'mb_group'=>$sel_mb_group,
    'sfl'=>$sfl,
    'stx'=>$stx,
    'sst'=>$sst,
    'sod'=>$sod,
    'status'=>$status_filter
]);
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?$qstr_for_paging&amp;page=");
?>

<script>
// 체크박스 전체선택
function check_all(f){
    var chk = document.getElementsByName('chk[]');
    for (var i=0;i<chk.length;i++) chk[i].checked = f.checked;
}
// 단일 행 액션
function rowAction(act, cid){
    var msg = (act==='activate')   ? '이 캠페인을 활성화하시겠습니까?' :
              (act==='deactivate') ? '이 캠페인을 비활성화하시겠습니까?' :
              '이 캠페인을 삭제 정말 하시겠습니까?';
    if(!confirm(msg)) return;

    var f = document.getElementById('frow_action');
    document.getElementById('row_action_value').value = act + ':' + cid;
    f.submit();
}
// 선택 액션
function hasChecked(){
    var boxes = document.getElementsByName('chk[]');
    for (var i=0;i<boxes.length;i++) if (boxes[i].checked) return true;
    return false;
}
function submitSelected(act){
    var f = document.getElementById('fcampaignlist');
    if (!hasChecked()) { alert('처리할 캠페인을 선택하세요.'); return; }
    if (!confirm('선택한 항목을 "'+act+'" 하시겠습니까?')) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'act_button';
    input.value = act;
    f.appendChild(input);
    f.submit();
}
function submitSelectedDelete(){
    var f = document.getElementById('fcampaignlist');
    if (!hasChecked()) { alert('삭제할 캠페인을 선택하세요.'); return; }
    if (!confirm('선택한 항목을 삭제 합니다. 정말 진행하시겠습니까?')) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'act_button';
    input.value = '선택삭제';
    f.appendChild(input);
    f.submit();
}
// 업로드 팝업
function open_upload_popup() {
    var url = './call_target_excel.php';
    var w = 860, h = 740;
    var left = (screen.width - w) / 2;
    var top  = (screen.height - h) / 2;
    window.open(url, 'call_target_excel', 'width='+w+',height='+h+',left='+left+',top='+top+',scrollbars=1,resizable=1');
}

// ===============================
// 비동기 조직(회사→그룹) 셀렉트
// ===============================
(function(){
    var companySel = document.getElementById('company_id');
    var groupSel   = document.getElementById('mb_group');
    if (!groupSel) return;

    // 9+에서만 회사 변경 이벤트 연결
    if (companySel && <?php echo ($my_level>=9?'true':'false'); ?>) {
        companySel.addEventListener('change', function(){
            var cid = parseInt(this.value || '0', 10) || 0;
            // 로딩표시
            groupSel.innerHTML = '<option value="">로딩 중...</option>';

            fetch('./ajax_group_options.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // 목록 페이지는 별도의 CSRF 토큰을 쓰지 않아도 되지만,
                    // ajax_group_options.php가 CSRF 검증을 사용한다면 아래처럼 세션 토큰을 헤더로 주도록 수정 필요.
                    // 'X-CSRF-TOKEN': '<?php echo $_SESSION['call_upload_token'] ?? ''; ?>'
                },
                body: JSON.stringify({ company_id: cid }),
                credentials: 'same-origin'
            })
            .then(function(res){
                if(!res.ok) throw new Error('네트워크 오류');
                return res.json();
            })
            .then(function(json){
                if (!json.success) throw new Error(json.message || '가져오기 실패');

                var opts = [];
                opts.push(new Option('-- 전체 그룹 --', 0));

                json.items.forEach(function(item){
                    if (item.separator) {
                        var sep = document.createElement('option');
                        sep.textContent = '── ' + item.separator + ' ──';
                        sep.disabled = true;
                        sep.className = 'opt-sep';
                        opts.push(sep);
                    } else {
                        opts.push(new Option(item.label, item.value));
                    }
                });

                groupSel.innerHTML = '';
                opts.forEach(function(o){ groupSel.appendChild(o); });
                groupSel.value = '0'; // 회사 변경 시 기본 전체그룹 선택
            })
            .catch(function(err){
                alert('그룹 목록을 불러오지 못했습니다: ' + err.message);
                groupSel.innerHTML = '<option value="0">-- 전체 그룹 --</option>';
            });
        });
    }
})();
</script>

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
