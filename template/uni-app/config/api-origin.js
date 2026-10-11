// 小程序 / App 共用构建和运行配置校验；H5 继续使用当前站点域名。
module.exports = function validateApiOrigin(value, mode) {
  const origin = typeof value === 'string' ? value.trim().replace(/\/$/, '') : '';
  const hint = '请设置 VUE_APP_API_URL；本机调试使用 .env.development，正式构建必须显式配置 HTTPS 域名。';
  if (!origin) throw new Error(`缺少小程序 / App 接口地址。${hint}`);

  const match = /^(https?):\/\/(\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/i.exec(origin);
  if (!match || (match[3] && (Number(match[3]) < 1 || Number(match[3]) > 65535))) {
    throw new Error(`VUE_APP_API_URL 必须是完整的 HTTP(S) origin，不能包含账号、路径、查询或片段。${hint}`);
  }
  const host = match[2].toLowerCase();
  if (host === 'demo.crmeb.com') throw new Error('VUE_APP_API_URL 不能指向 CRMEB 官方演示站。');

  const octets = host.split('.');
  const isIpv4 = octets.length === 4 && octets.every((part) => /^\d{1,3}$/.test(part) && Number(part) <= 255);
  const isLocal = host === 'localhost' || host === '[::1]' || (isIpv4 && (
    Number(octets[0]) === 127 || Number(octets[0]) === 10 ||
    (Number(octets[0]) === 192 && Number(octets[1]) === 168) ||
    (Number(octets[0]) === 172 && Number(octets[1]) >= 16 && Number(octets[1]) <= 31)
  ));
  if (match[1].toLowerCase() !== 'https' && (mode !== 'development' || !isLocal)) {
    throw new Error('VUE_APP_API_URL 必须使用 HTTPS；仅开发模式允许显式配置本机 / 局域网 HTTP 地址。');
  }
  return origin;
};
