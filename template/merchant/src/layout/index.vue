<template>
  <div class="merchant-shell">
    <aside class="merchant-sidebar">
      <div class="brand">{{ marketplaceName }}<span>商家后台</span></div>
      <el-menu router :default-active="$route.path" background-color="#fff" text-color="#303133" active-text-color="#2d8cf0">
        <el-menu-item index="/"><i class="el-icon-house" />本店信息</el-menu-item>
        <el-menu-item v-if="can('order.read')" index="/orders"><i class="el-icon-document" />订单列表</el-menu-item>
        <el-menu-item v-if="can('order.read')" index="/aftersales"><i class="el-icon-refresh-left" />多商户售后</el-menu-item>
        <el-menu-item v-if="can('product.read')" index="/products"><i class="el-icon-goods" />商品列表</el-menu-item>
        <el-menu-item v-if="can('attachment.manage')" index="/attachments"><i class="el-icon-picture-outline" />素材库</el-menu-item>
        <el-menu-item v-if="can('freight.manage')" index="/freight"><i class="el-icon-truck" />运费模板</el-menu-item>
        <el-menu-item v-if="can('merchant.employee.manage')" index="/employees"><i class="el-icon-user" />员工管理</el-menu-item>
        <el-menu-item v-if="can('merchant.role.manage')" index="/roles"><i class="el-icon-s-check" />角色管理</el-menu-item>
      </el-menu>
    </aside>
    <div class="merchant-workspace">
      <header class="merchant-header"><span>{{ merchantName }}</span><div class="actions"><span>{{ employeeName }}</span><el-button v-if="token" size="small" :loading="leaving" @click="logout">退出登录</el-button><router-link v-else to="/login">员工登录</router-link></div></header>
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <main class="merchant-main"><router-view /></main>
    </div>
  </div>
</template>
<script>
import marketplace from '../../marketplace.config';
export default {
  data: () => ({ marketplaceName: marketplace.name, leaving: false, error: '',
    authJob: 0, activeAuthIntent: null, authAlive: true }),
  computed: {
    token() { return this.$store.state.token; },
    merchantName() { const p = this.$store.state.profile; return p ? p.merchant.name : '商家后台'; },
    employeeName() { const p = this.$store.state.profile; return p ? (p.employee.name || p.employee.account) : ''; },
    latestAuthIntent() { return this.$store.state.authIntent; },
  },
  watch: {
    latestAuthIntent(intent) {
      if (this.activeAuthIntent !== null && intent !== this.activeAuthIntent) {
        this.authJob += 1; this.activeAuthIntent = null; this.leaving = false; this.error = '';
      }
    }
  },
  beforeDestroy() { this.authAlive = false; this.authJob += 1; },
  methods: {
    can(permission) { return this.$store.getters.can(permission); },
    currentAuthJob(job, intent) {
      return this.authAlive && job === this.authJob && intent === this.activeAuthIntent && intent === this.latestAuthIntent;
    },
    async logout() {
      if (this.leaving) return;
      const job = ++this.authJob; this.leaving = true; this.error = '';
      let intent = null;
      try {
        const pending = this.$store.dispatch('logout');
        intent = this.$store.state.authIntent; this.activeAuthIntent = intent;
        const receipt = await pending;
        if (!this.currentAuthJob(job, intent) || !receipt || receipt.intent !== intent || receipt.sessionRevision !== this.$store.state.sessionRevision) return;
        await this.$router.replace('/login');
      } catch (error) { if (this.currentAuthJob(job, intent)) this.error = error.message; }
      finally { if (this.currentAuthJob(job, intent)) this.leaving = false; }
    },
  },
};
</script>
