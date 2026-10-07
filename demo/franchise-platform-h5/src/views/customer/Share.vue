<template>
  <div class="share-page">
    <section class="share-summary">
      <div><span>累计分享</span><strong>{{ overview.records.length }}</strong></div>
      <div><span>带来访问</span><strong>{{ overview.visits }}</strong></div>
      <div><span>转化订单</span><strong>{{ overview.orders }}</strong></div>
    </section>

    <section class="share-card">
      <header><Share2 :size="18" aria-hidden="true" /><h2>分享内容（演示）</h2></header>
      <div class="share-templates">
        <button
          v-for="template in templates"
          :key="template.id"
          type="button"
          :class="{ 'is-active': selectedId === template.id }"
          @click="selectedId = template.id"
        >
          <component :is="template.icon" :size="19" aria-hidden="true" />
          <strong>{{ template.title }}</strong>
          <span>{{ template.desc }}</span>
        </button>
      </div>

      <div v-if="selected" class="share-preview">
        <p>{{ selected.copy }}</p>
        <QrCode
          v-if="generatedShare"
          :value="generatedUrl"
          :label="`扫码打开「${selected.title}」（演示）`"
          :download-name="`share-${generatedShare.token}.png`"
        />
        <button type="button" @click="simulateShare">
          <Send :size="16" aria-hidden="true" />模拟分享（微信好友）
        </button>
        <div v-if="generatedShare" class="share-preview__actions">
          <button type="button" @click="copyLink">
            <Copy :size="15" aria-hidden="true" />复制链接
          </button>
          <router-link :to="generatedPath" data-testid="friend-entry">
            <ExternalLink :size="15" aria-hidden="true" />模拟好友打开
          </router-link>
        </div>
      </div>
    </section>

    <section class="share-card">
      <header><History :size="18" aria-hidden="true" /><h2>分享记录</h2></header>
      <div class="share-list">
        <div v-for="record in state.shareRecords" :key="record.id" class="share-item">
          <div>
            <strong>{{ record.content }}</strong>
            <small>{{ record.channel }} · {{ formatTime(record.at) }}</small>
          </div>
          <span>访问 {{ record.visits }}</span>
          <span>绑定 {{ record.bindings }}</span>
          <em>{{ record.orders }} 单</em>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import {
  Copy,
  ExternalLink,
  Gift,
  History,
  Home,
  Send,
  Share2,
  ShoppingBag,
} from 'lucide-vue';
import QrCode from '../../components/qr/QrCode.vue';
import { buildShareEntryPath, buildShareEntryUrl } from '../../mock/shareLinks';
import { shareOverview } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { recordShare } from '../../mock/transitions';

const templates = [
  {
    id: 'store',
    title: '门店首页',
    desc: '推荐朋友逛门店、看商品',
    icon: Home,
    copy: '推荐一家靠谱的门店，商品都是集团统一配送。',
    contentType: 'store',
    contentId: 'TL1001',
  },
  {
    id: 'product',
    title: '精选商品',
    desc: '分享当前精选商品卡片',
    icon: ShoppingBag,
    copy: '看中这款商品，分享给你，支持全国统一配送。',
    contentType: 'product',
    contentId: 3,
  },
  {
    id: 'booking',
    title: '预约到店',
    desc: '邀朋友一起预约健康服务',
    icon: Gift,
    copy: '约个时间到店做体态健康评估吧。',
    contentType: 'booking',
    contentId: 'TL1001',
  },
];

export default {
  name: 'CustomerShare',
  components: {
    Copy,
    ExternalLink,
    Gift,
    History,
    Home,
    QrCode,
    Send,
    Share2,
    ShoppingBag,
  },
  data() {
    return {
      selectedId: 'store',
      generatedShareId: null,
      templates,
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return shareOverview(this.state);
    },
    selected() {
      return this.templates.find((item) => item.id === this.selectedId);
    },
    generatedShare() {
      return this.state.shareRecords.find((item) => item.id === this.generatedShareId) || null;
    },
    generatedUrl() {
      return this.generatedShare
        ? buildShareEntryUrl(window.location, this.generatedShare.storeId, this.generatedShare.token)
        : '';
    },
    generatedPath() {
      return this.generatedShare
        ? buildShareEntryPath(this.generatedShare.storeId, this.generatedShare.token)
        : '';
    },
  },
  methods: {
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    simulateShare() {
      demoStore.commit((draft) => {
        recordShare(draft, {
          channel: '微信好友',
          content: this.selected.title,
          contentType: this.selected.contentType,
          contentId: this.selected.contentId,
          storeId: this.$route.params.storeId,
        });
      });
      this.generatedShareId = this.state.shareRecords[0].id;
      this.$message.success('分享链接已生成');
    },
    async copyLink() {
      try {
        await navigator.clipboard.writeText(this.generatedUrl);
        this.$message.success('分享链接已复制');
      } catch (_error) {
        this.$message.success(`分享链接：${this.generatedUrl}`);
      }
    },
  },
};
</script>

<style scoped>
.share-page {
  display: grid;
  gap: 10px;
}

.share-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  padding: 14px 6px;
  color: #fff;
  background: #2d3038;
  border-radius: var(--radius-lg);
}

.share-summary div {
  text-align: center;
}

.share-summary span,
.share-summary strong {
  display: block;
}

.share-summary span {
  color: #c8ccd3;
  font-size: 10px;
}

.share-summary strong {
  margin-top: 4px;
  font-size: 22px;
}

.share-card {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.share-card > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 10px;
  color: var(--brand-primary);
}

.share-card h2 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.share-templates {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.share-templates button {
  display: flex;
  min-width: 0;
  min-height: 86px;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 5px;
  padding: 8px 4px;
  color: var(--text-regular);
  background: var(--surface-subtle);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  cursor: pointer;
}

.share-templates button.is-active {
  color: var(--brand-primary);
  background: #fff5f4;
  border-color: var(--brand-primary);
}

.share-templates strong {
  font-size: 12px;
}

.share-templates span {
  color: var(--text-muted);
  font-size: 9px;
}

.share-preview {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--border);
}

.share-preview > p {
  margin: 0 0 12px;
  padding: 10px;
  color: var(--text-regular);
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
  font-size: 12px;
  text-align: center;
}

.share-preview > button,
.share-preview__actions button,
.share-preview__actions a {
  display: flex;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  font-size: 12px;
  cursor: pointer;
}

.share-preview__actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 8px;
}

.share-preview__actions button,
.share-preview__actions a {
  min-height: 38px;
  color: var(--brand-primary);
  background: #fff;
  border: 1px solid var(--brand-primary);
}

.share-list {
  display: grid;
}

.share-item {
  display: grid;
  min-height: 56px;
  grid-template-columns: minmax(0, 1fr) auto auto auto;
  align-items: center;
  gap: 10px;
  border-top: 1px solid var(--border);
}

.share-item:first-child {
  border-top: 0;
}

.share-item strong,
.share-item small {
  display: block;
}

.share-item strong {
  font-size: 12px;
}

.share-item small {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.share-item > span {
  color: var(--text-muted);
  font-size: 11px;
}

.share-item em {
  color: var(--success);
  font-size: 12px;
  font-style: normal;
}
</style>
