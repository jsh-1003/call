<?php
// /adm/call/call_target_excel.php
$sub_menu = '700700';
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, "w");

$g5['title'] = '엑셀 등록';
$is_popup_page = true;
include_once(G5_ADMIN_PATH.'/admin.head.php');

// -----------------------------
// 접근 제어: 레벨 7 미만 금지
// -----------------------------
$my_level       = (int)($member['mb_level'] ?? 0);
$my_mb_no       = (int)($member['mb_no'] ?? 0);
$my_company_id  = (int)($member['company_id'] ?? 0);
$my_group       = (int)($member['mb_group'] ?? 0);

if ($my_level < 7) {
    alert('접근 권한이 없습니다.');
    exit;
}

// -----------------------------
// CSRF
// -----------------------------
if (!isset($_SESSION['call_upload_token'])) {
    $_SESSION['call_upload_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['call_upload_token'];

// -----------------------------
// 선택값 초기화
// -----------------------------
$req_company_id = isset($_REQUEST['company_id']) ? (int)$_REQUEST['company_id'] : null;
$req_mb_group   = isset($_REQUEST['mb_group'])   ? (int)$_REQUEST['mb_group']   : null;

if ($my_level >= 9) {
    $sel_company_id = is_null($req_company_id) ? 0 : max(0, $req_company_id); // 0=전체
    $sel_mb_group   = is_null($req_mb_group)   ? 0 : max(0, $req_mb_group);   // 0=전체
} elseif ($my_level >= 8) {
    $sel_company_id = $my_company_id; // 고정
    $sel_mb_group   = is_null($req_mb_group) ? 0 : max(0, $req_mb_group); // 회사 내 전체 기본
} else {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

// -------------------------------------------
// 회사 옵션(레벨9+만)
// -------------------------------------------
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

// -------------------------------------------
// 초기 그룹 옵션(첫 렌더용, 이후 변경은 AJAX)
// -------------------------------------------
$group_options = [];
if ($my_level >= 8) {
    $where = " WHERE m.mb_level = 7 ";
    if ($my_level >= 9) {
        if ($sel_company_id > 0) {
            $where .= " AND m.company_id = '{$sel_company_id}' ";
        } // 0이면 전체 회사
    } else {
        $where .= " AND m.company_id = '{$my_company_id}' ";
    }

    $sql_groups = "
        SELECT m.mb_no AS mb_group, m.company_id
        FROM {$g5['member_table']} m
        {$where}
        ORDER BY m.company_id ASC,
                 COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹-', m.mb_no)) ASC,
                 m.mb_no ASC
    ";
    $res = sql_query($sql_groups);
    while ($r = sql_fetch_array($res)) {
        $gid   = (int)$r['mb_group'];
        $cid   = (int)$r['company_id'];
        $gname = get_group_name_cached($gid);
        $cname = get_company_name_cached($cid);
        $mcnt  = count_members_by_group_cached($gid);
        $group_options[] = [
            'mb_group'      => $gid,
            'company_id'    => $cid,
            'company_name'  => $cname,
            'mb_group_name' => $gname,
            'member_count'  => $mcnt,
        ];
    }
}
?>
<style>
.input_wrap{margin:10px;padding:20px;border:1px solid #e9e9e9;background:#fff}
#memo{width:80%}
select[disabled]{background:#f7f7f7;color:#999}
.opt-sep{font-weight:bold;color:#666}
.tbl_frm01 table{width:100%}
.tbl_frm01 th{width:180px;text-align:center;padding:8px 0}
.tbl_frm01 td{padding:8px 0}
#excelfile_upload{margin:15px 0}
</style>

<div class="new_win">
    <h1><?php echo $g5['title']; ?></h1>

    <div class="local_desc01 local_desc">
        <p>
            엑셀파일을 업로드하면 <strong>파일명으로 캠페인을 자동 생성</strong>하고,<br>
            1행은 헤더로 사용하며 <strong>이름 / 전화번호 / 생년월일</strong>은 기본 컬럼, 그 외 열은 <code>추가정보</code>로 묶어 저장합니다.
        </p>
        <p>지원 형식: <strong>*.xls / *.xlsx</strong></p>
    </div>

    <form name="fcallexcel" id="fcallexcel" method="post" action="./call_target_excel_update.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="token" value="">

        <div class="tbl_frm01">
            <table>
                <?php if ($my_level >= 9) { ?>
                    <tr>
                        <th scope="row">회사 선택</th>
                        <td>
                            <!-- 폼 자동전송 제거, 비동기 갱신 -->
                            <select name="company_id" id="company_id" title="회사선택" required>
                                <option value="0"<?php echo get_selected($sel_company_id, 0); ?>>-- 전체 회사 --</option>
                                <?php foreach ($company_options as $c) { ?>
                                    <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                                        <?php echo get_text($c['company_name']); ?> (그룹 <?php echo (int)$c['group_count']; ?>)
                                    </option>
                                <?php } ?>
                            </select>
                            <span class="frm_info">회사 변경 시 아래 그룹 목록이 갱신됩니다.</span>
                        </td>
                    </tr>
                <?php } else { ?>
                    <input type="hidden" name="company_id" id="company_id" value="<?php echo (int)$sel_company_id; ?>">
                <?php } ?>

                <?php if ($my_level >= 8) { ?>
                    <tr>
                        <th scope="row">그룹 선택</th>
                        <td>
                            <select name="mb_group" id="mb_group" title="그룹선택" class="required" required>
                                <option value="0"<?php echo get_selected($sel_mb_group, 0); ?>>-- 전체 그룹 --</option>
                                <?php
                                if ($my_level >= 9) {
                                    $last_cid = null;
                                    foreach ($group_options as $g) {
                                        if ($last_cid !== (int)$g['company_id']) {
                                            echo '<option value="" disabled class="opt-sep">── '.get_text($g['company_name']).' ──</option>';
                                            $last_cid = (int)$g['company_id'];
                                        }
                                        echo '<option value="'.(int)$g['mb_group'].'" '.get_selected($sel_mb_group, (int)$g['mb_group']).'>'
                                           . get_text($g['mb_group_name']).' (상담원 '.(int)$g['member_count'].')</option>';
                                    }
                                } else {
                                    foreach ($group_options as $g) {
                                        echo '<option value="'.(int)$g['mb_group'].'" '.get_selected($sel_mb_group, (int)$g['mb_group']).'>'
                                           . get_text($g['mb_group_name']).' (상담원 '.(int)$g['member_count'].')</option>';
                                    }
                                }
                                ?>
                            </select>
                            <?php if ($my_level == 8) { ?>
                                <span class="frm_info">기본값은 회사 내 전체 그룹입니다.</span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } else { ?>
                    <input type="hidden" name="mb_group" id="mb_group" value="<?php echo (int)$sel_mb_group; ?>">
                <?php } ?>
            </table>
        </div>

        <div id="excelfile_upload">
            <label for="excelfile"><b>파일선택</b></label>
            <input type="file" name="excelfile" id="excelfile" accept=".xls,.xlsx" required>
        </div>
        <!-- is_open_number 전달 규격 -->
        <?php if ($my_level >= 9) { ?>
            <div class="input_wrap">
                <label for="is_open_number0">
                    <input type="checkbox"
                        id="is_open_number0"
                        name="is_open_number0"
                        value="0"
                        checked>
                    <b>1차 비공개</b>
                </label>
                <p class="frm_info" style="padding-top:5px">체크 시 2차 콜에서만 번호 공개합니다. - 기능 작업전입니다(현재는 전체 공개)</p>
            </div>
        <?php } else { ?>
            <!-- 레벨9 미만은 무조건 공개(=1) -->
            <input type="hidden" name="is_open_number_force" value="1">
        <?php } ?>

        <div class="input_wrap">
            <label for="memo"><b>DB메모</b></label>
            <input type="text" class="frm_input" name="memo" id="memo" placeholder="필요시 메모를 입력하세요.">
        </div>

        <div class="win_btn btn_confirm">
            <input type="submit" value="타겟 엑셀파일 등록" class="btn_submit btn">
            <button type="button" onclick="window.close();" class="btn_close btn">닫기</button>
        </div>
    </form>
</div>

<script>
(function(){
    // 권한 9+에서만 회사 변경 핸들링
    var companySel = document.getElementById('company_id');
    var groupSel   = document.getElementById('mb_group');
    if (!companySel || !groupSel) return;

    companySel.addEventListener('change', function(){
        var companyId = this.value || 0;

        // 로딩 표시
        groupSel.innerHTML = '<option value="">로딩 중...</option>';

        fetch('./ajax_group_options.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?php echo $csrf_token; ?>'
            },
            body: JSON.stringify({
                company_id: parseInt(companyId, 10) || 0
            }),
            credentials: 'same-origin'
        })
        .then(function(res){
            if(!res.ok) throw new Error('네트워크 오류');
            return res.json();
        })
        .then(function(json){
            if (!json.success) {
                throw new Error(json.message || '가져오기 실패');
            }
            // 옵션 렌더링
            var opts = [];
            // 항상 맨 위에 전체 옵션
            opts.push(new Option('-- 전체 그룹 --', 0));

            // separator/option 혼합 렌더
            json.items.forEach(function(item){
                if (item.separator) {
                    var sep = document.createElement('option');
                    sep.textContent = '── ' + item.separator + ' ──';
                    sep.disabled = true;
                    sep.className = 'opt-sep';
                    opts.push(sep);
                } else {
                    var label = item.label;
                    var opt = new Option(label, item.value);
                    opts.push(opt);
                }
            });

            groupSel.innerHTML = '';
            opts.forEach(function(o){ groupSel.appendChild(o); });

            // 선택값 유지가 필요하면 여기서 setSelected 로직 추가
            groupSel.value = '0';
        })
        .catch(function(err){
            alert('그룹 목록을 불러오지 못했습니다: ' + err.message);
            groupSel.innerHTML = '<option value="0">-- 전체 그룹 --</option>';
        });
    });
})();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
