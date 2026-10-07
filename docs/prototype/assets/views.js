/* 各视图渲染。样式参照 template/uni-app 现有页面结构。 */

const S = DATA.store;
const money = n => n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const int = n => n.toLocaleString('zh-CN');

/* ---------- 通用片段 ---------- */

const statusbar = (brand) => `
  <div class="statusbar ${brand ? 'on-brand' : ''}">
    <span>9:41</span>
    <span class="icons">📶 ﹒ 🔋</span>
  </div>`;

const navbar = (title, opts = {}) => `
  <div class="navbar ${opts.brand ? 'on-brand' : ''}">
    <div class="back">${opts.back === false ? '' : '‹'}</div>
    <div class="title">${title}</div>
    <div class="right">${opts.right || ''}</div>
  </div>`;

const storeBanner = () => `
  <div class="store-banner">
    <span class="ico">📍</span>
    <span class="nm">${S.name}</span>
    <span class="sub">扫码进店 · 本单归属此店</span>
  </div>`;

const tabbar = (active) => {
  const tabs = [
    { k: 'home', ico: '🏠', t: '首页' },
    { k: 'cate', ico: '☰', t: '分类' },
    { k: 'book', ico: '📅', t: '预约' },
    { k: 'cart', ico: '🛒', t: '购物车' },
    { k: 'me', ico: '👤', t: '我的' }
  ];
  return `<div class="tabbar">${tabs.map(x => `
    <div class="tab ${x.k === active ? 'on' : ''}" data-go="${x.k}">
      <div class="ico">${x.ico}</div><div>${x.t}</div>
    </div>`).join('')}</div>`;
};

const qr = () => {
  // 伪二维码图案
  const pat = '110101011101010110001110100101011011010001101011100101101110010100011011101001011010101110100110';
  let cells = '';
  for (let i = 0; i < 81; i++) cells += `<i class="${pat[i % pat.length] === '1' ? '' : 'b'}"></i>`;
  return `<div class="qr-box">${cells}</div>`;
};

/* ---------- 顾客端 ---------- */

const VIEWS = {};

VIEWS.home = () => `
  ${statusbar(true)}
  <div class="searchbar">
    <div class="box">🔍 搜索商品</div>
  </div>
  ${storeBanner()}
  <div class="screen-body">
    <div class="grid-nav">
      <div class="it" data-go="book"><div class="ico">📅</div>到店预约</div>
      <div class="it"><div class="ico">🎫</div>优惠券</div>
      <div class="it"><div class="ico">⭐</div>会员卡</div>
      <div class="it"><div class="ico">🎁</div>新人礼</div>
      <div class="it"><div class="ico">📞</div>联系门店</div>
    </div>

    <div class="card">
      <div class="card-title">门店信息 <span class="more">查看地图 ›</span></div>
      <div class="rowline"><span class="lab">📍 地址</span><span class="val muted">${S.address}</span></div>
      <div class="rowline"><span class="lab">🕙 营业</span><span class="val muted">${S.hours}</span></div>
      <div class="rowline"><span class="lab">☎️ 电话</span><span class="val muted">${S.phone}</span></div>
    </div>

    <div class="card-title" style="background:transparent;padding:14px 14px 4px">集团统一供货 · 好物推荐</div>
    <div class="goods-grid">
      ${DATA.goods.map(g => `
        <div class="goods-cell" data-go="goods">
          <div class="thumb">${g.ico}</div>
          <div class="meta">
            <div class="nm">${g.name}</div>
            <div class="foot">
              <span class="price"><span class="cur">¥</span><span class="int">${int(g.price)}</span></span>
              <span class="sold">${g.sold}人买过</span>
            </div>
          </div>
        </div>`).join('')}
    </div>

    <div class="annot">
      <b>原型要点：</b>顾客扫哪个门店的码，顶部归属条就显示该门店，本次下单与预约都记到这家店。同一顾客下次扫别家门店的码，会归属到新的门店——归属存在<b>订单上</b>，不是绑死在用户身上。
    </div>
  </div>
  ${tabbar('home')}`;

