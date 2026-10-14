const configuredName = (process.env.VUE_APP_MARKETPLACE_NAME || '').trim();
module.exports = { name: configuredName || '多商户商城' };
