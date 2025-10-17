<?php
$sub_menu = '700900';
require_once './_common.php';
if ($is_admin !== 'super') alert('최고관리자만 접근 가능합니다.');

$w = isset($_GET['w']) ? trim($_GET['w']) : '';
$call_status = isset($_GET['call_status']) ? (int)$_GET['call_status'] : 0;

// 저장 처리
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_admin_token();
    $w            = $_POST['w'] ?? '';
    $call_status  = (int)($_POST['call_status'] ?? 0);
    $name_ko      = trim((string)($_POST['name_ko'] ?? ''));
    $result_group = (int)($_POST['result_group'] ?? 0);         // 0=실패, 1=성공
    $is_after     = (int)($_POST['is_after_call'] ?? 0);       // 0/1
    $is_dnc       = (int)($_POST['is_do_not_call'] ?? 0);       // 0/1
    $ui_type      = trim((string)($_POST['ui_type'] ?? ''));    // primary/success/...
    $sort_order   = (int)($_POST['sort_order'] ?? 100);
    $status       = (int)($_POST['status'] ?? 1);

    if ($w === '') {
        if ($call_status <= 0) alert('상태 코드는 필수입니다.');
        // 전역(mb_group=0) 중복 방지
        $dup = sql_fetch("SELECT 1 FROM call_status_code WHERE call_status={$call_status} AND mb_group=0");
        if ($dup) alert('이미 존재하는 상태 코드입니다.');
        $sql = "
            INSERT INTO call_status_code
              (call_status, mb_group, name_ko, result_group, is_after_call, is_do_not_call, ui_type, sort_order, status, created_at, updated_at)
            VALUES
              ({$call_status}, 0, '".sql_escape_string($name_ko)."', {$result_group}, {$is_after}, {$is_dnc},
               '".sql_escape_string($ui_type)."', {$sort_order}, {$status}, NOW(), NOW())
        ";
        sql_query($sql, true);
        goto_url('./status_code_list.php');
    } elseif ($w === 'u') {
        if ($call_status <= 0) alert('잘못된 요청입니다.');
        // 상태 코드는 수정 불가 (PK 유지), 나머지 필드만 갱신
        $sql = "
            UPDATE call_status_code
               SET name_ko='".sql_escape_string($name_ko)."',
                   result_group={$result_group},
                   is_after_call={$is_after},
                   is_do_not_call={$is_dnc},
                   ui_type='".sql_escape_string($ui_type)."',
                   sort_order={$sort_order},
                   status={$status},
                   updated_at=NOW()
             WHERE call_status={$call_status} AND mb_group=0
             LIMIT 1
        ";
        sql_query($sql, true);
        goto_url('./status_code_list.php');
    } else {
        alert('허용되지 않은 동작입니다.');
    }
}

