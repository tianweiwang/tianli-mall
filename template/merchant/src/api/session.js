export const TOKEN_KEY = 'marketplace_merchant_token';
export function readToken() { return window.sessionStorage.getItem(TOKEN_KEY) || ''; }
export function writeToken(token) {
  if (typeof token !== 'string' || !token) throw new Error('登录响应未包含有效令牌');
  window.sessionStorage.setItem(TOKEN_KEY, token);
}
export function clearToken() { window.sessionStorage.removeItem(TOKEN_KEY); }
