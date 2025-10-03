<?php
if (!defined('_GNUBOARD_')) exit;

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