VIEWS.book = () => `
  ${statusbar()}
  ${navbar('到店预约')}
  ${storeBanner()}
  <div class="screen-body">
    <div class="steps">
      <div class="step cur"><div class="dot">1</div>选时段</div>
      <div class="step"><div class="dot">2</div>填信息</div>
      <div class="step"><div class="dot">3</div>到店核销</div>
      <div class="step"><div class="dot">4</div>到店付款</div>
    </div>

    <div class="divider"></div>
    <div class="card-title" style="background:#fff;margin:0">选择日期</div>
    <div class="date-strip">
      ${DATA.dates.map((d, i) => `
        <div class="date-chip ${i === 0 ? 'on' : ''} ${d.full ? 'off' : ''}" data-date="${i}">
          <div class="wd">${d.wd}</div><div class="dt">${d.d}</div>
        </div>`).join('')}
    </div>

    <div class="divider"></div>
    <div class="card-title" style="background:#fff;margin:0">
      选择时段 <span class="more">剩余名额实时更新</span>
    </div>
    <div class="slot-grid" id="slotGrid">
      ${DATA.slots.map((s, i) => {
        const left = s.cap - s.booked;
        const cls = left === 0 ? 'full' : (left <= 1 ? 'few' : '');
        return `<div class="slot ${cls}" data-slot="${i}">
          <div class="tm">${s.t}</div>
          <div class="cap">${left === 0 ? '已约满' : '余 ' + left + ' 位'}</div>
        </div>`;
      }).join('')}
    </div>

    <div class="annot">
      <b>并发控制：</b>最后一个名额被两人同时点击时，只有一个成功。占位用带守卫的单条更新（<code>WHERE booked &lt; capacity</code>），不能沿用现有商品库存的"先查后写"，那个在并发下会超卖。
    </div>
  </div>
  <div class="bottom-bar">
    <div class="sum" id="bookSum">请选择时段</div>
    <button class="btn" id="bookNext" disabled>下一步</button>
  </div>

  <div class="sheet-mask" id="bookSheet">
    <div class="sheet">
      <div class="sheet-title">确认预约信息</div>
      <div class="rowline"><span class="lab">门店</span><span class="val">${S.name}</span></div>
      <div class="rowline"><span class="lab">时间</span><span class="val" id="sheetTime">—</span></div>
      <div class="rowline"><span class="lab">联系人</span><span class="val muted">陈女士 138****6621</span></div>
      <div class="rowline"><span class="lab">备注</span><span class="val muted">无</span></div>
      <div style="padding:12px 2px;font-size:12px;color:var(--text-3);line-height:1.7">
        · 预约<b>不收取任何费用</b>，到店消费时付款<br>
        · 请于时段开始后 15 分钟内到店，逾期名额释放<br>
        · 开始前 2 小时可取消
      </div>
      <button class="btn block" id="bookConfirm" style="margin-top:6px">确认预约</button>
    </div>
  </div>
  <div class="toast" id="toast"></div>`;

VIEWS.booked = () => `
  ${statusbar()}
  ${navbar('预约成功')}
  <div class="screen-body">
    <div style="text-align:center;padding:26px 0 6px">
      <div style="font-size:48px">✅</div>
      <div style="font-size:17px;font-weight:600;margin-top:8px">预约成功</div>
      <div style="font-size:13px;color:var(--text-3);margin-top:5px">到店出示核销码即可</div>
    </div>

    <div class="verify-card">
      <div style="font-size:13px;color:var(--text-3)">核销码</div>
      <div class="verify-code">6823 4190 5573</div>
      ${qr()}
      <div style="font-size:12px;color:var(--text-4)">请到店由店员扫码核销</div>
    </div>

    <div class="card">
      <div class="rowline"><span class="lab">门店</span><span class="val">${S.name}</span></div>
      <div class="rowline"><span class="lab">时间</span><span class="val" id="bookedTime">今天 11:00 - 12:00</span></div>
      <div class="rowline"><span class="lab">地址</span><span class="val muted">${S.address}</span></div>
      <div class="rowline"><span class="lab">状态</span><span class="val"><span class="tagx orange">待到店</span></span></div>
    </div>

    <div style="padding:4px 10px 16px;display:flex;gap:10px">
      <button class="btn line" style="flex:1" data-go="book">再约一个</button>
      <button class="btn ghost" style="flex:1" data-go="home">回到首页</button>
    </div>

    <div class="annot">
      <b>复用现成能力：</b>核销码沿用订单的 <code>getStoreCode()</code>（12 位、带碰撞重试），核销走 <code>writeOffOrder()</code> 同一条路径，并校验店员的 <code>store_id</code> 与预约门店一致——防止 A 店店员核销 B 店的预约。
    </div>
  </div>
  ${tabbar('book')}`;

