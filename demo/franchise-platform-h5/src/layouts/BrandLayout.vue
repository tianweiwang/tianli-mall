<template>
  <AdminShell
    role-label="品牌方后台"
    user-label="品牌运营中心"
    home-path="/brand/dashboard"
    :menu-items="menuItems"
  />
</template>

<script>
import {
  Activity,
  BadgeCheck,
  BadgeDollarSign,
  BarChart3,
  Boxes,
  Building2,
  CalendarDays,
  ClipboardList,
  LayoutDashboard,
  MonitorCog,
  Network,
  Split,
  Users,
  Video,
  WalletCards,
} from 'lucide-vue';
import AdminShell from '../components/admin/AdminShell.vue';
import { demoStore } from '../mock/store';

export default {
  name: 'BrandLayout',
  components: { AdminShell },
  data() {
    return {
      menuItems: [
        { label: '平台总览', path: '/brand/dashboard', icon: LayoutDashboard },
        { label: '加盟门店', path: '/brand/stores', icon: Building2 },
        { label: '区域代理', path: '/brand/agents', icon: Network },
        { label: '进件审核', path: '/brand/onboarding', icon: BadgeCheck },
        { label: '服务与商品', path: '/brand/products', icon: Boxes },
        { label: '全平台订单', path: '/brand/orders', icon: ClipboardList },
        { label: '全平台预约', path: '/brand/bookings', icon: CalendarDays },
        { label: '会员运营', path: '/brand/members', icon: BadgeDollarSign },
        { label: '检测运营', path: '/brand/assessments', icon: Activity },
        { label: '设备管理', path: '/brand/devices', icon: MonitorCog },
        { label: '直播运营', path: '/brand/live', icon: Video },
        { label: '分账流水', path: '/brand/sharing', icon: Split },
        { label: '分销管理', path: '/brand/distribution', icon: Users },
        { label: '提现审核', path: '/brand/withdrawals', icon: WalletCards },
        { label: '经营分析', path: '/brand/analytics', icon: BarChart3 },
      ],
    };
  },
  created() {
    demoStore.commit((draft) => {
      draft.activeRole = 'brand';
    });
  },
};
</script>
