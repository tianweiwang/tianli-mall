const configuredName = (process.env.VUE_APP_MARKETPLACE_NAME || '').trim();
export default { name: configuredName || '多商户商城' };
