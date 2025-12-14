<?php
/**
 * 콜샵 API 처리 (api/call_shop_handler.php)
 *
 * Endpoint:
 *  - POST /api/callShop/order       (application/json)
 *
 * 인증:
 *  - HTTP Header: Authorization: Bearer {TOKEN}
 *
 * 요청 바디(JSON 예시)
 * {
 *   "hq_name": "신한라이프",
 *   "branch_name": "행복 지점",
 *   "planner_name": "홍길동",
 *   "phone_number": "010-1234-1234",
 *   "order_method": "개인",
 *   "db_type": "일반디비",
 *   "order_region": "수도권",
 *   "order_quantity": 5,
 *   "distribution_rule": "1일 2건",
 *   "order_date": "2025-12-08"
 * }
 *
 * 응답은 JSON, 메시지는 모두 한글.
 */

declare(strict_types=1);

/**
 * Authorization 헤더에서 Bearer 토큰 추출
 */
function call_shop_get_bearer_token(): ?string
{
    $headers = [];

    // 아파치/기타 서버 호환용
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        // Nginx, PHP-FPM 환경 고려
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
    }

    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    if (!$authHeader) {
        return null;
    }

    if (stripos($authHeader, 'Bearer ') === 0) {
        return trim(substr($authHeader, 7));
    }

    return null;
}

/**
 * 토큰 검증
 * 실제 환경에 맞게 DB 조회 또는 설정 파일 기반으로 수정해서 사용하면 됩니다.
 */
function call_shop_verify_token(?string $token): bool
{
    if (!$token) {
        return false;
    }

    // 예시: 고정 토큰 (운영에서는 DB/환경변수/설정파일 등으로 관리 권장)
    // define('CALL_SHOP_API_TOKEN', 'your-secret-token');
    if (defined('CALL_SHOP_API_TOKEN')) {
        return hash_equals(CALL_SHOP_API_TOKEN, $token);
    }

    // 예시: 하드코딩 토큰 (테스트용)
    $validTokens = [
        'test-token-123',
    ];

    return in_array($token, $validTokens, true);
}

/**
 * 콜샵 주문 등록 처리
 */
