<?php
if (!defined('_GNUBOARD_')) exit;

/** =========================
 *  공통 상수(만료 등)
 *  ========================= */
define('API_SESSION_TTL_SECONDS', 60*60*24*30); // 30일

/** =========================
 *  업로드 핸들러 (기존 그대로)
 *  ========================= */
/**
 * 파일 포함 + 상태값 업로드 / 파일 없이 상태값 업로드
 * 필드: status, phoneNumber, (file?)
 * 응답: { success, message }
 */
function handle_call_upload(): void {
    $status = null;
    $phoneNumber = null;
    $savedFile = null;

    if (is_multipart()) {
        $status = $_POST['status'] ?? null;
        $phoneNumber = $_POST['phoneNumber'] ?? null;

        if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $uploadDir = dirname(__DIR__).'/data/api_uploads/'.date('Ymd');
            ensure_dir($uploadDir);

            $orig = $_FILES['file']['name'];
            $ext = pathinfo($orig, PATHINFO_EXTENSION);
            $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($orig, PATHINFO_FILENAME));
            $fname = $safe.'_'.date('His').'_'.bin2hex(random_bytes(4)).($ext ? '.'.$ext : '');
            $dest = $uploadDir.'/'.$fname;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                send_json(['success' => false, 'message' => 'File save failed']);
            }
            $savedFile = [
                'originalName' => $orig,
                'savedPath'    => str_replace(dirname(__DIR__), '', $dest),
                'size'         => (int)($_FILES['file']['size'] ?? 0),
                'mimeType'     => $_FILES['file']['type'] ?? 'application/octet-stream',
            ];
        }
    } elseif (is_formurlencoded()) {
        $status = $_POST['status'] ?? null;
        $phoneNumber = $_POST['phoneNumber'] ?? null;
    } else {
        send_json(['success' => false, 'message' => 'Unsupported Content-Type']);
    }

    if (!$status || !$phoneNumber) {
        send_json(['success' => false, 'message' => 'Missing required fields: status, phoneNumber'], 400);
    }

    // TODO: 필요 시 DB 저장
    send_json([
        'success' => true,
        'message' => $savedFile ? 'Uploaded with file' : 'Uploaded without file',
        'echo'    => [
            'status'      => $status,
            'phoneNumber' => $phoneNumber,
            'file'        => $savedFile,
        ],
    ]);
}

/** =========================
 *  작업할 정보 리스트 반환 (기존 흐름 + meta_json decode)
 *  ========================= */
function handle_get_user_info_list($token=null): void {
    $token = $token ?: get_bearer_token_from_headers();
    $info = get_group_info($token);
    $mb_group       = $info['mb_group'];
    $mb_no          = $info['mb_no'];
    $call_api_count = $info['call_api_count'];
    $call_lease_min = $info['call_lease_min'];
    $campaign_id    = $info['campaign_id']; // = 0

    // 1) 만료 회수
    call_assign_release_expired($mb_group, $campaign_id);

    // 2) 현재 보유 개수(통화전=1, 리스 유효)
    $k = call_assign_count_my_queue($mb_group, $mb_no, $campaign_id, '1', true);
    // 3) 모자라면 배정
    $need = max(0, $call_api_count - $k);
    if ($need > 0) {
        $batch_id = get_uniqid();
        $result = call_assign_pick_and_lock(
            $mb_group,
            $mb_no,
            $need,
            $call_lease_min,
            $batch_id,
            [
                'use_skip_locked'        => true,
                'assigned_status_to'     => 1,
                'assigned_status_filter' => 0,
                'order'                  => 'target_id',
                'where_extra'            => 'AND do_not_call = 0'
            ],
            $campaign_id
        );
        if (!$result['ok'] && $need == $call_api_count) {
            send_json([
                'success' => false,
                'message' => '배정실패',
                'data'    => [],
            ]);
        }
    }

    // 4) 최종 조회
    $rows = call_assign_list_my_queue($mb_group, $mb_no, $call_api_count, $campaign_id, '1', true);
    $list = [];
    foreach ($rows as $row) {
        $list[] = [
            'phoneNumber' => $row['call_hp'],
            'name'        => $row['name'],
            'info'        => safe_json_decode_or_null($row['meta_json']),
            'targetId'    => (int)$row['target_id'],
            'leaseUntil'  => $row['assign_lease_until'],
        ];
    }

    send_json([
        'success' => true,
        'message' => 'ok',
        'data'    => $list,
    ]);
}

