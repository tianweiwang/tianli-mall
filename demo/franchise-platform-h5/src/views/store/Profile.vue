<template>
  <div class="admin-page store-profile">
    <section class="store-profile__summary">
      <div class="store-profile__mark"><Store :size="28" aria-hidden="true" /></div>
      <div><h1>{{ store.name }}</h1><p>{{ store.id }} · {{ store.address }}</p></div>
      <AdminStatusTag :status="store.status" />
    </section>

    <div class="store-profile__grid">
      <section class="admin-panel">
        <header class="admin-panel__header"><div><h2>门店信息</h2><p>加盟门店基础资料</p></div></header>
        <div class="admin-panel__body">
          <dl class="store-profile__list">
            <div><dt>门店名称</dt><dd>{{ store.name }}</dd></div>
            <div><dt>门店编码</dt><dd>{{ store.id }}</dd></div>
            <div><dt>经营地址</dt><dd>{{ store.address }}</dd></div>
            <div><dt>营业时间</dt><dd>{{ store.openHours }}</dd></div>
            <div><dt>运营联系人</dt><dd>{{ store.contactName || '门店负责人' }}</dd></div>
            <div><dt>联系电话</dt><dd>{{ store.contactPhone || '138****0000' }}</dd></div>
          </dl>
        </div>
      </section>

      <section class="admin-panel">
        <header class="admin-panel__header"><div><h2>支付与结算</h2><p>加盟进件状态</p></div></header>
        <div class="admin-panel__body">
          <dl class="store-profile__list">
            <div><dt>进件状态</dt><dd><AdminStatusTag :status="store.onboardingStatus" /></dd></div>
            <div><dt>子商户号</dt><dd>{{ store.subMchId }}</dd></div>
            <div><dt>品牌抽成比例</dt><dd>{{ store.commissionRate }}%</dd></div>
            <div><dt>门店收入比例</dt><dd class="admin-value-positive">{{ 100 - store.commissionRate }}%</dd></div>
            <div><dt>配送模式</dt><dd>品牌统一配送</dd></div>
            <div><dt>商品权限</dt><dd>统一商品只读</dd></div>
          </dl>
        </div>
      </section>
    </div>
  </div>
</template>

<script>
import { Store } from 'lucide-vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { activeStore } from '../../mock/selectors';

export default {
  name: 'StoreProfile',
  components: { AdminStatusTag, Store },
  computed: {
    store() {
      return activeStore(demoStore.state.data);
    },
  },
};
</script>

<style scoped>
.store-profile__summary {
  display: grid;
  min-height: 112px;
  grid-template-columns: 54px minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  padding: 20px 24px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.store-profile__mark {
  display: grid;
  width: 54px;
  height: 54px;
  place-items: center;
  color: #fff;
  background: var(--admin-primary);
  border-radius: var(--radius-md);
}

.store-profile__summary h1,
.store-profile__summary p {
  margin: 0;
}

.store-profile__summary h1 {
  font-size: 20px;
}

.store-profile__summary p {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 12px;
}

.store-profile__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.store-profile__list {
  display: grid;
  margin: 0;
}

.store-profile__list div {
  display: grid;
  min-height: 50px;
  grid-template-columns: 120px minmax(0, 1fr);
  align-items: center;
  border-bottom: 1px solid var(--border);
}

.store-profile__list div:last-child {
  border-bottom: 0;
}

.store-profile__list dt,
.store-profile__list dd {
  margin: 0;
  font-size: 12px;
}

.store-profile__list dt {
  color: var(--text-muted);
}

.store-profile__list dd {
  text-align: right;
}
</style>