VIEWS.goods = () => {
  const g = DATA.goods[0];
  return `
  ${statusbar()}
  ${navbar('商品详情', { right: '⋯' })}
  <div class="screen-body">
    <div style="aspect-ratio:1;background:linear-gradient(135deg,#f0f2f5,#e2e6ea);display:flex;align-items:center;justify-content:center;font-size:96px">${g.ico}</div>
    <div class="card-pad" style="background:#fff">
      <div class="price"><span class="cur">¥</span><span class="int">${int(g.price)}</span><span class="price-del">¥${int(g.old)}</span></div>
      <div style="font-size:15px;font-weight:500;margin-top:8px;line-height:1.5">${g.name}</div>
      <div style="font-size:12px;color:var(--text-3);margin-top:6px">已售 ${g.sold} 件 · 集团统一发货</div>
    </div>
    ${storeBanner()}
    <div class="card">
      <div class="rowline"><span class="lab">配送</span><span class="val muted">集团仓直发 · 预计 2-3 天</span></div>
      <div class="rowline"><span class="lab">服务</span><span class="val muted">7 天无理由 · 全国联保</span></div>
      <div class="rowline"><span class="lab">收款</span><span class="val"><span class="tagx">本店收款</span></span></div>
    </div>
    <div class="annot">
      <b>商品与资金分离：</b>商品来自集团统一商品库、集团配送，但<b>货款进入本门店的微信子商户号</b>，之后按 ${S.rate}% 分账给品牌方。门店不持货也不发货，只承接顾客与收款。
    </div>
  </div>
  <div class="bottom-bar">
    <button class="btn line sm" style="flex:0 0 auto">🛒 加购</button>
    <button class="btn block" data-go="pay">立即购买</button>
  </div>`;
};

VIEWS.pay = () => {
  const f = DATA.flow;
  return `
  ${statusbar()}
  ${navbar('确认订单')}
  <div class="screen-body">
    <div class="card">
      <div class="rowline"><span class="lab">📍 收货</span><span class="val muted">陈女士 138****6621<br>杭州市西湖区文三路 258 号</span></div>
    </div>
    <div class="card">
      <div class="li-main" style="padding:14px">
        <div class="th">${DATA.goods[0].ico}</div>
        <div class="info">
          <div class="t" style="font-size:13px;line-height:1.4">${DATA.goods[0].name}</div>
          <div class="s">×1</div>
        </div>
        <div class="price"><span class="cur">¥</span><span class="int">${int(f.total)}</span></div>
      </div>
    </div>
    ${storeBanner()}
    <div class="card">
      <div class="rowline"><span class="lab">商品金额</span><span class="val">¥${money(f.total)}</span></div>
      <div class="rowline"><span class="lab">运费</span><span class="val">免运费</span></div>
      <div class="rowline"><span class="lab">收款方</span><span class="val muted">${S.name}<br><span style="font-size:11px">子商户号 ${S.subMchid}</span></span></div>
    </div>
    <div class="annot">
      <b>支付参数按门店取值：</b>下单时用本门店的 <code>sub_mchid</code> 调起支付，钱直接进这家店的商户号。现在系统的商户号是<b>全站单一配置</b>（<code>SystemConfigService::more()</code> 全局读取），这里是需要改造的地方。
    </div>
  </div>
  <div class="bottom-bar">
    <div class="sum">合计 <span class="price"><span class="cur">¥</span><span class="int">${int(f.total)}</span></span></div>
    <button class="btn" data-go="paid">微信支付</button>
  </div>`;
};

