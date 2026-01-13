<?php
// /adm/paid/paid_point_list.php
$sub_menu = "200770";
require_once './_common.php';

$member_table = $g5['member_table']; // g5_member

/* -----------------------------------------------------------
 * 0) 접근권한
 *  - 관리자(9+)
 *  - 대표(member_type=0 && mb_level=0) : 본인 것만
 *  - (호환) 사용자대표(member_type=0 && mb_level=8) : 본인 것만
 * --------------------------------------------------------- */
$mb_level = (int)($member['mb_level'] ?? 0);
$my_type  = (int)($member['member_type'] ?? 0);
$my_mb_id = (string)($member['mb_id'] ?? '');

$is_admin9   = $is_admin_pay;
$is_rep_user = ($my_type === 0 && $mb_level === 8 && is_paid_db_use_company($member['mb_no']));
if (!$is_admin9 && !$is_rep_user) {
    alert('접근 권한이 없습니다.');
}

/* -----------------------------------------------------------
 * 1) 파라미터(날짜레인지 + 검색)
 *  - paid_stats.php 상단폼 스타일/구조 반영
 * --------------------------------------------------------- */
function _getv($key, $default='') {
    if (function_exists('_g')) return _g($key, $default);
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

// datetime-local 값(YYYY-mm-ddTHH:ii) -> SQL datetime(YYYY-mm-dd HH:ii:ss)
function dt_local_to_sql($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    $v = str_replace('T', ' ', $v);
    // seconds가 없으면 붙임
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $v)) {
        $v .= ':00';
    }
    return $v;
}

$today = date('Y-m-d');
$default_start = $today.'T08:00';
$default_end   = $today.'T19:00';

$start_date = (string)_getv('start', $default_start);
$end_date   = (string)_getv('end',   $default_end);

$start_sql = dt_local_to_sql($start_date);
$end_sql   = dt_local_to_sql($end_date);

// 검색/정렬/페이징
$page = max(1, (int)_getv('page', 1));

$sfl = trim((string)_getv('sfl', 'company_name'));
$stx = trim((string)_getv('stx', ''));

$sst = trim((string)_getv('sst', 'po_datetime'));
$sod = trim((string)_getv('sod', 'desc'));

/* -----------------------------------------------------------
 * 2) 화이트리스트
 * --------------------------------------------------------- */
$search_fields = array(
    'company_name' => 'mb.company_name',
    'mb_id'        => 'po.mb_id',
    'mb_hp'        => 'mb.mb_hp',
    'po_content'   => 'po.po_content',
);

$sort_fields = array(
    'po_id'       => 'po.po_id',
    'po_datetime' => 'po.po_datetime',
    'mb_id'       => 'po.mb_id',
    'company_name'=> 'mb.company_name',
    'mb_hp'       => 'mb.mb_hp',
    'po_point'    => 'po.po_point',
);

if (!isset($sort_fields[$sst])) $sst = 'po_datetime';
$sod_l = strtolower($sod);
if (!in_array($sod_l, array('asc','desc'), true)) $sod = 'desc';

/* -----------------------------------------------------------
 * 3) 쿼리 구성
 *  - po_rel_table='@passive' 고정
 *  - 대표 계정 범위(호환): member_type=0 AND mb_level IN (0,8)
 *  - 대표 로그인은 본인 것만
 * --------------------------------------------------------- */
$sql_common = "
    FROM {$g5['point_table']} po
    JOIN {$member_table} mb
      ON mb.mb_id = po.mb_id
";

$where = array();
$where[] = "po.po_rel_table = '@passive'";

// 대표 계정 범위
$where[] = "mb.member_type = 0";
$where[] = "mb.mb_level = 8";

// 날짜
$start_esc = sql_escape_string($start_sql);
$end_esc   = sql_escape_string($end_sql);
$where[] = "po.po_datetime BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 대표 로그인(비관리자)은 본인 것만
if (!$is_admin9) {
    $my_mb_id_esc = sql_escape_string($my_mb_id);
    $where[] = "po.mb_id = '{$my_mb_id_esc}'";
}

// 검색
if ($stx !== '' && isset($search_fields[$sfl])) {
    $field = $search_fields[$sfl];
    $stx_esc = sql_escape_string($stx);

    if ($sfl === 'mb_id') {
        $where[] = "{$field} = '{$stx_esc}'";
    } else {
        $where[] = "{$field} LIKE '%{$stx_esc}%'";
    }
}

$where_sql = 'WHERE '.implode(' AND ', $where);
$sql_order = "ORDER BY {$sort_fields[$sst]} {$sod}";

// count / sum
$row = sql_fetch("SELECT COUNT(*) AS cnt {$sql_common} {$where_sql}");
$total_count = (int)($row['cnt'] ?? 0);

$row_sum = sql_fetch("SELECT COALESCE(SUM(po.po_point),0) AS sum_point {$sql_common} {$where_sql}");
$sum_point = (int)($row_sum['sum_point'] ?? 0);

// paging
$rows = (int)$config['cf_page_rows'];
if ($rows < 1) $rows = 20;

$total_page  = (int)ceil($total_count / $rows);
$from_record = ($page - 1) * $rows;

