<?php
if (!defined('_GNUBOARD_')) exit;

/**
 * 통화 상태 코드 목록 내려주기 (간단 쿼리 + PHP 후처리)
 * - 토큰 있으면: mb_group IN (0, 내 그룹) → 같은 code면 조직 코드가 기본(0) 덮어씀
 * - 토큰 없으면: mb_group = 0
 * - 정렬: sort_order ASC
 */
function handle_get_call_status_codes(): void {
    // 1) 토큰/그룹 결정
    $token  = get_bearer_token_from_headers();
    $groups = [0];
    if ($token) {
        try {
            $info = get_group_info($token);
            $g = (int)($info['mb_group'] ?? 0);
            if ($g !== 0) $groups[] = $g;
        } catch (\Throwable $e) {
            // 토큰 유효X → 기본(0)만 사용
        }
    }
    $groups = array_values(array_unique(array_map('intval', $groups)));
    $grp_in = implode(',', $groups);

    // 2) 간단 쿼리: 필요한 컬럼만, 정렬은 sort_order만
    $sql = "
        SELECT
            call_status,
            mb_group,
            name_ko,
            result_group,
            is_do_not_call,
            ui_type,
            sort_order
        FROM call_status_code
        WHERE status = 1
          AND mb_group IN ($grp_in)
        ORDER BY sort_order ASC, call_status ASC
    ";
    $res = sql_query($sql);
    if (!$res) {
        send_json(['success' => false, 'message' => 'failed to load status codes'], 500);
    }

    // 3) PHP 후처리: 같은 call_status는 조직 코드(mb_group != 0)가 있으면 덮어쓰기
    //    - 기본(0) 먼저 담기 → 조직 코드 나오면 교체
    $byCode = [];   // key: call_status(int) -> row array
    while ($row = sql_fetch_array($res)) {
        $code    = (int)$row['call_status'];
        $mbGroup = (int)$row['mb_group'];

        if (!isset($byCode[$code])) {
            // 최초 진입(보통 기본 0이 먼저 들어감)
            $byCode[$code] = $row;
        } else {
            // 이미 기본이 있더라도, 조직 코드면 덮어씀
            if ($mbGroup !== 0) {
                $byCode[$code] = $row;
            }
        }
    }

    // 4) 값만 꺼내 정렬 유지(sort_order 기준)하여 배열화
    //    위 쿼리가 sort_order ASC라서, $byCode는 삽입 순서를 유지하면 되지만
    //    혹시나를 위해 다시 sort_order로 정렬
    $list = array_values($byCode);
    usort($list, function ($a, $b) {
        $sa = (int)$a['sort_order'];
        $sb = (int)$b['sort_order'];
        if ($sa === $sb) return (int)$a['call_status'] <=> (int)$b['call_status'];
        return $sa <=> $sb;
    });
    // $list[] = array('call_status'=> '1111', 'name_ko'=>'라벨1111', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1111);
    // $list[] = array('call_status'=> '1112', 'name_ko'=>'라벨1112', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1112);
    // $list[] = array('call_status'=> '1113', 'name_ko'=>'라벨1113', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1113);
    // $list[] = array('call_status'=> '1114', 'name_ko'=>'라벨1114', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1114);
    // $list[] = array('call_status'=> '1115', 'name_ko'=>'라벨1115', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1115);
    // $list[] = array('call_status'=> '1116', 'name_ko'=>'라벨1116', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1116);
    // $list[] = array('call_status'=> '1117', 'name_ko'=>'라벨1117', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1117);
    // $list[] = array('call_status'=> '1118', 'name_ko'=>'라벨1118', 'result_group'=>1, 'ui_type'=>'secondary', 'sort_order'=>1118);
    // 5) 응답 포맷 매핑
    $out = [];
    foreach ($list as $row) {
        $out[] = [
            'code'        => (int)$row['call_status'],
            'label'       => (string)$row['name_ko'],
            'group'       => (int)$row['result_group'],           // 0=실패, 1=성공
            // 'isDoNotCall' => ((int)$row['is_do_not_call'] === 1), // bool
            'uiType'      => (string)$row['ui_type'],
            'sortOrder'   => (int)$row['sort_order'],
            // 'mbGroup'     => (int)$row['mb_group'],
        ];
    }

    send_json([
        'success' => true,
        'message' => 'ok',
        'data'    => $out,
    ]);
}

