<?php
// /adm/call_target_excel.php
$sub_menu = '700700'; // 필요에 맞게 메뉴코드 부여
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, "w");

$g5['title'] = '엑셀 등록';
$is_popup_page=true;
include_once (G5_ADMIN_PATH.'/admin.head.php');

// CSRF
if (!isset($_SESSION['call_upload_token'])) {
    $_SESSION['call_upload_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['call_upload_token'];

// 현재 사용자
$my_level = (int)$member['mb_level'];
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;

// 레벨8+: 그룹 선택(그룹명)
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
?>
<style>
.input_wrap {
    margin: 10px;
    padding: 20px;
    border: 1px solid #e9e9e9;
    background: #fff;
}
#memo {width:80%}
</style>
<div class="new_win">
    <h1><?php echo $g5['title']; ?></h1>

    <div class="local_desc01 local_desc">
        <p>
            엑셀파일을 업로드하면 <strong>파일명으로 캠페인을 자동 생성</strong>하고,<br>
            1행은 헤더로 사용하며 <strong>이름 / 전화번호 / 생년월일</strong>은 기본 컬럼, 그 외 열은 <code>추가정보</code>로 묶어 저장합니다.
        </p>
        <p>
            엑셀은 <strong>*.xls / *.xlsx</strong>를 지원합니다.
        </p>
    </div>

    <form name="fcallexcel" method="post" action="./call_target_excel_update.php" enctype="MULTIPART/FORM-DATA" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="token" value="">

        <?php if ($my_level >= 8) { ?>
        <div id="mb_group_select" class="tbl_frm01">
            <table>
                <tr>
                    <th scope="row">그룹 선택 (mb_group_name)</th>
                    <td>
                        <select name="mb_group" required>
                            <option value="">-- 그룹 선택 --</option>
                            <?php foreach ($group_options as $g) { ?>
                                <option value="<?php echo (int)$g['mb_group']; ?>">
                                    <?php echo get_text($g['mb_group_name']); ?> (<?php echo (int)$g['mb_group']; ?>)
                                </option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <?php } else { ?>
            <input type="hidden" name="mb_group" value="<?php echo (int)$my_group; ?>">
        <?php } ?>

        <div id="excelfile_upload">
            <label for="excelfile">파일선택</label>
            <input type="file" name="excelfile" id="excelfile" accept=".xls,.xlsx" required>
        </div>

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

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
