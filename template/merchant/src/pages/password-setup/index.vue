<template>
  <div class="login-page"><section class="login-intro"><h1>{{ marketplaceName }}</h1><p>首次使用，设置员工密码</p></section><section class="login-card"><h2>设置密码</h2>
    <el-alert v-if="notice" :title="notice" role="status" aria-live="polite" aria-atomic="true" type="success" :closable="false" show-icon /><el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-form v-if="!notice" @submit.native.prevent="submit"><el-form-item label="一次性设置凭证" :for="fieldId('setup-token')"><el-input v-model="token" type="password" autocomplete="off" show-password :id="fieldId('setup-token')" :label="'一次性设置凭证'" /></el-form-item><el-form-item label="新密码" :for="fieldId('setup-password')"><el-input v-model="password" type="password" autocomplete="new-password" show-password :id="fieldId('setup-password')" :label="'新密码'" /></el-form-item><el-form-item label="确认密码" :for="fieldId('setup-confirmation')"><el-input v-model="confirmation" type="password" autocomplete="new-password" show-password :id="fieldId('setup-confirmation')" :label="'确认密码'" /></el-form-item><el-button native-type="submit" type="primary" :loading="busy" :disabled="busy">设置密码</el-button></el-form>
    <p class="field-note">使用管理员提供的一次性凭证；凭证在签发后 24 小时内有效。</p><router-link to="/login">返回员工登录</router-link>
  </section></div>
</template>
<script>
import marketplace from '../../../marketplace.config';
import { fieldNames } from '../../accessibility';
import { api } from '../../api';
export default {
  mixins: [fieldNames],
  data: () => ({ marketplaceName: marketplace.name, token: '', password: '', confirmation: '', error: '', notice: '', busy: false }),
  created() { const token = this.$route.query.token; if (typeof token === 'string') { this.token = token; this.$router.replace({ name: 'password-setup' }); } },
  methods: { async submit() {
    if (this.busy) return; this.error = '';
    const bytes = new TextEncoder().encode(this.password).length;
    if (!/^[A-Za-z0-9_-]{43}$/.test(this.token) || bytes < 8 || bytes > 72 || this.password !== this.confirmation) { this.error = '请填写有效凭证和 8–72 字节的密码，两次密码需一致'; return; }
    this.busy = true;
    try { await api.setupPassword({ token: this.token, password: this.password }); this.token = ''; this.password = ''; this.confirmation = ''; this.notice = '密码已设置，请使用员工账号登录'; }
    catch (error) { this.error = error.message; }
    finally { this.busy = false; }
  } },
};
</script>
