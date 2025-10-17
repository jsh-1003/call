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
$my_level = (int)$member['mb_level'];
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;

$sfl = isset($_GET['sfl']) ? preg_replace('/[^a-z0-9_]/i','', $_GET['sfl']) : 'name';
$stx = isset($_GET['stx']) ? trim($_GET['stx']) : '';
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

// 상태 필터: all(0/1), active(1), inactive(0)
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
if (!in_array($status_filter, ['all','active','inactive'], true)) $status_filter = 'all';

// 레벨7: 고정 / 레벨8+: 선택 (미선택시 0)
$sel_mb_group = ($my_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group;

// --------------------------------------------------------
// 그룹 선택 옵션(레벨8+만)
// --------------------------------------------------------
$group_options = [];
if ($my_level >= 8) {
    $sql = "
        SELECT DISTINCT mb_group, mb_group_name
        FROM g5_member
        WHERE mb_group_name IS NOT NULL AND mb_group_name <> ''
        ORDER BY mb_group_name ASC
    ";
    $res = sql_query($sql);
    while ($row = sql_fetch_array($res)) $group_options[] = $row;
}

$code_list = get_code_list($sel_mb_group);

// --------------------------------------------------------
// 캠페인 목록(페이징)
// - 레벨7: 본인 그룹만
// - 레벨8+: mb_group 선택 시 해당 그룹만, 미선택이면 전체
// - 상태 필터 적용 (삭제=9는 항상 제외)
// --------------------------------------------------------
if     ($status_filter === 'active')   $status_cond = " status=1 ";
elseif ($status_filter === 'inactive') $status_cond = " status=0 ";
else                                   $status_cond = " status IN (0,1) ";

$sql_search = " WHERE {$status_cond} ";
if ($my_level < 8) {
    $sql_search .= " AND mb_group='{$my_group}' ";
} else {
    if ($sel_mb_group > 0) $sql_search .= " AND mb_group='{$sel_mb_group}' ";
}

if ($stx !== '') {
    $safe_stx = sql_escape_string($stx);
    switch ($sfl) {
        case 'campaign_id':
            $sql_search .= " AND campaign_id='".(int)$safe_stx."' ";
            break;
        case 'campaign_memo':
            $sql_search .= " AND campaign_memo LIKE '%{$safe_stx}%' ";
            break;
        default:
            $sql_search .= " AND `name` LIKE '%{$safe_stx}%' ";
            break;
    }
}

$sst = $_GET['sst'] ?? 'campaign_id';
$sod = $_GET['sod'] ?? 'desc';
$allowed_sort = ['campaign_id','name','created_at'];
if (!in_array($sst, $allowed_sort, true)) $sst = 'campaign_id';
$sod = strtolower($sod)==='asc' ? 'asc' : 'desc';
$sql_order = " ORDER BY {$sst} {$sod} ";

$row = sql_fetch("SELECT COUNT(*) AS cnt FROM call_campaign {$sql_search}");
$total_count = (int)$row['cnt'];
$rows = $config['cf_page_rows'];
$total_page  = $rows ? (int)ceil($total_count / $rows) : 1;
$from_record = ($page - 1) * $rows;

$result = sql_query("
  SELECT campaign_id, mb_group, name, campaign_memo, status, created_at
  FROM call_campaign
  {$sql_search}
  {$sql_order}
  LIMIT {$from_record}, {$rows}
");

// --------------------------------------------------------
// 통계 집계(call_target.last_result)
// - mb_group 선택된 경우: 해당 그룹으로 필터
// - mb_group 미선택(0): 그룹 필터 없이 campaign_id 기준 합산
// --------------------------------------------------------
$campaign_rows = [];
$campaign_ids = [];
while ($r = sql_fetch_array($result)) {
    $campaign_rows[] = $r;
    $campaign_ids[] = (int)$r['campaign_id'];
}

$stats_by_campaign = [];
if ($campaign_ids) {
    $ids_csv = implode(',', array_map('intval',$campaign_ids));
    $mb_cond = ($sel_mb_group > 0) ? "AND t.mb_group='{$sel_mb_group}'" : "";

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
            $stats_by_campaign[$cid]['preassign'] += (int)$r['cnt']; // NULL → 배정전
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
$g5['title'] = '대상등록관리';
include_once (G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';
$base_cols = 6;                      // 체크, 이름, 메모, 잔여율, 총합, 잔여
$dyn_cols  = 1 + count($code_list) + 1;  // 배정전 + 상태코드 수 + 행별 액션
$colspan   = $base_cols + $dyn_cols;

// CSRF 토큰
$admin_token = get_admin_token();

// 현재 쿼리스트링(페이지 유지)
$qstr = http_build_query([
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
    --remain-low:   #f8bfbf;  /* 0~25% 연한 빨강 */
    --remain-mid:   #f9d9a8;  /* 26~50% 연한 주황 */
    --remain-high:  #bfe3b4;  /* 51~75% 연한 초록 */
    --remain-full:  #b4d8f0;  /* 76~100% 연한 파랑 */
}
.badge {
    display:inline-block; padding:2px 6px; font-size:11px; border-radius:10px; line-height:1.4;
    vertical-align:middle; margin-left:6px;
}
.badge-active { background:#e7f7ed; color:#137a2a; border:1px solid #bfe8cb;}
.badge-inactive { background:#f5f5f5; color:#666; border:1px solid #ddd;}
tr.row-inactive td { color:#888; background-image: linear-gradient(to right, rgba(0,0,0,0.03), rgba(0,0,0,0.03)); }
tr.row-inactive td .name-text { text-decoration: line-through; }
.btn-xs { padding:4px 7px; font-size:12px; border-radius:4px; }
.btn-inline { margin:0 2px; }
.td_actions { white-space:nowrap; }
.status-toggle { margin-left:8px; }
.status-toggle label { margin-right:10px; }
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<form name="fsearch" id="fsearch" class="local_sch01 local_sch" method="get">
    <?php if ($my_level >= 8) { ?>
    <select name="mb_group" title="그룹선택">
        <option value="0"<?php echo get_selected($sel_mb_group, 0); ?>>-- 조직선택 --</option>
        <?php foreach ($group_options as $g) { ?>
        <option value="<?php echo (int)$g['mb_group'];?>" <?php echo get_selected($sel_mb_group,(int)$g['mb_group']); ?>>
            <?php echo get_text($g['mb_group_name']); ?> (<?php echo (int)$g['mb_group'];?>)
        </option>
        <?php } ?>
    </select>
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
        <th scope="col"><?php echo subject_sort_link('name', "mb_group={$sel_mb_group}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>파일명</a></th>
        <th scope="col">메모</th>
        <th scope="col">잔여율</th>
        <th scope="col">총합</th>
        <th scope="col">잔여</th>
        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
        <th scope="col"><?php echo subject_sort_link('created_at', "mb_group={$sel_mb_group}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>등록일</a></th>
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

            $stat = $stats_by_campaign[$cid] ?? [];
            $total = (int)($stat['__total__'] ?? 0);
            $preassign = (int)($stat['preassign'] ?? 0);
            $rate = ($total) ? round($preassign/$total*100,2) : 0;
            if ($rate <= 25)      $color = 'var(--remain-low)';
            elseif ($rate <= 50)  $color = 'var(--remain-mid)';
            elseif ($rate <= 75)  $color = 'var(--remain-high)';
            else                  $color = 'var(--remain-full)';
            $bg_rate = "linear-gradient(to right, {$color} {$rate}%, #ffffff {$rate}%)";
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

<!-- 행 단일 액션 전송용 숨은 폼 (일괄 폼 바깥에 둡니다) -->
<form id="frow_action" method="post" action="./call_campaign_list_update.php" style="display:none;">
    <input type="hidden" name="token" value="<?php echo $admin_token; ?>">
    <input type="hidden" name="qstr"  value="<?php echo get_text($qstr); ?>">
    <input type="hidden" name="action" id="row_action_value" value="">
</form>

<?php
// 페이징
$qstr_for_paging = http_build_query([
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
function rowAction(act, cid){
    var msg = (act==='activate')   ? '이 캠페인을 활성화하시겠습니까?' :
              (act==='deactivate') ? '이 캠페인을 비활성화하시겠습니까?' :
              '이 캠페인을 삭제 정말 하시겠습니까?';
    if(!confirm(msg)) return;

    var f = document.getElementById('frow_action');
    document.getElementById('row_action_value').value = act + ':' + cid;
    f.submit();
}
function check_all(f){
    var chk = document.getElementsByName('chk[]');
    for (var i=0;i<chk.length;i++) chk[i].checked = f.checked;
}
function open_upload_popup() {
    var url = './call_target_excel.php';
    var w = 860, h = 740;
    var left = (screen.width - w) / 2;
    var top  = (screen.height - h) / 2;
    window.open(url, 'call_target_excel', 'width='+w+',height='+h+',left='+left+',top='+top+',scrollbars=1,resizable=1');
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

function hasChecked(){
    var boxes = document.getElementsByName('chk[]');
    for (var i=0;i<boxes.length;i++) if (boxes[i].checked) return true;
    return false;
}

function confirmDelete(){
    return confirm('이 캠페인을 삭제(소프트)하시겠습니까?');
}
function confirmRowAction(f){
    var actionField = f.querySelector('input[name="action"]');
    if (!actionField) return false;
    var a = actionField.value.split(':')[0];
    var msg = (a==='activate') ? '이 캠페인을 활성화하시겠습니까?' :
              (a==='deactivate') ? '이 캠페인을 비활성화하시겠습니까?' :
              '처리하시겠습니까?';
    return confirm(msg);
}
</script>

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