// 조회
$row = null;
if ($w === 'u' && $call_status > 0) {
    $row = sql_fetch("
        SELECT *
          FROM call_status_code
         WHERE call_status={$call_status} AND mb_group=0
         LIMIT 1
    ");
    if (!$row) alert('대상을 찾을 수 없습니다.');
}

$token = get_token();
include_once(G5_ADMIN_PATH.'/admin.head.php');
?>

<style>
.tbl_frm01 th { width: 220px; }
.help {
    display:block; margin-top:6px; color:#777; line-height:1.5;
}
.ui-radio-group label {
    display:inline-block; margin:3px 12px 3px 0;
    padding:6px 10px; border:1px solid #ddd; border-radius:4px; cursor:pointer;
}
.ui-radio-group input[type=radio] { margin-right:6px; vertical-align:middle; }
</style>

<form method="post" action="./status_code_form.php">
<input type="hidden" name="token" value="<?php echo $token;?>">
<input type="hidden" name="w" value="<?php echo get_text($w);?>">

<div class="tbl_frm01 tbl_wrap">
    <table>
        <caption>콜 상태 코드 설정</caption>
        <colgroup>
            <col class="grid_3">
            <col>
        </colgroup>
        <tbody>
            <tr>
                <th scope="row">상태 코드 (숫자)</th>
                <td>
                    <?php if ($w==='u') { ?>
                        <input type="number" name="call_status" value="<?php echo (int)$row['call_status'];?>" readonly class="frm_input" style="width:200px">
                        <span class="frm_info">수정 불가</span>
                    <?php } else { ?>
                        <input type="number" name="call_status" value="" required class="frm_input" style="width:200px" placeholder="예) 200, 401">
                        <span class="help">앱과 로그에서 사용하는 식별 코드입니다. 등록 후에는 변경할 수 없습니다.</span>
                    <?php } ?>
                </td>
            </tr>

            <tr>
                <th scope="row">표시 이름</th>
                <td>
                    <input type="text" name="name_ko" value="<?php echo get_text($row['name_ko'] ?? '');?>" required class="frm_input" style="width:420px" placeholder="예) 접수, 부재, 결번">
                    <span class="help">앱 버튼과 통계에 표시될 이름입니다.</span>
                </td>
            </tr>

            <tr>
                <th scope="row">결과 그룹</th>
                <td>
                    <label><input type="radio" name="result_group" value="1" <?php echo (($row['result_group'] ?? 0)==1?'checked':''); ?>> 통화성공</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="result_group" value="0" <?php echo (($row['result_group'] ?? 0)==0?'checked':''); ?>> 통화실패</label>
                    <span class="help">통계 집계 시 성공/실패를 구분하는 값입니다.</span>
                </td>
            </tr>
            
            <tr>
                <th scope="row">2차 콜 대상</th>
                <td>
                    <label><input type="checkbox" name="is_after_call" value="1" <?php echo ((int)($row['is_after_call'] ?? 0)===1?'checked':''); ?>> 적용</label>
                    <span class="help">
                        체크 시, 이 상태로 저장될 경우 해당 대상은 <b>접수관리</b> 대상으로 표시됩니다.
                    </span>
                </td>
            </tr>

            <tr>
                <th scope="row">발신 금지(DNC) 처리</th>
                <td>
                    <label><input type="checkbox" name="is_do_not_call" value="1" <?php echo ((int)($row['is_do_not_call'] ?? 0)===1?'checked':''); ?>> 적용</label>
                    <span class="help">
                        체크 시, 이 상태로 저장될 경우 해당 대상은 <b>발신 금지(Do Not Call)</b>로 표시되며<br>
                        재시도 대상에서 제외되고(재발신 차단), <code>next_try_at</code>은 비워집니다.<br>
                        예) <b>결번</b> 등 법·운영상 다시 전화하면 안 되는 경우에 사용합니다.
                    </span>
                </td>
            </tr>

            <tr>
                <th scope="row">버튼 스타일</th>
                <td class="ui-radio-group">
                    <?php
                    // ui_type 사전 정의 (값 => 라벨)
                    $ui_types = [
                        'primary'   => '주요(파랑)',
                        'success'   => '성공(초록)',
                        'warning'   => '경고(주황)',
                        'danger'    => '위험(빨강)',
                        'info'      => '안내(청록)',
                        'secondary' => '보통(회색)',
                        'ghost'     => '테두리(라인)'
                    ];
                    $cur_ui = (string)($row['ui_type'] ?? '');
                    foreach ($ui_types as $val => $label) {
                        $checked = ($cur_ui === $val) ? 'checked' : '';
                        echo '<label><input type="radio" name="ui_type" value="'.get_text($val).'" '.$checked.'> '.$label.'</label>';
                    }
                    ?>
                    <span class="help">앱에서 이 코드로 생성되는 버튼의 색/스타일을 지정합니다.</span>
                </td>
            </tr>

            <tr>
                <th scope="row">표시 순서</th>
                <td>
                    <input type="number" name="sort_order" value="<?php echo (int)($row['sort_order'] ?? 100);?>" class="frm_input" style="width:120px">
                    <span class="help">작을수록 위에 표시됩니다.</span>
                </td>
            </tr>

            <tr>
                <th scope="row">사용 여부</th>
                <td>
                    <label><input type="radio" name="status" value="1" <?php echo (($row['status'] ?? 1)==1?'checked':''); ?>> 사용</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="status" value="0" <?php echo (($row['status'] ?? 1)==0?'checked':''); ?>> 사용 안 함</label>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="btn_fixed_top">
    <a href="./status_code_list.php" class="btn btn_02">목록</a>
    <button type="submit" class="btn btn_01">저장</button>
</div>

</form>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
