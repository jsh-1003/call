/**
 * 설정 및 상수 정의
 */
var AC_SETTINGS = {
    ids: {
        panel: 'acPanel',
        overlay: 'acOverlay',
        closeBtn: 'acClose',
        cancelBtn: 'acCancel',
        form: 'acForm',
        timeline: 'f_timeline',
        detailSection: 'acDetailSection',

        // Hidden fields
        f_campaign_id: 'f_campaign_id',
        f_mb_group: 'f_mb_group',
        f_target_id: 'f_target_id',
        f_state_id: 'f_state_id',

        // Input fields
        f_memo: 'f_memo',
        f_schedule_date: 'f_schedule_date',
        f_schedule_time: 'f_schedule_time',
        f_schedule_note: 'f_schedule_note',
        f_schedule_clear: 'f_schedule_clear',
        f_after_agent: 'f_after_agent',

        // Summary fields
        s_target_name: 's_target_name',
        s_hp: 's_hp',
        s_birth: 's_birth',
        s_age: 's_age',
        s_meta: 's_meta',

        // Buttons
        btnSchedToday: 'btnSchedToday',
        btnSchedTomorrow: 'btnSchedTomorrow',
        btnSchedClear: 'btnSchedClear'
    },
    names: {
        detail_region1: 'detail_region1',
        detail_region2: 'detail_region2',
        detail_name: 'detail_name',
        detail_birth: 'detail_birth',
        detail_age: 'detail_age',
        detail_sex: 'detail_sex',
        detail_hp: 'detail_hp',
        detail_premium: 'detail_premium',
        detail_visit_at: 'detail_visit_at',
        detail_addr_etc: 'detail_addr_etc',
        detail_memo: 'detail_memo'
    },
    classes: {
        editBtn: 'ac-edit-btn'
    }
};

/**
 * 팝업 열기/닫기 제어
 */
function after_db_openPanel() {
    var panel = document.getElementById(AC_SETTINGS.ids.panel);
    var overlay = document.getElementById(AC_SETTINGS.ids.overlay);
    if (panel && overlay) {
        panel.hidden = false;
        overlay.hidden = false;
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ac-open');
    }
}

function after_db_closePanel() {
    var panel = document.getElementById(AC_SETTINGS.ids.panel);
    var overlay = document.getElementById(AC_SETTINGS.ids.overlay);
    if (panel && overlay) {
        panel.hidden = true;
        overlay.hidden = true;
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ac-open');
    }
}

/**
 * 팝업 이벤트 리스너 초기화
 */
function after_db_initPopupEvents() {
    var btnClose = document.getElementById(AC_SETTINGS.ids.closeBtn);
    var btnCancel = document.getElementById(AC_SETTINGS.ids.cancelBtn);
    var overlay = document.getElementById(AC_SETTINGS.ids.overlay);

    if (btnClose) btnClose.addEventListener('click', after_db_closePanel);
    if (btnCancel) btnCancel.addEventListener('click', after_db_closePanel);
    if (overlay) overlay.addEventListener('click', after_db_closePanel);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') after_db_closePanel(); });
}

/**
 * 타임라인(이력/노트) 렌더링
 */
function after_db_renderTimeline(history, notes) {
    var el = document.getElementById(AC_SETTINGS.ids.timeline);
    if (!el) return;
    el.innerHTML = '';

    var items = [];
    (history || []).forEach(function (h) {
        var baseText = (h.prev_label || (h.prev_state == null ? '대기' : h.prev_state)) + ' → ' + (h.new_label || h.new_state);
        if (h.prev_state == h.new_state) baseText = '';

        var memoTxt = h.memo ? ' <span class="small-muted-system">' + h.memo + '</span>' : '';
        items.push({
            t: new Date(h.changed_at.replace(' ', 'T')),
            time: h.changed_at,
            who: h.who_name || h.who_id || h.changed_by,
            kind: 'state',
            text: baseText + memoTxt
        });
    });

    (notes || []).forEach(function (n) {
        items.push({
            t: new Date(n.created_at.replace(' ', 'T')),
            time: n.created_at,
            who: n.who_name || n.who_id || n.created_by,
            kind: n.note_type,
            text: n.note_type === 'schedule'
                ? ((n.scheduled_at ? (n.scheduled_at.substring(5, 16) + ' / ') : '') + (n.note_text || ''))
                : (n.note_text || '')
        });
    });
    items.sort(function (a, b) { return b.t - a.t; });

    if (!items.length) {
        el.innerHTML = '<div class="ac-timeline__item"><div class="ac-timeline__time">-</div><div class="ac-timeline__body small-muted">로그가 없습니다.</div></div>';
        return;
    }

    items.forEach(function (it) {
        var row = document.createElement('div'); row.className = 'ac-timeline__item';
        var badgeClass = it.kind === 'state' ? 'ac-badge ac-badge--state' : (it.kind === 'schedule' ? 'ac-badge ac-badge--sched' : 'ac-badge ac-badge--note');
        var typeLabel = it.kind === 'state' ? '상태' : (it.kind === 'schedule' ? '일정' : '메모');

        var time = document.createElement('div'); time.className = 'ac-timeline__time'; time.textContent = it.time;
        var body = document.createElement('div'); body.className = 'ac-timeline__body';
        body.innerHTML = '<span class="' + badgeClass + '">' + typeLabel + '</span>'
            + '<b>' + (it.who || '') + '</b> · '
            + (it.text ? (it.text + '') : '');
        row.appendChild(time); row.appendChild(body);
        el.appendChild(row);
    });
}

