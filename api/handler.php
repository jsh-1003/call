<?php
if (!defined('_GNUBOARD_')) exit;

/**
 * 파일 포함 + 상태값 업로드 / 파일 없이 상태값 업로드
 * Kotlin 인터페이스와 동일 엔드포인트. (Multipart 또는 x-www-form-urlencoded)  :contentReference[oaicite:2]{index=2}
 * 필드: status, phoneNumber, (file?)
 * 응답: { success, message }
 */
function handle_call_upload(): void {
    // 입력 파싱
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

    // TODO: 필요 시 DB 저장 (그누보드 sql_query 사용 가능). 여기서는 더미 처리.
    // 예: sql_query("INSERT INTO ... (status, phone, file) VALUES (...)");

    $payload = [
        'success' => true,
        'message' => $savedFile ? 'Uploaded with file' : 'Uploaded without file',
        // 클라이언트 디버깅 편의용 더미 echo
        'echo'    => [
            'status'      => $status,
            'phoneNumber' => $phoneNumber,
            'file'        => $savedFile, // null이면 파일 없음
        ],
    ];
    send_json($payload);
}

/**
 * 유저 정보 리스트 반환 (더미 데이터)
 * Kotlin: @POST("api/call/getUserInfoList") -> UserInfoResponse  :contentReference[oaicite:3]{index=3} :contentReference[oaicite:4]{index=4}
 * 응답: { success, message, data: [{phoneNumber, name, info}, ...] }
 */
function handle_get_user_info_list(): void {
    // 실제로는 DB에서 로드. 여기서는 더미 3건.
    $list = [
        ['phoneNumber' => '010-1111-2222', 'name' => '홍길동', 'info' => 'VIP 고객'],
        ['phoneNumber' => '010-3333-4444', 'name' => '김아름', 'info' => '일반 고객'],
        ['phoneNumber' => '010-5555-6666', 'name' => '이도현', 'info' => '미응답 2회'],
    ];

    send_json([
        'success' => true,
        'message' => 'ok',
        'data'    => $list,
    ]);
}

/**
 * 로그인 (더미 검증)
 * Kotlin: @POST("api/auth/login") with body {username, password} -> LoginResponse  :contentReference[oaicite:5]{index=5} :contentReference[oaicite:6]{index=6}
 * Content-Type: application/json
 * 응답: { success, message, token? }
 */
function handle_login(): void {
    if (!is_json()) {
        send_json(['success' => false, 'message' => 'Content-Type must be application/json'], 400);
    }
    $in = read_json();
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');

    if ($username === '' || $password === '') {
        send_json(['success' => false, 'message' => 'Missing username or password'], 400);
    }

    // TODO: 실제 로그인 연동(그누보드 member 테이블 등). 여기서는 더미 처리:
    $ok = ($username === 'demo' && $password === 'demo1234'); // 데모 계정
    if ($ok) {
        $token = 'dummy-jwt.'.random_token(24); // 실제로는 JWT/세션 토큰 발급
        send_json([
            'success' => true,
            'message' => 'Login success',
            'token'   => $token,
        ]);
    } else {
        send_json([
            'success' => false,
            'message' => 'Invalid credentials',
            'token'   => null,
        ], 401);
    }
}
