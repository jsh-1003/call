<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
<meta name="theme-color" content="#0a0f1f" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<title>AI 위촉장 안내</title>
<style>
:root{
  --bg1:#0a0f1f; --bg2:#0f1b33; --accent:#42e6ff; --accent2:#00ffd5;
  --ok:#43d17a; --text:#e8f3ff; --muted:#9fb3c8;
  --chip:#0e1a2e; --chip-bd:#1c2a44; --chip-sel:#143d3a; --chip-sel-bd:#2fbba7;
}
*{box-sizing:border-box;margin:0;padding:0}
html{
  -webkit-text-size-adjust:100%;
}
html,body{
  min-height:100%;
  font-family:'Noto Sans KR', Pretendard, Roboto, system-ui, sans-serif;
  color:var(--text);
  background:linear-gradient(180deg,var(--bg1),var(--bg2));
  overflow:hidden;
  isolation:isolate;
}

/* 글로벌 BG */
.bgfx,.bgfx::before,.bgfx::after{position:fixed;inset:0;pointer-events:none}
.bgfx{z-index:0;will-change:transform}
.bgfx::before{
  content:"";
  background:
    radial-gradient(700px 420px at 20% 15%, rgba(66,230,255,.10), transparent 60%),
    radial-gradient(900px 540px at 80% 85%, rgba(0,255,213,.08), transparent 60%);
  animation:bg-pan 40s linear infinite; opacity:.55;
}
.bgfx::after{
  content:"";
  background:
    radial-gradient(circle at 1px 1px, rgba(255,255,255,.03) 1.2px, transparent 1.3px) 0 0/4px 4px;
  mix-blend-mode:overlay; opacity:.7;
}
@keyframes bg-pan{to{transform:translate(-120px,-80px)}}

/* 공통 화면 */
.screen{
  position:relative;
  display:grid; place-items:center;
  min-height:100svh;
  padding:24px;
  z-index:1;
  overflow:auto;
}
.screen:not(.active){display:none}
h1{font-size:clamp(22px,4vw,28px);font-weight:800;margin-bottom:12px;line-height:1.4;text-align:center}
p{color:var(--muted);font-size:15px;margin-bottom:20px;text-align:center}

