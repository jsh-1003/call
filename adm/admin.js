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

// ===============================================
// 공통 날짜 범위 버튼 유틸
// - weekStart: 1(월) | 0(일)
// - thisWeekEndToday/thisMonthEndToday: true면 '이번주/이번달' 종료일을 오늘로 설정
// ===============================================
(function(global){
  function pad(n){ return (n<10?'0':'')+n; }
  function fmt(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }

  // 로컬(브라우저) 기준 자정으로 맞추기
  function startOfDay(d){ const x = new Date(d); x.setHours(0,0,0,0); return x; }
  function endOfDay(d){ const x = new Date(d); x.setHours(23,59,59,999); return x; }

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

  function calcRange(key, opts){
    const now = new Date();
    const today = startOfDay(now);
    const y = new Date(today); y.setDate(today.getDate()-1);

    const weekStart = (opts && typeof opts.weekStart==='number') ? opts.weekStart : 1; // 월요일
    const thisWeekEndToday = !!(opts && opts.thisWeekEndToday !== false);   // default true
    const thisMonthEndToday = !!(opts && opts.thisMonthEndToday !== false); // default true

    switch(key){
      case 'yesterday':
        return { start: fmt(y), end: fmt(y) };
      case 'today':
        return { start: fmt(today), end: fmt(today) };

      case 'last_week': {
        const lastWeekRef = new Date(today); lastWeekRef.setDate(today.getDate()-7);
        const s = startOfWeek(lastWeekRef, weekStart);
        const e = endOfWeek(lastWeekRef, weekStart);
        return { start: fmt(s), end: fmt(e) };
      }
      case 'this_week': {
        const s = startOfWeek(today, weekStart);
        let e = thisWeekEndToday ? today : endOfWeek(today, weekStart);
        return { start: fmt(s), end: fmt(e) };
      }

      case 'last_month': {
        const ref = new Date(today); ref.setMonth(ref.getMonth()-1);
        const s = startOfMonth(ref);
        const e = endOfMonth(ref);
        return { start: fmt(s), end: fmt(e) };
      }
      case 'this_month': {
        const s = startOfMonth(today);
        let e = thisMonthEndToday ? today : endOfMonth(today);
        return { start: fmt(s), end: fmt(e) };
      }
    }
    return null;
  }

  /**
   * 버튼 초기화
   * @param {Object} cfg
   *  - container: 버튼 래퍼 요소 또는 selector (data-range 버튼들을 자식으로 가짐)
   *  - startInput, endInput: input 요소 또는 selector
   *  - form: submit할 form 요소 또는 selector
   *  - autoSubmit: true면 클릭 시 즉시 submit
   *  - weekStart, thisWeekEndToday, thisMonthEndToday: 동작 옵션(위 설명)
   */
  function initDateRangeButtons(cfg){
    const $container = typeof cfg.container==='string' ? document.querySelector(cfg.container) : cfg.container;
    const $start = typeof cfg.startInput==='string' ? document.querySelector(cfg.startInput) : cfg.startInput;
    const $end   = typeof cfg.endInput==='string' ? document.querySelector(cfg.endInput) : cfg.endInput;
    const $form  = cfg.form ? (typeof cfg.form==='string' ? document.querySelector(cfg.form) : cfg.form) : null;
    const autoSubmit = !!cfg.autoSubmit;

    if (!$container || !$start || !$end) return;

    function setActive(){
      const s = $start.value;
      const e = $end.value;
      const btns = $container.querySelectorAll('[data-range]');
      btns.forEach(btn=>{
        const key = btn.getAttribute('data-range');
        const rg = calcRange(key, cfg);
        const active = !!(rg && rg.start === s && rg.end === e);
        btn.classList.toggle('active', active);
      });
    }

    $container.addEventListener('click', function(ev){
      const t = ev.target.closest('[data-range]');
      if (!t) return;
      const key = t.getAttribute('data-range');
      const rg = calcRange(key, cfg);
      if (!rg) return;
      $start.value = rg.start;
      $end.value   = rg.end;
      setActive();
      if (autoSubmit && $form) $form.submit();
    });

    // 최초 활성화 반영
    setActive();

    // 외부에서 값이 바뀌었을 때 갱신하고 싶다면 아래를 호출:
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