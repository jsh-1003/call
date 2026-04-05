<?php
declare(strict_types=1);

/**
 * Presence API
 * - POST /api/call/presence/update
 * - POST /api/call/presence/list
 */

const PRESENCE_ALLOWED_STATES = [
    'READY',
    'DIALING',
    'RINGING',
    'CONNECTED',
    'WRAPUP',
    'OFFLINE',
];

const PRESENCE_STALE_SECONDS = 20;

function handle_call_presence_update(): void
{
    $session = require_api_session();
    $body = get_request_payload();

    $deviceId      = trim((string)($body['deviceId'] ?? ''));
    $sessionId     = trim((string)($body['sessionId'] ?? ''));
    $state         = strtoupper(trim((string)($body['state'] ?? '')));
    $targetId      = array_key_exists('targetId', $body) && $body['targetId'] !== null && $body['targetId'] !== ''
        ? (int)$body['targetId']
        : null;
    $phoneNumber   = trim((string)($body['phoneNumber'] ?? ''));
    $mode          = trim((string)($body['mode'] ?? ''));
    $eventType     = trim((string)($body['eventType'] ?? 'state_change'));
    $callStartedAt = trim((string)($body['callStartedAt'] ?? ''));
    $eventAt       = trim((string)($body['eventAt'] ?? ''));

    if ($deviceId === '') {
        send_json(['success' => false, 'message' => 'deviceId is required'], 400);
    }

    if ($sessionId === '') {
        send_json(['success' => false, 'message' => 'sessionId is required'], 400);
    }

    if ($state === '') {
        send_json(['success' => false, 'message' => 'state is required'], 400);
    }

    if (!in_array($state, PRESENCE_ALLOWED_STATES, true)) {
        send_json(['success' => false, 'message' => 'invalid state'], 400);
    }

    $allowedEventTypes = ['state_change', 'heartbeat'];
    if (!in_array($eventType, $allowedEventTypes, true)) {
        send_json(['success' => false, 'message' => 'invalid eventType'], 400);
    }

    $now = date('Y-m-d H:i:s');
    $eventAtSql = is_valid_datetime($eventAt) ? $eventAt : $now;
    $callStartedAtSql = is_valid_datetime($callStartedAt) ? "'".esc_sql($callStartedAt)."'" : 'NULL';

    $userId = (int)$session['user_id'];
    $mbGroup = (int)$session['mb_group'];

    $extra = [
        'source' => 'android',
    ];

    $targetIdSql = $targetId === null ? 'NULL' : (string)$targetId;
    $phoneSql = $phoneNumber !== '' ? "'".esc_sql($phoneNumber)."'" : 'NULL';
    $modeSql = $mode !== '' ? "'".esc_sql($mode)."'" : 'NULL';

    $sql = "INSERT INTO call_agent_presence (
            user_id,
            mb_group,
            device_id,
            session_id,
            state,
            target_id,
            phone_number,
            mode,
            event_type,
            call_started_at,
            last_event_at,
            last_heartbeat_at,
            extra_json,
            created_at,
            updated_at
        ) VALUES (
            {$userId},
            {$mbGroup},
            '".esc_sql($deviceId)."',
            '".esc_sql($sessionId)."',
            '".esc_sql($state)."',
            {$targetIdSql},
            {$phoneSql},
            {$modeSql},
            '".esc_sql($eventType)."',
            {$callStartedAtSql},
            '".esc_sql($eventAtSql)."',
            '{$now}',
            '".esc_sql(json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."',
            '{$now}',
            '{$now}'
        )
        ON DUPLICATE KEY UPDATE
            session_id = VALUES(session_id),
            state = VALUES(state),
            target_id = VALUES(target_id),
            phone_number = VALUES(phone_number),
            mode = VALUES(mode),
            event_type = VALUES(event_type),
            call_started_at = VALUES(call_started_at),
            last_event_at = VALUES(last_event_at),
            last_heartbeat_at = VALUES(last_heartbeat_at),
            extra_json = VALUES(extra_json),
            updated_at = VALUES(updated_at)
    ";

    $res = sql_query($sql, true);
    if (!$res) {
        send_json(['success' => false, 'message' => 'failed to update presence'], 500);
    }

    touch_api_session_last_seen((int)$session['id'], $now);

    send_json([
        'success' => true,
        'message' => 'ok',
        'data' => [
            'userId' => $userId,
            'mbGroup' => $mbGroup,
            'deviceId' => $deviceId,
            'sessionId' => $sessionId,
            'state' => $state,
            'serverTime' => $now,
        ],
    ]);
}

