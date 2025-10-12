<?php
// /adm/call_member_list.php
$sub_menu = '700750';
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');
// -------------------------------------------
// 접근 권한: 레벨 7 미만 차단
// -------------------------------------------
if ((int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

$g5['title'] = '회원관리';

// -------------------------------------------
// 파라미터
// -------------------------------------------
$sfl = isset($_GET['sfl']) ? preg_replace('/[^a-z0-9_]/i','', $_GET['sfl']) : 'mb_name';
$stx = isset($_GET['stx']) ? trim($_GET['stx']) : '';
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$include_blocked = (isset($_GET['include_blocked']) && $_GET['include_blocked'] == '1') ? 1 : 0;

// 레벨8+: 전체 그룹 관리 / 레벨7: 자기 그룹만
$my_level = (int)$member['mb_level'];
$my_mb_no = (int)$member['mb_no'];
$sel_mb_group = 0;
if ($my_level >= 8) {
    $sel_mb_group = (int)($_GET['mb_group'] ?? 0); // 0=전체
} else { // 레벨7은 본인 그룹만(=본인 mb_no가 그룹ID)
    $sel_mb_group = $my_mb_no;
}

// -------------------------------------------
// 그룹 선택 옵션(레벨8+만)
// -------------------------------------------
$group_options = [];
if ($my_level >= 8) {
    // 그룹장(레벨7)의 리스트를 기준으로 그룹 목록 구성
    $sql = "
      SELECT m.mb_no AS mb_group, 
             COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹-', m.mb_no)) AS mb_group_name
      FROM {$g5['member_table']} m
      WHERE m.mb_level = 7
      ORDER BY mb_group_name ASC, m.mb_no ASC
    ";
    $res = sql_query($sql);
    while ($row = sql_fetch_array($res)) $group_options[] = $row;
}

// -------------------------------------------
// 검색 조건
// - 레벨7: 자신의 그룹만 (mb_group = 내 mb_no) + 본인도 포함
// - 레벨8: mb_group 선택 시 해당 그룹, 미선택이면 전체
// - 기본은 차단/탈퇴 숨김, include_blocked=1 일 때 모두 표시
// -------------------------------------------
$sql_common = " FROM {$g5['member_table']} m ";

$where = [];
$where[] = "1";

if ($my_level >= 8) {
    if ($sel_mb_group > 0) {
        $where[] = "m.mb_group = '{$sel_mb_group}'";
    }
} else {
    // 내 그룹만: 내 mb_no가 그룹ID
    $where[] = "(m.mb_group = '{$sel_mb_group}' OR m.mb_no = '{$my_mb_no}')";
}

if (!$include_blocked) {
    $where[] = "IFNULL(m.mb_leave_date,'') = ''";
    $where[] = "IFNULL(m.mb_intercept_date,'') = ''";
}

if ($stx !== '') {
    $safe = sql_escape_string($stx);
    switch ($sfl) {
        case 'mb_id':
        case 'mb_name':
        case 'mb_group_name':
            $where[] = "m.{$sfl} LIKE '%{$safe}%'";
            break;
        default:
            $where[] = "(m.mb_name LIKE '%{$safe}%' OR m.mb_id LIKE '%{$safe}%')";
            break;
    }
}
$where[] = "(m.mb_level < 10)";

$sql_search = ' WHERE '.implode(' AND ', $where);

// -------------------------------------------
// 정렬/페이징
// -------------------------------------------
$sst = $_GET['sst'] ?? 'm.mb_datetime';
$sod = strtolower($_GET['sod'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$allowed_sort = ['m.mb_datetime','m.mb_today_login','m.mb_name','m.mb_id','m.mb_group_name'];
if (!in_array($sst, $allowed_sort, true)) $sst = 'm.mb_datetime';
$sql_order = " ORDER BY {$sst} {$sod} ";

$row = sql_fetch("SELECT COUNT(*) AS cnt {$sql_common} {$sql_search}");
$total_count = (int)$row['cnt'];

$rows = $config['cf_page_rows'];
$total_page  = $rows ? (int)ceil($total_count / $rows) : 1;
$from_record = ($page - 1) * $rows;

// -------------------------------------------
// 목록 조회
// -------------------------------------------
$sql = "
  SELECT 
    m.mb_no, m.mb_id, m.mb_name, m.mb_level,
    m.mb_group, COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹-', m.mb_group)) AS org_name,
    m.mb_datetime, m.mb_today_login,
    m.mb_leave_date, m.mb_intercept_date
  {$sql_common}
  {$sql_search}
  {$sql_order}
  LIMIT {$from_record}, {$rows}
";
$result = sql_query($sql);

// -------------------------------------------
include_once (G5_ADMIN_PATH.'/admin.head.php');

$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
$colspan = 8; // 조직명 / 이름 / 아이디 / 상태 / 등록일 / 최종접속일 / 수정 / 차단
?>

<div class="local_ov">
    <?php echo $listall; ?>
    <span class="btn_ov01">
        <span class="ov_txt">총 회원</span>
        <span class="ov_num"> <?php echo number_format($total_count); ?> 명</span>
    </span>
</div>

<form id="fsearch" name="fsearch" class="local_sch01 local_sch" method="get">
    <?php if ($my_level >= 8) { ?>
        <select name="mb_group" title="그룹선택">
            <option value="0"<?php echo get_selected($sel_mb_group, 0); ?>>-- 전체 그룹 --</option>
            <?php foreach ($group_options as $g) { ?>
                <option value="<?php echo (int)$g['mb_group']; ?>" <?php echo get_selected($sel_mb_group, (int)$g['mb_group']); ?>>
                    <?php echo get_text($g['mb_group_name']); ?> (<?php echo (int)$g['mb_group']; ?>)
                </option>
            <?php } ?>
        </select>
    <?php } else { ?>
        <input type="hidden" name="mb_group" value="<?php echo (int)$sel_mb_group; ?>">
    <?php } ?>

    <label for="include_blocked" style="margin-left:10px;">
        <input type="checkbox" name="include_blocked" id="include_blocked" value="1" <?php echo $include_blocked?'checked':''; ?>>
        차단/탈퇴 포함
    </label>

    <select name="sfl" title="검색대상" style="margin-left:10px;">
        <option value="mb_name"<?php echo get_selected($sfl, 'mb_name'); ?>>이름</option>
        <option value="mb_id"<?php echo get_selected($sfl, 'mb_id'); ?>>아이디</option>
        <option value="mb_group_name"<?php echo get_selected($sfl, 'mb_group_name'); ?>>조직명</option>
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
                <?php if($member['mb_level'] >= 8) { ?>
                <th scope="col">권한</th>
                <?php } ?>
                <th scope="col">조직명</th>
                <th scope="col"><?php echo subject_sort_link('m.mb_name', "mb_group={$sel_mb_group}&include_blocked={$include_blocked}&sfl={$sfl}&stx={$stx}"); ?>이름</a></th>
                <th scope="col"><?php echo subject_sort_link('m.mb_id', "mb_group={$sel_mb_group}&include_blocked={$include_blocked}&sfl={$sfl}&stx={$stx}"); ?>아이디</a></th>
                <th scope="col">상태</th>
                <th scope="col"><?php echo subject_sort_link('m.mb_datetime', "mb_group={$sel_mb_group}&include_blocked={$include_blocked}&sfl={$sfl}&stx={$stx}"); ?>등록일</a></th>
                <th scope="col"><?php echo subject_sort_link('m.mb_today_login', "mb_group={$sel_mb_group}&include_blocked={$include_blocked}&sfl={$sfl}&stx={$stx}"); ?>최종접속일</a></th>
                <th scope="col">수정</th>
                <th scope="col">차단</th>
            </tr>
        </thead>
        <tbody>
            <?php
            for ($i=0; $row = sql_fetch_array($result); $i++) {
                $bg = 'bg'.($i%2);
                // 상태 표시
                $status_label = '정상';
                if (!empty($row['mb_leave_date'])) {
                    $status_label = '<span class="mb_leave_msg">탈퇴</span>';
                } elseif (!empty($row['mb_intercept_date'])) {
                    $status_label = '<span class="mb_intercept_msg">차단</span>';
                }
                $level_name = '회원';
                if($row['mb_level'] == 7) $level_name = '조직장';
                else if($row['mb_level'] == 8) $level_name = '관리자';
                // 날짜 포맷
                $reg_date = $row['mb_datetime'] ? substr($row['mb_datetime'], 0, 10) : '';
                $last_login = $row['mb_today_login'] ? substr($row['mb_today_login'], 0, 10) : '';
                ?>
                <tr class="<?php echo $bg; ?>">
                    <?php if($member['mb_level'] >= 8) { ?>                
                    <td class="td_left"><?php echo $level_name; ?></td>
                    <?php } ?>
                    <td class="td_left"><?php echo get_text($row['org_name']); ?></td>
                    <td class="td_mbname"><?php echo get_text($row['mb_name']); ?><?php echo ($row['mb_level']==7?' <span class="sound_only"></span>':'' ); ?></td>
                    <td class="td_left"><?php echo get_text($row['mb_id']); ?></td>
                    <td class="td_mbstat"><?php echo $status_label; ?></td>
                    <td class="td_datetime"><?php echo $reg_date; ?></td>
                    <td class="td_datetime"><?php echo $last_login; ?></td>
                    <td class="td_mng td_mng_s">
                        <a href="./call_member_form.php?w=u&amp;mb_id=<?php echo urlencode($row['mb_id']); ?>" class="btn btn_03">수정</a>
                    </td>
                    <td class="td_mng td_mng_s">
                        <?php if (empty($row['mb_leave_date'])) { ?>
                            <?php if (empty($row['mb_intercept_date'])) { ?>
                                <a href="./call_member_block.php?action=block&amp;mb_id=<?php echo urlencode($row['mb_id']); ?>&amp;<?php echo http_build_query(['_ret'=>$_SERVER['REQUEST_URI']]); ?>" class="btn btn_02" onclick="return confirm('해당 회원을 차단하시겠습니까?');">차단</a>
                            <?php } else { ?>
                                <a href="./call_member_block.php?action=unblock&amp;mb_id=<?php echo urlencode($row['mb_id']); ?>&amp;<?php echo http_build_query(['_ret'=>$_SERVER['REQUEST_URI']]); ?>" class="btn btn_02" onclick="return confirm('해당 회원의 차단을 해제하시겠습니까?');">해제</a>
                            <?php } ?>
                        <?php } ?>
                    </td>
                </tr>
                <?php
            }
            if ($i===0) {
                echo '<tr><td colspan="'.$colspan.'" class="empty_table">자료가 없습니다.</td></tr>';
            }
            ?>
        </tbody>
    </table>
</div>

<div class="btn_fixed_top">
    <a href="./call_member_form.php" class="btn btn_01">회원추가</a>
</div>

<?php
// 페이징
$qstr = http_build_query([
    'mb_group'=>$sel_mb_group,
    'include_blocked'=>$include_blocked,
    'sfl'=>$sfl,
    'stx'=>$stx,
    'sst'=>$sst,
    'sod'=>$sod
]);
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?{$qstr}&amp;page=");
?>

<?php include_once (G5_ADMIN_PATH.'/admin.tail.php'); ?>
