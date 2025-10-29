<?php
// /adm/call/ajax_group_options.php
include_once('./_common.php');

// JSON 응답
header('Content-Type: application/json; charset=utf-8');

// 권한 체크
$my_level      = (int)($member['mb_level'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
if ($my_level < 8) {
    echo json_encode(['success'=>false, 'message'=>'권한이 없습니다.']);
    exit;
}

// CSRF 토큰 검증 (헤더 X-CSRF-TOKEN)
// $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
// if (!isset($_SESSION['call_upload_token']) || !$csrf_header || !hash_equals($_SESSION['call_upload_token'], $csrf_header)) {
//     echo json_encode(['success'=>false, 'message'=>'잘못된 요청입니다(CSRF).']);
//     exit;
// }

// 입력 파싱
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
$req_company_id = isset($in['company_id']) ? (int)$in['company_id'] : 0;

// 회사 범위 강제 (8은 자기 회사만, 9+는 요청 존중)
if ($my_level >= 9) {
    $company_id = max(0, $req_company_id); // 0=전체
} else {
    $company_id = $my_company_id; // 고정
}

// 데이터 조회
try {
    $where = " WHERE m.mb_level = 7 ";
    if ($company_id > 0) {
        $where .= " AND m.company_id = '{$company_id}' ";
    } elseif ($my_level < 9) {
        // 방어로직: 혹시 모를 8레벨 전체 요청 방지
        $where .= " AND m.company_id = '{$my_company_id}' ";
    }

    $sql = "
        SELECT m.mb_no AS mb_group, m.company_id
        FROM {$g5['member_table']} m
        {$where}
        ORDER BY m.company_id ASC,
                 COALESCE(NULLIF(m.mb_group_name,''), CONCAT('지점-', m.mb_no)) ASC,
                 m.mb_no ASC
    ";
    $res = sql_query($sql);

    $items = [];
    $last_cid = null;

    // 9+ & company_id=0 일 때 회사별 구분선 출력
    $use_separator = ($my_level >= 9 && $company_id === 0);

    while ($r = sql_fetch_array($res)) {
        $gid   = (int)$r['mb_group'];
        $cid   = (int)$r['company_id'];
        $gname = get_group_name_cached($gid);
        $cname = get_company_name_cached($cid);
        $mcnt  = count_members_by_group_cached($gid);

        if ($use_separator && $last_cid !== $cid) {
            $items[] = [
                'separator' => (string)$cname
            ];
            $last_cid = $cid;
        }

        $items[] = [
            'value' => $gid,
            'label' => sprintf('%s (상담원 %d)', (string)$gname, (int)$mcnt)
        ];
    }

    echo json_encode([
        'success' => true,
        'items'   => $items
    ]);
} catch (Throwable $e) {
    echo json_encode(['success'=>false, 'message'=>'서버 오류: '.$e->getMessage()]);
}