function handle_call_shop_order(): void
{
    // (index.php에서 이미 POST만 허용하고 있으나, 혹시 모르니 한 번 더 체크)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        send_json(['success' => false, 'message' => 'POST 메서드만 허용됩니다.'], 405);
    }

    // 토큰 인증
    $token = call_shop_get_bearer_token();
    if (!call_shop_verify_token($token)) {
        send_json(['success' => false, 'message' => '인증에 실패했습니다. 유효한 토큰이 아닙니다.'], 401);
    }

    // JSON 파싱
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        send_json(['success' => false, 'message' => 'JSON 형식의 요청 본문이 필요합니다.'], 400);
    }

    // 필수 필드 목록 (snake_case 기준)
    $requiredFields = [
        'hq_name',
        'branch_name',
        'planner_name',
        'phone_number',
        'order_method',
        'db_type',
        'order_region',
        'order_quantity',
        'distribution_rule',
        'order_date',
    ];

    // camelCase로 들어올 수도 있으니 둘 다 허용
    $fieldAlias = [
        'hq_name'          => ['hq_name', 'hqName'],
        'branch_name'      => ['branch_name', 'branchName'],
        'planner_name'     => ['planner_name', 'plannerName'],
        'phone_number'     => ['phone_number', 'phoneNumber'],
        'order_method'     => ['order_method', 'orderMethod'],
        'db_type'          => ['db_type', 'dbType'],
        'order_region'     => ['order_region', 'orderRegion'],
        'order_quantity'   => ['order_quantity', 'orderQuantity'],
        'distribution_rule'=> ['distribution_rule', 'distributionRule'],
        'order_date'       => ['order_date', 'orderDate'],
    ];

    $clean = [];

    // 필수값 검증 및 맵핑
    foreach ($requiredFields as $field) {
        $value = null;
        foreach ($fieldAlias[$field] as $alias) {
            if (array_key_exists($alias, $data)) {
                $value = $data[$alias];
                break;
            }
        }

        if ($value === null || $value === '') {
            send_json([
                'success' => false,
                'message' => "필수 항목이 누락되었습니다: {$field}",
                'field'   => $field,
            ], 400);
        }

        $clean[$field] = $value;
    }

    // 타입/형식 체크
    $clean['hq_name']          = trim((string)$clean['hq_name']);
    $clean['branch_name']      = trim((string)$clean['branch_name']);
    $clean['planner_name']     = trim((string)$clean['planner_name']);
    $clean['phone_number']     = trim((string)$clean['phone_number']);
    $clean['order_method']     = trim((string)$clean['order_method']);
    $clean['db_type']          = trim((string)$clean['db_type']);
    $clean['order_region']     = trim((string)$clean['order_region']);
    $clean['distribution_rule']= trim((string)$clean['distribution_rule']);

    // 수량 정수 변환
    $clean['order_quantity'] = (int)$clean['order_quantity'];
    if ($clean['order_quantity'] <= 0) {
        send_json([
            'success' => false,
            'message' => '주문수량은 1 이상 정수여야 합니다.',
        ], 400);
    }

    // 날짜 형식 체크 (YYYY-MM-DD)
    $orderDate = (string)$clean['order_date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
        send_json([
            'success' => false,
            'message' => '주문일자는 YYYY-MM-DD 형식이어야 합니다.',
        ], 400);
    }

    // 날짜 유효성 간단 체크
    [$y, $m, $d] = explode('-', $orderDate);
    if (!checkdate((int)$m, (int)$d, (int)$y)) {
        send_json([
            'success' => false,
            'message' => '유효하지 않은 주문일자입니다.',
        ], 400);
    }

    // DB Insert
    try {
        // 그누보드5의 sql_escape_string 사용 (환경에 맞게 조절)
        $hq_name          = sql_escape_string($clean['hq_name']);
        $branch_name      = sql_escape_string($clean['branch_name']);
        $planner_name     = sql_escape_string($clean['planner_name']);
        $phone_number     = sql_escape_string($clean['phone_number']);
        $order_method     = sql_escape_string($clean['order_method']);
        $db_type          = sql_escape_string($clean['db_type']);
        $order_region     = sql_escape_string($clean['order_region']);
        $order_quantity   = (int)$clean['order_quantity'];
        $distribution_rule= sql_escape_string($clean['distribution_rule']);
        $order_date       = sql_escape_string($orderDate);

        $sql = "
            INSERT INTO call_shop_api
            (
                hq_name,
                branch_name,
                planner_name,
                phone_number,
                order_method,
                db_type,
                order_region,
                order_quantity,
                distribution_rule,
                order_date,
                created_at,
                updated_at
            ) VALUES (
                '{$hq_name}',
                '{$branch_name}',
                '{$planner_name}',
                '{$phone_number}',
                '{$order_method}',
                '{$db_type}',
                '{$order_region}',
                {$order_quantity},
                '{$distribution_rule}',
                '{$order_date}',
                NOW(),
                NOW()
            )
        ";

        $result = sql_query($sql, true); // true: 에러 시 중단 (환경에 따라 옵션)
        if (!$result) {
            send_json([
                'success' => false,
                'message' => '주문 저장 중 오류가 발생했습니다.',
            ], 500);
        }

        // 그누보드5 기본 함수: sql_insert_id()
        if (function_exists('sql_insert_id')) {
            $insertId = (int)sql_insert_id();
        } else {
            $insertId = 0;
        }

        send_json([
            'success' => true,
            'message' => '주문이 정상적으로 등록되었습니다.',
            'data'    => [
                'id' => $insertId,
            ],
        ]);

    } catch (Exception $e) {
        // 예외 발생 시 에러 로그 남기고 클라이언트에는 한글 메시지
        error_log('[call_shop_api] DB Error: '.$e->getMessage());

        send_json([
            'success' => false,
            'message' => '시스템 오류가 발생했습니다. 잠시 후 다시 시도해주세요.',
        ], 500);
    }
}