/**
 * 더미 비동기 함수: 상세정보 사용 여부 및 데이터 조회
 * (나중에 실제 API 호출로 대체 예정)
 */
function after_db_mockFetchDetailInfo(targetId) {
    return new Promise(function (resolve) {
        setTimeout(function () {
            // 임시 로직: 테스트용으로 무조건 true 반환
            var useDetail = true;
            var dummyData = null;

            if (useDetail) {
                dummyData = {
                    name: '홍길동',
                    birth: '1990-01-01',
                    age: 34,
                    sex: 1,
                    hp: '010-1234-5678',
                    premium: '100,000',
                    visit_at: '2023-12-25T14:00',
                    region1: '서울',
                    region2: '강남구',
                    addr_etc: '역삼동 123-45',
                    memo: '테스트 메모입니다.'
                };
            }

            resolve({
                use_detail: useDetail,
                data: dummyData
            });
        }, 300);
    });
}

/**
 * 지역2 옵션 업데이트 (지역1 선택에 따라 변경)
 */
var REGION_DATA = {
    '서울': ['강남구', '강동구', '강북구', '강서구', '관악구', '광진구', '구로구', '금천구', '노원구', '도봉구', '동대문구', '동작구', '마포구', '서대문구', '서초구', '성동구', '성북구', '송파구', '양천구', '영등포구', '용산구', '은평구', '종로구', '중구', '중랑구'],
    '경기': ['가평군', '고양시', '과천시', '광명시', '광주시', '구리시', '군포시', '김포시', '남양주시', '동두천시', '부천시', '성남시', '수원시', '시흥시', '안산시', '안성시', '안양시', '양주시', '양평군', '여주시', '연천군', '오산시', '용인시', '의왕시', '의정부시', '이천시', '파주시', '평택시', '포천시', '하남시', '화성시'],
    '인천': ['강화군', '계양구', '남동구', '동구', '미추홀구', '부평구', '서구', '연수구', '옹진군', '중구'],
    '부산': ['강서구', '금정구', '기장군', '남구', '동구', '동래구', '부산진구', '북구', '사상구', '사하구', '서구', '수영구', '연제구', '영도구', '중구', '해운대구'],
    '대구': ['군위군', '남구', '달서구', '달성군', '동구', '북구', '서구', '수성구', '중구'],
    '광주': ['광산구', '남구', '동구', '북구', '서구'],
    '대전': ['대덕구', '동구', '서구', '유성구', '중구'],
    '울산': ['남구', '동구', '북구', '울주군', '중구'],
    '세종': ['세종시'],
    '강원': ['강릉시', '고성군', '동해시', '삼척시', '속초시', '양구군', '양양군', '영월군', '원주시', '인제군', '정선군', '철원군', '춘천시', '태백시', '평창군', '홍천군', '화천군', '횡성군'],
    '충북': ['괴산군', '단양군', '보은군', '영동군', '옥천군', '음성군', '제천시', '증평군', '진천군', '청주시', '충주시'],
    '충남': ['계룡시', '공주시', '금산군', '논산시', '당진시', '보령시', '부여군', '서산시', '서천군', '아산시', '예산군', '천안시', '청양군', '태안군', '홍성군'],
    '전북': ['고창군', '군산시', '김제시', '남원시', '무주군', '부안군', '순창군', '완주군', '익산시', '임실군', '장수군', '전주시', '정읍시', '진안군'],
    '전남': ['강진군', '고흥군', '곡성군', '광양시', '구례군', '나주시', '담양군', '목포시', '무안군', '보성군', '순천시', '신안군', '여수시', '영광군', '영암군', '완도군', '장성군', '장흥군', '진도군', '함평군', '해남군', '화순군'],
    '경북': ['경산시', '경주시', '고령군', '구미시', '김천시', '문경시', '봉화군', '상주시', '성주군', '안동시', '영덕군', '영양군', '영주시', '영천시', '예천군', '울릉군', '울진군', '의성군', '청도군', '청송군', '칠곡군', '포항시'],
    '경남': ['거제시', '거창군', '고성군', '김해시', '남해군', '밀양시', '사천시', '산청군', '양산시', '의령군', '진주시', '창녕군', '창원시', '통영시', '하동군', '함안군', '함양군', '합천군'],
    '제주': ['제주시', '서귀포시']
};

