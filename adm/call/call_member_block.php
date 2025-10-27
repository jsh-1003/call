<?php
// /adm/call_member_block.php
$sub_menu = "700750";
require_once "./_common.php";

// 최소 접근 레벨: 7+
if ((int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

auth_check_menu($auth, $sub_menu, "w");

// 입력 파라미터
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$mb_id  = isset($_GET['mb_id'])  ? trim($_GET['mb_id'])  : '';
$ret    = isset($_GET['_ret'])   ? (string)$_GET['_ret'] : './call_member_list.php';

if ($mb_id === '' || !in_array($action, ['block','unblock'], true)) {
    alert('잘못된 요청입니다.');
}

// 대상 회원 조회
$target = get_member($mb_id);
if (!(isset($target['mb_id']) && $target['mb_id'])) {
    alert('회원자료가 존재하지 않습니다.');
}

// -----------------------------
// 보호/권한 규칙
// -----------------------------
$my_level      = (int)($member['mb_level'] ?? 0);
$my_mb_no      = (int)($member['mb_no'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);      // 레벨7 관리자는 일반적으로 자신의 mb_no와 동일(그룹ID)
$my_company_id = (int)($member['company_id'] ?? 0);

// 1) 자기 자신
if ($member['mb_id'] === $target['mb_id']) {
    alert('로그인 중인 관리자는 차단/해제할 수 없습니다.');
}

// 2) 최고관리자 보호
if (is_admin($target['mb_id']) === 'super') {
    alert('최고 관리자는 차단할 수 없습니다.');
}

// 3) 권한 레벨 비교(같거나 더 높은 레벨은 불가)
if ((int)$target['mb_level'] >= $my_level) {
    alert('자신보다 권한이 높거나 같은 회원은 차단/해제할 수 없습니다.');
}

// 4) 스코프 제한
//   - 레벨 9+: 전사(회사/그룹 제한 없음)
//   - 레벨 8 : 본인 회사(company_id) 소속 회원만
//   - 레벨 7 : 본인 그룹(mb_group = 내 그룹ID) 회원만
if ($my_level == 8) {
    $t_company_id = (int)($target['company_id'] ?? 0);
    if ($t_company_id !== $my_company_id) {
        alert('자신의 회사 소속 회원만 차단/해제할 수 있습니다.');
    }
} elseif ($my_level == 7) {
    // 대상의 소속 그룹이 내 그룹과 동일해야 함
    // (일반 사원은 mb_group=그룹ID, 그룹관리자(레벨7)는 통상 본인의 mb_no가 그룹ID이며 mb_group에도 동일값이 세팅됩니다)
    $t_group = (int)($target['mb_group'] ?? 0);
    if ($t_group !== $my_group) {
        alert('자신의 소속 그룹 회원만 차단/해제할 수 있습니다.');
    }
}

// -----------------------------
// 처리
// -----------------------------
$safe_mb_id = sql_escape_string($mb_id);

if ($action === 'block') {
    // 탈퇴 회원은 대상 제외
    if (!empty($target['mb_leave_date'])) {
        alert('탈퇴 회원은 차단할 수 없습니다.');
    }
    sql_query("UPDATE {$g5['member_table']}
                  SET mb_intercept_date = '".date("Ymd")."'
                WHERE mb_id = '{$safe_mb_id}'");
} else {
    // unblock
    sql_query("UPDATE {$g5['member_table']}
                  SET mb_intercept_date = ''
                WHERE mb_id = '{$safe_mb_id}'");
}

// -----------------------------
// 안전 리다이렉트
// -----------------------------
// 외부 URL 삽입 방지: 스킴/호스트 포함 URL은 제거, 줄바꿈 제거
$ret = str_replace(["\r","\n"], '', $ret);
if (preg_match('#^https?://#i', $ret)) {
    $ret = './call_member_list.php';
}
goto_url($ret);
