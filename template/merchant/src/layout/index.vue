<template>
  <div class="merchant-shell">
    <aside class="merchant-sidebar">
      <div class="brand">{{ marketplaceName }}<span>商家后台</span></div>
      <el-menu router :default-active="$route.path" background-color="#fff" text-color="#303133" active-text-color="#2d8cf0">
        <el-menu-item index="/"><i class="el-icon-house" />本店信息</el-menu-item>
        <el-menu-item v-if="can('order.read')" index="/orders"><i class="el-icon-document" />订单列表</el-menu-item>
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
  data: () => ({ marketplaceName: marketplace.name, leaving: false, error: '' }),
  computed: {
    token() { return this.$store.state.token; },
    merchantName() { const p = this.$store.state.profile; return p ? p.merchant.name : '商家后台'; },
    employeeName() { const p = this.$store.state.profile; return p ? (p.employee.name || p.employee.account) : ''; },
  },
  methods: {
    can(permission) { return this.$store.getters.can(permission); },
    async logout() {
      this.leaving = true; this.error = '';
      try { await this.$store.dispatch('logout'); await this.$router.replace('/login'); }
      catch (error) { this.error = error.message; }
      finally { this.leaving = false; }
    },
  },
};
</script>