function after_db_updateRegion2(r1, selectedR2) {
    var r2Sel = document.querySelector('select[name="' + AC_SETTINGS.names.detail_region2 + '"]');
    if (!r2Sel) return;
    r2Sel.innerHTML = '<option value="">선택</option>';

    if (r1 && REGION_DATA[r1]) {
        REGION_DATA[r1].forEach(function (r2) {
            var opt = document.createElement('option');
            opt.value = r2;
            opt.textContent = r2;
            if (selectedR2 && selectedR2 === r2) opt.selected = true;
            r2Sel.appendChild(opt);
        });
    }
}

/**
 * 팝업 폼 초기화 (입력값 리셋)
 */
function after_db_resetPopupForm(campaign_id, mb_group, target_id, state_id) {
    // 기본 hidden 값 설정
    document.getElementById(AC_SETTINGS.ids.f_campaign_id).value = campaign_id;
    document.getElementById(AC_SETTINGS.ids.f_mb_group).value = mb_group;
    document.getElementById(AC_SETTINGS.ids.f_target_id).value = target_id;
    document.getElementById(AC_SETTINGS.ids.f_state_id).value = state_id;

    // 입력 필드 초기화
    document.getElementById(AC_SETTINGS.ids.f_memo).value = '';
    document.getElementById(AC_SETTINGS.ids.f_schedule_date).value = '';
    document.getElementById(AC_SETTINGS.ids.f_schedule_time).value = '';
    document.getElementById(AC_SETTINGS.ids.f_schedule_note).value = '';
    document.getElementById(AC_SETTINGS.ids.f_schedule_clear).value = '0';

    // 타임라인 초기화
    after_db_renderTimeline([], []);

    // 상세정보 섹션 초기화
    var secDetail = document.getElementById(AC_SETTINGS.ids.detailSection);
    if (secDetail) secDetail.hidden = true;

    // 상세정보 필드 초기화 (names 이용)
    var detailNames = Object.values(AC_SETTINGS.names);
    detailNames.forEach(function (name) {
        var els = document.querySelectorAll('[name="' + name + '"]');
        els.forEach(function (el) {
            if (el.type === 'radio' || el.type === 'checkbox') el.checked = false;
            else el.value = '';
        });
    });
}

/**
 * 상세정보 데이터 채우기
 */
function after_db_fillDetailSection(data) {
    if (!data) return;

    if (data.name) document.querySelector('input[name="' + AC_SETTINGS.names.detail_name + '"]').value = data.name;
    if (data.birth) document.querySelector('input[name="' + AC_SETTINGS.names.detail_birth + '"]').value = data.birth;
    if (data.age) document.querySelector('input[name="' + AC_SETTINGS.names.detail_age + '"]').value = data.age;
    if (data.sex) {
        var r = document.querySelector('input[name="' + AC_SETTINGS.names.detail_sex + '"][value="' + data.sex + '"]');
        if (r) r.checked = true;
    }
    if (data.hp) document.querySelector('input[name="' + AC_SETTINGS.names.detail_hp + '"]').value = data.hp;
    if (data.premium) document.querySelector('input[name="' + AC_SETTINGS.names.detail_premium + '"]').value = data.premium;
    if (data.visit_at) document.querySelector('input[name="' + AC_SETTINGS.names.detail_visit_at + '"]').value = data.visit_at;

    if (data.region1) {
        var r1 = document.querySelector('select[name="' + AC_SETTINGS.names.detail_region1 + '"]');
        if (r1) {
            r1.value = data.region1;
            after_db_updateRegion2(data.region1, data.region2);
        }
    }
    if (data.addr_etc) document.querySelector('input[name="' + AC_SETTINGS.names.detail_addr_etc + '"]').value = data.addr_etc;
    if (data.memo) document.querySelector('textarea[name="' + AC_SETTINGS.names.detail_memo + '"]').value = data.memo;
}

