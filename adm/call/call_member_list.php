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
// 파라미터 / 내 정보
// -------------------------------------------
$sfl  = isset($_GET['sfl']) ? preg_replace('/[^a-z0-9_]/i','', $_GET['sfl']) : 'mb_name';
$stx  = isset($_GET['stx']) ? trim($_GET['stx']) : '';
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$include_blocked = (isset($_GET['include_blocked']) && $_GET['include_blocked'] == '1') ? 1 : 0;

$my_level        = (int)$member['mb_level'];
$my_mb_no        = (int)$member['mb_no'];
$my_company_id   = (int)($member['company_id'] ?? 0);
$my_company_name = (string)($member['company_name'] ?? '');

// 역할 라디오 필터
$role_filter = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : 'all';
$allowed_role_filters = ($my_level >= 9)
    ? ['all','company','leader','member']   // 9+: 회사관리자/그룹리더/상담원
    : (($my_level >= 8) ? ['all','leader','member'] : ['all']);
if (!in_array($role_filter, $allowed_role_filters, true)) $role_filter = 'all';

// -------------------------------------------
// 셀렉션 스코프
// -------------------------------------------
$sel_company_id = 0;
if ($my_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체 회사
} else {
    $sel_company_id = $my_company_id; // 8/7은 자기 회사 고정
}

if ($my_level >= 8) {
    $sel_mb_group = (int)($_GET['mb_group'] ?? 0); // 0=전체 그룹
} else {
    $sel_mb_group = $my_mb_no; // 7은 자기 그룹(본인 mb_no)
}

// -------------------------------------------
// 유틸: 역할명/배지
// -------------------------------------------
function role_label_and_class($lv){
    if ($lv >= 10) return ['플랫폼관리자','badge-admin'];
    if ($lv >= 8)  return ['회사관리자','badge-company'];
    if ($lv == 7)  return ['그룹리더','badge-leader'];
    return ['상담원','badge-member'];
}

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

// -------------------------------------------
// 검색 조건
// -------------------------------------------
$sql_common = " FROM {$g5['member_table']} m ";
$where = [];
$where[] = "1";
$where[] = "(m.mb_level < 9)"; // 플랫폼관리자 제외

if ($my_level == 7) {
    $where[] = "(m.mb_group = '{$sel_mb_group}' OR m.mb_no = '{$my_mb_no}')";
    $where[] = "m.company_id = '{$my_company_id}'";
} else if ($my_level == 8) {
    $where[] = "m.company_id = '{$my_company_id}'";
    if ($sel_mb_group > 0) $where[] = "m.mb_group = '{$sel_mb_group}'";
} else { // 9+
    if ($sel_company_id > 0) $where[] = "m.company_id = '{$sel_company_id}'";
    if ($sel_mb_group > 0)   $where[] = "m.mb_group = '{$sel_mb_group}'";
}

// 역할 라디오 필터
if ($my_level >= 8) {
    if ($role_filter === 'company' && $my_level >= 9) {
        // 회사관리자만 (레벨 8)
        $where[] = "(m.mb_level = 8)";
    } elseif ($role_filter === 'leader') {
        // 그룹리더만 (레벨 7)
        $where[] = "(m.mb_level = 7)";
    } elseif ($role_filter === 'member') {
        // 상담원만 (레벨 6 이하 가정)
        $where[] = "(m.mb_level <= 6)";
    }
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
        case 'company_name':
            $where[] = "m.{$sfl} LIKE '%{$safe}%'";
            break;
        default:
            $where[] = "(m.mb_name LIKE '%{$safe}%' OR m.mb_id LIKE '%{$safe}%')";
            break;
    }
}
$sql_search = ' WHERE '.implode(' AND ', $where);

