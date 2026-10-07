<template>
  <section class="assessment-images" aria-label="体态演示影像">
    <header>
      <div>
        <strong>三视图记录</strong>
        <span>演示影像</span>
      </div>
      <small v-if="capturedAt || storeName">{{ storeName }}<template v-if="storeName && capturedAt"> · </template>{{ capturedAt }}</small>
    </header>
    <div class="assessment-images__grid">
      <figure v-for="view in views" :key="view.key">
        <img :src="images[view.key]" :alt="view.alt">
        <figcaption>{{ view.label }}</figcaption>
      </figure>
    </div>
  </section>
</template>

<script>
export default {
  name: 'AssessmentImageStrip',
  props: {
    images: {
      type: Object,
      required: true,
    },
    capturedAt: {
      type: String,
      default: '',
    },
    storeName: {
      type: String,
      default: '',
    },
  },
  data() {
    return {
      views: [
        { key: 'front', label: '正面', alt: '正面体态记录' },
        { key: 'side', label: '侧面', alt: '侧面体态记录' },
        { key: 'back', label: '背面', alt: '背面体态记录' },
      ],
    };
  },
};
</script>

<style scoped>
.assessment-images {
  padding: 16px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.assessment-images header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 14px;
}

.assessment-images header strong,
.assessment-images header span {
  display: block;
}

.assessment-images header strong { font-size: 15px; }

.assessment-images header span,
.assessment-images header small {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 11px;
}

.assessment-images__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.assessment-images figure { min-width: 0; margin: 0; }

.assessment-images img {
  display: block;
  width: 100%;
  aspect-ratio: 3 / 4;
  object-fit: cover;
  background: #f3f6f5;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

.assessment-images figcaption {
  margin-top: 6px;
  color: var(--text-muted);
  font-size: 12px;
  text-align: center;
}

@media (max-width: 520px) {
  .assessment-images { padding: 12px; }
  .assessment-images header { display: block; }
  .assessment-images__grid { gap: 6px; }
}
</style>