/**
 * 통화 업로드/저장 처리
 * - 필수: target_id, call_status
 * - 선택: call_start, call_end, memo, duration_sec
 * - 파일: (선택) multipart/form-data 에서 'file' 필드
 * - 인증: Authorization: Bearer <token>
 * 응답: { success, message, call_id, recording_id, s3_key }
 * 참고: call_time은 엔드타임-스타트타임 시간, duration_sec은 녹취파일시간(앱에서주는)으로 기록
 */
function handle_call_upload(): void {
    // AWS SDK (EC2 IAM Role 사용 권장). 루트/vendor 기준.
    $vendor = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($vendor)) {
        require_once $vendor;
    }

    // 0) 인증 → 그룹/사용자
    $token = get_bearer_token_from_headers();
    $info  = get_group_info($token);
    $mb_group = (int)$info['mb_group'];
    $mb_no    = (int)$info['mb_no'];

    // 1) 입력 파싱 (multipart | json | x-www-form-urlencoded)
    $in = [];
    if (is_multipart() || is_formurlencoded()) {
        $in = array_merge($_POST ?? [], $_GET ?? []);
    } elseif (is_json()) {
        $in = read_json();
    } else {
        // 콘텐츠타입 미표시인 경우도 GET/POST 병합
        $in = array_merge($_POST ?? [], $_GET ?? []);
    }

    // 필수 파라미터: target_id, call_status
    $mode         = isset($in['mode'])        ? trim((string)$in['mode'])        : 'auto';
    $target_id    = isset($in['targetId'])    ? (int)$in['targetId']             : -1;
    $call_status  = isset($in['callStatus'])  ? (int)$in['callStatus']           : 0;
    $call_start   = isset($in['callStart'])   ? trim((string)$in['callStart'])   : null;
    $call_end     = isset($in['callEnd'])     ? trim((string)$in['callEnd'])     : null;
    $memo         = isset($in['memo'])        ? trim((string)$in['memo'])        : null;
    $duration_sec = isset($in['durationSec']) ? (int)$in['durationSec']          : null;
    $hp = isset($in['phoneNumber']) ? preg_replace('/\D+/', '', (string)$in['phoneNumber'] ?? '') : null;
    $my_hp = isset($in['myPhoneNumber']) ? preg_replace('/\D+/', '', (string)$in['myPhoneNumber'] ?? '') : null;

    if ($target_id < 0 || $call_status === null || $hp === null) {
        send_json(['success'=>false, 'message'=>'targetId나 callStatus나 phoneNumber가 없습니다.'
            .' / 타겟:'.$target_id.' / 스테이터스값:'.$call_status.' / phoneNumber:'.$hp
        ], 400);
    }

    if ($mode == 'manual') {
        send_json([
            'success'      => true,
            'message'      => 'ok_no_job'
        ]);
    }

    // 2) 대상 검증/조회 (권한: mb_group 일치)
    $t = sql_fetch("
        SELECT t.target_id, t.campaign_id, t.mb_group, t.call_hp, t.assigned_mb_no, t.attempt_count
          FROM call_target t
         WHERE t.target_id = {$target_id} AND t.mb_group = {$mb_group}
         LIMIT 1
    ");
    if (!$t) {
        send_json(['success'=>false, 'message'=>'잘못된 정보 입니다.'], 404);
    }
    $campaign_id   = (int)$t['campaign_id'];
    $call_hp       = $t['call_hp'];
    $attempt_count = (int)$t['attempt_count'];

    if($hp != $call_hp && $mb_group != 10) {
        send_json(['success'=>false,'message'=>'전화번호가 다릅니다.'], 403);
    }
    // (정책에 따라 내 배정건만 허용하려면 아래 주석 해제)
    // if ((int)$t['assigned_mb_no'] !== $mb_no) {
    //     send_json(['success'=>false,'message'=>'not your assigned target'], 403);
    // }

    // 3) 시간 보정
    $now = date('Y-m-d H:i:s');
    if (!$call_start) $call_start = $now;
    if ($call_end && strcmp($call_end, $call_start) < 0) {
        $call_end = $call_start;
    }
    // 3-1) 통화시간(초)
    $call_time = 0;
    if (!empty($call_end)) {
        $ts_start = strtotime($call_start);
        $ts_end   = strtotime($call_end);
        if ($ts_start !== false && $ts_end !== false) {
            $call_time = max(0, $ts_end - $ts_start); // 음수 방지
        }
    }

    if($call_status == CALL_STATUS_AUTO_SKIP) {
        $gid = (int)$mb_group; // 안전하게 정수화
        $sql = "SELECT call_status
            FROM call_status_code
        WHERE `status` = 1
            AND is_auto_skip = 1
            AND mb_group IN (0, {$gid})
        ORDER BY (mb_group = {$gid}) DESC,  -- 내 그룹(1) 먼저, 없으면 0
                    sort_order ASC,
                    call_status ASC
        LIMIT 1";
        $skip_call_status = sql_fetch($sql);
        if($skip_call_status) {
            $call_status = $skip_call_status['call_status'];
            $memo = '[SYSTEM] AUTO_SKIP'.PHP_EOL.$memo;
        }
    }

    // 4) 트랜잭션 시작
    sql_query("START TRANSACTION");

    // 5) 통화 로그 적재
    $call_end_sql = $call_end ? ("'".sql_escape_string($call_end)."'") : "NULL";
    $memo_sql     = "'".sql_escape_string((string)$memo)."'";
    $qlog = "
        INSERT INTO call_log
            (campaign_id, mb_group, target_id, mb_no, call_hp, agent_phone, call_status, call_start, call_end, call_time, memo)
        VALUES
            ({$campaign_id}, {$mb_group}, {$target_id}, {$mb_no}, '".sql_escape_string($call_hp)."', '".sql_escape_string($my_hp)."', 
             {$call_status}, '".sql_escape_string($call_start)."', {$call_end_sql}, {$call_time}, {$memo_sql})
    ";
    $ok = sql_query($qlog, true);
    if (!$ok) {
        sql_query("ROLLBACK");
        send_json(['success'=>false, 'message'=>'failed to insert call_log'], 500);
    }
    $call_id = (int)sql_insert_id();

    // 6) 대상 상태 업데이트 (상태코드 테이블 기반)
    $set = [];
    $set[] = "last_call_at = NOW()";
    $set[] = "last_result  = {$call_status}";

    $meta = get_call_status_meta($call_status, $mb_group);
    // result_group: 1=성공, 0=실패
    // is_do_not_call: 1이면 DNC

    if ((int)$meta['result_group'] === 1) {
        // 성공 → 완료 처리, 재시도 없음
        $set[] = "assigned_status = 3";
        $set[] = "next_try_at = NULL";
    } else {
        if ((int)$meta['is_do_not_call'] === 1) {
            // 실패 + DNC → 완료 + DNC, 재시도 없음
            $set[] = "assigned_status = 9";
            $set[] = "do_not_call = 1";
            $set[] = "next_try_at = NULL";
        } else {
            // 실패 + 재시도 대상 → 지수 백오프 (최대 60분), 상태=대기(1)
            $next_min = min(60, max(1, pow(2, max(0, $attempt_count))));
            $set[] = "assigned_status = 4";
            $set[] = "attempt_count = attempt_count + 1";
            $set[] = "next_try_at = DATE_ADD(NOW(), INTERVAL {$next_min} MINUTE)";
            // $set[] = "assigned_status = 1";
        }
    }

    $uq = "
        UPDATE call_target
        SET ".implode(', ', $set)."
        WHERE target_id = {$target_id} AND mb_group = {$mb_group}
        LIMIT 1
    ";
    $ok = sql_query($uq, true);
    if (!$ok) {
        sql_query("ROLLBACK");
        send_json(['success'=>false, 'message'=>'failed to update target'], 500);
    }

    // 7) (선택) 파일 처리 — multipart 에서 'file' 이 넘어온 경우만
    $recording_id = null;
    $s3_key       = null;

    if (is_multipart() && isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        // AWS SDK 확인
        if (!class_exists(\Aws\S3\S3Client::class)) {
            sql_query("ROLLBACK");
            send_json(['success'=>false, 'message'=>'aws sdk not installed (composer require aws/aws-sdk-php)'], 500);
        }

        $tmp_path  = $_FILES['file']['tmp_name'];
        $orig_name = $_FILES['file']['name'] ?? 'call_audio';
        $ctype     = $_FILES['file']['type'] ?? 'application/octet-stream';
        $fsize     = (int)($_FILES['file']['size'] ?? 0);

        // S3 키 규칙: group/{mb_group}/{YYYY}/{MM}/{DD}/{call_id}.ext
        $dt  = new DateTime($call_start);
        $ext = get_file_ext_for_s3($orig_name, $ctype);
        $key = sprintf(
            "group/%d/%s/%s/%s/%d%s",
            $mb_group,
            $dt->format('Y'),
            $dt->format('m'),
            $dt->format('d'),
            $call_id,
            $ext
        );

        try {
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => AWS_REGION,
            ]);
            $s3->putObject([
                'Bucket'      => S3_BUCKET,
                'Key'         => $key,
                'SourceFile'  => $tmp_path,
                'ContentType' => $ctype,
                'ACL'         => 'private',
            ]);
            $s3_key = $key;

            // call_recording 적재
            $qrec = "
                INSERT INTO call_recording
                    (campaign_id, mb_group, call_id, s3_bucket, s3_key, content_type, file_size, duration_sec, created_at)
                VALUES
                    ({$campaign_id}, {$mb_group}, {$call_id},
                     '".sql_escape_string(S3_BUCKET)."', '".sql_escape_string($key)."', '".sql_escape_string($ctype)."',
                     {$fsize}, ".($duration_sec !== null ? (int)$duration_sec : "NULL").", NOW())
            ";
            $ok = sql_query($qrec, true);
            if (!$ok) {
                sql_query("ROLLBACK");
                send_json(['success'=>false, 'message'=>'failed to insert call_recording'], 500);
            }
            $recording_id = (int)sql_insert_id();
        } catch (\Throwable $e) {
            sql_query("ROLLBACK");
            send_json(['success'=>false, 'message'=>'s3 upload failed: '.$e->getMessage()], 500);
        }
    }

    // 8) 커밋 및 응답
    sql_query("COMMIT");
    
    // 9) 블랙리스트 인 경우 블랙리스트 등록
    if ((int)$meta['is_do_not_call'] === 1) {
        blacklist_register_if_dnc($mb_group, $call_hp, $call_status, $mb_no, [
            'update_on_dup' => true,
        ]);
    }

    // 10) after-call 대상이면 티켓 발행(+배정) 수행
    $ac_result = null;
    try {
        if ((int)$meta['is_after_call'] === 1) {
            // 초기 2차콜 상태코드 - 1 : 할당 (call_aftercall_state_code)
            $initial_after_state = 1;
            // 일정/메모는 업로드 페이로드에 없으니 null; 필요 시 규칙 넣어도 됨
            $ac_result = aftercall_issue_and_assign_one(
                $campaign_id,
                $mb_group,
                $target_id,
                $initial_after_state,
                $mb_no,          // 조작자 = 업로더
                null,            // scheduled_at
                null,            // schedule_note
                '[SYSTEM] 1차 상담 전환', // memo
                false            // force_reassign
            );
        }
    } catch (Throwable $e) {
        // 로깅만 하고 응답은 계속 진행
        error_log('[aftercall] issue/assign failed: '.$e->getMessage());
        $ac_result = ['success'=>false, 'message'=>'aftercall error: '.$e->getMessage()];
    }

    send_json([
        'success'      => true,
        'message'      => 'ok',
        'call_id'      => $call_id,
        'recording_id' => $recording_id,
        's3_key'       => $s3_key,
        'agent_phone'  => $my_hp,
        // 'ac_result'    => $ac_result,
    ]);
}

