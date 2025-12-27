<?php
// /adm/call/call_shop_api_list.php
$sub_menu = '700930';
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

// -------------------------------------------
// 접근 권한: 레벨 10만 허용
// -------------------------------------------
if ((int)$member['mb_level'] < 10 && !$is_shop_api_view) {
    alert('접근 권한이 없습니다.');
}

$g5['title'] = '쇼핑 API 주문 리스트';

// ✅ 테이블명
$table = 'call_shop_api';

// -------------------------------------------
// 파라미터
// -------------------------------------------
$sfl  = isset($_GET['sfl']) ? preg_replace('/[^a-z0-9_]/i','', $_GET['sfl']) : 'hq_name';
$stx  = isset($_GET['stx']) ? trim($_GET['stx']) : '';

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$export = isset($_GET['export']) ? trim($_GET['export']) : '';

// -------------------------------------------
// 검색 조건
// -------------------------------------------
$where = [];
$where[] = "1";

$allowed_sfl = [
    'hq_name','branch_name','planner_name','phone_number',
    'order_method','db_type','order_region','distribution_rule'
];
if (!in_array($sfl, $allowed_sfl, true)) $sfl = 'hq_name';

if ($stx !== '') {
    $safe = sql_escape_string($stx);
    $where[] = "{$sfl} LIKE '%{$safe}%'";
}

// 주문일자(date) 범위
if ($date_from !== '') {
    $df = sql_escape_string($date_from);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
        $where[] = "order_date >= '{$df}'";
    }
}
if ($date_to !== '') {
    $dt = sql_escape_string($date_to);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        $where[] = "order_date <= '{$dt}'";
    }
}

$sql_search = " WHERE " . implode(' AND ', $where);

