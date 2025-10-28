<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

function _g($key, $def='') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $def; }
function _p($key, $def='') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $def; }
function _h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }


/**
 * 날짜 범위 버튼 렌더링
 *
 * @param string $container_id  버튼 컨테이너 id (페이지 내 유일)
 * @param array  $ranges        노출할 범위 키 배열
 *                              ['yesterday','last_week','last_month','today','this_week','this_month']
 * @param string $class_wrap    래퍼 class
 * @param string $class_btn     버튼 class
 */
function render_date_range_buttons(
    string $container_id = 'dateRangeBtns',
    array $ranges = ['yesterday','last_week','last_month','today','this_week','this_month'],
    string $class_wrap = 'btn-line btn-grid-2x3',
    string $class_btn = 'btn-mini'
){
    // key => label
    $labels = [
        'yesterday'   => '어제',
        'today'       => '오늘',
        'last_week'   => '지난주',
        'this_week'   => '이번주',
        'last_month'  => '지난달',
        'this_month'  => '이번달',
    ];
    echo '<span class="'.htmlspecialchars($class_wrap).'" id="'.htmlspecialchars($container_id).'"';
    echo ' data-range-container="1">';
    foreach ($ranges as $k) {
        if (!isset($labels[$k])) continue;
        echo '<button type="button" class="'.htmlspecialchars($class_btn).'" data-range="'.htmlspecialchars($k).'">'
            . htmlspecialchars($labels[$k]) . '</button>';
    }
    echo '</span>';
}


/** JSON 미리보기(1줄 요약 + 펼치기 토글) */
function pretty_json_preview($json_str){
    if ($json_str === null || $json_str === '') return '';
    $data = json_decode($json_str, true);
    if ($data === null) return '<span class="small-muted">'. _h($json_str) .'</span>';
    $pretty = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $firstKeys = array_slice(array_keys($data), 0, 3);
    $summary = implode(', ', $firstKeys);
    return '<details><summary>'. _h($summary ?: 'json') .'</summary><pre class="json">'. _h($pretty) .'</pre></details>';
}

// MIME 보정: DB content_type이 비거나 audio/*이면 확장자로 추론
function guess_audio_mime($s3_key, $db_mime=''){
    $db_mime = trim((string)$db_mime);
    $is_specific = (bool)preg_match('#^audio/[a-z0-9\-\+\.]+$#i', $db_mime) && strtolower($db_mime)!=='audio/*';
    if ($is_specific) return $db_mime;

    $ext = strtolower(pathinfo($s3_key ?? '', PATHINFO_EXTENSION));
    switch ($ext) {
        case 'm4a':
        case 'mp4':  return 'audio/mp4';
        case 'mp3':  return 'audio/mpeg';
        case 'wav':  return 'audio/wav';
        case 'ogg':  return 'audio/ogg';
        case 'oga':  return 'audio/ogg';
        case 'opus': return 'audio/opus';
        case 'aac':  return 'audio/aac';
        case 'weba': return 'audio/webm';
        case 'webm': return 'audio/webm';
        case 'amr':  return 'audio/amr';
        default:     return 'audio/mpeg'; // 안전 기본값
    }
}

function fmt_datetime($s, $end_type='i') {
    if($end_type == 'i')
        return substr($s, 2, 14);
    else if($end_type == 's')
        return substr($s, 2, 17);
    else if($end_type == 'hi')
        return substr($s, 11, 5);
    else
        return substr($s, 5, 11);
}

function fmt_hms($s) {
    if ($s === null) return '-';
    $s = max(0, (int)$s);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $sec)
                  : sprintf('%02d:%02d', $m, $sec);
}

function fmt_bytes($b){
    $b = (int)$b; if ($b<=0) return '-';
    $u = ['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){$b/=1024;$i++;}
    return number_format($b, ($i>=2?2:0)).' '.$u[$i];
}

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


// --- Hangul-only Normalizer fallback (NFC <-> NFD) ---
// Normalizer 없으면 한글만 조합/분해. (U+AC00~U+D7A3 <-> choseong/jungseong/jongseong)
if (!function_exists('k_has_normalizer')) {
    function k_has_normalizer(){ return class_exists('Normalizer'); }
}
if (!function_exists('k_nfc')) {
    function k_nfc($s){
        if ($s === null || $s === '') return $s;
        if (!is_string($s)) $s = (string)$s;
        if (k_has_normalizer()) return Normalizer::normalize($s, Normalizer::FORM_C);
        // fallback: compose Hangul from jamo sequences
        $LBase=0x1100; $VBase=0x1161; $TBase=0x11A7; $SBase=0xAC00;
        $LCount=19; $VCount=21; $TCount=28; $NCount=$VCount*$TCount; $SCount=$LCount*$NCount;

        $codepoints = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $i = 0; $n = count($codepoints);
        $cp = function($ch){ return IntlChar::ord($ch); }; // 없을 수 있어 직접 ord 구현
        if (!function_exists('IntlChar::ord')) {
            $cp = function($ch){
                $u = unpack('N', mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8'));
                return $u ? $u[1] : 0;
            };
        }
        while ($i < $n) {
            $L = $cp($codepoints[$i]);
            // choseong?
            if ($L >= $LBase && $L < $LBase+$LCount) {
                if ($i+1 < $n) {
                    $V = $cp($codepoints[$i+1]);
                    if ($V >= $VBase && $V < $VBase+$VCount) {
                        $i2 = $i+2; $Tindex = 0;
                        if ($i2 < $n) {
                            $T = $cp($codepoints[$i2]);
                            if ($T > $TBase && $T < $TBase+$TCount) { $Tindex = $T - $TBase; $i2++; }
                        }
                        $Lindex = $L - $LBase;
                        $Vindex = $V - $VBase;
                        $Sindex = $Lindex*$NCount + $Vindex*$TCount + $Tindex;
                        if ($Sindex >=0 && $Sindex < $SCount) {
                            $out[] = mb_convert_encoding(pack('N', $SBase + $Sindex), 'UTF-8', 'UCS-4BE');
                            $i = $i2;
                            continue;
                        }
                    }
                }
            }
            $out[] = $codepoints[$i];
            $i++;
        }
        return implode('', $out);
    }
}

if (!function_exists('k_nfd')) {
    function k_nfd($s){
        if ($s === null || $s === '') return $s;
        if (!is_string($s)) $s = (string)$s;
        if (k_has_normalizer()) return Normalizer::normalize($s, Normalizer::FORM_D);
        // fallback: decompose Hangul syllables to jamo
        $LBase=0x1100; $VBase=0x1161; $TBase=0x11A7; $SBase=0xAC00;
        $LCount=19; $VCount=21; $TCount=28; $NCount=$VCount*$TCount; $SCount=$LCount*$NCount;
        $out = '';
        // iterate codepoints
        $len = mb_strlen($s, 'UTF-8');
        for ($i=0;$i<$len;$i++){
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            $u = unpack('N', mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8'))[1];
            $Sindex = $u - $SBase;
            if (0 <= $Sindex && $Sindex < $SCount) {
                $L = intdiv($Sindex, $NCount);
                $V = intdiv($Sindex % $NCount, $TCount);
                $T = $Sindex % $TCount;
                $out .= mb_convert_encoding(pack('N', $LBase+$L), 'UTF-8', 'UCS-4BE');
                $out .= mb_convert_encoding(pack('N', $VBase+$V), 'UTF-8', 'UCS-4BE');
                if ($T != 0) $out .= mb_convert_encoding(pack('N', $TBase+$T), 'UTF-8', 'UCS-4BE');
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }
}
