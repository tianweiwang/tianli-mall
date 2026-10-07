<template>
  <div class="admin-page store-verify">
    <section class="store-verify__context">
      <div><ScanLine :size="22" aria-hidden="true" /><span>当前核销门店</span><strong>{{ store.name }}</strong></div>
      <p>{{ store.id }} · {{ store.address }}</p>
    </section>

    <div class="store-verify__grid">
      <section class="admin-panel">
        <header class="admin-panel__header">
          <div><h2>扫描预约核销码</h2><p>支持摄像头、二维码图片和备用编号</p></div>
          <el-button v-if="candidate || result || scannerError" type="text" @click="resetScan">重新识别</el-button>
        </header>
        <div class="admin-panel__body">
          <QrScanner
            ref="scanner"
            :busy="busy"
            element-id="store-booking-qr-reader"
            @decoded="handleDecoded"
            @backup="handleBackup"
            @error="handleScannerError"
          />
          <p v-if="scannerError" class="store-verify__scanner-error"><AlertTriangle :size="16" aria-hidden="true" />{{ scannerError }}</p>
        </div>
      </section>

      <section class="admin-panel store-verify__result-panel">
        <header class="admin-panel__header"><div><h2>核销结果</h2><p>确认前核对门店与预约状态</p></div></header>

        <div v-if="candidate" class="store-verify__candidate">
          <div class="store-verify__candidate-heading">
            <div><strong>{{ candidate.booking.id }}</strong><span>{{ serviceName(candidate.booking.serviceId) }}</span></div>
            <AdminStatusTag status="pending" text="待到店" />
          </div>
          <dl class="admin-detail-list">
            <div><dt>所属门店</dt><dd>{{ storeName(candidate.booking.storeId) }}</dd></div>
            <div><dt>预约人</dt><dd>{{ candidate.booking.contactName }}</dd></div>
            <div><dt>手机</dt><dd>{{ candidate.booking.phone }}</dd></div>
            <div><dt>预约时间</dt><dd>{{ bookingTime(candidate.booking) }}</dd></div>
          </dl>
          <p v-if="candidate.imported" class="store-verify__import-notice">
            此二维码来自其他设备。确认后只在当前扫描设备导入并核销，消费者设备不会实时同步。
          </p>
          <el-button class="store-verify__confirm" type="primary" :loading="busy" @click="confirmVerification">
            <CheckCircle2 :size="17" aria-hidden="true" />确认核销
          </el-button>
        </div>

        <div v-else-if="result" :class="['store-verify__result', `is-${result.ok ? 'success' : 'error'}`]">
          <CheckCircle2 v-if="result.ok" :size="38" aria-hidden="true" />
          <XCircle v-else :size="38" aria-hidden="true" />
          <h3>{{ resultTitle }}</h3>
          <p>{{ result.message }}</p>
          <dl v-if="result.booking" class="admin-detail-list">
            <div><dt>预约编号</dt><dd>{{ result.booking.id }}</dd></div>
            <div><dt>所属门店</dt><dd>{{ storeName(result.booking.storeId) }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="result.booking.status" :text="statusText(result.booking.status)" /></dd></div>
            <div v-if="result.booking.verifiedAt"><dt>核销时间</dt><dd>{{ formatTime(result.booking.verifiedAt) }}</dd></div>
          </dl>
          <p v-if="result.imported && result.ok" class="store-verify__import-notice">
            已完成当前扫描设备的本地导入与核销，消费者设备状态不会实时变化。
          </p>
          <MemberSettlementPanel
            v-if="result.ok && result.booking.customerId"
            :booking-id="result.booking.id"
            :store-id="store.id"
          />
        </div>

        <div v-else class="store-verify__waiting">
          <ScanLine :size="42" aria-hidden="true" />
          <h3>等待识别预约码</h3>
          <p>识别后将在此显示预约信息与核销结果。</p>
        </div>
      </section>
    </div>
  </div>
</template>

<script>
import {
  AlertTriangle,
  CheckCircle2,
  ScanLine,
  XCircle,
} from 'lucide-vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import MemberSettlementPanel from '../../components/store/MemberSettlementPanel.vue';
import QrScanner from '../../components/qr/QrScanner.vue';
import { demoStore } from '../../mock/store';
import { importBookingFromPayload, verifyBooking } from '../../mock/transitions';
import { parseBusinessUrl } from '../../qr/codec';

const statusLabels = {
  pending: '待到店',
  verified: '已核销',
  cancelled: '已取消',
  expired: '已过期',
};

const resultTitles = {
  verified: '核销成功',
  'cross-store': '非本店预约',
  duplicate: '请勿重复核销',
  expired: '预约已过期',
  cancelled: '预约已取消',
  'not-found': '未找到预约',
  damaged: '二维码不可用',
  unsupported: '暂不支持此业务',
};

export default {
  name: 'StoreVerify',
  components: {
    AdminStatusTag,
    AlertTriangle,
    CheckCircle2,
    MemberSettlementPanel,
    QrScanner,
    ScanLine,
    XCircle,
  },
  data() {
    return {
      busy: false,
      candidate: null,
      result: null,
      scannerError: '',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.state.activeStoreId);
    },
    resultTitle() {
      return resultTitles[this.result.code] || '无法核销';
    },
  },
  mounted() {
    if (this.$route.query.payload) {
      this.handleDecoded(this.$route.query.payload);
    }
  },
  methods: {
    bookingTime(booking) {
      if (booking.imported) {
        return '跨设备演示记录';
      }
      return `${booking.date} ${booking.start}-${booking.end}`;
    },
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    handleBackup(code) {
      const booking = this.state.bookings.find((item) => item.verifyCode === code.trim());
      if (!booking) {
        this.showResult({ ok: false, code: 'not-found', message: '备用编号未匹配到本机预约记录' });
        return;
      }
      this.inspectBooking(booking, null, false);
    },
    async handleDecoded(value) {
      this.busy = true;
      this.scannerError = '';
      try {
        const payload = parseBusinessUrl(value);
        if (payload.type !== 'booking') {
          this.showResult({ ok: false, code: 'unsupported', message: '当前门店端只支持预约核销' });
          return;
        }
        const booking = this.state.bookings.find((item) => item.id === payload.id);
        if (booking && booking.storeId !== payload.storeId) {
          this.showResult({ ok: false, code: 'damaged', message: '二维码门店与预约记录不一致' });
          return;
        }
        if (booking) {
          this.inspectBooking(booking, payload, false);
          return;
        }

        const payloadStore = this.state.stores.find((item) => item.id === payload.storeId);
        if (!payloadStore) {
          this.showResult({ ok: false, code: 'not-found', message: '二维码对应门店不在当前演示数据中' });
          return;
        }
        if (payload.storeId !== this.store.id) {
          this.showResult({
            ok: false,
            code: 'cross-store',
            message: `该预约属于 ${payloadStore.name}，当前门店不能核销`,
            booking: { id: payload.id, storeId: payload.storeId, status: 'pending' },
          });
          return;
        }

        this.candidate = {
          imported: true,
          payload,
          booking: {
            id: payload.id,
            storeId: payload.storeId,
            serviceId: this.state.services[0]?.id,
            contactName: '跨设备演示顾客',
            phone: '未同步',
            status: 'pending',
            imported: true,
          },
        };
        this.result = null;
      } catch (error) {
        this.showResult({ ok: false, code: 'damaged', message: error.message });
      } finally {
        this.busy = false;
        await this.$refs.scanner?.stopScanner();
      }
    },
    handleScannerError(message) {
      this.scannerError = message;
    },
    inspectBooking(booking, payload, imported) {
      if (booking.storeId !== this.store.id) {
        this.showResult({
          ok: false,
          code: 'cross-store',
          message: `该预约属于 ${this.storeName(booking.storeId)}，当前门店不能核销`,
          booking,
        });
        return;
      }
      if (booking.status === 'verified') {
        this.showResult({ ok: false, code: 'duplicate', message: '该预约已完成核销', booking });
        return;
      }
      if (booking.status === 'expired') {
        this.showResult({ ok: false, code: 'expired', message: '该预约已过期，不能核销', booking });
        return;
      }
      if (booking.status !== 'pending') {
        this.showResult({ ok: false, code: booking.status, message: '当前预约状态不能核销', booking });
        return;
      }
      this.candidate = { booking, payload, imported };
      this.result = null;
    },
    confirmVerification() {
      this.busy = true;
      let transitionResult;
      demoStore.commit((draft) => {
        let booking = draft.bookings.find((item) => item.id === this.candidate.booking.id);
        if (!booking && this.candidate.payload) {
          booking = importBookingFromPayload(draft, this.candidate.payload);
        }
        transitionResult = verifyBooking(draft, {
          bookingId: booking.id,
          operatingStoreId: this.store.id,
        });
      });
      this.result = {
        ...transitionResult,
        imported: this.candidate.imported,
        message: transitionResult.ok ? '预约状态已更新为已核销' : transitionResult.message,
      };
      this.candidate = null;
      this.busy = false;
      if (this.result.ok) {
        this.$message.success('预约核销成功');
      }
    },
    resetScan() {
      this.candidate = null;
      this.result = null;
      this.scannerError = '';
    },
    serviceName(serviceId) {
      return this.state.services.find((item) => item.id === serviceId)?.name || '预约服务';
    },
    showResult(result) {
      this.candidate = null;
      this.result = result;
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    storeName(storeId) {
      return this.state.stores.find((item) => item.id === storeId)?.name || storeId;
    },
  },
};
</script>

<style scoped>
.store-verify__context {
  display: flex;
  min-height: 58px;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 0 18px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.store-verify__context div {
  display: flex;
  align-items: center;
  gap: 9px;
}

.store-verify__context svg {
  color: var(--admin-primary);
}

.store-verify__context span,
.store-verify__context p {
  color: var(--text-muted);
  font-size: 12px;
}

.store-verify__context p {
  margin: 0;
}

.store-verify__context strong {
  font-size: 14px;
}

.store-verify__grid {
  display: grid;
  grid-template-columns: minmax(480px, 1.05fr) minmax(390px, 0.95fr);
  align-items: start;
  gap: 16px;
}

.store-verify__result-panel {
  min-height: 560px;
}

.store-verify__candidate,
.store-verify__result {
  padding: 22px;
}

.store-verify__candidate-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}

.store-verify__candidate-heading strong,
.store-verify__candidate-heading span {
  display: block;
}

.store-verify__candidate-heading strong {
  font-size: 16px;
}

.store-verify__candidate-heading span {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 12px;
}

.store-verify__import-notice,
.store-verify__scanner-error {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  margin: 18px 0 0;
  padding: 11px 12px;
  color: #9d6413;
  background: #fff7e9;
  border: 1px solid #f4ddb7;
  border-radius: var(--radius-md);
  font-size: 11px;
}

.store-verify__confirm {
  display: flex;
  width: 100%;
  margin-top: 20px;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.store-verify__result {
  text-align: center;
}

.store-verify__result > svg {
  color: var(--danger);
}

.store-verify__result.is-success > svg {
  color: var(--success);
}

.store-verify__result h3 {
  margin: 10px 0 3px;
  font-size: 18px;
}

.store-verify__result > p {
  margin: 0 0 20px;
  color: var(--text-muted);
  font-size: 12px;
}

.store-verify__result .admin-detail-list {
  padding-top: 18px;
  border-top: 1px solid var(--border);
  text-align: left;
}

.store-verify__waiting {
  display: grid;
  min-height: 500px;
  place-content: center;
  justify-items: center;
  color: var(--text-muted);
  text-align: center;
}

.store-verify__waiting h3 {
  margin: 13px 0 3px;
  color: var(--text-regular);
  font-size: 16px;
}

.store-verify__waiting p {
  margin: 0;
  font-size: 11px;
}
</style>
