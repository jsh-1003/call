<?php
// /adm/call_member_block.php
$sub_menu = "700750";
require_once "./_common.php";

if ((int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

auth_check_menu($auth, $sub_menu, "w");

// 파라미터
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$mb_id  = isset($_GET['mb_id']) ? trim($_GET['mb_id']) : '';
$ret    = isset($_GET['_ret']) ? $_GET['_ret'] : './call_member_list.php';

if ($mb_id === '' || !in_array($action, ['block','unblock'], true)) {
    alert('잘못된 요청입니다.');
}

$target = get_member($mb_id);
if (!(isset($target['mb_id']) && $target['mb_id'])) {
    alert('회원자료가 존재하지 않습니다.');
}

// 보호 규칙
if ($member['mb_id'] === $target['mb_id']) {
    alert('로그인 중인 관리자는 차단/해제할 수 없습니다.');
}
if (is_admin($target['mb_id']) === 'super') {
    alert('최고 관리자는 차단할 수 없습니다.');
}
if ((int)$target['mb_level'] >= (int)$member['mb_level']) {
    alert('자신보다 권한이 높거나 같은 회원은 차단할 수 없습니다.');
}

// 레벨7 관리자는 자기 그룹만 조작 가능
if ((int)$member['mb_level'] == 7) {
    $my_mb_no = (int)$member['mb_no'];
    if ((int)$target['mb_group'] !== $my_mb_no && (int)$target['mb_no'] !== $my_mb_no) {
        alert('자신의 소속 그룹 회원만 차단/해제할 수 있습니다.');
    }
}

// 처리
if ($action === 'block') {
    // 탈퇴 회원은 대상 제외
    if (!empty($target['mb_leave_date'])) {
        alert('탈퇴 회원은 차단할 수 없습니다.');
    }
    sql_query("UPDATE {$g5['member_table']} SET mb_intercept_date = '".G5_TIME_YMD."' WHERE mb_id = '".sql_escape_string($mb_id)."'");
} else {
    // unblock
    sql_query("UPDATE {$g5['member_table']} SET mb_intercept_date = '' WHERE mb_id = '".sql_escape_string($mb_id)."'");
}

// 리다이렉트
goto_url($ret);