VIEWS.paid = () => {
  const f = DATA.flow;
  return `
  ${statusbar()}
  ${navbar('支付成功', { back: false })}
  <div class="screen-body">
    <div style="text-align:center;padding:30px 0 10px">
      <div style="font-size:48px">🎉</div>
      <div style="font-size:18px;font-weight:600;margin-top:8px">支付成功</div>
      <div class="price" style="margin-top:6px"><span class="cur">¥</span><span class="int">${int(f.total)}</span></div>
    </div>
    <div class="card">
      <div class="rowline"><span class="lab">收款门店</span><span class="val">${S.name}</span></div>
      <div class="rowline"><span class="lab">支付方式</span><span class="val muted">微信支付</span></div>
      <div class="rowline"><span class="lab">资金状态</span><span class="val"><span class="tagx orange">待分账（冻结）</span></span></div>
    </div>
    <div style="padding:6px 10px 14px;display:flex;gap:10px">
      <button class="btn line" style="flex:1" data-go="home">继续逛</button>
      <button class="btn ghost" style="flex:1" data-role="flow">看资金流向 →</button>
    </div>
    <div class="annot">
      <b>资金此刻在哪：</b>全额 ¥${money(f.total)} 已进入门店子商户号，处于<b>待分账冻结</b>状态。接着系统发起分账，抽成 ${f.rate}% 给品牌方，再完结分账解冻门店余额。切到「资金流」看完整过程。
    </div>
  </div>
  ${tabbar('home')}`;
};

VIEWS.me = () => `
  ${statusbar(true)}
  <div style="background:var(--brand);padding:18px 16px 26px;color:#fff">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:50px;height:50px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:24px">👤</div>
      <div>
        <div style="font-size:16px;font-weight:600">陈女士</div>
        <div style="font-size:12px;opacity:.85;margin-top:3px">常去门店：${S.name}</div>
      </div>
    </div>
  </div>
  <div class="screen-body">
    <div class="card" style="margin-top:-14px">
      <div class="kpi-grid" style="padding:14px;grid-template-columns:repeat(4,1fr)">
        <div style="text-align:center"><div style="font-size:18px;font-weight:600">2</div><div style="font-size:11px;color:var(--text-3)">待到店</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:600">5</div><div style="font-size:11px;color:var(--text-3)">待收货</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:600">3</div><div style="font-size:11px;color:var(--text-3)">优惠券</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:600">¥265</div><div style="font-size:11px;color:var(--text-3)">分享收益</div></div>
      </div>
    </div>
    <div class="card">
      <div class="card-title">我的预约 <span class="more">全部 ›</span></div>
      <div class="rowline" data-go="booked">
        <span class="lab">📅 ${S.name}</span>
        <span class="val"><span class="tagx orange">待到店</span><span class="arrow">›</span></span>
      </div>
      <div class="rowline">
        <span class="lab">📅 天俪·杭州武林店</span>
        <span class="val"><span class="tagx green">已到店</span><span class="arrow">›</span></span>
      </div>
    </div>
    <div class="card">
      <div class="rowline"><span class="lab">🎁 我的分享收益</span><span class="val">¥265.93 <span class="arrow">›</span></span></div>
      <div class="rowline"><span class="lab">👥 我的邀请</span><span class="val muted">8 人 <span class="arrow">›</span></span></div>
      <div class="rowline"><span class="lab">💳 提现</span><span class="val muted">›</span></div>
    </div>
    <div class="annot">
      <b>两套归属并存：</b>这位顾客在武林店也有预约记录——门店归属按<b>每次进店</b>算，不会互相覆盖。同时她分享商品给朋友产生的佣金（¥265.93）走的是现有分销体系，由<b>品牌方</b>从抽成中支付，与门店收入无关。
    </div>
  </div>
  ${tabbar('me')}`;

/* ---------- 门店端 ---------- */

