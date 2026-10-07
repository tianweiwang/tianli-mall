<template>
  <div class="customer-profile">
    <section class="profile-identity">
      <div><UserRound :size="28" aria-hidden="true" /></div>
      <div><h1>{{ identity.name }}</h1><span>演示消费者</span></div>
    </section>
    <section class="profile-member" aria-label="会员与资产">
      <router-link :to="membershipPath" class="profile-member__status">
        <Crown :size="20" aria-hidden="true" />
        <div><span>会员身份</span><strong>{{ memberTitle }}</strong></div>
        <ChevronRight :size="17" aria-hidden="true" />
      </router-link>
      <router-link :to="assetsPath" class="profile-assets">
        <div><span>储值本金</span><strong>{{ formatMoney(memberProfile.account.principalBalanceCents) }}</strong></div>
        <div><span>赠送积分</span><strong>{{ memberProfile.account.pointsBalance }}</strong></div>
        <div><span>补贴余额</span><strong>{{ formatMoney(memberProfile.account.subsidyBalanceCents) }}</strong></div>
        <WalletCards :size="18" aria-label="查看资产中心" />
      </router-link>
    </section>
    <section v-if="recommendation" class="profile-recommendation" data-testid="recommendation-relationship">
      <header><UserCheck :size="19" aria-hidden="true" /><h2>我的推荐关系</h2></header>
      <div><span>推荐人</span><strong>{{ recommendation.recommenderName }}</strong></div>
      <div><span>绑定状态</span><strong>已绑定</strong></div>
      <div><span>首单状态</span><strong>{{ recommendation.firstOrderStatus }}</strong></div>
    </section>
    <section class="profile-shortcuts">
      <router-link :to="ordersPath"><ClipboardList :size="21" aria-hidden="true" /><span>我的订单</span></router-link>
      <router-link :to="bookingsPath"><CalendarCheck :size="21" aria-hidden="true" /><span>我的预约</span></router-link>
      <router-link :to="assessmentsPath"><Activity :size="21" aria-hidden="true" /><span>我的检测</span></router-link>
    </section>
    <section class="profile-shortcuts profile-shortcuts--growth" aria-label="增长中心">
      <router-link :to="pointsPath"><Coins :size="21" aria-hidden="true" /><span>积分中心</span></router-link>
      <template v-if="isDistributor">
        <router-link :to="referralPath"><Megaphone :size="21" aria-hidden="true" /><span>推荐有礼</span></router-link>
        <router-link :to="sharePath"><Share2 :size="21" aria-hidden="true" /><span>分享中心</span></router-link>
        <router-link :to="distributionPath"><Users :size="21" aria-hidden="true" /><span>分销中心</span></router-link>
      </template>
    </section>
    <section class="profile-address">
      <MapPin :size="19" aria-hidden="true" />
      <div><span>默认演示地址</span><strong>成都市锦江区春熙路 66 号</strong></div>
    </section>
    <section class="profile-roles">
      <h2>演示角色</h2>
      <router-link to="/store/dashboard"><Store :size="19" aria-hidden="true" /><span>进入门店端</span><ChevronRight :size="17" aria-hidden="true" /></router-link>
      <router-link to="/brand/dashboard"><Building2 :size="19" aria-hidden="true" /><span>进入品牌方后台</span><ChevronRight :size="17" aria-hidden="true" /></router-link>
    </section>
  </div>
</template>

