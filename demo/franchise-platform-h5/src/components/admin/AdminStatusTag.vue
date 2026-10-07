<template>
  <span :class="['admin-status-tag', `is-${tone}`]">{{ label }}</span>
</template>

<script>
const statusMap = {
  active: ['营业中', 'success'],
  disabled: ['已停用', 'info'],
  selling: ['销售中', 'success'],
  paid: ['已支付', 'primary'],
  shipping: ['配送中', 'warning'],
  completed: ['已完成', 'success'],
  refunding: ['退款中', 'danger'],
  refunded: ['已退款', 'info'],
  pending: ['待处理', 'warning'],
  verified: ['已核销', 'success'],
  cancelled: ['已取消', 'info'],
  expired: ['已过期', 'danger'],
  processing: ['处理中', 'primary'],
  returning: ['回退中', 'danger'],
  returned: ['已回退', 'info'],
  approved: ['已通过', 'success'],
  reviewing: ['审核中', 'warning'],
  rejected: ['已驳回', 'danger'],
};

export default {
  name: 'AdminStatusTag',
  props: {
    status: {
      type: String,
      required: true,
    },
    text: {
      type: String,
      default: '',
    },
  },
  computed: {
    mapping() {
      return statusMap[this.status] || [this.status, 'info'];
    },
    label() {
      if (this.text) {
        return this.text;
      }
      if (this.status === 'pending') {
        return '待处理';
      }
      return this.mapping[0];
    },
    tone() {
      return this.mapping[1];
    },
  },
};
</script>

<style scoped>
.admin-status-tag {
  display: inline-flex;
  min-height: 24px;
  align-items: center;
  padding: 0 9px;
  color: var(--text-regular);
  background: #f2f4f7;
  border: 1px solid #e4e7ed;
  border-radius: var(--radius-sm);
  font-size: 11px;
  white-space: nowrap;
}

.admin-status-tag.is-primary {
  color: #1d6fd7;
  background: #eef6ff;
  border-color: #cfe4ff;
}

.admin-status-tag.is-success {
  color: #168851;
  background: #edf9f2;
  border-color: #ccebd9;
}

.admin-status-tag.is-warning {
  color: #9d6413;
  background: #fff7e9;
  border-color: #f4ddb7;
}

.admin-status-tag.is-danger {
  color: #bb403b;
  background: #fff0ef;
  border-color: #f3d0cd;
}
</style>
