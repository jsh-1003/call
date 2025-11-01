<?php
require_once './_common.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// -----------------------------
// 접근 권한: 관리자 레벨 7 이상
// -----------------------------
if ($is_admin !== 'super') {
    alert('접근 권한이 없습니다.');
}

$g5['title'] = '녹취 S3 파일명 일괄 변경 (테스트: 1~10)';

// AWS SDK 로드 (프로젝트 루트 기준 vendor)
$vendor = realpath(G5_PATH.'/vendor/autoload.php');
if (!$vendor) $vendor = realpath(dirname(__DIR__).'/vendor/autoload.php');
if (!$vendor) die('Composer autoload를 찾을 수 없습니다. vendor/autoload.php 확인');

require_once $vendor;

// -----------------------------
// 유틸
// -----------------------------
function safe_filename(string $name): string {
    // 앞뒤 공백 제거, 제어문자 제거
    $name = trim($name);
    // 위험문자 제거/치환 (슬래시, 역슬래시 포함)
    $name = str_replace(['/', '\\', "\0"], ' ', $name);
    // 윈도우 예약문자:  : * ? " < > |  를 밑줄로 치환
    $name = preg_replace('/[:\*\?"<>\|]/u', '_', $name);
    // 공백 정리
    $name = preg_replace('/\s+/u', '_', $name);
    // 너무 긴 경우 앞쪽만 사용 (S3 키 전체는 1024B 제한, 여기서는 파일명 180자 제한)
    if (mb_strlen($name, 'UTF-8') > 180) {
        $name = mb_substr($name, 0, 180, 'UTF-8');
    }
    return $name === '' ? 'NONAME' : $name;
}

function path_join($dir, $file) {
    $dir = rtrim($dir, '/');
    return $dir === '' ? $file : ($dir . '/' . $file);
}

// 동일 키 존재 시 뒤에 -n 붙이기
function ensure_unique_key(S3Client $s3, string $bucket, string $dir, string $base, string $ext): string {
    $candidate = $base . $ext;
    $key = path_join($dir, $candidate);
    $n = 1;
    while (true) {
        try {
            $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
            // 존재하면 증분
            $candidate = $base . '-' . $n . $ext;
            $key = path_join($dir, $candidate);
            $n++;
        } catch (AwsException $e) {
            // 404 Not Found면 사용 가능
            $code = $e->getStatusCode();
            if ($code === 404) {
                return $key;
            }
            // 그 외 오류면 재던지기
            throw $e;
        }
    }
}

// -----------------------------
// 리네임 대상(테스트: recording_id 1~10)
// 이름과 전화번호 확보를 위해 call_log, call_target 조인
// -----------------------------
$sql = "
SELECT
  r.recording_id,
  r.s3_bucket,
  r.s3_key,
  r.campaign_id,
  r.mb_group,
  r.call_id,
  l.call_hp,
  t.name
FROM call_recording r
JOIN call_log l
  ON l.call_id = r.call_id
LEFT JOIN call_target t
  ON t.target_id = l.target_id
WHERE r.recording_id BETWEEN 24687 AND 30000
ORDER BY r.recording_id ASC
";
$rs = sql_query($sql);

$rows = [];
for ($i=0; $row = sql_fetch_array($rs); $i++) {
    $rows[] = $row;
}

if (!$rows) {
    echo '<p>대상 레코드가 없습니다.</p>';
    exit;
}

// -----------------------------
// S3 클라이언트 생성 (리전은 인프라에 맞게)
// -----------------------------
$s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'ap-northeast-2', // 필요 시 수정
    // EC2 IAM Role 사용 시 자격증명 설정 불필요
]);

