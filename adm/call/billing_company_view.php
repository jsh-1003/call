<?php
// /adm/call/billing_company_view.php
$sub_menu = '700950';
require_once './_common.php';

auth_check_menu($auth, $sub_menu, 'r');

// -------------------------------------------
// 접근 권한: 레벨 10 이상
// -------------------------------------------
if ($member['mb_id'] != 'admin_pay') {
    alert('접근 권한이 없습니다.');
}

$g5['title'] = '회사별 정산 상세';

// -------------------------------------------
// 유틸
// -------------------------------------------
function ym_now(){ return (new DateTimeImmutable('first day of this month'))->format('Y-m'); }
function ym_add($ym, $n){
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym.'-01');
    if (!$dt) $dt = new DateTimeImmutable('first day of this month');
    return $dt->modify(($n>=0?'+':'').$n.' month')->format('Y-m');
}
function get_csrf_token_key(){ return 'billing_company_view_csrf'; }
if (!isset($_SESSION[get_csrf_token_key()])) {
    $_SESSION[get_csrf_token_key()] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION[get_csrf_token_key()];

// -------------------------------------------
// 파라미터: 리스트에서 링크로 들어오면 ?company_id=... 만 존재
// -------------------------------------------
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
if ($company_id <= 0) alert('company_id 가 없습니다.');

// 기본 기간: 최근 12개월
$default_end   = ym_now();
$default_start = ym_add($default_end, -11);

// URL에 기간이 오면 그대로 사용 (없으면 기본값)
$start = (isset($_GET['start']) && preg_match('/^\d{4}\-\d{2}$/', $_GET['start'])) ? $_GET['start'] : $default_start;
$end   = (isset($_GET['end'])   && preg_match('/^\d{4}\-\d{2}$/', $_GET['end']))   ? $_GET['end']   : $default_end;

// 회사 요약
$company_name = get_company_name_cached($company_id);
$team_count = (int)(sql_fetch("
    SELECT COUNT(*) AS c
    FROM g5_member
    WHERE company_id={$company_id}
      AND mb_level=7
      AND (mb_leave_date IS NULL OR mb_leave_date='')
      AND (mb_intercept_date IS NULL OR mb_intercept_date='')
")['c'] ?? 0);
$after_count = (int)(sql_fetch("
    SELECT COUNT(*) AS c
    FROM g5_member
    WHERE company_id={$company_id}
      AND mb_level=5
      AND (mb_leave_date IS NULL OR mb_leave_date='')
")['c'] ?? 0);
$agent_count = (int)(sql_fetch("
    SELECT COUNT(*) AS c
    FROM g5_member
    WHERE company_id={$company_id}
      AND mb_level=3
      AND (mb_leave_date IS NULL OR mb_leave_date='')
")['c'] ?? 0);

// -------------------------------------------
// 출력
// -------------------------------------------
include_once (G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="./billing_company_list.php" class="ov_listall">목록으로</a>';
?>
<div class="local_ov">
  <?php echo $listall; ?>
  <span class="btn_ov01">
    <span class="ov_txt">회사 정산 상세</span>
    <span class="ov_num"> <?php echo get_text($company_name); ?> (ID: <?php echo (int)$company_id; ?>)</span>
  </span>
</div>

<!-- 회사 요약 카드 -->
<div class="cards_wrap2">
  <div class="card">
    <div class="card_tit">회사명</div>
    <div class="card_val"><?php echo get_text($company_name); ?></div>
  </div>
  <div class="card">
    <div class="card_tit">지점 수</div>
    <div class="card_val"><?php echo number_format($team_count); ?> 팀</div>
  </div>
  <div class="card">
    <div class="card_tit">2차팀장 수</div>
    <div class="card_val"><?php echo number_format($after_count); ?> 명</div>
  </div>
  <div class="card">
    <div class="card_tit">상담원 수</div>
    <div class="card_val"><?php echo number_format($agent_count); ?> 명</div>
  </div>
</div>

<!-- 기간 선택 -->
<div class="local_sch01 local_sch" style="margin-bottom:18px">
  <form method="get" action="./billing_company_view.php" class="form-row" id="searchForm" autocomplete="off">
    <input type="hidden" name="company_id" value="<?php echo (int)$company_id; ?>">
    <label for="start">기간</label>
    <input type="month" id="start" name="start" value="<?php echo get_text($start); ?>" class="frm_input">
    <span class="tilde">~</span>
    <input type="month" id="end" name="end" value="<?php echo get_text($end); ?>" class="frm_input">
    <button type="submit" class="btn btn_03">페이지 조회</button>

    <span class="btn_right btn-nav">
      <button type="button" class="btn btn_02" data-range="last12">최근 12개월</button>
      <button type="button" class="btn btn_02" data-range="thisyear">올해</button>
      <button type="button" class="btn btn_02" data-range="lastyear">작년</button>
      <button type="button" class="btn btn_02" id="btnAjaxReload">재조회</button>
    </span>
  </form>
</div>

<!-- 월별 정산/수납 내역 -->
<div class="tbl_head01 tbl_wrap">
  <table id="tblHistory">
    <caption>월별 정산/수납 내역</caption>
    <thead>
      <tr>
        <th>월</th>
        <th>기본요금</th>
        <th>일할</th>
        <th>추가요금</th>
        <th>총요금</th>
        <th>수납합계</th>
        <th>미수금</th>
        <th>결제상태</th>
        <th>결제일</th>
        <th>상세</th>
      </tr>
    </thead>
    <tbody id="tbodyHistory">
      <tr><td class="empty_table" colspan="10">불러오는 중...</td></tr>
    </tbody>
    <tfoot>
      <tr id="tfootSum" style="display:none">
        <th>합계</th>
        <th class="td_money td_right" data-key="base_fee">-</th>
        <th class="td_money td_right" data-key="prorate_fee">-</th>
        <th class="td_money td_right" data-key="additional_fee">-</th>
        <th class="td_money td_right" data-key="total_fee">-</th>
        <th class="td_money td_right" data-key="paid_sum">-</th>
        <th class="td_money td_right" data-key="outstanding">-</th>
        <th colspan="3"></th>
      </tr>
    </tfoot>
  </table>
</div>

<!-- 코멘트 -->
<div class="comment_wrap">
  <h3>텍스트 코멘트</h3>
  <form id="commentForm" onsubmit="return false;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="company_id" value="<?php echo (int)$company_id; ?>">
    <div class="comment_controls">
      <label for="c_month">관련 월(선택)</label>
      <input type="month" id="c_month" name="month" class="frm_input" placeholder="YYYY-MM">
    </div>
    <textarea id="c_text" name="text" rows="3" class="frm_input" style="width:100%;" placeholder="코멘트를 입력하세요(누적 기록)"></textarea>
    <div class="comment_actions">
      <button type="button" id="btnAddComment" class="btn btn_01">코멘트 등록</button>
      <span class="desc">등록 시 수정 없이 누적으로 쌓입니다.</span>
    </div>
  </form>

  <div class="tbl_head01 tbl_wrap" style="margin-top:30px">
    <table>
      <thead>
        <tr>
          <th>등록일시</th>
          <th>작성자</th>
          <th>관련 월</th>
          <th>내용</th>
          <th>관리</th>
        </tr>
      </thead>
      <tbody id="tbodyComments">
        <tr><td class="empty_table" colspan="5">불러오는 중...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<style>
.cards_wrap2{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:10px;margin:18px 0}
.card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px}
.card_tit{font-size:.85rem;color:#6b7280;margin-bottom:4px}
.card_val{font-size:1.05rem;font-weight:700}
.td_right{text-align:right}
.td_money{white-space:nowrap}
.status_badge{display:inline-block;padding:.15rem .45rem;border-radius:6px;font-size:.8rem;margin-left:.25rem}
.bg-green{background:#16a34a;color:#fff}
.bg-gray{background:#9ca3af;color:#fff}
.bg-amber{background:#f59e0b;color:#111}
.comment_wrap{margin-top:26px}
.comment_controls{margin-bottom:6px}
.comment_actions{margin-top:6px;display:flex;gap:10px;align-items:center}
.desc{color:#666}
</style>

<script>
(function(){
  const companyId = <?php echo (int)$company_id; ?>;
  const csrfToken = "<?php echo $csrf_token; ?>";
  const startInit = "<?php echo get_text($start); ?>";
  const endInit   = "<?php echo get_text($end); ?>";

  const $tbody = document.getElementById('tbodyHistory');
  const $tfoot = document.getElementById('tfootSum');

  function fmt(num){
    num = parseInt(num||0,10);
    return num.toLocaleString('ko-KR');
  }

  function setRange(type){
    const $s = document.getElementById('start');
    const $e = document.getElementById('end');
    const now = new Date();
    const ym = (d)=> d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
    if(type==='last12'){
      const end = new Date(now.getFullYear(), now.getMonth(), 1);
      const start = new Date(now.getFullYear(), now.getMonth()-11, 1);
      $s.value = ym(start); $e.value = ym(end);
    } else if (type==='thisyear'){
      const start = new Date(now.getFullYear(), 0, 1);
      const end   = new Date(now.getFullYear(), now.getMonth(), 1);
      $s.value = ym(start); $e.value = ym(end);
    } else if (type==='lastyear'){
      const start = new Date(now.getFullYear()-1, 0, 1);
      const end   = new Date(now.getFullYear()-1, 11, 1);
      $s.value = ym(start); $e.value = ym(end);
    }
  }

  document.querySelectorAll('[data-range]').forEach(btn=>{
    btn.addEventListener('click', ()=> setRange(btn.getAttribute('data-range')));
  });

  document.getElementById('btnAjaxReload').addEventListener('click', ()=>{
    const s = document.getElementById('start').value;
    const e = document.getElementById('end').value;
    loadHistory(s,e);
  });

  async function loadHistory(start, end){
    $tbody.innerHTML = '<tr><td class="empty_table" colspan="10">불러오는 중...</td></tr>';
    try{
      const params = new URLSearchParams({
        action: 'history',
        company_id: String(companyId),
        start: start,
        end: end
      });
      const res = await fetch('./billing_company_view_ajax.php?'+params.toString(), { credentials:'same-origin' });
      const j = await res.json();
      if(!j || !j.ok){ throw new Error(j && j.message ? j.message : '로드 실패'); }

      $tbody.innerHTML = j.html || '<tr><td class="empty_table" colspan="10">데이터가 없습니다.</td></tr>';

      if (j.totals){
        $tfoot.style.display = '';
        Object.entries(j.totals).forEach(([k,v])=>{
          const el = $tfoot.querySelector('[data-key="'+k+'"]');
          if(el) el.textContent = fmt(v)+'원';
        });
      } else {
        $tfoot.style.display = 'none';
      }

      document.querySelectorAll('.btn-payments').forEach(btn=>{
        btn.addEventListener('click', ()=> togglePayments(btn));
      });

    }catch(e){
      $tbody.innerHTML = '<tr><td class="empty_table" colspan="10">오류: '+ (e.message||e) +'</td></tr>';
    }
  }

  async function togglePayments(btn){
    const ym = btn.getAttribute('data-month');
    const rowId = 'payrow_'+ym.replace('-','');
    let row = document.getElementById(rowId);
    if (row){
      row.style.display = (row.style.display==='none' ? '' : 'none');
      return;
    }

    const tr = document.createElement('tr');
    tr.id = rowId;
    const td = document.createElement('td');
    td.colSpan = 10;
    td.innerHTML = '불러오는 중...';
    tr.appendChild(td);
    btn.closest('tr').after(tr);

    try {
      const params = new URLSearchParams({
        action: 'payments',
        company_id: String(companyId),
        month: ym
      });
      const res = await fetch('./billing_company_view_ajax.php?'+params.toString(), { credentials:'same-origin' });
      const j = await res.json();
      if(!j || !j.ok){ throw new Error(j && j.message ? j.message : '로드 실패'); }
      td.innerHTML = j.html || '<div class="empty_table">결제 로그가 없습니다.</div>';
    } catch(e){
      td.innerHTML = '<div class="empty_table">오류: '+ (e.message||e) +'</div>';
    }
  }

  document.getElementById('btnAddComment').addEventListener('click', async ()=>{
    const text = (document.getElementById('c_text').value||'').trim();
    const month = document.getElementById('c_month').value||'';
    if (!text){ alert('코멘트를 입력하세요.'); return; }

    try{
      const form = new FormData();
      form.append('action','add_comment');
      form.append('csrf_token', csrfToken);
      form.append('company_id', String(companyId));
      form.append('text', text);
      form.append('month', month);

      const res = await fetch('./billing_company_view_ajax.php', { method:'POST', body: form, credentials:'same-origin' });
      const j = await res.json();
      if(!j || !j.ok){ throw new Error(j && j.message ? j.message : '등록 실패'); }

      document.getElementById('c_text').value = '';
      document.getElementById('c_month').value = '';
      await loadComments();
    }catch(e){
      alert('오류: '+ (e.message||e));
    }
  });

  async function loadComments(){
    const $ctb = document.getElementById('tbodyComments');
    $ctb.innerHTML = '<tr><td class="empty_table" colspan="4">불러오는 중...</td></tr>';

    try{
      const params = new URLSearchParams({
        action: 'list_comments',
        company_id: String(companyId)
      });
      const res = await fetch('./billing_company_view_ajax.php?'+params.toString(), { credentials:'same-origin' });
      const j = await res.json();
      if(!j || !j.ok){ throw new Error(j && j.message ? j.message : '로드 실패'); }
      $ctb.innerHTML = j.html || '<tr><td class="empty_table" colspan="4">코멘트가 없습니다.</td></tr>';
    }catch(e){
      $ctb.innerHTML = '<tr><td class="empty_table" colspan="4">오류: '+ (e.message||e) +'</td></tr>';
    }
  }

// 코멘트 삭제 (이벤트 위임)
document.getElementById('tbodyComments').addEventListener('click', async (e)=>{
const btn = e.target.closest('.btn-del-comment');
if (!btn) return;

const commentId = btn.getAttribute('data-id');
if (!commentId) return;
if (!confirm('이 코멘트를 삭제하시겠습니까?')) return;

try{
    const form = new FormData();
    form.append('action','delete_comment');
    form.append('csrf_token', "<?php echo $csrf_token; ?>");
    form.append('company_id', String(<?php echo (int)$company_id; ?>));
    form.append('comment_id', commentId);

    const res = await fetch('./billing_company_view_ajax.php', { method:'POST', body: form, credentials:'same-origin' });
    const j = await res.json();
    if (!j || !j.ok) throw new Error(j && j.message ? j.message : '삭제 실패');

    await loadComments();
}catch(err){
    alert('오류: '+ (err.message||err));
}
});

  // 초기 로드 (리스트에서 링크로 들어왔을 때도 안전)
  loadHistory(startInit, endInit);
  loadComments();

})();
</script>

<?php
include_once (G5_ADMIN_PATH.'/admin.tail.php');