VIEWS.storeHome = () => {
  const k = DATA.storeKpi;
  return `
  ${statusbar()}
  ${navbar('门店工作台', { back: false, right: '⚙️' })}
  <div class="screen-body">
    <div style="background:linear-gradient(96deg,var(--brand),#ff6a3d);color:#fff;padding:16px">
      <div style="font-size:12px;opacity:.9">${S.name} · ${S.code}</div>
      <div style="font-size:12px;opacity:.9;margin-top:10px">本月营业额</div>
      <div style="font-size:28px;font-weight:700;margin-top:2px">¥${int(k.monthAmount)}</div>
      <div style="display:flex;gap:22px;margin-top:12px;font-size:12px">
        <div><div style="opacity:.85">已结算</div><div style="font-size:15px;font-weight:600;margin-top:2px">¥${int(k.monthSettle)}</div></div>
        <div><div style="opacity:.85">品牌抽成 ${S.rate}%</div><div style="font-size:15px;font-weight:600;margin-top:2px">¥${int(k.monthCommission)}</div></div>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi"><div class="lab">今日订单</div><div class="num">${k.todayOrder}<small> 单</small></div><div class="delta up">↑ 较昨日 +3</div></div>
      <div class="kpi"><div class="lab">今日收款</div><div class="num">¥${int(k.todayAmount)}</div><div class="delta up">↑ +18%</div></div>
      <div class="kpi"><div class="lab">今日预约</div><div class="num">${k.todayBooking}<small> 个</small></div><div class="delta">已到店 ${k.arrived}</div></div>
      <div class="kpi"><div class="lab">待核销</div><div class="num">2<small> 个</small></div><div class="delta down">需关注</div></div>
    </div>

    <div class="grid-nav" style="grid-template-columns:repeat(4,1fr);margin:0 10px;border-radius:var(--radius)">
      <div class="it" data-go="storeVerify"><div class="ico">📷</div>扫码核销</div>
      <div class="it" data-go="storeBooking"><div class="ico">📅</div>预约管理</div>
      <div class="it"><div class="ico">📦</div>订单</div>
      <div class="it"><div class="ico">💰</div>结算</div>
    </div>

    <div class="card">
      <div class="card-title">今日待到店 <span class="more" data-go="storeBooking">全部 ›</span></div>
      ${DATA.bookings.filter(b => b.status === 'pending').map(b => `
        <div class="rowline">
          <span class="lab">🕙 ${b.slot}　${b.name}</span>
          <span class="val"><span class="tagx orange">待到店</span><span class="arrow">›</span></span>
        </div>`).join('')}
    </div>

    <div class="annot">
      <b>门店只看自己的数据。</b>门店端不能看到其他加盟店的订单、预约和结算。商品与库存由集团统一管理，门店<b>不能自建商品</b>，也无需处理发货。
    </div>
  </div>`;
};

VIEWS.storeVerify = () => `
  ${statusbar()}
  ${navbar('扫码核销')}
  <div class="screen-body" style="background:#1c1c1e">
    <div style="height:300px;display:flex;align-items:center;justify-content:center;position:relative">
      <div style="width:190px;height:190px;border:2px solid var(--brand);border-radius:14px;position:relative">
        <div style="position:absolute;left:0;right:0;height:2px;background:var(--brand);top:50%;box-shadow:0 0 12px var(--brand)"></div>
      </div>
      <div style="position:absolute;bottom:28px;left:0;right:0;text-align:center;color:#fff;font-size:13px;opacity:.8">将顾客核销码放入框内</div>
    </div>
    <div style="background:var(--bg);padding:14px 0">
      <div class="card" style="margin-top:0">
        <div class="card-title">或手动输入核销码</div>
        <div style="padding:0 14px 14px">
          <div style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;font-size:17px;letter-spacing:2px;color:var(--text-4);font-variant-numeric:tabular-nums">6823 4190 5573</div>
          <button class="btn block" style="margin-top:12px" data-go="storeVerified">确认核销</button>
        </div>
      </div>
      <div class="annot">
        <b>权限校验：</b>核销时校验店员的 <code>verify_status=1</code>，并要求店员的 <code>store_id</code> 与预约门店<b>一致</b>。这条防的是加盟商之间的信任问题——A 店店员不能核销 B 店的预约。
      </div>
    </div>
  </div>`;

VIEWS.storeVerified = () => `
  ${statusbar()}
  ${navbar('核销成功')}
  <div class="screen-body">
    <div style="text-align:center;padding:32px 0 12px">
      <div style="font-size:52px">✅</div>
      <div style="font-size:18px;font-weight:600;margin-top:10px">核销成功</div>
      <div style="font-size:13px;color:var(--text-3);margin-top:5px">顾客已到店</div>
    </div>
    <div class="card">
      <div class="rowline"><span class="lab">预约号</span><span class="val">YY20260730001</span></div>
      <div class="rowline"><span class="lab">顾客</span><span class="val">陈** 138****6621</span></div>
      <div class="rowline"><span class="lab">时段</span><span class="val">今天 11:00 - 12:00</span></div>
      <div class="rowline"><span class="lab">核销人</span><span class="val muted">店员 小林</span></div>
      <div class="rowline"><span class="lab">核销时间</span><span class="val muted">今天 11:06</span></div>
      <div class="rowline"><span class="lab">状态</span><span class="val"><span class="tagx green">已到店</span></span></div>
    </div>
    <div style="padding:6px 10px 14px">
      <button class="btn block" data-go="storeVerify">继续核销下一位</button>
    </div>
    <div class="annot">
      <b>预约不含付款。</b>核销只表示顾客到店，此时名额被消耗但<b>不释放</b>。顾客在店内购买时才走正常下单流程，那笔订单会关联到这条预约做业绩统计。
    </div>
  </div>`;

