<template>
  <main class="verify-payload">
    <DemoNavigator />
    <header class="verify-payload__header">
      <span class="verify-payload__mark">天</span>
      <div><strong>天俪商城</strong><small>预约核销码</small></div>
    </header>

    <section v-if="payload" class="verify-payload__panel">
      <div class="verify-payload__icon"><ScanLine :size="32" aria-hidden="true" /></div>
      <h1>预约核销码已识别</h1>
      <p>{{ payload.id }}</p>
      <dl>
        <div><dt>业务类型</dt><dd>服务预约</dd></div>
        <div><dt>所属门店</dt><dd>{{ storeName }}</dd></div>
        <div><dt>门店编号</dt><dd>{{ payload.storeId }}</dd></div>
        <div><dt>签发时间</dt><dd>{{ issuedTime }}</dd></div>
      </dl>
      <p class="verify-payload__notice">当前为演示二维码，只用于本地业务流程确认。</p>
      <router-link class="verify-payload__primary" :to="handoffRoute">
        进入门店核销台<ArrowRight :size="17" aria-hidden="true" />
      </router-link>
      <router-link v-if="bookingPath" class="verify-payload__secondary" :to="bookingPath">查看本机预约</router-link>
    </section>

    <section v-else class="verify-payload__panel is-error">
      <div class="verify-payload__icon"><XCircle :size="32" aria-hidden="true" /></div>
      <h1>二维码不可用</h1>
      <p>{{ errorMessage }}</p>
      <router-link class="verify-payload__primary" to="/c/store/TL1001">返回门店首页</router-link>
    </section>
  </main>
</template>

<script>
import { ArrowRight, ScanLine, XCircle } from 'lucide-vue';
import DemoNavigator from '../../components/demo/DemoNavigator.vue';
import { demoStore } from '../../mock/store';
import { parseBusinessUrl } from '../../qr/codec';

export default {
  name: 'VerifyPayload',
  components: { ArrowRight, DemoNavigator, ScanLine, XCircle },
  data() {
    return {
      errorMessage: '',
      payload: null,
      rawValue: '',
    };
  },
  computed: {
    bookingPath() {
      const booking = demoStore.state.data.bookings.find((item) => item.id === this.payload.id);
      return booking ? `/c/store/${booking.storeId}/bookings/${booking.id}` : '';
    },
    handoffRoute() {
      return { path: '/store/verify', query: { payload: this.rawValue } };
    },
    issuedTime() {
      return new Date(this.payload.issuedAt * 1000).toLocaleString('zh-CN', {
        hour12: false,
        timeZone: 'Asia/Shanghai',
      });
    },
    storeName() {
      return demoStore.state.data.stores.find((item) => item.id === this.payload.storeId)?.name
        || '未知门店';
    },
  },
  created() {
    this.rawValue = window.location.href;
    try {
      this.payload = parseBusinessUrl(this.rawValue);
      if (this.payload.type !== 'booking') {
        throw new Error('当前只支持预约核销');
      }
    } catch (error) {
      this.payload = null;
      this.errorMessage = error.message;
    }
  },
};
</script>

<style scoped>
.verify-payload {
  width: 100%;
  min-height: 100vh;
  padding: 0 16px 36px;
  background: var(--page);
}

.verify-payload__header {
  display: flex;
  width: min(100%, 520px);
  height: 68px;
  margin: 0 auto;
  align-items: center;
  gap: 10px;
}

.verify-payload__mark {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  color: #fff;
  background: var(--brand-primary);
  border-radius: var(--radius-md);
  font-weight: 700;
}

.verify-payload__header strong,
.verify-payload__header small {
  display: block;
}

.verify-payload__header strong {
  font-size: 15px;
}

.verify-payload__header small {
  color: var(--text-muted);
  font-size: 10px;
}

.verify-payload__panel {
  width: min(100%, 520px);
  margin: 16px auto 0;
  padding: 30px 24px 24px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  text-align: center;
}

.verify-payload__icon {
  display: grid;
  width: 58px;
  height: 58px;
  margin: 0 auto;
  place-items: center;
  color: var(--admin-primary);
  background: #eef6ff;
  border-radius: 50%;
}

.verify-payload__panel.is-error .verify-payload__icon {
  color: var(--danger);
  background: #fff0ef;
}

.verify-payload h1 {
  margin: 14px 0 3px;
  font-size: 20px;
}

.verify-payload__panel > p {
  margin: 0;
  color: var(--text-muted);
  font-size: 12px;
}

.verify-payload dl {
  display: grid;
  gap: 11px;
  margin: 24px 0 0;
  padding: 18px 0;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}

.verify-payload dl div {
  display: flex;
  justify-content: space-between;
  gap: 16px;
}

.verify-payload dt,
.verify-payload dd {
  margin: 0;
  font-size: 12px;
}

.verify-payload dt {
  color: var(--text-muted);
}

.verify-payload dd {
  text-align: right;
}

.verify-payload .verify-payload__notice {
  margin-top: 14px;
  font-size: 11px;
}

.verify-payload__primary,
.verify-payload__secondary {
  display: flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  gap: 7px;
  margin-top: 18px;
  border-radius: var(--radius-md);
}

.verify-payload__primary {
  color: #fff;
  background: var(--brand-primary);
}

.verify-payload__secondary {
  margin-top: 10px;
  color: var(--brand-primary);
  border: 1px solid var(--brand-primary);
}
</style>