$results = [];
foreach ($rows as $r) {
    $recording_id = (int)$r['recording_id'];
    $bucket = $r['s3_bucket'];
    $oldKey = $r['s3_key'];
    $hp = preg_replace('/[^0-9]/', '', (string)$r['call_hp']); // 숫자만
    $name = safe_filename((string)($r['name'] ?? ''));
    if ($name === '' || $name === 'NONAME') {
        // 이름이 비어 있으면 'NONAME' 사용
        $name = 'NONAME';
    }

    // 경로/파일 분리
    $pi = pathinfo($oldKey);
    $dir = $pi['dirname'] ?? '';
    $oldBase = $pi['basename'] ?? '';
    $ext = isset($pi['extension']) ? ('.'.$pi['extension']) : '';
    // ✅ [추가] 숫자로 시작하지 않으면 이미 변경된 파일로 간주 → skip
    if (!preg_match('/^[0-9]/', $oldBase)) {
        $results[] = [
            'recording_id' => $recording_id,
            'status' => 'skip',
            'message' => '파일명이 숫자로 시작하지 않아 스킵',
            'old_key' => $oldKey
        ];
        continue;
    }

    // 새 파일명: 이름_전화번호_기존파일명
    $newBaseCore = safe_filename($name) . '_' . $hp . '_' . $oldBase;
    // 혹시 확장자가 중복되면 정리 (oldBase에 이미 .m4a 포함이므로 위에서 그대로 둠)
    // 고유키 보장
    try {
        $newKey = ensure_unique_key($s3, $bucket, $dir, $newBaseCore, '');
    } catch (AwsException $e) {
        $results[] = [
            'recording_id' => $recording_id,
            'status' => 'skip',
            'message' => 'unique key check 실패: '.$e->getAwsErrorMessage()
        ];
        continue;
    }

    // 1) S3 Copy
    try {
        $s3->copyObject([
            'Bucket' => $bucket,
            'Key' => $newKey,
            'CopySource' => rawurlencode($bucket . '/' . $oldKey),
            'MetadataDirective' => 'COPY', // 기존 메타 유지
            // 'ACL' => 'private', // 필요 시 명시
        ]);
    } catch (AwsException $e) {
        $results[] = [
            'recording_id' => $recording_id,
            'status' => 'error',
            'message' => 'S3 copy 실패: '.$e->getAwsErrorMessage()
        ];
        continue;
    }

    // 2) DB 업데이트 (copy 성공 후)
    $ok = false;
    try {
        sql_query('START TRANSACTION');
        $aff = sql_query("
          UPDATE call_recording
             SET s3_key = '".sql_real_escape_string($newKey)."'
           WHERE recording_id = {$recording_id}
             AND s3_key = '".sql_real_escape_string($oldKey)."'
        ");
        // 그누보드 sql_query는 성공/실패만 반환 → 재조회로 확인
        $chk = sql_fetch("
          SELECT s3_key FROM call_recording WHERE recording_id={$recording_id}
        ");
        if ($chk && $chk['s3_key'] === $newKey) {
            sql_query('COMMIT');
            $ok = true;
        } else {
            sql_query('ROLLBACK');
        }
    } catch (Exception $e) {
        @sql_query('ROLLBACK');
        $ok = false;
    }

    if (!$ok) {
        // DB 실패 시 새 객체 삭제(롤백 유사)
        try {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $newKey]);
        } catch (\Throwable $t) {}
        $results[] = [
            'recording_id' => $recording_id,
            'status' => 'error',
            'message' => 'DB 업데이트 실패'
        ];
        continue;
    }

    // 3) 원본 삭제 (DB 업데이트 성공 후)
    $deleted = true;
    try {
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => $oldKey]);
    } catch (AwsException $e) {
        $deleted = false; // 남아 있어도 치명적이지 않음(중복 보관)
    }

    $results[] = [
        'recording_id' => $recording_id,
        'status' => 'ok',
        'old_key' => $oldKey,
        'new_key' => $newKey,
        'old_deleted' => $deleted ? 1 : 0
    ];
}

// -----------------------------
// 결과 출력 (간단 테이블)
// -----------------------------
include_once(G5_PATH.'/_head.php');
?>
<div class="local_desc01 local_desc">
    <p><strong>처리 결과 (recording_id 1~10):</strong></p>
</div>
<div class="tbl_head01 tbl_wrap">
    <table>
        <thead>
            <tr>
                <th>recording_id</th>
                <th>status</th>
                <th>old_key</th>
                <th>new_key</th>
                <th>old_deleted</th>
                <th>message</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td><?php echo (int)$r['recording_id']; ?></td>
                <td><?php echo get_text($r['status']); ?></td>
                <td style="max-width:420px;word-break:break-all"><?php echo isset($r['old_key'])? get_text($r['old_key']) : '-'; ?></td>
                <td style="max-width:420px;word-break:break-all"><?php echo isset($r['new_key'])? get_text($r['new_key']) : '-'; ?></td>
                <td><?php echo isset($r['old_deleted'])? (int)$r['old_deleted'] : '-'; ?></td>
                <td><?php echo isset($r['message'])? get_text($r['message']) : ''; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
include_once(G5_PATH.'/_tail.php');