function handle_call_presence_list(): void
{
    $session = require_api_session();
    $body = get_request_payload();

    $limit = isset($body['limit']) ? max(1, min(1000, (int)$body['limit'])) : 500;
    $stateFilter = strtoupper(trim((string)($body['state'] ?? '')));

    $where = [];
    $where[] = build_presence_scope_where($session);

    if ($stateFilter !== '') {
        $where[] = "state = '".esc_sql($stateFilter)."'";
    }

    $whereSql = implode(' AND ', $where);
    $nowTs = time();

    $sql = "SELECT
            id,
            user_id,
            mb_group,
            device_id,
            session_id,
            state,
            target_id,
            phone_number,
            mode,
            event_type,
            call_started_at,
            last_event_at,
            last_heartbeat_at,
            updated_at
        FROM call_agent_presence
        WHERE {$whereSql}
        ORDER BY updated_at DESC
        LIMIT {$limit}
    ";

    $res = sql_query($sql);
    if (!$res) {
        send_json(['success' => false, 'message' => 'failed to load presence list'], 500);
    }

    $data = [];
    while ($row = sql_fetch_array($res)) {
        $lastHeartbeatAt = (string)$row['last_heartbeat_at'];
        $heartbeatTs = strtotime($lastHeartbeatAt);
        $isStale = !$heartbeatTs || (($nowTs - $heartbeatTs) > PRESENCE_STALE_SECONDS);

        $effectiveState = $isStale ? 'OFFLINE' : (string)$row['state'];

        $data[] = [
            'userId' => (int)$row['user_id'],
            'mbGroup' => (int)$row['mb_group'],
            'deviceId' => (string)$row['device_id'],
            'sessionId' => (string)$row['session_id'],
            'state' => $effectiveState,
            'rawState' => (string)$row['state'],
            'targetId' => $row['target_id'] !== null ? (int)$row['target_id'] : null,
            'phoneNumber' => $row['phone_number'] !== null ? (string)$row['phone_number'] : null,
            'mode' => $row['mode'] !== null ? (string)$row['mode'] : null,
            'eventType' => (string)$row['event_type'],
            'callStartedAt' => $row['call_started_at'] !== null ? (string)$row['call_started_at'] : null,
            'lastEventAt' => (string)$row['last_event_at'],
            'lastHeartbeatAt' => $lastHeartbeatAt,
            'updatedAt' => (string)$row['updated_at'],
            'isStale' => $isStale,
        ];
    }

    send_json([
        'success' => true,
        'message' => 'ok',
        'data' => $data,
        'meta' => [
            'staleAfterSec' => PRESENCE_STALE_SECONDS,
            'serverTime' => date('Y-m-d H:i:s'),
        ],
    ]);
}

function require_api_session(): array
{
    $token = get_bearer_token_from_headers();
    if (!$token) {
        send_json(['success' => false, 'message' => 'Unauthorized'], 401);
    }

    $session = find_api_session_by_token($token);
    if (!$session) {
        send_json(['success' => false, 'message' => 'Invalid or expired token'], 401);
    }

    return $session;
}

function find_api_session_by_token(string $token): ?array
{
    $tokenHash = hash('sha256', $token);
    $now = date('Y-m-d H:i:s');

    $sql = "
        SELECT
            id,
            user_id,
            mb_group,
            expires_at,
            revoked_at,
            device_id,
            user_agent
        FROM api_sessions
        WHERE token_hash = '".esc_sql($tokenHash)."'
          AND revoked_at IS NULL
          AND expires_at > '{$now}'
        LIMIT 1
    ";

    $row = sql_fetch($sql);
    return $row ?: null;
}

function touch_api_session_last_seen(int $sessionId, string $now): void
{
    $sql = "
        UPDATE api_sessions
        SET last_seen = '".esc_sql($now)."'
        WHERE id = {$sessionId}
        LIMIT 1
    ";
    sql_query($sql, true);
}

function build_presence_scope_where(array $session): string
{
    $mbGroup = (int)($session['mb_group'] ?? 0);

    if ($mbGroup === 0) {
        return '1=1';
    }

    return 'mb_group = '.$mbGroup;
}

function get_request_payload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST ?: [];
}

function is_valid_datetime(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
    return $dt instanceof \DateTime && $dt->format('Y-m-d H:i:s') === $value;
}

function esc_sql(string $value): string
{
    return sql_real_escape_string($value);
}
