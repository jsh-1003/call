<?php
// /adm/paid/paid_campaign_list.php
$sub_menu = '200765';
include_once('./_common.php');

if (empty($is_admin_pay)) {
    alert('접근 권한이 없습니다.');
    exit;
}

// -------------------------
// 검색 파라미터
// -------------------------
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$db_agency = (int)($_GET['db_agency'] ?? 0); // 0=전체
$db_vendor = (int)($_GET['db_vendor'] ?? 0); // 0=전체

$sfl  = isset($_GET['sfl']) ? preg_replace('/[^a-z0-9_]/i','', $_GET['sfl']) : 'paid_db_name';
$stx  = isset($_GET['stx']) ? trim($_GET['stx']) : '';

$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
if (!in_array($status_filter, ['all','active','inactive'], true)) $status_filter = 'all';

$sst = $_GET['sst'] ?? 'campaign_id';
$sod = $_GET['sod'] ?? 'desc';
$allowed_sort = ['campaign_id','paid_db_name','created_at'];
if (!in_array($sst, $allowed_sort, true)) $sst = 'campaign_id';
$sod = strtolower($sod)==='asc' ? 'asc' : 'desc';

// -------------------------
// 에이전시/벤더 옵션 로드
// -------------------------
function get_member_label($row) {
    $label = '';
    if($row['member_type']==2) $label = $row['mb_group_name'];
    elseif (!empty($row['company_name'])) $label = $row['company_name'];
    elseif (!empty($row['mb_name']))  $label = $row['mb_name'];
    else $label = $row['mb_id'];
    if(empty($row['is_paid_db'])) $label = '<span class="badge badge-inactive">비활성</span> - '.$label;
    return $label;
}

$agency_options = [];
$vendor_options = [];