<script>
import {
  Activity,
  Building2,
  CalendarCheck,
  ChevronRight,
  ClipboardList,
  Coins,
  Crown,
  MapPin,
  Megaphone,
  Share2,
  Store,
  UserCheck,
  UserRound,
  Users,
  WalletCards,
} from 'lucide-vue';
import {
  activeCustomerIsDistributor,
  customerIdentity,
  customerRecommendationOverview,
  formatMoney,
} from '../../mock/selectors';
import { memberProfileForCustomer } from '../../mock/memberSelectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerProfile',
  components: {
    Activity,
    Building2,
    CalendarCheck,
    ChevronRight,
    ClipboardList,
    Coins,
    Crown,
    MapPin,
    Megaphone,
    Share2,
    Store,
    UserCheck,
    UserRound,
    Users,
    WalletCards,
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    identity() {
      return customerIdentity(this.state);
    },
    recommendation() {
      return customerRecommendationOverview(this.state);
    },
    isDistributor() {
      return activeCustomerIsDistributor(this.state);
    },
    memberProfile() {
      return memberProfileForCustomer(this.state, this.state.activeCustomerId);
    },
    memberTitle() {
      const benefits = this.memberProfile.effectiveBenefits;
      if (benefits.hasMembership && benefits.hasStoredValue) return '双重权益会员';
      if (benefits.hasMembership) return '510会员';
      if (benefits.hasStoredValue) return '储值会员';
      return '普通客户';
    },
    basePath() {
      return `/c/store/${this.$route.params.storeId}`;
    },
    ordersPath() {
      return `${this.basePath}/orders`;
    },
    bookingsPath() {
      return `${this.basePath}/bookings`;
    },
    assessmentsPath() {
      return `${this.basePath}/assessments`;
    },
    pointsPath() {
      return `${this.basePath}/points`;
    },
    membershipPath() {
      return `${this.basePath}/membership`;
    },
    assetsPath() {
      return `${this.basePath}/assets`;
    },
    referralPath() {
      return `${this.basePath}/referral`;
    },
    sharePath() {
      return `${this.basePath}/share`;
    },
    distributionPath() {
      return `${this.basePath}/distribution`;
    },
  },
  methods: { formatMoney },
};
</script>

<style scoped>
.customer-profile {
  display: grid;
  gap: 10px;
}

.customer-profile > section {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.profile-identity {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 16px;
}

.profile-identity > div:first-child {
  display: grid;
  width: 52px;
  height: 52px;
  place-items: center;
  color: #fff;
  background: var(--brand-primary);
  border-radius: 50%;
}

.profile-identity h1 {
  margin: 0;
  font-size: 17px;
}

.profile-identity span {
  color: var(--text-muted);
  font-size: 11px;
}

.profile-recommendation {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  padding: 14px;
}

.profile-recommendation header {
  display: flex;
  grid-column: 1 / -1;
  align-items: center;
  gap: 7px;
  margin-bottom: 12px;
  color: var(--brand-primary);
}

.profile-recommendation h2 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.profile-recommendation > div {
  text-align: center;
  border-right: 1px solid var(--border);
}

.profile-recommendation > div:last-child {
  border-right: 0;
}

.profile-recommendation span,
.profile-recommendation strong {
  display: block;
}

.profile-recommendation span {
  color: var(--text-muted);
  font-size: 10px;
}

.profile-recommendation strong {
  margin-top: 4px;
  font-size: 12px;
}

.profile-shortcuts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  padding: 12px;
}

.profile-member {
  padding: 0 14px 12px;
}

.profile-member__status {
  display: grid;
  min-height: 50px;
  grid-template-columns: 24px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 8px;
}

.profile-member__status svg,
.profile-assets > svg {
  color: var(--brand-primary);
}

.profile-member span,
.profile-member strong {
  display: block;
}

.profile-member span {
  color: var(--text-muted);
  font-size: 10px;
}

.profile-member strong {
  margin-top: 2px;
  font-size: 12px;
}

.profile-assets {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr)) 20px;
  align-items: center;
  gap: 6px;
  padding-top: 11px;
  border-top: 1px solid var(--border);
}

.profile-assets > div {
  min-width: 0;
}

.profile-assets strong {
  overflow-wrap: anywhere;
}

.profile-shortcuts a {
  display: flex;
  min-height: 58px;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 5px;
  color: var(--text-regular);
  font-size: 12px;
}

.profile-shortcuts--growth {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.profile-shortcuts svg,
.profile-address > svg,
.profile-roles svg {
  color: var(--brand-primary);
}

.profile-address {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  padding: 14px;
}

.profile-address span,
.profile-address strong {
  display: block;
}

.profile-address span {
  color: var(--text-muted);
  font-size: 10px;
}

.profile-address strong {
  font-size: 12px;
}

.profile-roles {
  padding: 0 14px;
}

.profile-roles h2 {
  margin: 0;
  padding: 13px 0 8px;
  font-size: 14px;
}

.profile-roles a {
  display: grid;
  min-height: 48px;
  grid-template-columns: 24px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 7px;
  border-top: 1px solid var(--border);
  font-size: 12px;
}
</style>
