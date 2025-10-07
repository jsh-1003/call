<?php
if (!defined('_GNUBOARD_')) exit;

/**
 * 토큰으로 mb_group, mb_no 구하기
 * CALL_API_COUNT : call.config.php
 *   - 조직별로 다르게 할 수도 있으니 해당 함수에서 리턴해주기로 함
 * CALL_LEASE_MIN : call.config.php
 *   - 조직별로 리스 시간을 다르게 할 수도 있으니 해당 함수에서 리턴
 * campaign_id : 특정 캠페인 우선 처리시 캠페이ID를 지정해서 처리
 */
function get_group_info($token) {
    $sql = "";
    $mb_group = 1;
    $mb_no = 2;
    $call_api_count = CALL_API_COUNT;
    $call_lease_min = CALL_LEASE_MIN;
    $campaign_id = 0;
    $result = array(
        'mb_group' => $mb_group,
        'mb_no' => $mb_no,
        'call_api_count' => $call_api_count,
        'campaign_id' => $campaign_id,
        'call_lease_min' => $call_lease_min
    );
    return $result;
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
    if ($json === null || $json === '') return null;
    $decoded = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    // 로그만 남기고 null로 폴백(원문을 같이 보내고 싶으면 meta_raw 등 필드로 분리)
    error_log('[meta_json] invalid json: ' . json_last_error_msg());
    return null;
}
// Bearer 토큰 파서(선택)
function get_bearer_token_from_headers(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    return null;
}