/**
 * 담당자 목록 로드 (AJAX)
 */
function after_db_loadAgentOptions(mb_group, currentAfterMbNo) {
    const afterSel = document.getElementById(AC_SETTINGS.ids.f_after_agent);
    if (!afterSel) return;

    afterSel.innerHTML = '<option value="0">불러오는 중...</option>';

    const urlAg = new URL('./ajax_call_after_list.php', location.href);
    urlAg.searchParams.set('ajax', 'agents');
    urlAg.searchParams.set('mb_group', mb_group);

    fetch(urlAg.toString(), { credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) throw new Error('네트워크 오류');
            return res.json();
        })
        .then(function (j) {
            if (!j || j.success === false) throw new Error((j && j.message) || '가져오기 실패');

            const opts = ['<option value="0">미지정</option>'];
            (j.rows || []).forEach(function (x) {
                const label = (x.mb_name || x.mb_id) + ' ' + (parseInt(x.is_after_call, 10) === 1 ? '[ON]' : '[OFF]');
                opts.push('<option value="' + x.mb_no + '">' + label + '</option>');
            });
            afterSel.innerHTML = opts.join('');

            // 기존 값 선택
            afterSel.value = String(currentAfterMbNo);
            if (afterSel.selectedIndex < 0) afterSel.value = '0';
        })
        .catch(function (err) {
            console.error(err);
            afterSel.innerHTML = '<option value="0">미지정</option>';
            afterSel.value = '0';
        });
}

/**
 * 티켓/이력 데이터 로드 (AJAX)
 */
function after_db_loadTicketData(campaign_id, mb_group, target_id) {
    var url = new URL('./ajax_call_after_list.php', location.href);
    url.searchParams.set('ajax', 'get');
    url.searchParams.set('campaign_id', campaign_id);
    url.searchParams.set('mb_group', mb_group);
    url.searchParams.set('target_id', target_id);

    fetch(url.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
            if (j && j.success) {
                var t = j.ticket || {};
                if (typeof t.state_id !== 'undefined' && t.state_id !== null)
                    document.getElementById(AC_SETTINGS.ids.f_state_id).value = t.state_id;

                // 일정 값 프리필
                if (t.scheduled_at) {
                    var d = t.scheduled_at.split(' ');
                    if (d[0]) document.getElementById(AC_SETTINGS.ids.f_schedule_date).value = d[0];
                    if (d[1]) document.getElementById(AC_SETTINGS.ids.f_schedule_time).value = d[1].slice(0, 5);
                }
                if (t.schedule_note) document.getElementById(AC_SETTINGS.ids.f_schedule_note).value = t.schedule_note;

                // 2차담당자 프리필 (티켓 값이 있으면 우선 적용)
                if (typeof t.assigned_after_mb_no !== 'undefined' && t.assigned_after_mb_no !== null) {
                    var v = String(parseInt(t.assigned_after_mb_no, 10) || 0);
                    var sel = document.getElementById(AC_SETTINGS.ids.f_after_agent);
                    if (sel) { sel.value = v; if (sel.selectedIndex < 0) sel.value = '0'; }
                }

                after_db_renderTimeline(j.history || [], j.notes || []);
            }
        })
        .catch(console.error);
}

/**
 * 일정 퀵버튼
 */
function after_db_setDateInput(offsetDays) {
    var iDate = document.getElementById(AC_SETTINGS.ids.f_schedule_date);
    var d = new Date();
    d.setDate(d.getDate() + offsetDays);
    var y = d.getFullYear(), m = (d.getMonth() + 1 + '').padStart(2, '0'), day = (d.getDate() + '').padStart(2, '0');
    iDate.value = y + '-' + m + '-' + day;
}
