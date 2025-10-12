<?php

function _g($key, $def='') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $def; }
function _p($key, $def='') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $def; }
function _h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * 한국형 전화번호 보기 좋게 포맷
 * - 02 지역번호는 2-4-4
 * - 휴대폰/기타 11자리: 3-4-4
 * - 12자리: 4-4-4
 * - 10자리: 3-3-4 (일반 케이스)
 * - 그 외: 뒤에서 4-4 나누고 앞부분 그대로
 */
function format_korean_phone(string $hp_raw): string {
    $hp = preg_replace('/\D+/', '', $hp_raw);
    $len = strlen($hp);
    if ($len === 0) return $hp_raw;

    // 02 지역번호 케이스(9~10자리)
    if (substr($hp, 0, 2) === '02' && ($len === 9 || $len === 10)) {
        if ($len === 9)  return '02-'.substr($hp, 2, 3).'-'.substr($hp, 5, 4);  // 2-3-4
        if ($len === 10) return '02-'.substr($hp, 2, 4).'-'.substr($hp, 6, 4);  // 2-4-4
    }

    // 11자리(휴대폰 일반): 3-4-4
    if ($len === 11) return substr($hp, 0, 3).'-'.substr($hp, 3, 4).'-'.substr($hp, 7, 4);
    // 12자리: 4-4-4
    if ($len === 12) return substr($hp, 0, 4).'-'.substr($hp, 4, 4).'-'.substr($hp, 8, 4);
    // 10자리: 3-3-4
    if ($len === 10) return substr($hp, 0, 3).'-'.substr($hp, 3, 3).'-'.substr($hp, 6, 4);

    // 그 외: 뒤에서 4-4 자르고 나머지 앞
    if ($len > 8) {
        return substr($hp, 0, $len-8).'-'.substr($hp, $len-8, 4).'-'.substr($hp, $len-4, 4);
    } elseif ($len > 4) {
        return substr($hp, 0, $len-4).'-'.substr($hp, $len-4, 4);
    }
    return $hp;
}

/** 만 나이(생년월일 Y-m-d) */
function calc_age_years($birth_date){
    if (!$birth_date) return null;
    $today = new DateTime('today');
    $b = DateTime::createFromFormat('Y-m-d', $birth_date);
    if (!$b) return null;
    $age = (int)$today->format('Y') - (int)$b->format('Y');
    if ($today->format('md') < $b->format('md')) $age--;
    return $age;
}
