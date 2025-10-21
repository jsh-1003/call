<?php
if (!defined('_GNUBOARD_')) exit;

/**
 * 상태코드 메타 조회 (조직 우선 → 기본(0) 폴백)
 * 반환 예: ['found'=>true, 'result_group'=>1|0, 'is_do_not_call'=>0|1, 'ui_type'=>'primary', 'sort_order'=>10]
 */
function get_call_status_meta(int $call_status, int $mb_group = 0): array {
    static $cache = [];

    $ckey = $mb_group . ':' . $call_status;
    if (isset($cache[$ckey])) return $cache[$ckey];

    $call_status = (int)$call_status;
    $mb_group    = (int)$mb_group;

    // 조직 우선(= mb_group = ?), 없으면 기본(=0)
    $sql = "
        SELECT call_status, mb_group, result_group, is_do_not_call, ui_type, sort_order
          FROM call_status_code
         WHERE status = 1
           AND call_status = {$call_status}
           AND mb_group IN (0, {$mb_group})
         ORDER BY (mb_group = {$mb_group}) DESC, mb_group DESC
         LIMIT 1
    ";
    $row = sql_fetch($sql);

    if ($row) {
        $out = [
            'found'         => true,
            'call_status'   => (int)$row['call_status'],
            'mb_group'      => (int)$row['mb_group'],
            'result_group'  => (int)$row['result_group'],     // 0=실패, 1=성공
            'is_do_not_call'=> (int)$row['is_do_not_call'],   // 0/1
            'ui_type'       => (string)$row['ui_type'],
            'sort_order'    => (int)$row['sort_order'],
        ];
        return $cache[$ckey] = $out;
    }

    // 폴백 규칙 (코드가 테이블에 없을 때)
    // - 200대: 성공, 그 외는 실패
    // - DNC: 기본 0
    $fallback = [
        'found'         => false,
        'call_status'   => $call_status,
        'mb_group'      => 0,
        'result_group'  => ($call_status >= 200 && $call_status < 300) ? 1 : 0,
        'is_do_not_call'=> 0,
        'ui_type'       => 'secondary',
        'sort_order'    => 999,
    ];
    return $cache[$ckey] = $fallback;
}

/** =========================
 *  토큰으로 mb_group, mb_no 구하기
 *  =========================
 * CALL_API_COUNT, CALL_LEASE_MIN은 환경/조직별로 member 테이블 등에서 가져오거나 상수 사용.
 * campaign_id는 우선 0으로.
 */