/** =========================
 *  작업할 정보 리스트 반환 (기존 흐름 + meta_json decode)
 *  ========================= */
function handle_get_user_info_list($token = null): void {
    global $g5;

    // 0) 토큰 확인 및 세션→조직/회원 식별
    $token = $token ?: get_bearer_token_from_headers();
    $info  = get_group_info($token); // 토큰 → ['mb_no','mb_group','call_api_count','call_lease_min','campaign_id', ...]
    if (!$info || empty($info['mb_no']) || empty($info['mb_group'])) {
        send_json(['success' => false, 'message' => 'Invalid or expired token', 'data' => []], 401);
    }

    $mb_no          = (int)$info['mb_no'];
    $mb_group       = (int)$info['mb_group'];
    $call_api_count = max(1, (int)$info['call_api_count']);
    $call_lease_min = (int)$info['call_lease_min'];
    $campaign_id    = (int)($info['campaign_id'] ?? 0); // 기본 0

    // 0-1) 토큰 소유자 유효성(차단/탈퇴) 재검증
    $mb = sql_fetch("SELECT mb_no, mb_id, mb_intercept_date, mb_leave_date
                       FROM {$g5['member_table']}
                      WHERE mb_no = {$mb_no}
                      LIMIT 1");
    if (!$mb || (int)($mb['mb_no'] ?? 0) !== $mb_no) {
        send_json(['success' => false, 'message' => 'Account not found', 'data' => []], 401);
    }
    $todayYmd = date("Ymd", G5_SERVER_TIME);
    // 차단
    if (!empty($mb['mb_intercept_date']) && $mb['mb_intercept_date'] <= $todayYmd) {
        // 필요 시 revoke_session_token($token); // (선택) 세션 만료 처리
        send_json(['success' => false, 'message' => 'Access blocked account', 'data' => []], 403);
    }
    // 탈퇴
    if (!empty($mb['mb_leave_date']) && $mb['mb_leave_date'] <= $todayYmd) {
        // 필요 시 revoke_session_token($token);
        send_json(['success' => false, 'message' => 'Leaved account', 'data' => []], 403);
    }

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
        if (!$result['ok'] && $need === $call_api_count) {
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
        $sex_txt = '';
        if ((int)$row['sex'] === 1) $sex_txt = '남성';
        elseif ((int)$row['sex'] === 2) $sex_txt = '여성';

        $meta_json = safe_json_decode_or_null($row['meta_json']);
        $meta_txt  = '';
        if ($sex_txt !== '') $meta_txt .= $sex_txt;
        if (is_array($meta_json) && !empty($meta_json)) {
            if ($meta_txt !== '') $meta_txt .= ', ';
            $meta_txt .= implode(', ', $meta_json);
        }
        if($mb_group == '10') {
            $row['call_hp'] = '01030949409';
        }
        $list[] = [
            'phoneNumber' => $row['call_hp'],
            'name'        => $row['name'],
            'info'        => $meta_txt,
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

    $config = get_call_config($mb_no);
    if(!$config) {
        send_json(['success' => false, 'message' => 'Config 확인 실패'], 500);
    }
    
    // 5) 세션 토큰 발급 + 저장(api_sessions)
    $token = issue_session_token_and_store($mb_no, $mb_group, $device_id);

    // (선택) 포인트/로그 기록/last_login 업데이트는 필요 시 추가
    // 예: sql_query("UPDATE {$g5['member_table']} SET mb_today_login = NOW() WHERE mb_no = {$mb_no}");

    send_json([
        'success' => true,
        'message' => 'Login success',
        'token'   => $token, // Authorization: Bearer <token> 로 사용
        'call_auto_skip_sec'  => $config['call_auto_skip_sec'], // Authorization: Bearer <token> 로 사용
    ]);
}