/* 버튼 */
.btn{
  border:none;border-radius:12px;padding:14px 26px;
  font-size:17px;font-weight:700;color:#00170e;
  background:linear-gradient(180deg,#6dffb2,#43d17a);
  box-shadow:0 8px 20px rgba(67,209,122,.35);
  cursor:pointer;transition:transform .15s ease;touch-action:manipulation
}
.btn:active{transform:scale(.97)}
.btn[disabled]{opacity:.5;filter:saturate(.7);cursor:not-allowed}

/* Stage 0: 위원 선택 */
#stage0{ /* 액션바 높이만큼 바닥 패딩 추가(가림 방지) */
  padding-bottom:calc(120px + env(safe-area-inset-bottom));
}
.stage0-panel{
  width:min(1080px, 92vw);
  display:flex; flex-direction:column; align-items:center;
  background:linear-gradient(180deg, rgba(8,20,36,.62), rgba(8,18,34,.58));
  border:1px solid rgba(114,194,255,.22);
  border-radius:16px; padding:18px 16px 16px;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.stage0-panel > .desc{margin-top:4px;margin-bottom:16px}

.search-row{
  width:100%; display:flex; gap:10px; align-items:center; justify-content:center; margin-bottom:14px; flex-wrap:wrap;
}
.input{
  width:min(520px, 100%); height:48px;
  border-radius:12px; padding:0 14px; font-size:16px; color:var(--text);
  background:#0b1424; border:1px solid rgba(114,194,255,.22); outline:0;
}
.clear-btn{
  height:48px; padding:0 14px; border-radius:12px; border:1px solid rgba(114,194,255,.22);
  background:#0b1424; color:#a9c9ff; cursor:pointer; touch-action:manipulation;
}

/* ⚠️ 항상 3열, % 너비(33.333%) */
.name-grid{
  width:100%;
  display:grid;
  grid-template-columns:repeat(3, 32.5%);
  gap:10px;
}
.name-card{
  height:72px; display:flex; align-items:center; justify-content:center; gap:10px;
  font-size:clamp(18px, 2.2vh, 22px); font-weight:800; letter-spacing:-.02em;
  border-radius:14px; border:1px solid var(--chip-bd);
  background:linear-gradient(180deg, #0f1a2c, #0b1424);
  color:#d9ebff; cursor:pointer; user-select:none; touch-action:manipulation;
  box-shadow:0 4px 14px rgba(0,0,0,.25);
  text-align:center; padding:0 6px;
}
.name-card:active{transform:scale(.98)}
.name-card.selected{
  background:linear-gradient(180deg, #155d55, #0f3a37);
  border-color:var(--chip-sel-bd);
  box-shadow:0 0 0 2px rgba(47,187,167,.25), 0 12px 28px rgba(0,0,0,.35);
}

/* ✅ 하단 고정 액션바(선택 완료 버튼) */
.action-bar{
  position:fixed;
  left: max(12px, env(safe-area-inset-left) + 12px);
  right:max(12px, env(safe-area-inset-right) + 12px);
  bottom: calc(env(safe-area-inset-bottom) + 12px);
  z-index:20;
  display:flex; justify-content:center;
  padding:10px;
  background:linear-gradient(180deg, rgba(8,20,36,.70), rgba(8,18,34,.78));
  border:1px solid rgba(114,194,255,.18);
  border-radius:16px;
  box-shadow:0 20px 60px rgba(0,0,0,.45), 0 0 60px rgba(66,230,255,.16);
}
.action-bar .btn{
  width:100%;
  max-width:min(520px, 92vw);
}

/* Stage 2: 활성화 */
#stage2{position:relative}
.stage2-layer{position:absolute;inset:0;z-index:0;overflow:hidden}
.stage2-layer .grid::before,.stage2-layer .grid::after{content:"";position:absolute;inset:0;pointer-events:none}
.stage2-layer .grid::before{
  background:
    repeating-linear-gradient(0deg, rgba(66,230,255,.08) 0 1px, transparent 1px 40px),
    repeating-linear-gradient(90deg, rgba(66,230,255,.08) 0 1px, transparent 1px 40px);
  opacity:.26; animation:grid-pan 30s linear infinite;
}
.stage2-layer .grid::after{
  background: radial-gradient(closest-side, rgba(66,230,255,.18), transparent 70%) 50% 30%/90% 90% no-repeat;
  opacity:.20; mix-blend-mode:overlay;
}
@keyframes grid-pan { to { transform: translate(-120px,-80px)} }

/* 중앙 카드 */
.stage2-card{
  position:relative; z-index:1;
  width:min(92vw, 560px);
  padding:22px 18px 18px; border-radius:14px;
  background:linear-gradient(180deg, rgba(6,14,26,.86), rgba(7,15,28,.84));
  border:1px solid rgba(114,194,255,.25);
  box-shadow:0 12px 36px rgba(0,0,0,.35);
  display:flex; flex-direction:column; align-items:center; gap:10px;
}
.stage2-card h1,.stage2-card p{ text-shadow:0 1px 0 rgba(0,0,0,.6) }

/* ⬇️ 오브: 카드 내부, 중앙, 프로그레스 바로 위 */
.stage2-card .orb{
  width:120px; height:120px; border-radius:50%;
  background:radial-gradient(circle at 30% 30%,#00ffd5 0%,#005c6d 90%);
  box-shadow:0 0 40px rgba(0,255,213,.35);
  margin:6px auto 8px; pointer-events:none;
  animation:floatY 2.6s ease-in-out infinite alternate;
  filter: blur(.15px);
}
@keyframes floatY{
  0%{ transform: translateY(0) scale(1) }
  100%{ transform: translateY(-6px) scale(1.05) }
}

.progress-wrap{width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center}
.bar-wrap{
  width:min(90vw, 360px); height:10px; border-radius:999px; background:rgba(66,230,255,.15);
  overflow:hidden; margin:6px auto 2px;
}
.bar{width:0%; height:100%; background:linear-gradient(90deg,var(--accent2),var(--accent)); box-shadow:0 0 20px rgba(0,255,213,.3); transition:width .2s ease}
.percent{font-size:13px;color:#a9c9ff}

/* Stage 3 */
.checkmark{
  width:120px;height:120px;border-radius:50%;margin-bottom:18px;
  background:linear-gradient(180deg,#0b241e,#0a1b2b);
  display:flex;align-items:center;justify-content:center;
  border:2px solid rgba(66,230,255,.25);
  box-shadow:0 0 40px rgba(0,255,213,.25) inset;
}
.checkmark::after{content:"✔";font-size:58px;color:#6dffb2;animation:popIn .4s ease}
@keyframes popIn{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1;}}

/* 모달: 중앙 */
.modal{
  position:fixed; inset:0; z-index:50;
  display:none; background:rgba(5,14,26,.78);
  backdrop-filter:blur(4px);
  padding:max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right))
          max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
}
.modal.show{display:grid; place-items:center; overflow:auto}
.modal-frame{
  max-width:min(640px, 94vw); max-height:90svh; width:100%;
  border-radius:14px; padding:16px;
  background:linear-gradient(180deg,#0b1524,#0a1220);
  border:1px solid rgba(114,194,255,.25);
  box-shadow:0 20px 80px rgba(0,0,0,.6), 0 0 120px rgba(66,230,255,.18);
  display:grid; place-items:center;
}
.modal-paper{
  width:min(520px, 84vw); max-height:80svh; aspect-ratio:3/4;
  border-radius:10px; overflow:hidden; background:#f5f6f9; display:grid; place-items:center; position:relative;
}
.modal-paper img{width:100%; height:100%; object-fit:contain; background:#f5f6f9}
.skeleton{
  position:absolute; inset:0; border-radius:10px;
  background: linear-gradient(90deg, #e9edf3 0%, #f7f9fc 50%, #e9edf3 100%);
  background-size:200% 100%; animation: shimmer 1.2s infinite;
}
@keyframes shimmer { to { background-position:-200% 0 } }

/* 모션 줄이기 */
@media (prefers-reduced-motion: reduce){
  .bgfx::before,.stage2-layer .grid::before,.stage2-card .orb{animation:none}
}
</style>
</head>
<body>

<!-- 글로벌 BG -->
<div class="bgfx" aria-hidden="true"></div>

<!-- 0단계: 위원 선택 -->
<section id="stage0" class="screen active" aria-labelledby="selTitle">
  <div class="stage0-panel">
    <h1 id="selTitle">위원님 성함을 선택하세요</h1>
    <div class="search-row">
      <input id="search" class="input" type="search" placeholder="이름 검색" inputmode="search" />
      <button class="clear-btn" id="clearBtn" type="button">지우기</button>
    </div>

    <div class="name-grid" id="nameGrid" role="listbox" aria-label="위원 이름 선택"></div>
  </div>

  <!-- ✅ 하단 고정 액션바: 언제나 보이는 선택 완료 -->
  <div class="action-bar">
    <button id="goNext" class="btn" type="button" disabled>선택 완료</button>
  </div>
</section>

<!-- 1단계 -->
<section id="stage1" class="screen" aria-live="polite">
  <div style="display:flex;flex-direction:column;align-items:center;gap:10px">
    <h1>귀하를 충남AI특별위원회<br>위원으로 위촉하고자 합니다.</h1>
    <p id="chosenNameTxt">인증을 완료하시고 위촉장을 생성하여 주시기 바랍니다.</p>
    <button class="btn" onclick="startAuth()">인증 확인</button>
  </div>
</section>

<!-- 2단계: 활성화 -->
<section id="stage2" class="screen">
  <div class="stage2-layer" aria-hidden="true">
    <div class="grid"></div>
  </div>
  <div class="stage2-card" role="group" aria-label="위촉장 활성화 진행">
    <h1>위촉장이 활성화 중입니다.</h1>
    <p>잠시만 기다려 주세요.</p>

    <!-- ⬇️ 오브: 중앙, 그 아래 프로그레스 -->
    <div class="orb" aria-hidden="true"></div>

    <div class="progress-wrap">
      <div class="bar-wrap"><div class="bar" id="bar"></div></div>
      <div class="percent" id="percent">0%</div>
    </div>
  </div>
</section>

<!-- 3단계 -->
<section id="stage3" class="screen">
  <div style="display:flex;flex-direction:column;align-items:center">
    <div class="checkmark"></div>
    <h1 id="doneTitle">위촉장 활성화가<br>완료되었습니다.</h1>
    <p>위촉장을 확인해주세요.</p>
    <button class="btn" onclick="showModal()">위촉장 보기</button>
  </div>
</section>

<!-- 모달 -->
<div class="modal" id="modal" role="dialog" aria-modal="true" aria-label="위촉장 미리보기">
  <div class="modal-frame" id="modalFrame">
    <div class="modal-paper">
      <span class="skeleton" id="skeleton" aria-hidden="true"></span>
      <img id="certImg" src="" alt="위촉장 미리보기" decoding="async" />
    </div>
  </div>
</div>

<script>
/* ====== 이름+이미지 매핑 ====== */
const members = [
  {name:'김민수', img:'./img/1.png'},
  {name:'이서윤', img:'./img/이서윤.png'},
  {name:'박지훈', img:'./img/박지훈.png'},
  {name:'최민준', img:'./img/최민준.png'},
  {name:'정예은', img:'./img/정예은.png'},
  {name:'한지우', img:'./img/한지우.png'},
  {name:'오하늘', img:'./img/오하늘.png'},
  {name:'유나리', img:'./img/유나리.png'},
  {name:'송도윤', img:'./img/송도윤.png'},
  {name:'임하준', img:'./img/임하준.png'},
  {name:'강다인', img:'./img/강다인.png'},
  {name:'조서연', img:'./img/조서연.png'},
  {name:'배주원', img:'./img/배주원.png'},
  {name:'신가은', img:'./img/신가은.png'},
  {name:'문하린', img:'./img/문하린.png'},
  {name:'노태윤', img:'./img/노태윤.png'},
  {name:'서우진', img:'./img/서우진.png'},
  {name:'장수아', img:'./img/장수아.png'}
];

/* 엘리먼트 */
const bar=document.getElementById('bar');
const percent=document.getElementById('percent');
const modal=document.getElementById('modal');
const modalFrame=document.getElementById('modalFrame');
const certImg=document.getElementById('certImg');
const skeleton=document.getElementById('skeleton');

const nameGrid=document.getElementById('nameGrid');
const searchInput=document.getElementById('search');
const clearBtn=document.getElementById('clearBtn');
const goNext=document.getElementById('goNext');
const chosenNameTxt=document.getElementById('chosenNameTxt');
const doneTitle=document.getElementById('doneTitle');

let selectedMember=null;
let placeholderUrl=null;

/* 화면 전환 */
function show(n){
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  document.getElementById('stage'+n).classList.add('active');
}

/* Stage0 렌더 */
function renderNameGrid(filterText=''){
  const f=filterText.trim();
  const list = f ? members.filter(m=> m.name.includes(f)) : members.slice();
  nameGrid.innerHTML='';
  list.forEach(m=>{
    const btn=document.createElement('button');
    btn.type='button';
    btn.className='name-card';
    btn.setAttribute('role','option');
    btn.setAttribute('aria-selected', selectedMember && selectedMember.name===m.name ? 'true':'false');
    btn.textContent=m.name;
    if(selectedMember && selectedMember.name===m.name) btn.classList.add('selected');
    btn.addEventListener('click', ()=>{
      selectedMember=m;
      document.querySelectorAll('.name-card').forEach(el=>{
        const sel = el.textContent===m.name;
        el.classList.toggle('selected', sel);
        el.setAttribute('aria-selected', sel ? 'true':'false');
      });
      goNext.disabled=false;
    });
    nameGrid.appendChild(btn);
  });
}
renderNameGrid();
searchInput.addEventListener('input', e=>renderNameGrid(e.target.value));
clearBtn.addEventListener('click', ()=>{ searchInput.value=''; renderNameGrid(''); searchInput.focus(); });

/* 다음 단계 */
goNext.addEventListener('click', ()=>{
  if(!selectedMember) return;
  chosenNameTxt.innerHTML = `<strong>${selectedMember.name}</strong> 위원님, 인증을 완료하시고 위촉장을 생성해 주세요.`;
  doneTitle.innerHTML = `<strong>${selectedMember.name}</strong> 위원님의<br>위촉장 활성화가 완료되었습니다.`;
  show(1);
});

/* 활성화 진행 */
function startAuth(){
  show(2);
  let p=0;
  const timer=setInterval(()=>{
    p+=Math.random()*5+2;
    if(p>100)p=100;
    bar.style.width=p+'%';
    percent.textContent=Math.round(p)+'%';
    if(p>=100){
      clearInterval(timer);
      setTimeout(()=>show(3), 1000);
    }
  },200);
}

/* 모달 표시 */
function showModal(){
  if(!selectedMember) return;
  skeleton.style.display='block';

  const primary = selectedMember.img || `./img/certs/${selectedMember.name}.png`;

  certImg.onload = ()=>{ skeleton.style.display='none'; };
  certImg.onerror = ()=>{
    skeleton.style.display='none';
    certImg.src = makeCertPlaceholder(selectedMember.name);
  };
  certImg.alt = selectedMember.name + ' 위촉장 미리보기';
  certImg.src = primary;

  modal.classList.add('show');
}

/* 모달 닫힘 방지(요구사항: 닫기 없음) */
modal.addEventListener('click', e=>e.stopPropagation());
modalFrame.addEventListener('click', e=>e.stopPropagation());

/* SVG 플레이스홀더 */
function makeCertPlaceholder(name){
  if(placeholderUrl){ URL.revokeObjectURL(placeholderUrl); placeholderUrl=null; }
  const svg =
`<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200" viewBox="0 0 900 1200">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#ffffff"/>
      <stop offset="1" stop-color="#f2f5fb"/>
    </linearGradient>
    <linearGradient id="bd" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#74c7ff"/>
      <stop offset="1" stop-color="#00ffd5"/>
    </linearGradient>
  </defs>
  <rect x="20" y="20" width="860" height="1160" fill="url(#g)" rx="14" />
  <rect x="32" y="32" width="836" height="1136" fill="none" stroke="url(#bd)" stroke-width="4" rx="12" />
  <circle cx="450" cy="190" r="60" fill="#eaf6ff" stroke="#8ad7ff" stroke-width="3"/>
  <text x="450" y="205" font-size="28" text-anchor="middle" fill="#0070a8" font-family="Noto Sans KR, Pretendard, sans-serif">충남 AI 특별위원회</text>
  <text x="450" y="410" font-size="56" text-anchor="middle" fill="#16293d" font-weight="800" font-family="Noto Sans KR, Pretendard, sans-serif">위 촉 장</text>
  <text x="450" y="600" font-size="42" text-anchor="middle" fill="#0b1e33" font-weight="700" font-family="Noto Sans KR, Pretendard, sans-serif">${name}</text>
  <text x="450" y="660" font-size="18" text-anchor="middle" fill="#435165" font-family="Noto Sans KR, Pretendard, sans-serif">귀하를 충남 AI 특별위원회 위원으로 위촉합니다.</text>
  <text x="450" y="920" font-size="18" text-anchor="middle" fill="#435165" font-family="Noto Sans KR, Pretendard, sans-serif">2025년 11월 2일</text>
  <text x="450" y="960" font-size="20" text-anchor="middle" fill="#0b1e33" font-weight="700" font-family="Noto Sans KR, Pretendard, sans-serif">충청남도지사</text>
  <rect x="630" y="870" width="120" height="120" fill="#fff0" stroke="#99c9ff" stroke-dasharray="6 6"/>
</svg>`;
  const blob=new Blob([svg], {type:'image/svg+xml;charset=utf-8'});
  placeholderUrl=URL.createObjectURL(blob);
  return placeholderUrl;
}

/* 감속 모드 */
if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches){
  document.documentElement.classList.add('reduced-motion');
}
</script>
</body>
</html>
