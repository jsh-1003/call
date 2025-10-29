<?php
$sub_menu = '700900';
require_once './_common.php';
if ($is_admin !== 'super') alert('최고관리자만 접근 가능합니다.');

// CSRF
if (!function_exists('get_admin_token')) {
    function get_admin_token() { return get_token(); }
}

// 공통 입력 헬퍼
function i($key, $def=null){ return isset($_POST[$key]) ? trim($_POST[$key]) : $def; }

// 액션 파싱: 'deactivate:123' / 'activate:123' / 'sortsave'
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$call_status = 0;
if ($action) {
    // CSRF 검증
    check_admin_token();
    // 복합 액션 분리
    if (strpos($action, ':') !== false) {
        list($action, $call_status) = explode(':', $action, 2);
        $call_status = (int)$call_status;
    }
}

// 처리: 비활성화/활성화/정렬저장
if ($action === 'deactivate') {
    if ($call_status <= 0) alert('잘못된 요청입니다.');
    sql_query("UPDATE call_status_code SET status=0, updated_at=NOW() WHERE call_status={$call_status} AND mb_group=0 LIMIT 1");
    goto_url('./status_code_list.php');
}
elseif ($action === 'activate') {
    if ($call_status <= 0) alert('잘못된 요청입니다.');
    sql_query("UPDATE call_status_code SET status=1, updated_at=NOW() WHERE call_status={$call_status} AND mb_group=0 LIMIT 1");
    goto_url('./status_code_list.php');
}
elseif ($action === 'sortsave') {
    $orders = $_POST['sort_order'] ?? [];
    foreach ($orders as $code => $ord) {
        $code = (int)$code;
        $ord  = (int)$ord;
        sql_query("UPDATE call_status_code SET sort_order={$ord}, updated_at=NOW() WHERE call_status={$code} AND mb_group=0");
    }
    goto_url('./status_code_list.php');
}

// 목록 조회
$rows = [];
$q = "
    SELECT *
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
";
$r = sql_query($q);
while ($row = sql_fetch_array($r)) $rows[] = $row;

$token = get_admin_token();
$g5['title'] = '코드관리';
include_once(G5_ADMIN_PATH.'/admin.head.php');
?>

<style>
.tbl_head01 th, .tbl_head01 td { text-align:center; }

/* 비활성 행 스타일 */
tr.inactive { background-color:#f8f9fa; color:#999; opacity:0.7; }

/* 상태 표시 */
.status-off { color:#d9534f; font-weight:bold; }
.status-on  { color:#5cb85c; font-weight:bold; }

/* DNC 강조 */
.dnc-yes { color:#d9534f; font-weight:bold; }
.dnc-no  { color:#777; }
.after-yes { color:#33e; font-weight:bold; }
.after-no  { color:#777; }

/* 관리 버튼 정렬 */
.td_mng .btn { margin:0 2px; }
</style>

<div class="local_ov01 local_ov">
    <a href="./status_code_form.php" class="btn_frmline">+ 새 코드 추가</a>
</div>

<!-- ✅ 단일 폼만 사용 -->
<form id="listForm" method="post" action="./status_code_list.php">
    <input type="hidden" name="token" value="<?php echo $token;?>">

    <div class="tbl_head01 tbl_wrap">
        <table>
            <thead>
                <tr>
                    <th>정렬</th>
                    <th>코드</th>
                    <th>표시 이름</th>
                    <th>지점</th>
                    <th>2차콜대상</th>
                    <th>블랙등록</th>
                    <th>버튼 스타일</th>
                    <th>상태</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows) { ?>
                <tr><td colspan="9" class="empty_table">데이터가 없습니다.</td></tr>
            <?php } else {
                // UI 타입 매핑 (영문 → 한글)
                $ui_labels = [
                    'primary'   => '주요(파랑)',
                    'success'   => '성공(초록)',
                    'warning'   => '경고(주황)',
                    'danger'    => '위험(빨강)',
                    'info'      => '안내(청록)',
                    'secondary' => '보통(회색)',
                    'ghost'     => '테두리(라인)',
                ];

                foreach ($rows as $row) {
                    $is_active   = (int)$row['status'] === 1;
                    $tr_class    = $is_active ? '' : 'inactive';
                    $dnc_class   = ((int)$row['is_do_not_call']===1) ? 'dnc-yes' : 'dnc-no';
                    $after_class = ((int)$row['is_after_call']===1) ? 'after-yes' : 'after-no';
                    $ui_label    = $ui_labels[$row['ui_type']] ?? $row['ui_type'];
                    $code        = (int)$row['call_status'];
            ?>
                <tr class="<?php echo $tr_class; ?>">
                    <td>
                        <input type="number"
                               name="sort_order[<?php echo $code;?>]"
                               value="<?php echo (int)$row['sort_order'];?>"
                               style="width:70px;text-align:center">
                    </td>
                    <td><?php echo $code;?></td>
                    <td><?php echo get_text($row['name_ko']);?></td>
                    <td><?php echo ((int)$row['result_group']===1?'통화성공':'<span style="color:#e33">통화실패</span>'); ?></td>
                    <td><span class="<?php echo $after_class; ?>"><?php echo ((int)$row['is_after_call']===1?'Y':'N'); ?></span></td>
                    <td><span class="<?php echo $dnc_class; ?>"><?php echo ((int)$row['is_do_not_call']===1?'Y':'N'); ?></span></td>
                    <td><?php echo get_text($ui_label);?></td>
                    <td>
                        <?php if ($is_active) { ?>
                            <span class="status-on">ON</span>
                        <?php } else { ?>
                            <span class="status-off">OFF</span>
                        <?php } ?>
                    </td>
                    <td class="td_mng" style="width:260px">
                        <a href="./status_code_form.php?w=u&amp;call_status=<?php echo $code;?>" class="btn btn_03">수정</a>

                        <?php if ($is_active) { ?>
                            <!-- ✅ 같은 폼에서 제출: action 값을 버튼으로 전달 -->
                            <button type="submit"
                                    class="btn btn_02"
                                    name="action"
                                    value="deactivate:<?php echo $code;?>"
                                    onclick="return confirm('비활성화 하시겠습니까?');">
                                비활성화
                            </button>
                        <?php } else { ?>
                            <button type="submit"
                                    class="btn btn_01"
                                    name="action"
                                    value="activate:<?php echo $code;?>"
                                    onclick="return confirm('이 코드를 활성화하시겠습니까?');">
                                활성
                            </button>
                        <?php } ?>
                    </td>
                </tr>
            <?php }} ?>
            </tbody>
        </table>
    </div>

    <!-- ✅ 정렬 저장: 같은 폼, action 명시 -->
    <div class="btn_fixed_top">
        <button type="submit" class="btn btn_01" name="action" value="sortsave">정렬 저장</button>
    </div>
</form>

<script>
// 엔터 입력 시 정렬 저장으로 제출되도록 처리
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('listForm');
    if (!form) return;

    // 숫자 입력창에서 엔터 감지
    form.querySelectorAll('input[name^="sort_order"]').forEach(input => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault(); // 엔터 기본 제출 방지
                // 정렬 저장으로 전송
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'sortsave';
                form.appendChild(actionInput);
                form.submit();
            }
        });
    });
});
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
