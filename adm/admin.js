/** 공통 UI 모듈 */
window.CommonUI = {
  bindTabs(tabSelector, contentSelector, options = {}) {
    const tabs = document.querySelectorAll(tabSelector);
    const contents = document.querySelectorAll(contentSelector);

    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const tabName = tab.dataset.tab;
        const target = document.getElementById(`tab-${tabName}`);

        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        contents.forEach(c => c.classList.add('is-hidden'));

        if (target) target.classList.remove('is-hidden');

        options.onChange?.(tabName, target);
      });
    });
  }
};

function setHtml(el, markup) {
    if (!el) return; 
    if (markup == null || markup === '') { 
        el.textContent = '';
        return;
    }
    const range = document.createRange();
    range.selectNodeContents(el);
    el.replaceChildren(range.createContextualFragment(markup));
}

/** 팝업 관리 모듈 */
window.PopupManager = {
    open(id, options = {}) {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('is-hidden');
            this.bindOutsideClickClose(id);

            if (!options.disableOutsideClose) {
                this.bindOutsideClickClose(id);
            } else {
                this.unbindOutsideClickClose(id);
            }
        }
    },

    close(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('is-hidden');
    },

    toggle(id) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('is-hidden');
    },

    bindOutsideClickClose(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.onclick = () => this.close(id);
    },

    unbindOutsideClickClose(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.onclick = null;
    },

    /**
     * 팝업 콘텐츠 렌더링 (타이틀, 바디, 푸터 구성)
     * @param {string} title - 팝업 제목
     * @param {string} body - 팝업 본문 HTML
     * @param {string} [footer] - 푸터 HTML
     * @param {object} [options] - 팝업 열기 옵션
     */
    render(title, body, footer = '', options = {}) {
        const titleEl = document.getElementById('popupTitle');
        const bodyEl = document.getElementById('popupBody');
        const footerEl = document.getElementById('popupFooter');

        if (titleEl) titleEl.textContent = title;
        if (bodyEl) setHtml(bodyEl, body);
        if (footerEl) setHtml(footerEl, footer);

        this.open('popupOverlay', options);
    }
};

/** 형식 체크 */
function check_all(target) {
    const chkboxes = document.getElementsByName("chk[]");
    let chkall;

    if (target && target.tagName === "FORM") {
        chkall = target.querySelector('input[name="chkall"]');
    } else if (target && target.type === "checkbox") {
        chkall = target;
    }

    if (!chkall) return;

    for (const checkbox of chkboxes) {
        checkbox.checked = chkall.checked;
    }
}


function btn_check(f, act)
{
    if (act == "update") // 선택수정
    {
        f.action = list_update_php;
        str = "수정";
    }
    else if (act == "delete") // 선택삭제
    {
        f.action = list_delete_php;
        str = "삭제";
    }
    else
        return;

    var chk = document.getElementsByName("chk[]");
    var bchk = false;

    for (i=0; i<chk.length; i++)
    {
        if (chk[i].checked)
            bchk = true;
    }

    if (!bchk)
    {
        alert(str + "할 자료를 하나 이상 선택하세요.");
        return;
    }

    if (act == "delete")
    {
        if (!confirm("선택한 자료를 정말 삭제 하시겠습니까?"))
            return;
    }

    f.submit();
}

function is_checked(elements_name)
{
    var checked = false;
    var chk = document.getElementsByName(elements_name);
    for (var i=0; i<chk.length; i++) {
        if (chk[i].checked) {
            checked = true;
        }
    }
    return checked;
}

function delete_confirm(el)
{
    if(confirm("한번 삭제한 자료는 복구할 방법이 없습니다.\n\n정말 삭제하시겠습니까?")) {
        var token = get_ajax_token();
        var href = el.href.replace(/&token=.+$/g, "");
        if(!token) {
            alert("토큰 정보가 올바르지 않습니다.");
            return false;
        }
        el.href = href+"&token="+token;
        return true;
    } else {
        return false;
    }
}

function delete_confirm2(msg)
{
    if(confirm(msg))
        return true;
    else
        return false;
}

function get_ajax_token()
{
    var token = "",
        admin_csrf_token_key = (typeof g5_admin_csrf_token_key !== "undefined") ? g5_admin_csrf_token_key : "";

    $.ajax({
        type: "POST",
        url: g5_admin_url+"/ajax.token.php",
        data : {admin_csrf_token_key:admin_csrf_token_key},
        cache: false,
        async: false,
        dataType: "json",
        success: function(data) {
            if(data.error) {
                alert(data.error);
                if(data.url)
                    document.location.href = data.url;

                return false;
            }

            token = data.token;
        }
    });

    return token;
}

