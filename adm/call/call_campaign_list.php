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

// --------------------------------------------------------
// 상태코드 헤더 구성
// - mb_group가 선택된 경우: 해당 그룹 우선, 없으면 0(공통)
// - mb_group 미선택(0)인 경우: 0(공통)만 사용
// - 각 그룹 내부 sort_order ASC, 출력 순서는 "그룹(>0) 먼저, 그다음 0"
// --------------------------------------------------------
$code_map = [];
$code_list = [];

if ($sel_mb_group > 0) {
    $sql = "
      SELECT c.call_status, c.mb_group, c.name_ko, c.sort_order
      FROM call_status_code c
      WHERE c.status=1 AND (c.mb_group='{$sel_mb_group}' OR c.mb_group=0)
      ORDER BY (c.mb_group='{$sel_mb_group}') DESC, c.sort_order ASC, c.call_status ASC
    ";
} else {
    // 그룹 선택이 없으면 공통(0)만
    $sql = "
      SELECT c.call_status, c.mb_group, c.name_ko, c.sort_order
      FROM call_status_code c
      WHERE c.status=1 AND c.mb_group=0
      ORDER BY c.sort_order ASC, c.call_status ASC
    ";
}
$res = sql_query($sql);
while ($r = sql_fetch_array($res)) {
    $cs = (int)$r['call_status'];
    if (!isset($code_map[$cs])) {
        $code_map[$cs] = [
            'name' => $r['name_ko'],
            'mb_group' => (int)$r['mb_group'],
            'sort_order' => (int)$r['sort_order'],
        ];
    }
}
foreach ($code_map as $cs=>$info) {
    $code_list[] = ['call_status'=>$cs,'name'=>$info['name'],'mb_group'=>$info['mb_group'],'sort_order'=>$info['sort_order']];
}
usort($code_list, function($a,$b){
    if ($a['mb_group'] !== $b['mb_group']) return ($a['mb_group'] === 0) ? 1 : -1; // 그룹>0 먼저
    if ($a['sort_order'] === $b['sort_order']) return $a['call_status'] <=> $b['call_status'];
    return $a['sort_order'] <=> $b['sort_order'];
});

// --------------------------------------------------------
// 캠페인 목록(페이징)
// - 레벨7: 본인 그룹만
// - 레벨8+: mb_group 선택 시 해당 그룹만, 미선택이면 전체
// --------------------------------------------------------
$sql_search = " WHERE status=1 ";
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
        default:
            $sql_search .= " AND name LIKE '%{$safe_stx}%' ";
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
  SELECT campaign_id, mb_group, name, created_at
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
$base_cols = 4;                      // 체크, ID, 이름, 총합
$dyn_cols  = 1 + count($code_list);  // 배정전 + 상태코드 수
$colspan   = $base_cols + $dyn_cols;
?>
<style>
.td_cntsmall {width:58px}
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
        <option value="campaign_id"<?php echo get_selected($sfl, "campaign_id"); ?>>캠페인ID</option>
    </select>
    <label for="stx" class="sound_only">검색어<strong class="sound_only"> 필수</strong></label>
    <input type="text" name="stx" value="<?php echo get_text($stx); ?>" id="stx" class="frm_input">
    <button type="submit" class="btn btn_01">검색</button>
</form>

<div class="tbl_head01 tbl_wrap">
    <table>
    <caption><?php echo $g5['title']; ?></caption>
    <thead>
    <tr>
        <th scope="col">
            <label for="chkall" class="sound_only">전체</label>
            <input type="checkbox" id="chkall" onclick="check_all(this.form)">
        </th>
        <th scope="col"><?php echo subject_sort_link('campaign_id', "mb_group={$sel_mb_group}&sfl={$sfl}&stx={$stx}"); ?>캠페인ID</a></th>
        <th scope="col"><?php echo subject_sort_link('name', "mb_group={$sel_mb_group}&sfl={$sfl}&stx={$stx}"); ?>파일명</a></th>
        <th scope="col">총합</th>
        <th scope="col">배정전</th>
        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
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

            $stat = $stats_by_campaign[$cid] ?? [];
            $total = (int)($stat['__total__'] ?? 0);
            $preassign = (int)($stat['preassign'] ?? 0);
            ?>
            <tr class="<?php echo $bg; ?>">
                <td class="td_chk"><input type="checkbox" name="chk[]" value="<?php echo $cid; ?>" title="선택"></td>
                <td class="td_num"><?php echo $cid; ?></td>
                <td class="td_left"><?php echo get_text($r['name']); ?></td>
                <td class="td_cntsmall"><?php echo number_format($total); ?></td>
                <td class="td_cntsmall"><?php echo number_format($preassign); ?></td>
                <?php
                foreach ($code_list as $c) {
                    $cs = (int)$c['call_status'];
                    $val = (int)($stat[$cs] ?? 0);
                    echo '<td class="td_cntsmall">'.number_format($val).'</td>';
                }
                ?>
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
</div>

<?php
$qstr = http_build_query([
    'mb_group'=>$sel_mb_group,
    'sfl'=>$sfl,
    'stx'=>$stx,
    'sst'=>$sst,
    'sod'=>$sod
]);
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?$qstr&amp;page=");
?>

<script>
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
</script>

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