// list
$sql = "SELECT
        po.*,
        mb.mb_name,
        mb.mb_hp,
        mb.company_name,
        mb.mb_point
    {$sql_common}
    {$where_sql}
    {$sql_order}
    LIMIT {$from_record}, {$rows}
";
$result = sql_query($sql);

// qstr
$qparams = array(
    'start' => $start_date,
    'end'   => $end_date,
    'sfl'   => $sfl,
    'stx'   => $stx,
    'sst'   => $sst,
    'sod'   => $sod,
);
$qstr = http_build_query($qparams, '', '&amp;');

$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';

$g5['title'] = '포인트 내역';
require_once '../admin.head.php';
if(!empty($aaa)) {
    print_r2($sql);
}
$colspan = 8;
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.tilde { color:#777; }
.small-muted{ color:#777; font-size:12px; }
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01"><span class="ov_txt">전체 </span><span class="ov_num"><?php echo number_format($total_count) ?> 건</span></span>
    <span class="btn_ov01"><span class="ov_txt">포인트 합계 </span><span class="ov_num"><?php echo number_format($sum_point) ?> 점</span></span>
</div>

<!-- 검색/필터 (paid_stats.php 상단폼 스타일/구조 반영) -->
<div class="local_sch01 local_sch">
    <form method="get" action="./paid_point_list.php" class="form-row" id="searchForm">
        <input type="datetime-local" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input">
        <span class="tilde">~</span>
        <input type="datetime-local" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input">

        <?php render_date_range_buttons('dateRangeBtns'); ?>
        <script>
        if (typeof DateRangeButtons !== 'undefined') {
            DateRangeButtons.init({
                container: '#dateRangeBtns',
                startInput: '#start',
                endInput: '#end',
                form: '#searchForm',
                autoSubmit: true,
                weekStart: 1,
                thisWeekEndToday: true,
                thisMonthEndToday: true
            });
        }
        </script>

        <span>&nbsp;|&nbsp;</span>

        <label for="sfl">검색</label>
        <select name="sfl" id="sfl" style="width:120px">
            <?php if($is_admin9) { ?>
            <option value="company_name" <?php echo get_selected($sfl, "company_name"); ?>>업체명</option>
            <option value="mb_id" <?php echo get_selected($sfl, "mb_id"); ?>>회원아이디</option>
            <option value="mb_hp" <?php echo get_selected($sfl, "mb_hp"); ?>>대표연락처</option>
            <?php } ?>
            <option value="po_content" <?php echo get_selected($sfl, "po_content"); ?>>내용</option>
        </select>
        <input type="text" name="stx" value="<?php echo get_text($stx);?>" class="frm_input" style="width:200px" placeholder="검색어 입력">
        <button type="submit" class="btn btn_01">검색</button>
    </form>
</div>

<div class="tbl_head01 tbl_wrap">
    <table>
        <caption><?php echo $g5['title']; ?> 목록</caption>
        <thead>
        <tr>
            <th scope="col"><?php echo subject_sort_link('mb_id') ?>회원아이디</a></th>
            <th scope="col"><?php echo subject_sort_link('company_name') ?>업체명</a></th>
            <th scope="col"><?php echo subject_sort_link('mb_hp') ?>대표연락처</a></th>
            <th scope="col">대표명</th>
            <th scope="col">포인트 내용</th>
            <th scope="col"><?php echo subject_sort_link('po_point') ?>포인트</a></th>
            <th scope="col"><?php echo subject_sort_link('po_datetime') ?>일시</a></th>
            <?php if(!$is_rep_user) { ?>
            <th scope="col">포인트합(현재)</th>
            <?php } ?>
        </tr>
        </thead>
        <tbody>
        <?php
        for ($i=0; $row=sql_fetch_array($result); $i++) {
            $bg = 'bg'.($i % 2);

            $company_name = (string)($row['company_name'] ?? '');
            $mb_hp = (string)($row['mb_hp'] ?? '');

            // @passive 고정이라 링크 없음
        ?>
        <tr class="<?php echo $bg; ?>">
            <td class="td_left"><a href="?<?php echo $qstr; ?>&amp;sfl=mb_id&amp;stx=<?php echo urlencode($row['mb_id']); ?>"><?php echo get_text($row['mb_id']); ?></a></td>
            <td class="td_left"><?php echo get_text($company_name); ?></td>
            <td class="td_left"><?php echo get_text($mb_hp); ?></td>
            <td class="td_left"><?php echo get_text($row['mb_name']); ?></td>
            <td class="td_left"><?php echo get_text($row['po_content']); ?></td>
            <td class="td_num td_pt"><?php echo number_format((int)$row['po_point']); ?></td>
            <td class="td_datetime"><?php echo get_text($row['po_datetime']); ?></td>
            <?php if(!$is_rep_user) { ?>
            <td class="td_num td_pt"><?php echo number_format((int)$row['mb_point']); ?></td>
            <?php } ?>
        </tr>
        <?php
        }
        if ($i === 0) {
            echo '<tr><td colspan="'.$colspan.'" class="empty_table">자료가 없습니다.</td></tr>';
        }
        ?>
        </tbody>
    </table>
</div>

<?php
echo get_paging(
    G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'],
    $page,
    $total_page,
    "{$_SERVER['SCRIPT_NAME']}?{$qstr}&amp;page="
);

require_once '../admin.tail.php';
