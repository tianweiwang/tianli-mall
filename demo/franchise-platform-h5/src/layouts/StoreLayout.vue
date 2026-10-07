<template>
  <AdminShell
    role-label="加盟门店"
    :user-label="store.name"
    home-path="/store/dashboard"
    :menu-items="menuItems"
  />
</template>

<script>
import {
  Activity,
  BadgeDollarSign,
  BarChart3,
  Boxes,
  CalendarDays,
  ClipboardList,
  LayoutDashboard,
  ScanLine,
  Store,
  Video,
  WalletCards,
} from 'lucide-vue';
import AdminShell from '../components/admin/AdminShell.vue';
import { demoStore } from '../mock/store';

export default {
  name: 'StoreLayout',
  components: { AdminShell },
  data() {
    return {
      menuItems: [
        { label: '门店工作台', path: '/store/dashboard', icon: LayoutDashboard },
        { label: '订单管理', path: '/store/orders', icon: ClipboardList },
        { label: '预约管理', path: '/store/bookings', icon: CalendarDays },
        { label: '检测中心', path: '/store/assessments', icon: Activity },
        { label: '扫码核销', path: '/store/verify', icon: ScanLine },
        { label: '直播管理', path: '/store/live', icon: Video },
        { label: '服务与商品', path: '/store/products', icon: Boxes },
        { label: '门店业绩', path: '/store/performance', icon: BarChart3 },
        { label: '结算明细', path: '/store/settlements', icon: BadgeDollarSign },
        { label: '提现管理', path: '/store/withdrawals', icon: WalletCards },
        { label: '门店资料', path: '/store/profile', icon: Store },
      ],
    };
  },
  computed: {
    store() {
      return demoStore.state.data.stores.find(
        (item) => item.id === demoStore.state.data.activeStoreId,
      );
    },
  },
  created() {
    demoStore.commit((draft) => {
      draft.activeRole = 'store';
    });
  },
};
</script>
