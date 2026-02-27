<?php
// /paid/paid_target_excel.php
$sub_menu = '200760';
include_once('./_common.php');

// 1) 권한체크는 $is_admin_pay 으로만
if (!$is_admin_pay) {
    alert('접근 권한이 없습니다.');
    exit;
}

$g5['title'] = '유료DB 엑셀 등록';
$is_popup_page = true;
include_once(G5_ADMIN_PATH.'/admin.head.php');

// CSRF(유료DB 업로드 전용)
if (!isset($_SESSION['paid_upload_token'])) {
    $_SESSION['paid_upload_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['paid_upload_token'];
?>
<style>
body {min-width:100%}
.input_wrap{margin:10px;padding:20px;border:1px solid #e9e9e9;background:#fff}
#memo{width:80%}
.tbl_frm01 table{width:100%}
.tbl_frm01 th{width:180px;text-align:center;padding:8px 0}
.tbl_frm01 td{padding:8px 0}
#excelfile_upload{margin:15px 0}
</style>

<div class="new_win">
    <h1><?php echo $g5['title']; ?></h1>

    <div class="local_desc01 local_desc">
        <p>
            (합치기 모드용)<br>
            엑셀파일을 업로드하면 <strong>파일명으로 유료DB 캠페인을 자동 생성</strong>합니다.<br>
            1행은 헤더로 사용하며 <strong>이름 / 전화번호 / 생년월일</strong>은 기본 컬럼, 그 외 열은 <code>추가정보(JSON)</code>로 저장합니다.
        </p>
        <p>지원 형식: <strong>*.csv / *.xls / *.xlsx</strong></p>
        <p>업로드시 상위 20개 행 검증 후, 이상 없으면 업로드 진행합니다.</p>
        <p style="font-weight:bold;font-size:1.1em;color:#33e">되도록 csv로 저장하여 업로드 바랍니다.</p>
        <!-- <p style="font-weight:bold;font-size:1.1em;color:#e33">50MB, 100만행 까지 지원합니다.</p> -->
        <!-- <p style="margin-top:8px">
            <b>고정값</b><br>
            - call_campaign: name='유료DB', is_paid_db=1, is_open_number=0<br>
            - call_target: is_paid_db=1, company_id=0, mb_group=0
        </p> -->
    </div>

    <form name="fpaidexcel" id="fpaidexcel" method="post"
          action="./paid_target_excel_update_add_mode.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">

        <div class="tbl_frm01">
            <table>
                <tr>
                    <th scope="row">배치Id</th>
                    <td>
                        <input type="number" class="frm_input required" name="batch_id" required>
                    </td>
                </tr>
            </table>
        </div>

        <div id="excelfile_upload">
            <label for="excelfile"><b>파일선택</b></label>
            <input type="file" name="excelfile" id="excelfile" accept=".xls,.xlsx,.csv" required>
            <label for="is_validate">
                <input type="checkbox" id="is_validate" name="is_validate" value="1" checked>
                DB 유효성 검사 진행 <span>&lt;이름/전화번호&gt;</span>
            </label>
        </div>

        <div class="input_wrap">
            <p class="frm_info" style="padding-top:5px">유료DB는 항상 1차 비공개로 생성됩니다.</p>
        </div>

        <div class="win_btn btn_confirm">
            <input id="btn_submit" type="submit" value="유료DB 엑셀 합체 모드 등록" class="btn_submit btn">&nbsp;&nbsp;
            <button type="button" onclick="window.close();" class="btn_close btn">닫기</button>
        </div>
    </form>
</div>

<script>
$(function(){
    var MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB

    $('#fpaidexcel').on('submit', function(e){
        var $btn  = $('#btn_submit');
        var input = document.getElementById('excelfile');

        if (!input || !input.files || !input.files.length) {
            alert('엑셀 파일을 선택해 주세요.');
            e.preventDefault();
            return false;
        }

        var file = input.files[0];

        if (file.size > MAX_FILE_SIZE) {
            alert('파일 용량이 50MB를 초과했습니다.\n'
                + '현재 용량: 약 ' + (file.size / (1024*1024)).toFixed(1) + 'MB\n'
                + '10MB 이하로 나누어 업로드해 주세요.');
            e.preventDefault();
            return false;
        }

        $btn.prop('disabled', true).val('처리 중...');
        return true;
    });
});
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