/**
 * 회사 선택 시 지점 목록을 로드하는 함수
 * @param {HTMLSelectElement} companySel - 회사 선택 셀렉트박스
 * @param {HTMLSelectElement} groupSel - 지점 선택 셀렉트박스
 * @param {string} [csrfToken] - (선택) CSRF 토큰, 필요 시 전달
 */
function initCompanyGroupSelector(companySel, groupSel, csrfToken = null) {
    if (!companySel || !groupSel) return;

    companySel.addEventListener('change', async function() {
        const cid = parseInt(this.value || '0', 10) || 0;
        groupSel.innerHTML = '<option value="">로딩 중...</option>';

        try {
            const headers = {
                'Content-Type': 'application/json',
            };
            if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

            const res = await fetch('/adm/call/ajax_group_options.php', {
                method: 'POST',
                headers,
                body: JSON.stringify({ company_id: cid }),
                credentials: 'same-origin'
            });

            if (!res.ok) throw new Error('네트워크 오류');
            const json = await res.json();

            if (!json.success) throw new Error(json.message || '가져오기 실패');

            const opts = [];
            opts.push(new Option('-- 전체 지점 --', 0));

            json.items.forEach(item => {
                if (item.separator) {
                    const sep = document.createElement('option');
                    sep.textContent = '── ' + item.separator + ' ──';
                    sep.disabled = true;
                    sep.className = 'opt-sep';
                    opts.push(sep);
                } else {
                    opts.push(new Option(item.label, item.value));
                }
            });

            groupSel.innerHTML = '';
            opts.forEach(o => groupSel.appendChild(o));
            groupSel.value = '0';
        } catch (err) {
            alert('지점 목록을 불러오지 못했습니다: ' + err.message);
            groupSel.innerHTML = '<option value="0">-- 전체 지점 --</option>';
        }
    });
}

const baseHex = {
  success:   '#28a745',
  primary:   '#007bff',
  secondary: '#6c757d',
  warning:   '#ffc107',
  danger:    '#dc3545',
};
function hexToRgb(hex){
  const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  const r = parseInt(m[1],16), g = parseInt(m[2],16), b = parseInt(m[3],16);
  return {r,g,b};
}
function rgbToHsl(r,g,b){
  r/=255; g/=255; b/=255;
  const max=Math.max(r,g,b), min=Math.min(r,g,b);
  let h,s,l=(max+min)/2;
  if(max===min){ h=s=0; }
  else {
    const d=max-min;
    s=l>0.5 ? d/(2-max-min) : d/(max+min);
    switch(max){
      case r: h=(g-b)/d+(g<b?6:0); break;
      case g: h=(b-r)/d+2; break;
      case b: h=(r-g)/d+4; break;
    }
    h/=6;
  }
  return {h, s, l};
}
function hslToCss(h,s,l){
  return `hsl(${Math.round(h*360)}, ${Math.round(s*100)}%, ${Math.round(l*100)}%)`;
}
function clamp01(x){ return Math.max(0, Math.min(1, x)); }
// 3) 밝기 조정 규칙
// - 성공(1): 기본 +8% 밝기 + (status%7)*1.5% 추가
// - 실패(0): 기본 -6% 밝기 - (status%7)*1.0% 추가
// - DNC: danger 색상으로, 실패 규칙 적용
function tintByStatus(hex, result_group, call_status, forceDanger=false){
  const base = hexToRgb(forceDanger ? baseHex.danger : hex);
  let {h,s,l} = rgbToHsl(base.r, base.g, base.b);

  const mod = (call_status ?? 0) % 7; // 작은 변주
  if (result_group == 1) {              // 성공군: 더 밝게
    l = l + 0.08 + mod * 0.015;
  } else {                               // 실패군: 더 어둡게
    l = l - 0.06 - mod * 0.010;
  }
  l = clamp01(l);

  // 채도도 살짝 보정(밝아지면 -2%, 어두워지면 +2%)
  s = clamp01(s + (result_group == 1 ? -0.02 : 0.02));

  return hslToCss(h,s,l);
}