function get_group_info($token=null) {
    if (!$token) {
        send_json(['success'=>false,'message'=>'missing token'], 401);
    }

    $hash = hash('sha256', $token);
    // api_sessions + (예시) member 테이블 조인
    // ※ 실제 컬럼명/테이블명에 맞게 수정하세요.
    $row = sql_fetch("
        SELECT 
            s.user_id AS mb_no,
            s.mb_group,
            s.expires_at,
            s.revoked_at,
            m.call_api_count,
            m.call_lease_min
        FROM api_sessions s
        JOIN g5_member m ON m.mb_no = s.user_id
        WHERE s.token_hash = '{$hash}'
        LIMIT 1
    ");

    if (!$row) {
        send_json(['success'=>false,'message'=>'invalid token'], 401);
    }
    if (!empty($row['revoked_at']) || strtotime($row['expires_at']) <= time()) {
        send_json(['success'=>false,'message'=>'expired or revoked token'], 401);
    }

    // sliding window 연장(선택): last_seen만 갱신
    sql_query("UPDATE api_sessions SET last_seen = NOW() WHERE token_hash = '{$hash}'");

    // $set_info = sql_fetch("SELECT call_api_count, call_lease_min FROM g5_member WHERE mb_no = '{$row['mb_group']}' LIMIT 1 ");
    $set_info = get_call_config($row['mb_group']);

    // 조직별 기본값 폴백(멤버 컬럼이 없으면 상수 사용)
    $call_api_count = isset($set_info['call_api_count']) ? (int)$set_info['call_api_count'] : (int)CALL_API_COUNT;
    $call_lease_min = isset($set_info['call_lease_min']) ? (int)$set_info['call_lease_min'] : (int)CALL_LEASE_MIN;

    return [
        'mb_group'       => (int)$row['mb_group'],
        'mb_no'          => (int)$row['mb_no'],
        'call_api_count' => $call_api_count,
        'campaign_id'    => 0,                    // 요청하신 대로 캠페인 ID는 사용 안 함
        'call_lease_min' => $call_lease_min,
    ];
}


/** =========================
 *  세션 토큰 발급/저장 (옵션B 핵심)
 *  ========================= */
function issue_session_token_and_store($mb_no, $mb_group, ?string $device_id = null): string {
    $raw    = random_token(48);               // 클라에 전달할 원문 토큰
    $hash   = hash('sha256', $raw);           // DB 저장용 해시
    $now    = date('Y-m-d H:i:s');
    $exp    = date('Y-m-d H:i:s', time() + API_SESSION_TTL_SECONDS);

    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipbin = inet_pton($ip) ?: null;

    $sql = "INSERT INTO api_sessions
            (token_hash, user_id, mb_group, expires_at, last_seen, created_at, device_id, user_agent, ip_bin)
            VALUES
            ('{$hash}', ".(int)$mb_no.", ".(int)$mb_group.", '{$exp}', '{$now}', '{$now}', "
            .($device_id ? "'".sql_real_escape_string($device_id)."'" : "NULL").", "
            ."'".sql_real_escape_string($ua)."', ".($ipbin ? "'".bin2hex($ipbin)."'" : "NULL").")";

    sql_query($sql);
    return $raw;
}

/** =========================
 *  로그아웃(선택): 토큰 즉시 무효화
 *  ========================= */
function handle_logout($token = null): void {
    $token = $token ?: get_bearer_token_from_headers();
    if (!$token) {
        send_json(['success'=>false,'message'=>'missing token'], 400);
    }
    $hash = hash('sha256', $token);
    sql_query("UPDATE api_sessions SET revoked_at = NOW() WHERE token_hash = '{$hash}'");
    send_json(['success'=>true,'message'=>'logged out']);
}


// ---- 유틸 ----
function send_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function read_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function route_path(): string {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    // 예: /api/call/upload
    return rtrim($uri ?? '/', '/');
}
function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @chmod($dir, 0775);
    }
}
// 토큰 생성(클라에 내려갈 원문)
function random_token(int $len = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($len)), '+/', '-_'), '=');
}
function is_multipart(): bool {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($ct, 'multipart/form-data') !== false;
}
function is_formurlencoded(): bool {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($ct, 'application/x-www-form-urlencoded') !== false;
}
function is_json(): bool {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($ct, 'application/json') !== false;
}
// 안전 JSON decode (문자열→배열), 실패/빈값은 null
function safe_json_decode_or_null($json) {
    if ($json === null || $json === '') return array();
    $decoded = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    // 로그만 남기고 null로 폴백(원문을 같이 보내고 싶으면 meta_raw 등 필드로 분리)
    error_log('[meta_json] invalid json: ' . json_last_error_msg());
    return array();
}
/**
 * Bearer 토큰 파서
 * 다양한 서버 환경(Apache, Nginx, FastCGI)에서 안전하게 헤더를 추출합니다.
 */
function get_bearer_token_from_headers(): ?string {
    $hdr = '';

    // 1) Apache나 mod_php 환경
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // 2) 일부 환경(Nginx + PHP-FPM 등)은 Authorization 헤더가 HTTP_AUTHORIZATION으로 전달되지 않음
    elseif (isset($_SERVER['Authorization'])) {
        $hdr = $_SERVER['Authorization'];
    }
    // 3) PHP-FPM이나 FastCGI에서 fallback
    elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $hdr = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $hdr = $headers['authorization']; // 대소문자 다를 수 있음
        }
    }

    // 4) Bearer 토큰 추출
    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }

    return null;
}

/** 파일 확장자 결정 (mp3, m4a, wav 등) */
function get_file_ext_for_s3(string $orig, string $ctype): string {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext) return '.' . preg_replace('/[^a-z0-9]/','', $ext);

    // 확장자 없으면 content-type 기준 추정
    $map = [
        'audio/mpeg'  => '.mp3',
        'audio/mp3'   => '.mp3',
        'audio/x-m4a' => '.m4a',
        'audio/aac'   => '.aac',
        'audio/wav'   => '.wav',
        'audio/webm'  => '.webm',
        'application/octet-stream' => '', // 모를 때
    ];
    return $map[strtolower($ctype)] ?? '';
}
