<?php
$sub_menu = '700900';
require_once './_common.php';
if ($is_admin !== 'super') alert('최고관리자만 접근 가능합니다.');

// CSRF
if (!function_exists('get_admin_token')) {
    function get_admin_token() { return get_token(); }
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';
if ($action) check_admin_token();

// 공통 필드 클린업
function i($key, $def=null){ return isset($_POST[$key]) ? trim($_POST[$key]) : $def; }

// 처리: 추가/수정/비활성화/활성화/정렬저장
if ($action === 'save') {
    $w = i('w', '');
    $call_status = (int)i('call_status', 0);
    $name_ko     = i('name_ko', '');
    $result_group= (int)i('result_group', 0); // 0=fail, 1=success
    $is_dnc      = (int)i('is_do_not_call', 0);
    $ui_type     = i('ui_type', '');
    $sort_order  = (int)i('sort_order', 100);
    $status      = (int)i('status', 1);

    if ($w === '') {
        if ($call_status <= 0) alert('상태 코드는 필수입니다.');
        $dup = sql_fetch("SELECT 1 FROM call_status_code WHERE call_status={$call_status} AND mb_group=0");
        if ($dup) alert('이미 존재하는 상태 코드입니다.');
        $sql = "
            INSERT INTO call_status_code
              (call_status, mb_group, name_ko, result_group, is_do_not_call, ui_type, sort_order, status, created_at, updated_at)
            VALUES
              ({$call_status}, 0, '".sql_escape_string($name_ko)."', {$result_group}, {$is_dnc},
               '".sql_escape_string($ui_type)."', {$sort_order}, {$status}, NOW(), NOW())
        ";
        sql_query($sql, true);
        goto_url('./status_code_list.php');
    } elseif ($w === 'u') {
        if ($call_status <= 0) alert('잘못된 요청입니다.');
        $sql = "
            UPDATE call_status_code
               SET name_ko='".sql_escape_string($name_ko)."',
                   result_group={$result_group},
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
elseif ($action === 'deactivate') {
    $call_status = (int)i('call_status', 0);
    if ($call_status <= 0) alert('잘못된 요청입니다.');
    sql_query("UPDATE call_status_code SET status=0, updated_at=NOW() WHERE call_status={$call_status} AND mb_group=0 LIMIT 1");
    goto_url('./status_code_list.php');
}
elseif ($action === 'activate') {
    $call_status = (int)i('call_status', 0);
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
    SELECT call_status, name_ko, result_group, is_do_not_call, ui_type, sort_order, status
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
</style>

<div class="local_ov01 local_ov">
    <a href="./status_code_form.php" class="btn_frmline">+ 새 코드 추가</a>
</div>

<form method="post" action="./status_code_list.php">
<input type="hidden" name="action" value="sortsave">
<input type="hidden" name="token" value="<?php echo $token;?>">

<div class="tbl_head01 tbl_wrap">
    <table>
        <thead>
            <tr>
                <th>정렬</th>
                <th>코드</th>
                <th>표시 이름</th>
                <th>그룹</th>
                <th>DNC</th>
                <th>버튼 스타일</th>
                <th>상태</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows) { ?>
            <tr><td colspan="8" class="empty_table">데이터가 없습니다.</td></tr>
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
                $is_active = (int)$row['status'] === 1;
                $tr_class  = $is_active ? '' : 'inactive';
                $dnc_class = ((int)$row['is_do_not_call']===1) ? 'dnc-yes' : 'dnc-no';
                $ui_label  = $ui_labels[$row['ui_type']] ?? $row['ui_type'];
        ?>
            <tr class="<?php echo $tr_class; ?>">
                <td>
                    <input type="number" name="sort_order[<?php echo (int)$row['call_status'];?>]" 
                           value="<?php echo (int)$row['sort_order'];?>" 
                           style="width:70px;text-align:center">
                </td>
                <td><?php echo (int)$row['call_status'];?></td>
                <td><?php echo get_text($row['name_ko']);?></td>
                <td><?php echo ((int)$row['result_group']===1?'성공':'실패'); ?></td>
                <td><span class="<?php echo $dnc_class; ?>"><?php echo ((int)$row['is_do_not_call']===1?'Y':'N'); ?></span></td>
                <td><?php echo get_text($ui_label);?></td>
                <td>
                    <?php if ($is_active) { ?>
                        <span class="status-on">ON</span>
                    <?php } else { ?>
                        <span class="status-off">OFF</span>
                    <?php } ?>
                </td>
                <td class="td_mng" style="width:200px">
                    <a href="./status_code_form.php?w=u&amp;call_status=<?php echo (int)$row['call_status'];?>" class="btn btn_03">수정</a>

                    <?php if ($is_active) { ?>
                        <!-- 비활성화 버튼 -->
                        <form method="post" action="./status_code_list.php" style="display:inline" onsubmit="return confirm('비활성화 하시겠습니까?');">
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="token" value="<?php echo $token;?>">
                            <input type="hidden" name="call_status" value="<?php echo (int)$row['call_status'];?>">
                            <button type="submit" class="btn btn_02">비활성화</button>
                        </form>
                    <?php } else { ?>
                        <!-- 활성화 버튼 -->
                        <form method="post" action="./status_code_list.php" style="display:inline" onsubmit="return confirm('이 코드를 활성화하시겠습니까?');">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="token" value="<?php echo $token;?>">
                            <input type="hidden" name="call_status" value="<?php echo (int)$row['call_status'];?>">
                            <button type="submit" class="btn btn_01">활성</button>
                        </form>
                    <?php } ?>
                </td>
            </tr>
        <?php }} ?>
        </tbody>
    </table>
</div>

<div class="btn_fixed_top">
    <button type="submit" class="btn btn_01">정렬 저장</button>
</div>
</form>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
