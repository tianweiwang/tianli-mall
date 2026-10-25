<template>
  <div>
    <p v-if="transferNotice" role="status">{{ transferNotice }}</p>
    <div class="list-wrapper">
      <div class="user-item" v-for="(item, index) in labelLists" :key="index" role="button" tabindex="0"
        :aria-disabled="loading || transferLocked" v-db-click @click="bindActive(item)" @keydown.enter.prevent="bindActive(item)" @keydown.space.prevent="bindActive(item)">
        <img v-lazy="item.avatar" alt="" />
        <p class="line1">{{ item.wx_name }}</p>
      </div>
    </div>
  </div>
</template>

<script>
import { transferList, serviceTransferGuarded } from '@/api/kefu';
import { platformChatTransferPending } from '@/api/platform-chat-materials';
export default {
  name: 'transfer',
  props: {
    materialContext: { type: Function, required: true },
    active: { type: Boolean, default: false },
    userUid: {
      type: String | Number,
      default: '',
    },
  },
  data() {
    return {
      loading: false,
      transferNotice: '',
      currentChoose: '',
      labelLists: [],
      name: '',
    };
  },
  computed: {
    transferLocked() { return this.loading || platformChatTransferPending(() => this.currentTransferContext()); },
  },
  mounted() {
    if (this.transferLocked) this.transferNotice = '此前转接结果尚未确认，请先查看原聊天记录';
    this.getList();
  },
  methods: {
    currentTransferContext() {
      return { ...this.materialContext(), active: this.active && !this._isDestroyed && !this._isBeingDestroyed };
    },
    getList() {
      transferList({
        nickname: this.name,
        uid: this.userUid,
      }).then((res) => {
        this.labelLists = res.data.list;
      });
    },
    async bindActive(item) {
      if (this.transferLocked) { this.transferNotice = '转接处理中或此前结果尚未确认，请查看原聊天记录'; return; }
      this.loading = true; this.transferNotice = '';
      try {
      const res = await serviceTransferGuarded({
        uid: this.userUid,
        kefuToUid: item.uid,
      }, () => this.currentTransferContext());
          if (!this.active || this._isDestroyed || this._isBeingDestroyed) return;
          this.$message.success(res.msg);
          this.$emit('close');
      } catch (error) { this.transferNotice = error.message || '转接结果尚未确认，请查看原聊天记录'; }
      finally { this.loading = false; }
    },
  },
};
</script>

<style lang="scss" scoped>
.list-wrapper {
  .user-item {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
    cursor: pointer;
    &:last-child {
      margin-bottom: 0;
    }
    img {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      margin-right: 8px;
    }
    p {
      width: 80%;
      color: #333;
      font-size: 13px;
    }
  }
}
</style>
