<template>
  <div class="login-page"><section class="login-intro"><h1>{{ marketplaceName }}</h1><p>商家工作台</p><p>商品、素材与运费管理</p></section><section class="login-card"><h2>员工登录</h2>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-form @submit.native.prevent="submit"><el-form-item label="员工账号" :for="fieldId('account')"><el-input v-model="account" autocomplete="username" maxlength="191" :id="fieldId('account')" :label="'员工账号'" /></el-form-item><el-form-item label="密码" :for="fieldId('password')"><el-input v-model="password" type="password" autocomplete="current-password" show-password :id="fieldId('password')" :label="'密码'" /></el-form-item><el-button type="primary" native-type="submit" :loading="busy" :disabled="busy">登录商家后台</el-button></el-form>
    <router-link to="/password-setup">使用一次性凭证设置密码</router-link>
  </section></div>
</template>
<script>
import marketplace from '../../../marketplace.config';
import { fieldNames } from '../../accessibility';
export default {
  mixins: [fieldNames],
  data: () => ({ marketplaceName: marketplace.name, account: '', password: '', error: '', busy: false }),
  methods: { async submit() {
    if (this.busy) return;
    this.error = ''; if (!this.account.trim() || !this.password) { this.error = '请填写员工账号和密码'; return; }
    this.busy = true;
    try {
      await this.$store.dispatch('login', { account: this.account.trim(), password: this.password }); this.password = '';
      const redirect = this.$route.query.redirect;
      await this.$router.replace(typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') && !redirect.startsWith('/login') ? redirect : '/');
    } catch (error) { this.error = error.message; }
    finally { this.busy = false; }
  } },
};
</script>
