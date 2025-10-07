<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once './jump.lib.php';
// ===== 설정 =====
$ALLOWED_IP = '183.111.100.170';

// ===== IP 화이트리스트 =====
// 프록시 환경이 아니라면 REMOTE_ADDR만 사용(보안상 안전)
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remoteIp !== $ALLOWED_IP) {
    http_response_code(403);
    api_msg('forbidden (ip not allowed)', '4030');
    exit;
}

// ===== 랜덤 지연 (0.02 ~ 0.2초) =====
try {
    $delayUs = random_int(20000, 200000); // 마이크로초
    usleep($delayUs);
} catch (Throwable $e) {
    // random_int 실패해도 동작은 계속
}

// 공통 헤더
header('Content-Type: application/json; charset=UTF-8');

// ===== 설정값 =====
const MAX_QUERY_LEN = 120;   // 검색어 최대 길이
const MIN_COUNT     = 1;
const MAX_COUNT     = 100;    // 페이지당 최대 아이템
const MIN_PAGE      = 1;
const MAX_PAGE      = 1000;  // 페이지 최대 한도

// ===== 유틸: 에러 응답 일원화 =====
function fail(string $msg, string $code = '1000'): never {
    api_msg($msg, $code);
    exit; // api_msg 내부에서 exit한다면 중복이지만 안전하게 한 번 더
}

// ===== 모드 화이트리스트 =====
$allowedModes = ['search_keyword_v2'];

// GET/POST 모두 허용. 필요시 한쪽만 허용하세요.
$mode  = filter_input(INPUT_GET,  'mode',  FILTER_UNSAFE_RAW) ?? filter_input(INPUT_POST, 'mode', FILTER_UNSAFE_RAW);
$query = filter_input(INPUT_GET,  'query', FILTER_UNSAFE_RAW) ?? filter_input(INPUT_POST, 'query', FILTER_UNSAFE_RAW);

// 정수 파라미터는 강제 캐스팅 & 범위 제한
$count = filter_input(INPUT_GET,  'count', FILTER_VALIDATE_INT, ['options' => ['default' => 50]]) 
      ?? filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT, ['options' => ['default' => 50]]);
$page  = filter_input(INPUT_GET,  'page',  FILTER_VALIDATE_INT, ['options' => ['default' => 1]])  
      ?? filter_input(INPUT_POST, 'page',  FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);

// ===== 모드 검증 =====
if (!$mode || !in_array($mode, $allowedModes, true)) {
    fail('mode 없음!', '1000');
}

// ===== query 정규화/검증 =====
if ($query !== null) {
    // 앞뒤 공백 제거
    $query = trim($query);

    // 제어문자 제거 (탭/개행 제외). 필요시 개행도 제거: "[\x00-\x1F\x7F]"
    $query = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $query);

    // 공백 정규화 (여러 공백 -> 하나)
    $query = preg_replace('/\s{2,}/u', ' ', $query);

    // 길이 제한
    if (mb_strlen($query) > MAX_QUERY_LEN) {
        $query = mb_substr($query, 0, MAX_QUERY_LEN);
    }

    // 허용 문자 정책(선택): 한글/영문/숫자/일부 구두점/공백만 허용
    // 필요에 따라 폭을 조절하세요.
    if (!preg_match('/^[\p{Hangul}a-zA-Z0-9\s\-\._&()+,\|\/\[\]~:;\'"!?@#%]*$/u', $query)) {
        fail('쿼리에 허용되지 않은 문자가 포함되어 있습니다.', '9998');
    }
}

// 빈 쿼리 차단
if (!$query) {
    fail('쿼리없음', '9999');
}

// ===== 범위 제한 (비정상 값 방어) =====
$count = (int)$count;
$page  = (int)$page;

if ($count < MIN_COUNT || $count > MAX_COUNT) {
    fail("count 범위 초과 (허용: ".MIN_COUNT."~".MAX_COUNT.")", '2001');
}
if ($page < MIN_PAGE || $page > MAX_PAGE) {
    fail("page 범위 초과 (허용: ".MIN_PAGE."~".MAX_PAGE.")", '2002');
}

// ===== 라우팅 =====
switch ($mode) {
    case 'search_keyword_v2':
        $res = searchKeywordV2($query, $count, $page);
        echo json_encode($res);
        break;

    default:
        fail('mode 없음!', '1000'); // 화이트리스트 덕분에 사실상 도달 불가
}