$aq = sql_query("
    SELECT mb_no, mb_id, mb_name, company_name, mb_group_name, member_type, is_paid_db
      FROM {$g5['member_table']}
     WHERE member_type = 1
     ORDER BY COALESCE(NULLIF(company_name,''), NULLIF(mb_name,''), mb_id) ASC, mb_no ASC
");
while ($r = sql_fetch_array($aq)) {
    $agency_options[] = ['mb_no'=>(int)$r['mb_no'], 'label'=>get_member_label($r)];
}

$vq = sql_query("
    SELECT mb_no, mb_id, mb_name, company_name, mb_group_name, member_type, is_paid_db
      FROM {$g5['member_table']}
     WHERE member_type = 2
     ORDER BY COALESCE(NULLIF(company_name,''), NULLIF(mb_name,''), mb_id) ASC, mb_no ASC
");
while ($r = sql_fetch_array($vq)) {
    $vendor_options[] = ['mb_no'=>(int)$r['mb_no'], 'label'=>get_member_label($r)];
}

// -------------------------
// 상태 조건
// -------------------------
if     ($status_filter === 'active')   $status_cond = " c.status=1 ";
elseif ($status_filter === 'inactive') $status_cond = " c.status=0 ";
else                                   $status_cond = " c.status IN (0,1) ";

// -------------------------
// 검색 조건 SQL
// -------------------------
$sql_search = " WHERE c.is_paid_db=1
                 AND c.deleted_at IS NULL
                 AND {$status_cond} ";

if ($db_agency > 0) $sql_search .= " AND c.db_agency = ".(int)$db_agency." ";
if ($db_vendor > 0) $sql_search .= " AND c.db_vendor = ".(int)$db_vendor." ";

// 검색어
if ($stx !== '') {
    $safe_stx = sql_escape_string($stx);
    switch ($sfl) {
        case 'campaign_id':
            $sql_search .= " AND c.campaign_id = ".(int)$safe_stx." ";
            break;
        case 'campaign_memo':
            $sql_search .= " AND c.campaign_memo LIKE '%{$safe_stx}%' ";
            break;
        case 'paid_db_name':
        default:
            $sql_search .= " AND c.paid_db_name LIKE '%{$safe_stx}%' ";
            break;
    }
}

// 정렬
$sql_order = " ORDER BY c.{$sst} {$sod} ";

// 카운트/페이징
$row = sql_fetch("SELECT COUNT(*) AS cnt FROM call_campaign c {$sql_search}");
$total_count = (int)$row['cnt'];
$rows = (int)$config['cf_page_rows'];
if ($rows <= 0) $rows = 20;

$total_page  = (int)ceil($total_count / $rows);
$from_record = ($page - 1) * $rows;

// 목록
$result = sql_query("
    SELECT c.campaign_id, c.db_agency, c.db_vendor,
           c.paid_db_name, c.campaign_memo,
           c.is_open_number, c.status,
           c.created_at
      FROM call_campaign c
      {$sql_search}
      {$sql_order}
      LIMIT {$from_record}, {$rows}
");

$campaign_rows = [];
$campaign_ids  = [];
$member_ids    = [];
while ($r = sql_fetch_array($result)) {
    $campaign_rows[] = $r;
    $campaign_ids[]  = (int)$r['campaign_id'];
    if (!empty($r['db_agency'])) $member_ids[] = (int)$r['db_agency'];
    if (!empty($r['db_vendor'])) $member_ids[] = (int)$r['db_vendor'];
}
$member_ids = array_values(array_unique($member_ids));

// -------------------------
// 에이전시/벤더명 매핑
// -------------------------
$member_map = []; // mb_no => label
if ($member_ids) {
    $ids_csv = implode(',', array_map('intval', $member_ids));
    $mq = sql_query("
        SELECT mb_no, mb_id, mb_name, company_name, mb_group_name, member_type, is_paid_db
          FROM {$g5['member_table']}
         WHERE mb_no IN ({$ids_csv})
    ");
    while ($m = sql_fetch_array($mq)) {
        $mid = (int)$m['mb_no'];
        $member_map[$mid] = get_member_label($m);
    }
}

// -------------------------
// 통계 집계 (call_target.last_result 기준)
// - 유료DB 캠페인만 목록에서 가져온 것이므로,
//   여기서는 campaign_id IN(...) 만으로 집계해서 인덱스 활용을 최대화
// -------------------------
$code_list = get_code_list(0); // 유료DB는 그룹 개념이 없으므로 0(전사/기본)로 사용

$stats_by_campaign = [];
if ($campaign_ids) {
    $ids_csv = implode(',', array_map('intval', $campaign_ids));

    // last_result 분포
    $res = sql_query("
        SELECT t.campaign_id, t.last_result, COUNT(*) AS cnt
          FROM call_target t
         WHERE t.campaign_id IN ({$ids_csv})
         GROUP BY t.campaign_id, t.last_result
    ");
    while ($r = sql_fetch_array($res)) {
        $cid = (int)$r['campaign_id'];
        if (!isset($stats_by_campaign[$cid])) $stats_by_campaign[$cid] = ['preassign'=>0];

        if (is_null($r['last_result'])) {
            $stats_by_campaign[$cid]['preassign'] += (int)$r['cnt']; // 잔여(미통화)
        } else {
            $cs = (int)$r['last_result'];
            $stats_by_campaign[$cid][$cs] = ((int)($stats_by_campaign[$cid][$cs] ?? 0)) + (int)$r['cnt'];
        }
    }

    // 총합
    $res2 = sql_query("
        SELECT t.campaign_id, COUNT(*) AS cnt
          FROM call_target t
         WHERE t.campaign_id IN ({$ids_csv})
         GROUP BY t.campaign_id
    ");
    while ($r = sql_fetch_array($res2)) {
        $cid = (int)$r['campaign_id'];
        if (!isset($stats_by_campaign[$cid])) $stats_by_campaign[$cid] = ['preassign'=>0];
        $stats_by_campaign[$cid]['__total__'] = (int)$r['cnt'];
    }
}

// -------------------------
$g5['title'] = '유료DB 캠페인 관리';
include_once(G5_ADMIN_PATH.'/admin.head.php');

$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';

// 테이블 컬럼 수 계산
$base_cols = 7; // 체크 + 에이전시/벤더 + 메모 + 파일명 + 잔여율 + 총합 + 잔여
$dyn_cols  = count($code_list) + 2; // 상태코드들 + 등록일 + 관리
$colspan   = $base_cols + $dyn_cols;

$admin_token = get_admin_token();

$qstr = http_build_query([
    'db_agency'=>$db_agency,
    'db_vendor'=>$db_vendor,
    'sfl'=>$sfl,
    'stx'=>$stx,
    'sst'=>$sst,
    'sod'=>$sod,
    'status'=>$status_filter,
    'page'=>$page
]);
?>
<style>
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
.badge-private  { background:#ffe9e9; color:#c0392b; border-color:#f5c2c2;}
tr.row-inactive td { color:#888; background-image: linear-gradient(to right, rgba(0,0,0,0.03), rgba(0,0,0,0.03)); }
tr.row-inactive td .name-text { text-decoration: line-through; }
.btn-xs { padding:4px 7px; font-size:12px; border-radius:4px; }
.btn-inline { margin:0 2px; }
.td_actions { white-space:nowrap; }
.status-toggle { margin-left:8px; }
.status-toggle label { margin-right:10px; }
.td_cnt {width:60px;font-weight:bold;font-size:1.05em;font-family:sans-serif}
.td_cntsmall {width:56px}
.td_cam_name {font-size:0.85em;letter-spacing:-1px;}
.td_src {line-height:1.1em;}
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<form name="fsearch" id="fsearch" class="local_sch01 local_sch" method="get" autocomplete="off">
    <select name="db_agency" id="db_agency">
        <option value="0"<?php echo $db_agency===0?' selected':'';?>>전체 에이전시</option>
        <?php foreach ($agency_options as $a) { ?>
            <option value="<?php echo (int)$a['mb_no']; ?>" <?php echo get_selected($db_agency, (int)$a['mb_no']); ?>>
                <?php echo $a['label']; ?>
            </option>
        <?php } ?>
    </select>

    <select name="db_vendor" id="db_vendor">
        <option value="0"<?php echo $db_vendor===0?' selected':'';?>>전체 벤더사</option>
        <?php foreach ($vendor_options as $v) { ?>
            <option value="<?php echo (int)$v['mb_no']; ?>" <?php echo get_selected($db_vendor, (int)$v['mb_no']); ?>>
                <?php echo $v['label']; ?>
            </option>
        <?php } ?>
    </select>

    <select name="sfl" title="검색대상">
        <option value="paid_db_name"<?php echo get_selected($sfl, "paid_db_name"); ?>>파일명</option>
        <option value="campaign_memo"<?php echo get_selected($sfl, "campaign_memo"); ?>>메모</option>
        <option value="campaign_id"<?php echo get_selected($sfl, "campaign_id"); ?>>캠페인ID</option>
    </select>
    <label for="stx" class="sound_only">검색어<strong class="sound_only"> 필수</strong></label>
    <input type="text" name="stx" value="<?php echo get_text($stx); ?>" id="stx" class="frm_input">

    <span class="status-toggle">
        <label><input type="radio" name="status" value="all" <?php echo $status_filter==='all' ? 'checked' : ''; ?>> 전체</label>
        <label><input type="radio" name="status" value="active" <?php echo $status_filter==='active' ? 'checked' : ''; ?>> 활성</label>
        <label><input type="radio" name="status" value="inactive" <?php echo $status_filter==='inactive' ? 'checked' : ''; ?>> 비활성</label>
    </span>

    <button type="submit" class="btn btn_01">검색</button>
</form>

<form id="fcampaignlist" name="fcampaignlist" method="post" action="./paid_campaign_list_update.php" onsubmit="return false;">
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
        <th scope="col">에이전시 / 벤더사</th>
        <th scope="col">메모</th>
        <th scope="col"><?php echo subject_sort_link('paid_db_name', "db_agency={$db_agency}&db_vendor={$db_vendor}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>파일명</a></th>
        <th scope="col">잔여율</th>
        <th scope="col">총합</th>
        <th scope="col" style="background-color:#137a2a !important">잔여</th>
        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
        <th scope="col"><?php echo subject_sort_link('created_at', "db_agency={$db_agency}&db_vendor={$db_vendor}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>등록일</a></th>
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
            $remain = (int)($stat['preassign'] ?? 0); // last_result IS NULL
            $rate = ($total) ? round($remain/$total*100,2) : 0;

            if ($rate <= 25)      $color = 'var(--remain-low)';
            elseif ($rate <= 50)  $color = 'var(--remain-mid)';
            elseif ($rate <= 75)  $color = 'var(--remain-high)';
            else                  $color = 'var(--remain-full)';
            $bg_rate = "linear-gradient(to right, {$color} {$rate}%, #ffffff {$rate}%)";

            $agency_id = (int)($r['db_agency'] ?? 0);
            $vendor_id = (int)($r['db_vendor'] ?? 0);
            $agency_nm = $agency_id ? ($member_map[$agency_id] ?? ('#'.$agency_id)) : '-';
            $vendor_nm = $vendor_id ? ($member_map[$vendor_id] ?? ('#'.$vendor_id)) : '-';
            ?>
            <tr class="<?php echo $bg; ?> <?php echo $inactive ? 'row-inactive' : ''; ?>">
                <td class="td_chk">
                    <input type="checkbox" name="chk[]" value="<?php echo $cid; ?>" title="선택">
                </td>
                <td class="td_left td_src">
                    <b><?php echo $agency_nm; ?></b><br>
                    <?php echo $vendor_nm; ?></span>
                </td>
                <td class="td_left"><?php echo get_text($r['campaign_memo']); ?></td>
                <td class="td_left td_cam_name" style='background:<?php echo $bg_rate ?>;'>
                    <a href="./paid_db_list.php?campaign_id=<?php echo $cid ?>" target="_blank">
                        <span class="name-text"><?php echo get_text($r['paid_db_name']); ?></span>
                        <?php if ($inactive) { ?>
                            <span class="badge badge-inactive">비활성</span>
                        <?php } else { ?>
                            <span class="badge badge-active">활성</span>
                        <?php } ?>
                        <?php if ((int)$r['is_open_number'] === 0) { ?>
                            <span class="badge badge-private">1차 비공개</span>
                        <?php } ?>
                    </a>
                    <div style="margin-top:4px;color:#888;font-size:11px;">
                        캠페인ID: <?php echo $cid; ?>
                    </div>
                </td>
                <td class="td_cnt"><?php echo $rate ?>%</td>
                <td class="td_cntsmall"><?php echo number_format($total); ?></td>
                <td class="td_cntsmall"><?php echo number_format($remain); ?></td>
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
    <a href="javascript:void(0)" class="btn btn_01" onclick="open_upload_popup();">유료DB 엑셀 등록</a>
    <button type="button" class="btn btn_02" onclick="submitSelected('선택활성화')">선택활성화</button>
    <button type="button" class="btn btn_02" onclick="submitSelected('선택비활성화')">선택비활성화</button>
    <button type="button" class="btn btn_03" onclick="submitSelectedDelete()">선택삭제</button>
</div>
</form>

<form id="frow_action" method="post" action="./paid_campaign_list_update.php" style="display:none;">
    <input type="hidden" name="token" value="<?php echo $admin_token; ?>">
    <input type="hidden" name="qstr"  value="<?php echo get_text($qstr); ?>">
    <input type="hidden" name="action" id="row_action_value" value="">
</form>

<?php
$qstr_for_paging = http_build_query([
    'db_agency'=>$db_agency,
    'db_vendor'=>$db_vendor,
    'sfl'=>$sfl,
    'stx'=>$stx,
    'sst'=>$sst,
    'sod'=>$sod,
    'status'=>$status_filter
]);
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?$qstr_for_paging&amp;page=");
?>

<script>
function check_all(f){
    var chk = document.getElementsByName('chk[]');
    for (var i=0;i<chk.length;i++) chk[i].checked = f.checked;
}
function rowAction(act, cid){
    var msg = (act==='activate')   ? '이 캠페인을 활성화하시겠습니까?' :
              (act==='deactivate') ? '이 캠페인을 비활성화하시겠습니까?' :
              '이 캠페인을 삭제하시겠습니까? (복구 불가/삭제처리)';
    if(!confirm(msg)) return;

    var f = document.getElementById('frow_action');
    document.getElementById('row_action_value').value = act + ':' + cid;
    f.submit();
}
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
    if (!confirm('선택한 항목을 삭제 처리 합니다. 정말 진행하시겠습니까?')) return;

    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'act_button';
    input.value = '선택삭제';
    f.appendChild(input);
    f.submit();
}
function open_upload_popup() {
    var url = './paid_target_excel.php';
    var w = 860, h = 740;
    var left = (screen.width - w) / 2;
    var top  = (screen.height - h) / 2;
    window.open(url, 'paid_target_excel', 'width='+w+',height='+h+',left='+left+',top='+top+',scrollbars=1,resizable=1');
}
function open_upload_popup_add_mode() {
    var url = './paid_target_excel_add_mode.php';
    var w = 860, h = 740;
    var left = (screen.width - w) / 2;
    var top  = (screen.height - h) / 2;
    window.open(url, 'paid_target_excel_add_mode', 'width='+w+',height='+h+',left='+left+',top='+top+',scrollbars=1,resizable=1');
}
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
