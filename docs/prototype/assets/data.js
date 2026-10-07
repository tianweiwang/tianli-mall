/* 原型演示数据。商品取自当前验收库的真实记录，门店与预约为演示数据。 */

const DATA = {
  // 当前扫码进入的门店
  store: {
    id: 3,
    name: '天俪·杭州西湖店',
    code: 'HZ-XH-003',
    address: '杭州市西湖区文三路 258 号 1F',
    phone: '0571-8888 6003',
    hours: '10:00 - 21:00',
    subMchid: '16****8823',
    rate: 20
  },

  otherStores: [
    { id: 1, name: '天俪·杭州武林店', code: 'HZ-WL-001' },
    { id: 2, name: '天俪·杭州滨江店', code: 'HZ-BJ-002' },
    { id: 4, name: '天俪·宁波天一店', code: 'NB-TY-004' }
  ],

  // 商品：来自验收库 eb_store_product 真实数据
  goods: [
    { id: 4, name: 'Apple/苹果iPad mini6 8.3英寸平板电脑 64G-WLAN版 深空灰色', price: 3799, old: 4299, sold: 128, ico: '📱' },
    { id: 3, name: '智能护眼台灯 Pro 触控调光 国AA级照度', price: 299, old: 399, sold: 486, ico: '💡' },
    { id: 2, name: '轻奢真皮单肩包 头层牛皮 通勤款', price: 899, old: 1299, sold: 92, ico: '👜' },
    { id: 1, name: '天俪定制香氛礼盒 三支装 附贺卡', price: 458, old: 598, sold: 231, ico: '🎁' }
  ],

  // 预约时段
  dates: [
    { d: '7-30', wd: '今天', full: false },
    { d: '7-31', wd: '周五', full: false },
    { d: '8-01', wd: '周六', full: false },
    { d: '8-02', wd: '周日', full: false },
    { d: '8-03', wd: '周一', full: true },
    { d: '8-04', wd: '周二', full: false }
  ],

  slots: [
    { t: '10:00', cap: 5, booked: 5 },
    { t: '11:00', cap: 5, booked: 2 },
    { t: '13:00', cap: 5, booked: 0 },
    { t: '14:00', cap: 5, booked: 4 },
    { t: '15:00', cap: 5, booked: 1 },
    { t: '16:00', cap: 5, booked: 0 },
    { t: '17:00', cap: 3, booked: 3 },
    { t: '19:00', cap: 3, booked: 1 },
    { t: '20:00', cap: 3, booked: 0 }
  ],

  // 门店端：待到店预约
  bookings: [
    { no: 'YY20260730001', name: '陈**', phone: '138****6621', slot: '今天 11:00', code: '682341905573', status: 'pending' },
    { no: 'YY20260730002', name: '李**', phone: '139****2280', slot: '今天 14:00', code: '682341906104', status: 'pending' },
    { no: 'YY20260730003', name: '王**', phone: '137****9945', slot: '今天 14:00', code: '682341907732', status: 'arrived' },
    { no: 'YY20260729018', name: '刘**', phone: '135****3312', slot: '昨天 16:00', code: '682330441182', status: 'expired' }
  ],

  // 门店端 KPI
  storeKpi: {
    todayOrder: 12, todayAmount: 8642, todayBooking: 6, arrived: 4,
    monthAmount: 186420, monthSettle: 149136, monthCommission: 37284
  },

  // 品牌方：门店排行
  brandStores: [
    { name: '杭州西湖店', amount: 186420, orders: 214, rate: 20, settled: 37284, status: 'ok' },
    { name: '杭州武林店', amount: 152800, orders: 178, rate: 20, settled: 30560, status: 'ok' },
    { name: '宁波天一店', amount: 98650, orders: 121, rate: 18, settled: 17757, status: 'ok' },
    { name: '杭州滨江店', amount: 76200, orders: 94, rate: 20, settled: 15240, status: 'pending' }
  ],

  brandKpi: {
    stores: 4, gmv: 514070, commission: 100841, bookings: 86, distributed: 12460
  },

  // 资金流演示
  flow: {
    total: 3799,
    rate: 20,
    commission: 759.8,
    storeGet: 3039.2,
    brokerOne: 189.95,
    brokerTwo: 75.98,
    brandNet: 493.87
  }
};
