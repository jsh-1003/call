<?php
// /adm/call/call_blacklist.php
$sub_menu = '700500'; // 메뉴코드는 프로젝트에 맞게 조정
require_once './_common.php';

// -------------------------------------------
// 접근 권한: 7레벨 미만 차단
// -------------------------------------------
if ((int)$member['mb_level'] < 5) {
    alert('접근 권한이 없습니다.');
}

// CSRF
if (!isset($_SESSION['chk_token'])) {
    $_SESSION['chk_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['chk_token'];

// -------------------------------------------
// 현재 사용자/권한/조직
// -------------------------------------------
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id = isset($member['company_id']) ? (int)$member['company_id'] : 0;

// -------------------------------------------
// 입력/검색 파라미터
// -------------------------------------------
$mode = isset($_POST['mode']) ? trim($_POST['mode']) : ''; // add | del
if ($mode === '') $mode = isset($_GET['mode']) ? trim($_GET['mode']) : '';

$q         = _g('q', '');
$q_type    = _g('q_type', 'last4'); // last4 | full | reason
$page      = max(1, (int)_g('page','1'));
$rows      = max(10, min(200, (int)_g('rows','50')));
$offset    = ($page-1) * $rows;

// 조직 필터 (회사/지점)
if ($mb_level >= 9) {
    $sel_company_id = (int)_g('company_id', 0); // 0=전체
    $sel_mb_group   = (int)_g('mb_group', 0);   // 0=전체
} elseif ($mb_level >= 8) {
    $sel_company_id = $my_company_id;           // 고정
    $sel_mb_group   = (int)_g('mb_group', 0);   // 0=회사 내 전체
} else {
    // 7레벨: 자기 지점만
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

// -------------------------------------------
// 등록/삭제 처리 (POST)
// -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        alert('유효하지 않은 요청입니다.(CSRF)');
    }

    if ($mode === 'add') {
        // 파라미터
        $in_hp_raw   = isset($_POST['call_hp']) ? $_POST['call_hp'] : '';
        $in_reason   = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        $in_memo     = isset($_POST['memo']) ? trim($_POST['memo']) : '';
        $in_group    = $mb_level >= 8 ? (int)($_POST['mb_group'] ?? 0) : $my_group;
        // 9레벨만 임의 회사 선택 가능, 그 외는 내 회사 고정
        $in_company  = $mb_level >= 9 ? (int)($_POST['company_id'] ?? $my_company_id) : $my_company_id;

        // 전화번호 정규화/검증
        $hp = preg_replace('/\D+/', '', $in_hp_raw);
        if (!preg_match('/^[0-9]{10,12}$/', $hp)) {
            alert('전화번호는 숫자만 10~12자리여야 합니다.');
        }
        if ($in_company <= 0) {
            alert('회사 정보가 올바르지 않습니다.');
        }
        // 7레벨 사용자는 자기 지점 외 등록 금지
        if ($mb_level == 7 && $in_group !== $my_group) {
            alert('자기 지점만 등록할 수 있습니다.');
        }
        // 8레벨 사용자는 자사 소속 지점만 허용
        if ($mb_level == 8 && $in_group > 0) {
            // 지점이 자사 소속인지 검증
            $gr = sql_fetch("SELECT COUNT(*) AS cnt FROM {$g5['member_table']} m WHERE m.mb_no=".(int)$in_group." AND m.mb_level=7 AND m.company_id=".(int)$my_company_id);
            if ((int)($gr['cnt'] ?? 0) === 0) alert('해당 지점은 귀사의 소속이 아닙니다.');
        }

        // INSERT
        $sql = "INSERT INTO call_blacklist
                (company_id, mb_group, call_hp, reason, memo, created_by, created_at)
                VALUES
                ('".(int)$in_company."', '".(int)$in_group."', '".sql_escape_string($hp)."',
                 '".sql_escape_string($in_reason)."', '".sql_escape_string($in_memo)."',
                 '".(int)$mb_no."', NOW())";
        $ok = sql_query($sql, false);
        if (!$ok) {
            // 유니크 충돌 등 처리
            if (mysqli_errno($g5['connect_db']) == 1062) {
                alert('이미 회사 블랙리스트에 등록된 번호입니다.');
            }
            alert('등록 중 오류가 발생했습니다.');
        }
        goto_url('./call_blacklist.php?'.http_build_query([
            'company_id' => $sel_company_id,
            'mb_group'   => $sel_mb_group,
            'q'          => $q,
            'q_type'     => $q_type,
            'page'       => $page,
            'rows'       => $rows,
        ]));
        exit;

    } elseif ($mode === 'del') {
        $bid = (int)($_POST['blacklist_id'] ?? 0);
        if ($bid <= 0) alert('삭제 대상이 올바르지 않습니다.');

        // 소유/권한 확인: 9레벨은 무조건 가능 / 8레벨은 자사 / 7레벨은 자신의 지점 등록건만
        $row = sql_fetch("SELECT blacklist_id, company_id, mb_group FROM call_blacklist WHERE blacklist_id=".(int)$bid);
        if (!$row) alert('대상을 찾을 수 없습니다.');
        $target_company = (int)$row['company_id'];
        $target_group   = (int)$row['mb_group'];

        $allowed = false;
        if ($mb_level >= 9) $allowed = true;
        elseif ($mb_level == 8) $allowed = ($target_company === $my_company_id);
        elseif ($mb_level == 7) $allowed = ($target_group === $my_group);

        if (!$allowed) alert('삭제 권한이 없습니다.');

        sql_query("DELETE FROM call_blacklist WHERE blacklist_id=".(int)$bid." LIMIT 1");
        goto_url('./call_blacklist.php?'.http_build_query([
            'company_id' => $sel_company_id,
            'mb_group'   => $sel_mb_group,
            'q'          => $q,
            'q_type'     => $q_type,
            'page'       => $page,
            'rows'       => $rows,
        ]));
        exit;
    }
}

// -------------------------------------------
// WHERE 구성 (권한 + 조직 필터 + 검색)
// -------------------------------------------
$where = [];

// 권한 기본 범위
if ($mb_level >= 9) {
    // 별도 제한 없음 (필터에서 제한)
} elseif ($mb_level == 8) {
    $where[] = "b.company_id = {$my_company_id}";
} else { // 7레벨
    $where[] = "b.mb_group = {$my_group}";
}

// 조직 필터
if ($mb_level >= 8) {
    if ($sel_mb_group > 0) {
        $where[] = "b.mb_group = {$sel_mb_group}";
    } else {
        if ($mb_level >= 9 && $sel_company_id > 0) {
            $where[] = "b.company_id = {$sel_company_id}";
        } elseif ($mb_level == 8) {
            // 회사 고정이므로, sel_mb_group=0이면 자사 전체
            $where[] = "b.company_id = {$my_company_id}";
        }
    }
}

// 검색
if ($q !== '' && $q_type !== '') {
    if ($q_type === 'last4') {
        $last4 = substr(preg_replace('/\D+/', '', $q), -4);
        if ($last4 !== '') {
            $l4 = sql_escape_string($last4);
            $where[] = "b.hp_last4 = '{$l4}'";
        }
    } elseif ($q_type === 'full') {
        $full = preg_replace('/\D+/', '', $q);
        if ($full !== '') {
            $full_esc = sql_escape_string($full);
            $where[] = "b.call_hp = '{$full_esc}'";
        }
    } elseif ($q_type === 'reason') {
        $q_esc = sql_escape_string($q);
        $where[] = "b.reason LIKE '%{$q_esc}%'";
    }
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// -------------------------------------------
// 카운트
// -------------------------------------------
$sql_cnt = "SELECT COUNT(*) AS cnt FROM call_blacklist b {$where_sql}";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// -------------------------------------------
// 목록
// -------------------------------------------
$sql_list = "
  SELECT b.blacklist_id, b.company_id, b.mb_group, b.call_hp, b.hp_last4,
         b.reason, b.memo, b.created_by, b.created_at
    FROM call_blacklist b
    {$where_sql}
   ORDER BY b.blacklist_id DESC
   LIMIT {$offset}, {$rows}
";
$res = sql_query($sql_list);

// -------------------------------------------
// 드롭다운 옵션 (회사/지점)
// -------------------------------------------
$build_org_select_options = build_org_select_options($sel_company_id, $sel_mb_group);
$company_options = $build_org_select_options['company_options'];
$group_options   = $build_org_select_options['group_options'];

// 엑셀 링크 (현재 조건 유지)
$__q = $_GET;
$__q['mode'] = 'screen';    $href_xls_screen    = './call_blacklist_excel.php?'.http_build_query($__q);
$__q['mode'] = 'condition'; $href_xls_condition = './call_blacklist_excel.php?'.http_build_query($__q);
$__q['mode'] = 'all';       $href_xls_all       = './call_blacklist_excel.php?'.http_build_query($__q);

// 화면 출력
$g5['title'] = '블랙리스트';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.tbl_head01 th, .tbl_head01 td { text-align:center; vertical-align:middle; }
.small-muted { color:#888; font-size:12px; }
.badge-del { background:#dc3545; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px; }
</style>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01">
        <span class="ov_txt">전체 </span>
        <span class="ov_num"> <?php echo number_format($total_count) ?> 개</span>
    </span>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./call_blacklist.php" class="form-row" autocomplete="off">
        <?php if ($mb_level >= 9) { ?>
            <select name="company_id" id="company_id">
                <option value="0"<?php echo $sel_company_id===0?' selected':'';?>>전체 회사</option>
                <?php if($is_admin_pay) { ?>
                <option value="1"<?php echo $sel_company_id===1?' selected':'';?>>::::: 유료DB공통 :::::</option>
                <?php } ?>
                <?php foreach ($company_options as $c) { ?>
                    <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                        <?php echo get_text($c['company_name']); ?> (지점 <?php echo (int)$c['group_count']; ?>)
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="company_id" id="company_id" value="<?php echo (int)$sel_company_id; ?>">
        <?php } ?>

        <?php if ($mb_level >= 8) { ?>
            <select name="mb_group" id="mb_group">
                <option value="0"<?php echo $sel_mb_group===0?' selected':'';?>>전체 지점</option>
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
            <option value="last4"  <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"   <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
            <option value="reason" <?php echo $q_type==='reason'?'selected':'';?>>사유</option>
        </select>
        <input type="text" name="q" value="<?php echo _h($q);?>" class="frm_input" style="width:240px" placeholder="검색어 입력">

        <label for="rows">표시건수</label>
        <select name="rows" id="rows">
            <?php foreach ([20,50,100,200] as $opt){ ?>
                <option value="<?php echo $opt;?>" <?php echo $rows==$opt?'selected':'';?>><?php echo $opt;?></option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql){ ?><a href="./call_blacklist.php" class="btn btn_02">초기화</a><?php } ?>

        <span class="small-muted" style="margin-left:auto">
        권한:
        <?php
            if     ($mb_level >= 9) echo '전사 조회/관리(최고관리자)';
            elseif ($mb_level >= 8) echo '회사 조회/관리';
            else                    echo '지점 제한(등록/삭제는 자기 지점)';
        ?>
        </span>
    </form>
</div>

<?php if ($mb_level >= 7) { ?>
<!-- 등록 폼 -->
<div class="local_sch01 local_sch">
    <form method="post" action="./call_blacklist.php" class="form-row" autocomplete="off" onsubmit="return confirm('블랙리스트로 등록하시겠습니까?');">
        <input type="hidden" name="mode" value="add">
        <input type="hidden" name="csrf_token" value="<?php echo _h($csrf_token);?>">

        <?php if ($mb_level >= 9) { ?>
            <select id="w_company_id" name="company_id" required>
                <option value="">회사 선택</option>
                <?php if($is_admin_pay) { ?>
                <option value="1"<?php echo $sel_company_id===1?' selected':'';?>>::::: 유료DB공통 :::::</option>
                <?php } ?>
                <?php foreach ($company_options as $c) { ?>
                    <option value="<?php echo (int)$c['company_id']; ?>"><?php echo get_text($c['company_name']); ?></option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="company_id" value="<?php echo (int)$my_company_id; ?>">
        <?php } ?>

        <?php if ($mb_level >= 8) { ?>
            <select id="w_mb_group" name="mb_group">
                <option value="0">등록 지점 선택(선택)</option>
                <?php
                if ($group_options) {
                    if ($mb_level >= 9 && $sel_company_id == 0) {
                        $last_cid = null;
                        foreach ($group_options as $g) {
                            if ($last_cid !== (int)$g['company_id']) {
                                echo '<option value="" disabled>── '.get_text($g['company_name']).' ──</option>';
                                $last_cid = (int)$g['company_id'];
                            }
                            echo '<option value="'.(int)$g['mb_group'].'">'.get_text($g['mb_group_name']).'</option>';
                        }
                    } else {
                        foreach ($group_options as $g) {
                            echo '<option value="'.(int)$g['mb_group'].'">'.get_text($g['mb_group_name']).'</option>';
                        }
                    }
                }
                ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="mb_group" value="<?php echo (int)$my_group; ?>">
        <?php } ?>

        <input type="text" name="call_hp" class="frm_input" style="width:200px" required placeholder="전화번호(숫자만 10~12자리)">
        <input type="text" name="reason" class="frm_input" style="width:260px" placeholder="사유(선택)">
        <input type="text" name="memo" class="frm_input" style="width:320px" placeholder="메모(선택)">
        <button type="submit" class="btn btn_02">블랙리스트 등록</button>
    </form>
</div>
<?php } ?>

<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <th style="width:50px">P_No.</th>
                <th style="width:100px">회사</th>
                <th style="width:120px">지점</th>
                <th style="width:160px">전화번호</th>
                <th>사유</th>
                <th style="width:100px">등록자</th>
                <th style="width:160px">등록일</th>
                <th style="width:90px">관리</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0){
            echo '<tr><td colspan="7" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            sql_data_seek($res, 0);
            $p_no = 0;
            while ($row = sql_fetch_array($res)) {
                $p_no++;
                $cid    = (int)$row['company_id'];
                $gid    = (int)$row['mb_group'];
                // 회사명은 지점을 통해 얻는 헬퍼가 있다면 사용
                // 지점이 0일 수도 있으니 fallback
                $cname  = $cid > 0 ? get_company_name_from_cached($cid) : ('회사ID '.$row['company_id']);
                $gname  = $gid > 0 ? get_group_name_cached($gid) : '-';
                $hp_fmt = _h(format_korean_phone($row['call_hp']));
                $creator= get_agent_name_cached((int)$row['created_by']) ?: ('#'.$row['created_by']);
                ?>
                <tr>
                    <td><?php echo $p_no; ?></td>
                    <td><?php echo _h($cname); ?></td>
                    <td><?php echo _h($gname); ?></td>
                    <td><?php echo $hp_fmt; ?></td>
                    <td style="text-align:left"><?php echo _h($row['reason']); ?> <?php if($row['memo']){ echo ' / <span class="small-muted">'. _h($row['memo']) .'</span>'; } ?></td>
                    <td><?php echo _h($creator); ?></td>
                    <td><?php echo substr(_h($row['created_at']), 2, 17);?></td>
                    <td>
                        <form method="post" action="./call_blacklist.php" onsubmit="return confirm('삭제하시겠습니까?');">
                            <input type="hidden" name="mode" value="del">
                            <input type="hidden" name="csrf_token" value="<?php echo _h($csrf_token);?>">
                            <input type="hidden" name="blacklist_id" value="<?php echo (int)$row['blacklist_id']; ?>">
                            <button type="submit" class="btn btn_01" style="background:#ef4444;border-color:#ef4444">삭제</button>
                        </form>
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
    <a href="<?php echo $href_xls_all;       ?>" class="btn btn_02">전체 엑셀다운</a>&nbsp;&nbsp;&nbsp;
    <a href="<?php echo $href_xls_condition; ?>" class="btn btn_02">현재조건 엑셀다운</a>&nbsp;&nbsp;&nbsp;
    <a href="<?php echo $href_xls_screen;    ?>" class="btn btn_02" style="background:#e5e7eb !important">현재화면 엑셀다운</a>
</div>

<?php
// 페이징
$total_page = max(1, (int)ceil($total_count / $rows));
$qstr_arr = $_GET; unset($qstr_arr['page']);
$qstr = http_build_query($qstr_arr);

echo '<div class="pg_wrap">';
echo get_paging($config['cf_write_pages'], $page, $total_page, "./call_blacklist.php?{$qstr}&amp;page=");
echo '</div>';
?>

<div class="local_sch01 local_sch">
    <form method="post" action="./call_blacklist_excel_update.php" class="form-row" enctype="multipart/form-data" onsubmit="return handleSubmit(this);">
        <input type="hidden" name="csrf_token" value="<?php echo _h($csrf_token);?>">
        <?php if ($mb_level >= 9) { ?>
            <select id="xls_company_id" name="company_id" required>
                <option value="">회사 선택(필수)</option>
                <?php if($is_admin_pay) { ?>
                <option value="1"<?php echo $sel_company_id===1?' selected':'';?>>::::: 유료DB공통 :::::</option>
                <?php } ?>
                <?php foreach ($company_options as $c) { ?>
                    <option value="<?php echo (int)$c['company_id']; ?>"><?php echo get_text($c['company_name']); ?></option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="company_id" value="<?php echo (int)$sel_company_id; ?>">
        <?php } ?>

        <?php if ($mb_level >= 8) { ?>
            <select id="xls_mb_group" name="mb_group">
                <option value="0">등록 지점 선택(선택)</option>
                <?php foreach ($group_options as $g) { ?>
                    <option value="<?php echo (int)$g['mb_group']; ?>"><?php echo get_text($g['mb_group_name']); ?></option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="mb_group" value="<?php echo (int)$sel_mb_group; ?>">
        <?php } ?>

        <input type="file" name="excel" accept=".xlsx,.xls,.csv" required style="padding:3px;border:1px solid var(--neutral-300);border-radius:5px;">
        <label><input type="checkbox" name="update_on_dup" value="1"> 중복 시 사유/메모 덮어쓰기</label>
        <button id="btn_submit" type="submit" class="btn btn_01">엑셀 업로드</button>
        <a href="./call_blacklist_excel.php?mode=template" class="btn btn_02" target="_blank">템플릿 다운로드</a>
    </form>
</div>
<script>
function handleSubmit(form) {
    if (!confirm('엑셀 업로드로 블랙리스트를 등록하시겠습니까?')) {
        return false;
    }

    const btn = form.querySelector('#btn_submit');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '업로드 중...';
    }
    return true; // 폼 제출 계속 진행
}
</script>
<?php if ($mb_level >= 9) { ?>
<script>
// 회사 변경 시 지점 셀렉트 갱신 (9레벨만)
(function(){
    var companySel = document.getElementById('company_id');
    var groupSel   = document.getElementById('mb_group');
    if (companySel && groupSel) {
        initCompanyGroupSelector(companySel, groupSel);
    }
    var wCompanySel = document.getElementById('w_company_id');
    var wGroupSel   = document.getElementById('w_mb_group');
    if (companySel && groupSel) {
        initCompanyGroupSelector(wCompanySel, wGroupSel);
    }
    var xlsCompanySel = document.getElementById('xls_company_id');
    var xlsGroupSel   = document.getElementById('xls_mb_group');
    if (companySel && groupSel) {
        initCompanyGroupSelector(xlsCompanySel, xlsGroupSel);
    }
})();
</script>
<?php } ?>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