VIEWS.storeBooking = () => `
  ${statusbar()}
  ${navbar('预约管理', { right: '⚙️' })}
  <div class="screen-body">
    <div style="background:#fff;padding:12px 14px;border-bottom:1px solid var(--line)">
      <div class="seg">
        <button class="on">今天 (6)</button><button>明天 (4)</button><button>全部</button>
      </div>
    </div>
    ${DATA.bookings.map(b => {
      const map = {
        pending: ['orange', '待到店'], arrived: ['green', '已到店'],
        expired: ['gray', '已过期'], cancelled: ['gray', '已取消']
      };
      const [c, t] = map[b.status];
      return `<div class="li">
        <div class="li-head">
          <span class="no">${b.no}</span>
          <span class="tagx ${c}">${t}</span>
        </div>
        <div class="li-main">
          <div class="th">📅</div>
          <div class="info">
            <div class="t">${b.name}　${b.phone}</div>
            <div class="s">${b.slot} · 核销码 ${b.code.slice(0, 4)} **** ${b.code.slice(-4)}</div>
          </div>
        </div>
        ${b.status === 'pending' ? `<div class="li-foot">
          <button class="btn line sm">取消</button>
          <button class="btn sm" data-go="storeVerify">核销</button>
        </div>` : ''}
      </div>`;
    }).join('')}
    <div class="annot">
      <b>过期自动释放名额。</b>时段结束后超过宽限期仍未核销的预约（如上面刘**那条），由定时任务标记过期并<b>释放名额</b>。不做这件事，容量会永久泄漏，门店日历会被废弃预约占满。
    </div>
  </div>`;

VIEWS.storeSlot = () => `
  ${statusbar()}
  ${navbar('时段设置')}
  <div class="screen-body">
    <div class="card">
      <div class="card-title">营业时间</div>
      <div class="rowline"><span class="lab">营业时段</span><span class="val">${S.hours} <span class="arrow">›</span></span></div>
      <div class="rowline"><span class="lab">可预约天数</span><span class="val">提前 7 天 <span class="arrow">›</span></span></div>
      <div class="rowline"><span class="lab">时段长度</span><span class="val">60 分钟 <span class="arrow">›</span></span></div>
    </div>
    <div class="card">
      <div class="card-title">各时段容量</div>
      ${DATA.slots.map(s => `
        <div class="rowline">
          <span class="lab">${s.t}</span>
          <span class="val">${s.cap} 位　<span style="font-size:12px;color:var(--text-3)">已约 ${s.booked}</span> <span class="arrow">›</span></span>
        </div>`).join('')}
    </div>
    <div class="annot">
      <b>待定规则：</b>时段与容量由门店自配还是品牌方统一，尚未确定（见需求文档待确认清单第 5 条）。另外可预约天数、取消时限、失约处理、单用户限额也都需要业务方拍板。
    </div>
  </div>`;

/* ---------- 品牌方 ---------- */

