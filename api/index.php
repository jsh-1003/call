<?php
/**
 * API Router (api/index.php)
 * Endpoints:
 *  - POST /api/call/upload            (multipart/form-data: status, phoneNumber, file?) 
 *  - POST /api/call/getUserInfoList   (no body)
 *  - POST /api/auth/login             (application/json: {username, password})
 *
 * 응답은 JSON, UTF-8
 */

declare(strict_types=1);

// ---- 그누보드 부트스트랩 ----
// 경로는 설치 환경에 맞게 조정 (예: '/_common.php' 또는 '/common.php')
$root = dirname(__DIR__);
require_once './_common.php';
require_once G5_LIB_PATH.'/call.lib.php';
require_once G5_LIB_PATH.'/call.assign.lib.php';
require_once './handler.php';
require_once './call_shop_handler.php'; // ← 콜샵 API 전용 핸들러


// ---- 공통 헤더 ----
header('Content-Type: application/json; charset=utf-8');
// CORS가 필요하면 아래 주석 해제
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Headers: Content-Type, Authorization');
// header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }


// ---- 라우팅 ----
$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 안전장치: POST만 허용 (요청 정의에 따름)
if ($method !== 'POST') {
    send_json(['success' => false, 'message' => 'Only POST allowed'], 405);
}

switch ($path) {
    // 통화결과 처리
    case '/api/call/upload':
        handle_call_upload();
        break;

    // 대상 내려보내기
    case '/api/call/getUserInfoList':
        handle_get_user_info_list();
        break;

    // 로그인
    case '/api/auth/login':
        handle_login();
        break;
    
    // 상태값 조회
    case '/api/call/statusCodes':
        handle_get_call_status_codes();
        break;

        // 콜샵 주문 등록
    case '/api/callShop/order':
        handle_call_shop_order();
        break;

    default:
        send_json(['success' => false, 'message' => 'Not Found: '.$path], 404);
}
