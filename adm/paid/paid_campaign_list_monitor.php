<?php
// /adm/paid/paid_campaign_list.php
$sub_menu = '700765';
include_once('./_common.php');
include_once(G5_LIB_PATH.'/call.renew.php');

if ($member['member_type'] != 3) {
    alert('접근 권한이 없습니다..');
    exit;
}
$mb_level = (int)$member['mb_level'];
$sel_info = rn_select_company_mb_group_id($mb_level);
$sel_company_id = implode(',', $sel_info['company_id']);
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
// 상태 조건
// -------------------------
$status_cond = '1';
if     ($status_filter === 'active')   $status_cond = " c.status=1 ";
elseif ($status_filter === 'inactive') $status_cond = " c.status=0 ";


// -------------------------
// 검색 조건 SQL
// -------------------------
$sql_search = " WHERE EXISTS (SELECT 1
            FROM call_campaign_company cc
           WHERE cc.campaign_id = c.campaign_id
             AND cc.company_id IN (".$sel_company_id.")
             AND cc.scope_mode = 'selected'
        )
                 AND c.is_paid_db=1
                 AND c.deleted_at IS NULL
                 AND {$status_cond} ";             

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
$sql = "SELECT COUNT(*) AS cnt FROM call_campaign c 
        {$sql_search}";
// 카운트/페이징
$row = sql_fetch($sql);
$total_count = (int)$row['cnt'];
$rows = (int)$config['cf_page_rows'];
if ($rows <= 0) $rows = 20;

$total_page  = (int)ceil($total_count / $rows);
$from_record = ($page - 1) * $rows;

// 목록
$result = sql_query("SELECT c.campaign_id, c.db_agency, c.db_vendor,
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
function get_member_label($row) {
    $label = '';
    if($row['member_type']==2) $label = $row['mb_group_name'];
    elseif (!empty($row['company_name'])) $label = $row['company_name'];
    elseif (!empty($row['mb_name']))  $label = $row['mb_name'];
    else $label = $row['mb_id'];
    if(empty($row['is_paid_db'])) $label = '<span class="badge badge-inactive">비활성</span> - '.$label;
    return $label;
}

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

$campaign_target_summary_map = get_paid_campaign_target_summaries($campaign_ids);
$campaign_target_summary_json = [];
foreach ($campaign_target_summary_map as $campaign_id => $summary) {
    $campaign_target_summary_json[(string)$campaign_id] = [
        'mode' => (string)$summary['mode'],
        'company_ids' => array_values(array_map('intval', (array)$summary['company_ids'])),
        'company_count' => (int)$summary['company_count'],
        'summary_text' => (string)$summary['summary_text'],
    ];
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
.td_cam_name {font-size:0.9em;letter-spacing:-1px;}
.td_src {line-height:1.1em;}
.target-summary {
    display:block;
    margin-bottom:6px;
    color:#444;
    font-size:12px;
    line-height:1.4;
}
.target-summary.mode-selected { color:#0f5ba7; font-weight:600; }
.target-summary.mode-exclude { color:#a64b00; font-weight:600; }
.popup-target-wrap { display:flex; flex-direction:column; gap:14px; }
.popup-target-help { color:#666; font-size:12px; line-height:1.5; }
.popup-target-mode { display:flex; gap:18px; flex-wrap:wrap; }
.popup-target-mode label { display:inline-flex; align-items:center; gap:6px; font-weight:600; }
.popup-target-panel { border:1px solid #ddd; border-radius:8px; padding:14px; background:#fafafa; }
.popup-target-toolbar { display:flex; justify-content:space-between; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.popup-target-toolbar .frm_input { width:260px; max-width:100%; }
.popup-target-count { color:#555; font-size:12px; }
.popup-target-list {
    max-height:350px;
    overflow:auto;
    border:1px solid #e5e5e5;
    border-radius:6px;
    background:#fff;
}
.popup-target-item {
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    border-top:1px solid #f0f0f0;
}
.popup-target-item:first-child { border-top:0; }
.popup-target-item input[type="checkbox"] { margin:0; }
.popup-target-item label { display:flex; align-items:center; gap:8px; width:100%; cursor:pointer; }
.popup-target-empty { padding:24px 12px; text-align:center; color:#777; }
.popup-target-all-note { color:#1b6a36; font-weight:600; }
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<div class="tbl_head01 tbl_wrap">
    <table>
    <caption><?php echo $g5['title']; ?></caption>
    <thead>
    <tr>
        <?php /*
        <th scope="col">
            <label for="chkall" class="sound_only">전체</label>
            <input type="checkbox" id="chkall" onclick="check_all(this.form)">
        </th>
        <th scope="col">에이전시 / 벤더사</th>
        <th scope="col">메모</th>
        */ ?>
        <th scope="col"><?php echo subject_sort_link('paid_db_name', "db_agency={$db_agency}&db_vendor={$db_vendor}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>파일명</a></th>
        <th scope="col">잔여율</th>
        <th scope="col">총합</th>
        <th scope="col" style="background-color:#137a2a !important">잔여</th>
        <?php foreach ($code_list as $c) echo '<th scope="col">'.get_text($c['name']).'</th>'; ?>
        <th scope="col"><?php echo subject_sort_link('created_at', "db_agency={$db_agency}&db_vendor={$db_vendor}&sfl={$sfl}&stx={$stx}&status={$status_filter}"); ?>등록일</a></th>
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
            $target_summary = $campaign_target_summary_map[$cid] ?? [
                'mode' => 'all',
                'company_ids' => [],
                'company_count' => 0,
                'summary_text' => '전체 사용',
            ];
            $popup_campaign_name = htmlspecialchars(
                json_encode((string)$r['paid_db_name'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP),
                ENT_QUOTES
            );
            ?>
            <tr class="<?php echo $bg; ?> <?php echo $inactive ? 'row-inactive' : ''; ?>">
                <?php /*
                <td class="td_chk">
                    <input type="checkbox" name="chk[]" value="<?php echo $cid; ?>" title="선택">
                </td>
                <td class="td_left td_src">
                    <b><?php echo $agency_nm; ?></b><br>
                    <?php echo $vendor_nm; ?></span>
                </td>
                <td class="td_left"><?php echo get_text($r['campaign_memo']); ?></td>
                */ ?>
                <td class="td_left td_cam_name" style='background:<?php echo $bg_rate ?>;'>
                    <!-- <a href="./paid_db_list.php?campaign_id=<?php echo $cid ?>" target="_blank"> -->
                        <span class="name-text"><?php echo get_text($r['paid_db_name']); ?></span>
                        <?php if ($inactive) { ?>
                            <span class="badge badge-inactive">비활성</span>
                        <?php } else { ?>
                            <span class="badge badge-active">활성</span>
                        <?php } ?>
                    <!-- </a> -->
                    <!-- <div style="margin-top:4px;color:#888;font-size:11px;">
                        캠페인ID: <?php echo $cid; ?>
                    </div> -->
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
            </tr>
            <?php
        }
    }
    ?>
    </tbody>
    </table>
</div>

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

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