VIEWS.brandDash = () => {
  const k = DATA.brandKpi;
  const max = Math.max(...DATA.brandStores.map(s => s.amount));
  return `
  <div class="admin-panel">
    <div class="admin-head">
      <h2>品牌方总览</h2>
      <span class="tagx blue">全部门店</span>
      <div class="spacer"></div>
      <div class="seg"><button class="on">本月</button><button>本季</button><button>全年</button></div>
    </div>
    <div class="admin-body">
      <div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);padding:0 0 18px">
        <div class="kpi"><div class="lab">加盟门店</div><div class="num">${k.stores}<small> 家</small></div><div class="delta up">↑ 本月新增 1</div></div>
        <div class="kpi"><div class="lab">全平台 GMV</div><div class="num">¥${int(k.gmv)}</div><div class="delta up">↑ +24%</div></div>
        <div class="kpi"><div class="lab">抽成收入</div><div class="num">¥${int(k.commission)}</div><div class="delta up">↑ +22%</div></div>
        <div class="kpi"><div class="lab">到店预约</div><div class="num">${k.bookings}<small> 个</small></div><div class="delta">到店率 78%</div></div>
        <div class="kpi"><div class="lab">已付分销佣金</div><div class="num">¥${int(k.distributed)}</div><div class="delta">占抽成 12%</div></div>
      </div>

      <h3 style="font-size:14px;font-weight:600;margin:6px 0 12px">门店营业额排行</h3>
      <div class="bar-chart" style="padding:0">
        ${DATA.brandStores.map(s => `
          <div class="bar-row">
            <span class="nm">${s.name}</span>
            <span class="track"><span class="fill" style="width:${(s.amount / max * 100).toFixed(0)}%"></span></span>
            <span class="vl">¥${int(s.amount)}</span>
          </div>`).join('')}
      </div>

      <div class="annot" style="margin:14px 0 0">
        <b>分销佣金由品牌方承担。</b>本月付出 ¥${int(k.distributed)} 分销佣金，占抽成收入的 12%。这笔钱不从门店收入扣——所以<b>抽成比例必须覆盖分销成本与平台运营成本</b>。
      </div>
    </div>
  </div>

  <div class="admin-panel" style="margin-top:18px">
    <div class="admin-head"><h2>门店与分账</h2><div class="spacer"></div><button class="btn sm">+ 新增门店</button></div>
    <div class="admin-body" style="padding:0">
      <table class="tbl">
        <thead><tr>
          <th>门店</th><th>子商户号</th><th class="right">营业额</th><th class="right">订单</th>
          <th class="right">抽成比例</th><th class="right">已分账</th><th>状态</th><th></th>
        </tr></thead>
        <tbody>
        ${DATA.brandStores.map((s, i) => `
          <tr>
            <td><b>${s.name}</b></td>
            <td class="num" style="color:var(--text-3)">16****88${20 + i}</td>
            <td class="right num">¥${int(s.amount)}</td>
            <td class="right num">${s.orders}</td>
            <td class="right num">${s.rate}%</td>
            <td class="right num">¥${int(s.settled)}</td>
            <td>${s.status === 'ok'
              ? '<span class="tagx green">进件完成</span>'
              : '<span class="tagx orange">待门店签约</span>'}</td>
            <td class="right"><span style="color:var(--blue);font-size:12px;cursor:pointer">详情</span></td>
          </tr>`).join('')}
        </tbody>
      </table>
      <div class="annot" style="margin:14px">
        <b>进件需门店配合。</b>滨江店状态是「待门店签约」——微信会向门店法人手机发签约链接，<b>必须门店方主动完成</b>，否则无法收款。这一步在实际运营中经常卡住。<br>
        另外每个门店必须有<b>独立营业执照与对公账户</b>才能进件，个体户可以但有限额差异。
      </div>
    </div>
  </div>`;
};

/* ---------- 资金流 ---------- */