// -------------------------------------------
// 정렬/페이징
// -------------------------------------------
$sst = $_GET['sst'] ?? 'm.mb_datetime';
$sod = strtolower($_GET['sod'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$allowed_sort = ['m.mb_datetime','m.mb_today_login','m.mb_name','m.mb_id','m.mb_group_name','m.company_name'];
if (!in_array($sst, $allowed_sort, true)) $sst = 'm.mb_datetime';
$sql_order = " ORDER BY {$sst} {$sod} ";

$row = sql_fetch("SELECT COUNT(*) AS cnt {$sql_common} {$sql_search}");
$total_count = (int)$row['cnt'];

$rows = $config['cf_page_rows'];
$total_page  = $rows ? (int)ceil($total_count / $rows) : 1;
$from_record = ($page - 1) * $rows;

// -------------------------------------------
// 목록 조회(회사/그룹 “표시용 이름”은 캐시 함수로 변환)
// -------------------------------------------
$sql = "
  SELECT 
    m.mb_no, m.mb_id, m.mb_name, m.mb_level,
    m.company_id, m.company_name,
    m.mb_group,  m.mb_group_name,
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

// 컬럼수: 권한(8+만 보임) + 회사 + 조직명 + 이름 + 아이디 + 상태 + 등록일 + 최종접속일 + 수정 + 차단
$colspan = ($my_level >= 8) ? 10 : 9;
?>
<style>
/* 회사별 구분선 option */
.opt-sep { color:#888; font-style:italic; }

/* 권한 배지 */
.badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; line-height:1.6; color:#fff; }
.badge-company { background:#2563eb; } /* 파랑: 회사관리자 */
.badge-leader  { background:#059669; } /* 초록: 그룹리더 */
.badge-member  { background:#6b7280; } /* 회색: 상담원 */
.badge-admin   { background:#7c3aed; } /* 보라: 플랫폼관리자(참고) */

/* 상태 라벨 */
.mb_leave_msg { color:#d14343; font-weight:600; }
.mb_intercept_msg { color:#b45309; font-weight:600; }

.role-radio label { margin-right:8px; }
</style>
<div class="local_ov">
    <?php echo $listall; ?>
    <span class="btn_ov01">
        <span class="ov_txt">총 회원</span>
        <span class="ov_num"> <?php echo number_format($total_count); ?> 명</span>
    </span>
</div>

<form id="fsearch" name="fsearch" class="local_sch01 local_sch" method="get">
    <?php if ($my_level >= 9) { ?>
        <label for="company_id">회사</label>
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

    <?php if ($my_level >= 8) { ?>
        <label for="mb_group">그룹선택</label>
        <select name="mb_group" id="mb_group">
            <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 그룹</option>
            <?php
            if ($group_options) {
                if ($my_level >= 9 && $sel_company_id == 0) {
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

    <?php if ($my_level >= 8) { ?>
        <!-- (NEW) 권한 라디오 필터 -->
        <span class="role-radio" style="margin-left:10px;">
            <label><input type="radio" name="role_filter" value="all" <?php echo $role_filter==='all'?'checked':''; ?>> 전체</label>
            <?php if ($my_level >= 9) { ?>
                <label><input type="radio" name="role_filter" value="company" <?php echo $role_filter==='company'?'checked':''; ?>> 회사관리자</label>
            <?php } ?>
            <label><input type="radio" name="role_filter" value="leader" <?php echo $role_filter==='leader'?'checked':''; ?>> 그룹리더</label>
            <label><input type="radio" name="role_filter" value="member" <?php echo $role_filter==='member'?'checked':''; ?>> 상담원</label>
        </span>
    <?php } else { ?>
        <input type="hidden" name="role_filter" value="all">
    <?php } ?>


    <label for="include_blocked" style="margin-left:10px;">
        <input type="checkbox" name="include_blocked" id="include_blocked" value="1" <?php echo $include_blocked?'checked':''; ?>>
        차단/탈퇴 포함
    </label>

    <select name="sfl" title="검색대상" style="margin-left:10px;">
        <option value="mb_name"<?php echo get_selected($sfl, 'mb_name'); ?>>이름</option>
        <option value="mb_id"<?php echo get_selected($sfl, 'mb_id'); ?>>아이디</option>
        <option value="mb_group_name"<?php echo get_selected($sfl, 'mb_group_name'); ?>>조직명</option>
        <option value="company_name"<?php echo get_selected($sfl, 'company_name'); ?>>회사명</option>
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
                <?php if($my_level >= 8) { ?>
                <th scope="col">권한</th>
                <?php } ?>
                <?php
                $qstr_member_list = "company_id={$sel_company_id}&mb_group={$sel_mb_group}&role_filter={$role_filter}&include_blocked={$include_blocked}&sfl={$sfl}&stx={$stx}";
                ?>
                <th scope="col"><?php echo subject_sort_link('m.company_name', $qstr_member_list); ?>회사</a></th>
                <th scope="col">조직명</th>
                <th scope="col"><?php echo subject_sort_link('m.mb_name', $qstr_member_list); ?>이름</a></th>
                <th scope="col"><?php echo subject_sort_link('m.mb_id', $qstr_member_list); ?>아이디</a></th>
                <th scope="col">상태</th>
                <th scope="col"><?php echo subject_sort_link('m.mb_datetime', $qstr_member_list); ?>등록일</a></th>
                <th scope="col"><?php echo subject_sort_link('m.mb_today_login', $qstr_member_list); ?>최종접속일</a></th>
                <th scope="col">수정</th>
                <th scope="col">차단</th>
            </tr>
        </thead>
        <tbody>
            <?php
            for ($i=0; $row = sql_fetch_array($result); $i++) {
                $bg = 'bg'.($i%2);

                // 상태
                $status_label = '정상';
                if (!empty($row['mb_leave_date'])) {
                    $status_label = '<span class="mb_leave_msg">탈퇴</span>';
                } elseif (!empty($row['mb_intercept_date'])) {
                    $status_label = '<span class="mb_intercept_msg">차단</span>';
                }

                // 권한 배지
                list($role_name, $role_class) = role_label_and_class((int)$row['mb_level']);

                // 표시용 회사/그룹 이름(정식 소유자 레코드 기준)
                $disp_company = get_company_name_cached((int)$row['company_id']);
                $disp_group   = get_group_name_cached((int)$row['mb_group']);

                // 날짜
                $reg_date   = $row['mb_datetime']     ? substr($row['mb_datetime'], 0, 10) : '';
                $last_login = $row['mb_today_login']  ? substr($row['mb_today_login'], 0, 10) : '';
                ?>
                <tr class="<?php echo $bg; ?>">
                    <?php if($my_level >= 8) { ?>
                    <td class=""><span class="badge <?php echo $role_class; ?>"><?php echo $role_name; ?></span></td>
                    <?php } ?>
                    <td class="td_left"><?php echo get_text($disp_company); ?></td>
                    <td class="td_left"><?php echo get_text($disp_group); ?></td>
                    <td class="td_mbname"><?php echo get_text($row['mb_name']); ?></td>
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
// $qstr = http_build_query([
//     'company_id'=>$sel_company_id,
//     'mb_group'=>$sel_mb_group,
//     'include_blocked'=>$include_blocked,
//     'sfl'=>$sfl,
//     'stx'=>$stx,
//     'sst'=>$sst,
//     'sod'=>$sod
// ]);
$qstr = "company_id={$sel_company_id}&mb_group={$sel_mb_group}&role_filter={$role_filter}&include_blocked={$include_blocked}&sfl={$sfl}&stx={$stx}&sod={$sod}";
echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, "{$_SERVER['SCRIPT_NAME']}?{$qstr}&amp;page=");
?>

<script>
// 회사→그룹 비동기(9+만)
var companySel = document.getElementById('company_id');
if (companySel) {
companySel.addEventListener('change', function(){
    var groupSel = document.getElementById('mb_group');
    if (!groupSel) return;
    groupSel.innerHTML = '<option value="">로딩 중...</option>';
    // 상담원 초기화
    var agent = document.getElementById('agent'); if (agent) agent.selectedIndex = 0;

    fetch('./ajax_group_options.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({ company_id: parseInt(this.value||'0',10)||0 }),
    credentials: 'same-origin'
    })
    .then(function(res){ if(!res.ok) throw new Error('네트워크 오류'); return res.json(); })
    .then(function(json){
    if (!json.success) throw new Error(json.message || '가져오기 실패');
    var opts = [];
    opts.push(new Option('전체 그룹', 0));
    json.items.forEach(function(item){
        if (item.separator) {
        var sep = document.createElement('option');
        sep.textContent = '── ' + item.separator + ' ──';
        sep.disabled = true;
        opts.push(sep);
        } else {
        opts.push(new Option(item.label, item.value));
        }
    });
    groupSel.innerHTML = '';
    opts.forEach(function(o){ groupSel.appendChild(o); });
    groupSel.value = '0'; // 회사 변경 시 전체 그룹 유지
    })
    .catch(function(err){
    alert('그룹 목록을 불러오지 못했습니다: ' + err.message);
    groupSel.innerHTML = '<option value="0">전체 그룹</option>';
    });
});
}
</script>
<?php include_once (G5_ADMIN_PATH.'/admin.tail.php'); ?>