// call_status별 색 변형 (숫자 차이에 따라 밝기 조정)
function adjustColor([r, g, b], status) {
  // status값이 0~999 단위라고 가정, modulo로 명도조정
  const shift = (status % 100) / 100; // 0~1 사이
  const factor = 0.7 + shift * 0.3;   // 0.7~1.0 정도의 밝기
  return `rgb(${Math.min(255, r * factor)}, ${Math.min(255, g * factor)}, ${Math.min(255, b * factor)})`;
}



// ===============================================
// 공통 날짜 범위 버튼 유틸
// - weekStart: 1(월) | 0(일)
// - thisWeekEndToday/thisMonthEndToday: true면 '이번주/이번달' 종료일을 오늘로 설정
// ===============================================
(function(global){
  function pad(n){ return (n<10?'0':'')+n; }
  function fmtDate(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
  function fmtDT(d){  // HTML datetime-local 형식: YYYY-MM-DDTHH:MM
    return fmtDate(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // 자정/하루끝 스냅(날짜 모드에서만 의미있음)
  function startOfDay(d){ const x = new Date(d); x.setHours(0,0,0,0); return x; }
  function endOfDay(d){   const x = new Date(d); x.setHours(23,59,59,999); return x; }

  function startOfWeek(d, weekStart){ // weekStart: 1=Mon, 0=Sun
    const x = startOfDay(d);
    const day = x.getDay(); // 0~6 (Sun~Sat)
    const diff = ( (day - weekStart + 7) % 7 );
    x.setDate(x.getDate() - diff);
    return x;
  }
  function endOfWeek(d, weekStart){
    const s = startOfWeek(d, weekStart);
    const e = new Date(s);
    e.setDate(s.getDate()+6);
    return endOfDay(e);
  }
  function startOfMonth(d){
    const x = startOfDay(d);
    x.setDate(1);
    return x;
  }
  function endOfMonth(d){
    const x = startOfDay(d);
    x.setMonth(x.getMonth()+1, 0); // 다음달 0일 = 말일
    return endOfDay(x);
  }

  // 시간 문자열 "HH:MM" 을 날짜에 주입
  function applyTime(dateObj, hhmm){
    const [hh, mm] = (hhmm || '00:00').split(':').map(v=>parseInt(v,10)||0);
    const x = new Date(dateObj);
    x.setHours(hh, mm, 0, 0);
    return x;
  }

  // 입력 엘리먼트의 모드 자동판별 ('date' or 'datetime')
  function detectMode($start, $end){
    const t1 = ($start && $start.type) || '';
    const t2 = ($end   && $end.type)   || '';
    // 둘 중 하나라도 datetime-local이면 datetime 모드
    if (t1 === 'datetime-local' || t2 === 'datetime-local') return 'datetime';
    return 'date';
  }

  // 범위 계산(기준은 '날짜'); 실제 적용시 모드에 따라 시간 주입
  function calcRangeDateOnly(key, opts){
    const now = new Date();
    const today = startOfDay(now);
    const y = new Date(today); y.setDate(today.getDate()-1);

    const weekStart = (opts && typeof opts.weekStart==='number') ? opts.weekStart : 1; // 월요일
    const thisWeekEndToday  = !!(opts && opts.thisWeekEndToday  !== false); // default true
    const thisMonthEndToday = !!(opts && opts.thisMonthEndToday !== false); // default true

    switch(key){
      case 'yesterday':  return { start: new Date(y),     end: new Date(y) };
      case 'today':      return { start: new Date(today), end: new Date(today) };

      case 'last_week': {
        const ref = new Date(today); ref.setDate(today.getDate()-7);
        return { start: startOfWeek(ref, weekStart), end: endOfWeek(ref, weekStart) };
      }
      case 'this_week': {
        const s = startOfWeek(today, weekStart);
        const e = thisWeekEndToday ? endOfDay(today) : endOfWeek(today, weekStart);
        return { start: s, end: e };
      }

      case 'last_month': {
        const ref = new Date(today); ref.setMonth(ref.getMonth()-1);
        return { start: startOfMonth(ref), end: endOfMonth(ref) };
      }
      case 'this_month': {
        const s = startOfMonth(today);
        const e = thisMonthEndToday ? endOfDay(today) : endOfMonth(today);
        return { start: s, end: e };
      }
    }
    return null;
  }

  // 공개 API: calcRange(key, cfg) -> {start, end} 문자열 반환 (모드에 맞춰 포맷)
  function calcRange(key, cfg){
    const base = calcRangeDateOnly(key, cfg);
    if (!base) return null;

    const mode = (cfg && cfg.mode) ? cfg.mode : 'auto';
    const startHHMM = (cfg && cfg.defaultStartTime) ? cfg.defaultStartTime : '08:00';
    const endHHMM   = (cfg && cfg.defaultEndTime)   ? cfg.defaultEndTime   : '19:00';

    let finalMode = mode;
    if (mode === 'auto') {
      // auto 모드는 호출자가 startInput/endInput을 넣었을 때만 의미
      try {
        const $s = typeof cfg.startInput==='string' ? document.querySelector(cfg.startInput) : cfg.startInput;
        const $e = typeof cfg.endInput==='string'   ? document.querySelector(cfg.endInput)   : cfg.endInput;
        finalMode = detectMode($s, $e);
      } catch(e){ finalMode = 'date'; }
    }

    if (finalMode === 'datetime') {
      const s = applyTime(base.start, startHHMM);
      const e = applyTime(base.end,   endHHMM);
      return { start: fmtDT(s), end: fmtDT(e) };
    } else {
      // date 모드: YYYY-MM-DD 고정
      return { start: fmtDate(base.start), end: fmtDate(base.end) };
    }
  }

  /**
   * 버튼 초기화
   * @param {Object} cfg
   *  - container: 버튼 래퍼 요소 또는 selector
   *  - startInput, endInput: input 요소 또는 selector (type=date | datetime-local)
   *  - form: submit할 form 요소 또는 selector
   *  - autoSubmit: true면 클릭 시 즉시 submit
   *  - mode: 'auto' | 'date' | 'datetime' (기본 'auto' = 자동감지)
   *  - defaultStartTime: 'HH:MM' (datetime 모드에서 시작 기본시각, 기본 '08:00')
   *  - defaultEndTime:   'HH:MM' (datetime 모드에서 종료 기본시각, 기본 '19:00')
   *  - weekStart, thisWeekEndToday, thisMonthEndToday: 범위 계산 옵션
   */
  function initDateRangeButtons(cfg){
    const $container = typeof cfg.container==='string' ? document.querySelector(cfg.container) : cfg.container;
    const $start = typeof cfg.startInput==='string' ? document.querySelector(cfg.startInput) : cfg.startInput;
    const $end   = typeof cfg.endInput==='string' ? document.querySelector(cfg.endInput) : cfg.endInput;
    const $form  = cfg.form ? (typeof cfg.form==='string' ? document.querySelector(cfg.form) : cfg.form) : null;
    const autoSubmit = !!cfg.autoSubmit;

    if (!$container || !$start || !$end) return;

    // 최종 모드 확정
    const finalMode = (cfg.mode && cfg.mode!=='auto') ? cfg.mode : detectMode($start, $end);
    const cmp = (v)=> String(v||'').trim();

    function setActive(){
      const sVal = cmp($start.value);
      const eVal = cmp($end.value);
      const btns = $container.querySelectorAll('[data-range]');
      btns.forEach(btn=>{
        const key = btn.getAttribute('data-range');
        const rg = calcRange(key, Object.assign({}, cfg, {mode: finalMode}));
        const active = !!(rg && cmp(rg.start) === sVal && cmp(rg.end) === eVal);
        btn.classList.toggle('active', active);
      });
    }

    $container.addEventListener('click', function(ev){
      const t = ev.target.closest('[data-range]');
      if (!t) return;
      const key = t.getAttribute('data-range');
      const rg = calcRange(key, Object.assign({}, cfg, {mode: finalMode}));
      if (!rg) return;
      $start.value = rg.start;
      $end.value   = rg.end;
      setActive();
      if (autoSubmit && $form) $form.submit();
    });

    // 최초 활성화 반영
    setActive();

    // 외부에서 값이 바뀌었을 때 갱신하고 싶다면 아래를 받는다:
    return { refresh: setActive };
  }

  // export
  global.DateRangeButtons = { init: initDateRangeButtons, calcRange: calcRange };
})(window);


$(function() {
    $(document).on("click", "form input:submit, form button:submit", function() {
        var f = this.form;
        var token = get_ajax_token();

        if(!token) {
            //alert("토큰 정보가 올바르지 않습니다.!");
            return false;
        }

        var $f = $(f);

        if(typeof f.token === "undefined")
            $f.prepend('<input type="hidden" name="token" value="">');

        $f.find("input[name=token]").val(token);

        return true;
    });
});