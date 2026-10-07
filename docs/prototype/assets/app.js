/* 原型交互控制 */

const phonesEl = document.getElementById('phones');
const adminEl = document.getElementById('adminWrap');
const hintEl = document.getElementById('hintText');

const HINTS = {
  customer: '顾客在店内扫码进入该门店子商城，可预约到店或直接下单。点击金刚区、商品、按钮均可交互。',
  store: '门店端只能看到本店数据。扫码核销会校验店员所属门店，防止跨店核销。',
  brand: '品牌方总览全部门店的营业额、抽成与分账状态。注意「待门店签约」状态需门店方主动完成。',
  flow: '一笔 ¥3,799 订单的完整资金流向：先进门店子商户号，分账抽成给品牌方，分销佣金由品牌方从抽成中支付。'
};

// 顾客端：多屏并排展示 + 单屏可交互
const ROLE_SCREENS = {
  customer: [
    { v: 'home', label: '① 扫码进店 · 门店首页' },
    { v: 'book', label: '② 选择预约时段' },
    { v: 'booked', label: '③ 预约成功 · 核销码' }
  ],
  store: [
    { v: 'storeHome', label: '① 门店工作台' },
    { v: 'storeBooking', label: '② 预约管理' },
    { v: 'storeVerify', label: '③ 扫码核销' }
  ]
};

let state = { role: 'customer', slot: null, date: 0 };

/* ---------- 渲染 ---------- */

function phone(viewName, label) {
  const html = VIEWS[viewName] ? VIEWS[viewName]() : '<div class="empty">视图缺失</div>';
  return `<div>
    <div class="phone" data-screen="${viewName}">
      <div class="phone-screen">${html}</div>
    </div>
    <div class="phone-label">${label || ''}</div>
  </div>`;
}

const navEl = document.getElementById('screenNav');

function renderNav() {
  const screens = ROLE_SCREENS[state.role];
  if (!screens || state.role === 'brand' || state.role === 'flow') {
    navEl.innerHTML = '';
    return;
  }
  const cur = state.single || screens[0].v;
  navEl.innerHTML = screens.map(s =>
    `<button class="${s.v === cur ? 'on' : ''}" data-nav="${s.v}" data-navlabel="${s.label}">${s.label}</button>`
  ).join('');
  navEl.querySelectorAll('[data-nav]').forEach(b => {
    b.addEventListener('click', () => go(b.dataset.nav, b.dataset.navlabel));
  });
}

function render() {
  const r = state.role;
  hintEl.textContent = HINTS[r] || '';
  renderNav();

  if (r === 'brand') {
    phonesEl.style.display = 'none';
    adminEl.style.display = '';
    adminEl.innerHTML = VIEWS.brandDash();
    return;
  }
  if (r === 'flow') {
    phonesEl.style.display = 'none';
    adminEl.style.display = '';
    adminEl.innerHTML = VIEWS.flowView();
    return;
  }

  adminEl.style.display = 'none';
  phonesEl.style.display = '';

  if (state.single) {
    phonesEl.innerHTML = phone(state.single, state.singleLabel || '');
  } else {
    const screens = ROLE_SCREENS[r] || [];
    phonesEl.innerHTML = screens.map(s => phone(s.v, s.label)).join('');
  }
  bindScreen();
}

/* ---------- 屏内交互 ---------- */

function toast(msg, host) {
  const t = (host || document).querySelector('#toast') || document.getElementById('toast');
  if (!t) return alert(msg);
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 1900);
}

function bindScreen() {
  // 日期切换
  phonesEl.querySelectorAll('[data-date]').forEach(el => {
    el.addEventListener('click', () => {
      const wrap = el.closest('.date-strip');
      wrap.querySelectorAll('.date-chip').forEach(c => c.classList.remove('on'));
      el.classList.add('on');
      state.date = +el.dataset.date;
    });
  });

  // 时段选择
  phonesEl.querySelectorAll('[data-slot]').forEach(el => {
    el.addEventListener('click', () => {
      const scr = el.closest('.phone-screen');
      scr.querySelectorAll('.slot').forEach(s => s.classList.remove('on'));
      el.classList.add('on');
      const i = +el.dataset.slot;
      state.slot = DATA.slots[i];
      const d = DATA.dates[state.date];
      const sum = scr.querySelector('#bookSum');
      const btn = scr.querySelector('#bookNext');
      if (sum) sum.innerHTML = `已选 <b>${d.wd} ${state.slot.t}</b>`;
      if (btn) btn.disabled = false;
    });
  });

  // 下一步 → 确认弹层
  phonesEl.querySelectorAll('#bookNext').forEach(btn => {
    btn.addEventListener('click', () => {
      const scr = btn.closest('.phone-screen');
      if (!state.slot) return;
      const d = DATA.dates[state.date];
      const st = scr.querySelector('#sheetTime');
      if (st) st.textContent = `${d.wd} ${d.d} ${state.slot.t} - ${(+state.slot.t.split(':')[0]) + 1}:00`;
      scr.querySelector('#bookSheet').classList.add('show');
    });
  });

  // 关闭弹层
  phonesEl.querySelectorAll('.sheet-mask').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
  });

  // 确认预约
  phonesEl.querySelectorAll('#bookConfirm').forEach(btn => {
    btn.addEventListener('click', () => {
      const scr = btn.closest('.phone-screen');
      scr.querySelector('#bookSheet').classList.remove('show');
      toast('预约成功，已占用 1 个名额', scr);
      setTimeout(() => go('booked', '预约成功 · 核销码'), 900);
    });
  });

  // 满位时段提示
  phonesEl.querySelectorAll('.slot.full').forEach(el => {
    el.style.pointerEvents = 'auto';
    el.addEventListener('click', () => toast('该时段已约满，请选择其他时段', el.closest('.phone-screen')));
  });

  // 视图跳转
  phonesEl.querySelectorAll('[data-go]').forEach(el => {
    el.addEventListener('click', e => {
      e.stopPropagation();
      const v = el.dataset.go;
      const MAP = {
        home: ['home', '门店首页'], book: ['book', '选择预约时段'],
        booked: ['booked', '预约成功'], goods: ['goods', '商品详情'],
        pay: ['pay', '确认订单'], paid: ['paid', '支付成功'],
        me: ['me', '我的'], cate: ['home', '门店首页'], cart: ['pay', '确认订单'],
        storeVerify: ['storeVerify', '扫码核销'],
        storeVerified: ['storeVerified', '核销成功'],
        storeBooking: ['storeBooking', '预约管理'],
        storeSlot: ['storeSlot', '时段设置']
      };
      if (MAP[v]) go(MAP[v][0], MAP[v][1]);
    });
  });

  // 屏内切角色
  phonesEl.querySelectorAll('[data-role]').forEach(el => {
    el.addEventListener('click', () => setRole(el.dataset.role));
  });
}

function go(view, label) {
  state.single = view;
  state.singleLabel = label;
  render();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function setRole(role) {
  state.role = role;
  state.single = null;
  state.slot = null;
  document.querySelectorAll('#roleSwitch button').forEach(b => {
    b.classList.toggle('on', b.dataset.role === role);
  });
  render();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.getElementById('roleSwitch').addEventListener('click', e => {
  const b = e.target.closest('button');
  if (b) setRole(b.dataset.role);
});

render();