// -------------------------------------------
// 정렬
// -------------------------------------------
$sst = $_GET['sst'] ?? 'id';
$sod = strtolower($_GET['sod'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$allowed_sort = [
    'id','hq_name','branch_name','planner_name','phone_number',
    'order_method','db_type','order_region','order_quantity',
    'distribution_rule','order_date','created_at','updated_at'
];
if (!in_array($sst, $allowed_sort, true)) $sst = 'id';

$sql_order = " ORDER BY {$sst} {$sod} ";

// -------------------------------------------
// CSV 다운로드
// -------------------------------------------
function csv_cell($v) {
    $v = (string)$v;
    // 줄바꿈/탭 정리
    $v = str_replace(["\r\n", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $v);
    // 엑셀 수식 인젝션 방지
    if ($v !== '' && preg_match('/^[=\+\-@]/', $v)) {
        $v = "'".$v;
    }
    // CSV escaping
    $v = str_replace('"', '""', $v);
    return '"'.$v.'"';
}

if ($export === 'csv') {
    // 레벨10만 여기까지 도달 (상단에서 이미 차단)
    $filename = 'call_shop_api_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM (엑셀 한글 깨짐 방지)
    echo "\xEF\xBB\xBF";

    // 헤더
    $headers = [
        'ID','주문일','본사','지점','설계사','전화번호',
        '주문방식','디비유형','주문지역','수량','분배방식','생성일시'
    ]; // ,'수정일시'
    echo implode(',', array_map('csv_cell', $headers)) . "\n";

    // 데이터 (전체 검색 결과를 CSV로)
    $sql_csv = "
        SELECT
            id,
            order_date,
            hq_name,
            branch_name,
            planner_name,
            phone_number,
            order_method,
            db_type,
            order_region,
            order_quantity,
            distribution_rule,
            created_at,
            updated_at
        FROM {$table}
        {$sql_search}
        {$sql_order}
    ";
    $res = sql_query($sql_csv);

    while ($r = sql_fetch_array($res)) {
        $line = [
            (int)$r['id'],
            (string)$r['order_date'],
            (string)$r['hq_name'],
            (string)$r['branch_name'],
            (string)$r['planner_name'],
            (string)format_korean_phone($r['phone_number']),
            (string)$r['order_method'],
            (string)$r['db_type'],
            (string)$r['order_region'],
            (string)$r['order_quantity'],
            (string)$r['distribution_rule'],
            (string)$r['created_at'],
            // (string)$r['updated_at'],
        ];
        echo implode(',', array_map('csv_cell', $line)) . "\n";
    }
    exit;
}

// -------------------------------------------
// 페이징
// -------------------------------------------
$row = sql_fetch("SELECT COUNT(*) AS cnt FROM {$table} {$sql_search}");
$total_count = (int)($row['cnt'] ?? 0);

$rows = 50;
$total_page  = $rows ? (int)ceil($total_count / $rows) : 1;
$from_record = ($page - 1) * $rows;

// -------------------------------------------
// 목록 조회
// -------------------------------------------
$sql = "
    SELECT
        id,
        hq_name,
        branch_name,
        planner_name,
        phone_number,
        order_method,
        db_type,
        order_region,
        order_quantity,
        distribution_rule,
        order_date,
        created_at,
        updated_at
    FROM {$table}
    {$sql_search}
    {$sql_order}
    LIMIT {$from_record}, {$rows}
";
$result = sql_query($sql);

// -------------------------------------------
include_once(G5_ADMIN_PATH.'/admin.head.php');

$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';

// qstr (검색/필터 유지)
$qstr = "sfl={$sfl}&stx=".urlencode($stx)
      ."&date_from=".urlencode($date_from)
      ."&date_to=".urlencode($date_to);

// CSV 링크 (현재 필터 그대로)
$csv_url = "{$_SERVER['SCRIPT_NAME']}?{$qstr}&sst={$sst}&sod={$sod}&export=csv";
?>
<style>
.td_date { width: 90px; }
.td_dt   { width: 130px; }
.td_num  { width: 80px; text-align:right; }
.frm_input { height:28px; }
</style>

<div class="local_ov">
    <?php echo $listall; ?>
    <span class="btn_ov01">
        <span class="ov_txt">총 주문</span>
        <span class="ov_num"><?php echo number_format($total_count); ?> 건</span>
    </span>

    <span style="float:right;">
        <a href="<?php echo $csv_url; ?>" class="btn btn_02" style="margin-left:8px;">CSV 다운로드</a>
    </span>
</div>

<form name="fsearch" class="local_sch01 local_sch" method="get">
    <label for="date_from">주문일</label>
    <input type="date" name="date_from" id="date_from" value="<?php echo get_text($date_from); ?>" class="frm_input">
    ~
    <input type="date" name="date_to" id="date_to" value="<?php echo get_text($date_to); ?>" class="frm_input">

    <select name="sfl" title="검색대상" style="margin-left:10px;">
        <option value="hq_name"<?php echo get_selected($sfl, 'hq_name'); ?>>본사</option>
        <option value="branch_name"<?php echo get_selected($sfl, 'branch_name'); ?>>지점</option>
        <option value="planner_name"<?php echo get_selected($sfl, 'planner_name'); ?>>설계사</option>
        <option value="phone_number"<?php echo get_selected($sfl, 'phone_number'); ?>>전화번호</option>
        <option value="order_method"<?php echo get_selected($sfl, 'order_method'); ?>>주문방식</option>
        <option value="db_type"<?php echo get_selected($sfl, 'db_type'); ?>>디비유형</option>
        <option value="order_region"<?php echo get_selected($sfl, 'order_region'); ?>>주문지역</option>
        <option value="distribution_rule"<?php echo get_selected($sfl, 'distribution_rule'); ?>>분배방식</option>
    </select>

    <label for="stx" class="sound_only">검색어</label>
    <input type="text" name="stx" id="stx" value="<?php echo get_text($stx); ?>" class="frm_input">

    <button type="submit" class="btn btn_01">검색</button>
</form>

<div class="tbl_head01 tbl_wrap">
    <table>
        <caption><?php echo $g5['title']; ?></caption>
        <thead>
            <tr>
                <th scope="col"><?php echo subject_sort_link('id', $qstr); ?>ID</a></th>
                <th scope="col"><?php echo subject_sort_link('order_date', $qstr); ?>주문일</a></th>
                <th scope="col"><?php echo subject_sort_link('hq_name', $qstr); ?>본사</a></th>
                <th scope="col"><?php echo subject_sort_link('branch_name', $qstr); ?>지점</a></th>
                <th scope="col"><?php echo subject_sort_link('planner_name', $qstr); ?>설계사</a></th>
                <th scope="col"><?php echo subject_sort_link('phone_number', $qstr); ?>전화번호</a></th>
                <th scope="col"><?php echo subject_sort_link('order_method', $qstr); ?>주문방식</a></th>
                <th scope="col"><?php echo subject_sort_link('db_type', $qstr); ?>디비유형</a></th>
                <th scope="col"><?php echo subject_sort_link('order_region', $qstr); ?>주문지역</a></th>
                <th scope="col"><?php echo subject_sort_link('order_quantity', $qstr); ?>수량</a></th>
                <th scope="col"><?php echo subject_sort_link('distribution_rule', $qstr); ?>분배방식</a></th>
                <th scope="col"><?php echo subject_sort_link('created_at', $qstr); ?>생성</a></th>
                <!-- <th scope="col"><?php echo subject_sort_link('updated_at', $qstr); ?>수정</a></th> -->
            </tr>
        </thead>
        <tbody>
        <?php
        $colspan = 13;
        for ($i=0; $row = sql_fetch_array($result); $i++) {
            $bg = 'bg'.($i%2);

            $phone = $row['phone_number'];
            $phone = format_korean_phone($phone);

            $od  = $row['order_date']  ? substr($row['order_date'], 2, 8) : '';
            $ca  = $row['created_at']  ? substr($row['created_at'], 2, 14) : '';
            $ua  = $row['updated_at']  ? substr($row['updated_at'], 2, 14) : '';
            ?>
            <tr class="<?php echo $bg; ?>">
                <td class="td_num"><?php echo (int)$row['id']; ?></td>
                <td class="td_date"><?php echo get_text($od); ?></td>
                <td class="td_left"><?php echo get_text($row['hq_name']); ?></td>
                <td class="td_left"><?php echo get_text($row['branch_name']); ?></td>
                <td class="td_left"><?php echo get_text($row['planner_name']); ?></td>
                <td class="td_left"><?php echo get_text($phone); ?></td>
                <td class="td_left"><?php echo get_text($row['order_method']); ?></td>
                <td class="td_left"><?php echo get_text($row['db_type']); ?></td>
                <td class="td_left"><?php echo get_text($row['order_region']); ?></td>
                <td class="td_num"><?php echo number_format((int)$row['order_quantity']); ?></td>
                <td class="td_left"><?php echo get_text($row['distribution_rule']); ?></td>
                <td class="td_dt"><?php echo get_text($ca); ?></td>
                <!-- <td class="td_dt"><?php echo get_text($ua); ?></td> -->
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
    "{$_SERVER['SCRIPT_NAME']}?{$qstr}&sst={$sst}&sod={$sod}&page="
);

include_once(G5_ADMIN_PATH.'/admin.tail.php');