/**
 * 로그인 (그누보드5 실제 검증) → 불투명 세션 토큰 발급
 * 요청(JSON): { "username": "<mb_id>", "password": "<mb_password>", "deviceId": "optional" }
 * 응답: { success, message, token? }
 */
function handle_login(): void {
    if (!is_json()) {
        send_json(['success' => false, 'message' => 'Content-Type must be application/json'], 400);
    }

    $in        = read_json();
    $mb_id     = trim((string)($in['username'] ?? ''));
    $mb_passwd = (string)($in['password'] ?? '');
    $device_id = isset($in['deviceId']) ? trim((string)$in['deviceId']) : null;

    if ($mb_id === '' || $mb_passwd === '') {
        send_json(['success' => false, 'message' => 'Missing username or password'], 400);
    }

    // 1) 회원 조회
    $mb = get_member($mb_id); // g5 기본 함수

    // 2) 비밀번호 검증 (소셜/예외처리 없이 심플하게)
    if (!isset($mb['mb_id']) || !$mb['mb_id'] || !login_password_check($mb, $mb_passwd, $mb['mb_password'])) {
        // 고의로 구체 사유 미공개(보안 관례)
        send_json(['success' => false, 'message' => 'Invalid credentials'], 401);
    }

    // 3) 차단/탈퇴/미인증 체크 (G5 login_check.php 로직 축약)
    // 차단
    if (!empty($mb['mb_intercept_date']) && $mb['mb_intercept_date'] <= date("Ymd", G5_SERVER_TIME)) {
        send_json(['success' => false, 'message' => 'Access blocked account'], 403);
    }
    // 탈퇴
    if (!empty($mb['mb_leave_date']) && $mb['mb_leave_date'] <= date("Ymd", G5_SERVER_TIME)) {
        send_json(['success' => false, 'message' => 'Leaved account'], 403);
    }
    /*
    // 메일 인증 사용 중이면 인증 확인
    if (function_exists('is_use_email_certify') && is_use_email_certify()) {
        if (!preg_match("/[1-9]/", (string)($mb['mb_email_certify'] ?? '0'))) {
            send_json(['success' => false, 'message' => 'Email not certified'], 403);
        }
    }
        */

    // 4) mb_no / mb_group 결정
    // - g5_member에 mb_no 기본 존재
    // - mb_group은 커스텀 필드라고 하셨으니 없으면 기본값 1로 폴백
    $mb_no    = (int)($mb['mb_no'] ?? 0);
    $mb_group = isset($mb['mb_group']) ? (int)$mb['mb_group'] : 1;

    if ($mb_no <= 0) {
        // 환경에 따라 mb_no가 없을 수 있으면 mb_id 기반 별도 매핑 필요
        // 현재 설계(get_group_info에서 member.m b_no 조인)와 일관 위해 mb_no 필수로 가정
        send_json(['success' => false, 'message' => 'Account data error (mb_no missing)'], 500);
    }

    // 5) 세션 토큰 발급 + 저장(api_sessions)
    $token = issue_session_token_and_store($mb_no, $mb_group, $device_id);

    // (선택) 포인트/로그 기록/last_login 업데이트는 필요 시 추가
    // 예: sql_query("UPDATE {$g5['member_table']} SET mb_today_login = NOW() WHERE mb_no = {$mb_no}");

    send_json([
        'success' => true,
        'message' => 'Login success',
        'token'   => $token, // Authorization: Bearer <token> 로 사용
    ]);
}
