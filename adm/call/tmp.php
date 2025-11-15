<?php
// /adm/call/tmp_load_mid4_only.php
// 목적: /mnt/data/phone.xlsx 전체 셀에서 4자리 숫자 토큰(mid4)만 추출 → call_phone_mid4(mid4) 에 적재
// 특징: 한 셀에 "1234/5678"처럼 여러 개여도 각 토큰을 개별 row로 저장. 중복은 PK로 무시.
// 실행: 브라우저에서 1회 실행하고 삭제 권장
// 환경: 그누보드5, PHPExcel(내장)
require_once './_common.php';

if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// ===== 설정 =====
$FILE_PATH = G5_PATH.'/phone.xlsx'; // 이번 업로드 대상 고정
$BULK_SIZE = 800;                    // 벌크 INSERT 크기 (필요 시 조정)

// ===== PHPExcel 로드 =====
include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');

// ===== 유틸 =====
/**
 * 셀 문자열에서 4자리 숫자 토큰을 모두 추출
 * 예: "010-1234-5678 / 9876" → ['1234','5678','9876']
 */
function extract_mid4_tokens($raw) {
    $s = (string)$raw;
    if ($s === '') return [];
    // 숫자 경계 기반 4자리만 추출 (앞뒤가 숫자가 아닌 곳에서만 매칭)
    preg_match_all('/(?<!\d)(\d{4})(?!\d)/u', $s, $m);
    return $m[1] ?? [];
}

// ===== 파일 확인 =====
if (!is_file($FILE_PATH)) {
    die("파일을 찾을 수 없습니다: {$FILE_PATH}");
}

// ===== 엑셀 로드 =====
try {
    $obj = PHPExcel_IOFactory::load($FILE_PATH);
} catch (Exception $e) {
    die('엑셀 로드 실패: '.$e->getMessage());
}

$sheetCount     = $obj->getSheetCount();
$total_cells    = 0;
$total_tokens   = 0;
$insert_attempt = 0;

$vals = [];              // 벌크 VALUES
$seen_in_batch = [];     // 같은 실행 중 중복 제거(선택: 쿼리 건수 줄이기)

/**
 * 벌크 쓰기
 */
$flush = function() use (&$vals, &$insert_attempt) {
    if (!$vals) return;
    $sql = "INSERT IGNORE INTO call_phone_mid4 (mid4) VALUES ".implode(',', $vals);
    sql_query($sql, true);
    $insert_attempt += count($vals);
    $vals = [];
};

// ===== 순회 =====
for ($si=0; $si<$sheetCount; $si++) {
    $sheet = $obj->getSheet($si);
    $highestRow      = $sheet->getHighestRow();
    $highestCol      = $sheet->getHighestColumn();
    $highestColIndex = PHPExcel_Cell::columnIndexFromString($highestCol);

    for ($r=1; $r <= $highestRow; $r++) {
        for ($c=0; $c < $highestColIndex; $c++) {
            $total_cells++;
            $val = $sheet->getCellByColumnAndRow($c, $r)->getValue();
            if ($val === null || $val === '') continue;

            $tokens = extract_mid4_tokens($val);
            if (!$tokens) continue;

            foreach ($tokens as $mid4) {
                // 안전장치(정확히 4자리 숫자만)
                if (!preg_match('/^\d{4}$/', $mid4)) continue;

                $total_tokens++;

                // 같은 실행 내 중복은 스킵(쿼리 수 절감)
                if (isset($seen_in_batch[$mid4])) continue;
                $seen_in_batch[$mid4] = 1;

                $vals[] = "('".sql_escape_string($mid4)."')";

                if (count($vals) >= $BULK_SIZE) {
                    $flush();
                }
            }
        }
    }
}
// 잔여 쓰기
$flush();

// ===== 결과 =====
echo nl2br(
    "[완료] mid4 적재\n".
    "- 파일: {$FILE_PATH}\n".
    "- 시트 수: {$sheetCount}\n".
    "- 검사한 셀: {$total_cells}\n".
    "- 추출된 4자리 토큰 수: {$total_tokens}\n".
    "- INSERT 시도(벌크 rows): {$insert_attempt}\n".
    "- 테이블: call_phone_mid4(mid4)\n"
);

// 1회성 스크립트면 실행 후 삭제 권장
// @unlink(__FILE__);