VIEWS.flowView = () => {
  const f = DATA.flow;
  return `
  <div class="admin-panel">
    <div class="admin-head">
      <h2>一笔订单的资金流向</h2>
      <span class="tagx">订单金额 ¥${money(f.total)}</span>
      <div class="spacer"></div>
      <span style="font-size:12px;color:var(--text-3)">门店抽成 ${f.rate}% · 分销佣金由品牌方承担</span>
    </div>
    <div class="admin-body">
      <div class="flow" style="padding:0;max-width:560px;margin:0 auto">

        <div class="flow-node">
          <div class="ic">👤</div>
          <div class="tx"><div class="t1">消费者付款</div><div class="t2">在 ${S.name} 子商城下单</div></div>
          <div class="amt">¥${money(f.total)}</div>
        </div>

        <div class="flow-arrow">↓<small>微信支付（服务商模式，带本店 sub_mchid）</small></div>

        <div class="flow-node" style="border-left:3px solid var(--brand)">
          <div class="ic">🏪</div>
          <div class="tx"><div class="t1">进入门店子商户号</div><div class="t2">${S.subMchid} · 待分账（冻结）</div></div>
          <div class="amt">¥${money(f.total)}</div>
        </div>

        <div class="flow-arrow">↓<small>发起分账（须在微信规定窗口内，默认 30 天）</small></div>

        <div style="display:flex;gap:10px">
          <div class="flow-node" style="flex:1;border-left:3px solid var(--green)">
            <div class="ic" style="background:#e8f8ee">🏪</div>
            <div class="tx"><div class="t1">门店留存</div><div class="t2">解冻后 T+1 到对公账户</div></div>
            <div class="amt" style="color:var(--green)">¥${money(f.storeGet)}</div>
          </div>
          <div class="flow-node" style="flex:1;border-left:3px solid var(--brand)">
            <div class="ic">🏢</div>
            <div class="tx"><div class="t1">品牌方抽成 ${f.rate}%</div><div class="t2">分账至品牌方商户号</div></div>
            <div class="amt" style="color:var(--brand)">¥${money(f.commission)}</div>
          </div>
        </div>

        <div class="flow-arrow">↓<small>品牌方从抽成中支付分销佣金（不进微信分账链路）</small></div>

        <div class="flow-node">
          <div class="ic" style="background:#eaf1fe">🎁</div>
          <div class="tx"><div class="t1">一级分享佣金</div><div class="t2">分享者 张三 · 走现有提现体系</div></div>
          <div class="amt" style="color:var(--blue)">¥${money(f.brokerOne)}</div>
        </div>
        <div style="height:8px"></div>
        <div class="flow-node">
          <div class="ic" style="background:#eaf1fe">🎁</div>
          <div class="tx"><div class="t1">二级分享佣金</div><div class="t2">上级 李四</div></div>
          <div class="amt" style="color:var(--blue)">¥${money(f.brokerTwo)}</div>
        </div>
        <div style="height:8px"></div>
        <div class="flow-node" style="border-left:3px solid var(--gold)">
          <div class="ic" style="background:#fdf4e3">🏢</div>
          <div class="tx"><div class="t1">品牌方净收入</div><div class="t2">抽成 − 分销佣金</div></div>
          <div class="amt" style="color:#a5762f">¥${money(f.brandNet)}</div>
        </div>

      </div>

      <div class="annot" style="max-width:560px;margin:18px auto 0">
        <b>为什么分销佣金不走微信分账：</b>微信分账要求接收方<b>预先注册并确认</b>，个人接收方限制严格，且单笔分账总额有上限（标准为订单金额 30%）。让品牌方拿到抽成后用现有 <code>eb_user_extract</code> 提现体系发佣金，规避了这两个硬限制。
      </div>

      <div class="annot" style="max-width:560px;margin:12px auto 0;background:#fff1f0;border-color:#ffa39e;color:#a8071a">
        <b>⚠ 退款是最容易出错的环节：</b>若订单已分账，必须<b>先调用分账回退</b>把抽成退回门店，再发起退款。顺序错了会导致门店余额不足、退款失败。部分退款还需按比例计算回退金额。
      </div>
    </div>
  </div>

  <div class="admin-panel" style="margin-top:18px">
    <div class="admin-head"><h2>前置条件（任一不通过，方案需重做）</h2></div>
    <div class="admin-body" style="padding:0">
      <table class="tbl">
        <thead><tr><th>#</th><th>前置条件</th><th>说明</th><th>状态</th></tr></thead>
        <tbody>
          <tr><td>1</td><td>品牌方申请微信支付<b>服务商</b>资质</td><td>需企业执照、法人信息、对公账户</td><td><span class="tagx orange">待确认</span></td></tr>
          <tr><td>2</td><td>单独开通<b>服务商分账</b>产品权限</td><td>不是服务商默认能力，需另行申请</td><td><span class="tagx orange">待确认</span></td></tr>
          <tr><td>3</td><td>抽成比例在<b>分账上限</b>内</td><td>标准为订单金额 30%，部分行业可申请提高</td><td><span class="tagx orange">待确认</span></td></tr>
          <tr><td>4</td><td>加盟门店<b>均有独立营业执照与对公账户</b></td><td>无独立主体无法进件；个体户有限额差异</td><td><span class="tagx orange">待确认</span></td></tr>
          <tr><td>5</td><td><b>税务模型</b>经会计师确认</td><td>资金进门店主体＝门店按全额收入开票纳税，品牌方按抽成向门店开服务费票</td><td><span class="tagx orange">待确认</span></td></tr>
        </tbody>
      </table>
    </div>
  </div>`;
};
