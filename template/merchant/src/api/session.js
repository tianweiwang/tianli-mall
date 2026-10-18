export const TOKEN_KEY = 'marketplace_merchant_token';
let revision = 0;
function nextRevision() {
  if (!Number.isSafeInteger(revision) || revision >= Number.MAX_SAFE_INTEGER) throw new Error('登录状态代次不可用，请刷新页面');
  return revision + 1;
}
export function readToken() { return window.sessionStorage.getItem(TOKEN_KEY) || ''; }
export function captureSession() { return Object.freeze({ token: readToken(), revision }); }
export function isCurrentSession(snapshot) {
  return !!snapshot && Number.isSafeInteger(snapshot.revision) && snapshot.revision >= 0
    && typeof snapshot.token === 'string' && snapshot.revision === revision && snapshot.token === readToken();
}
export function writeToken(token) {
  if (typeof token !== 'string' || !token) throw new Error('登录响应未包含有效令牌');
  const next = nextRevision();
  window.sessionStorage.setItem(TOKEN_KEY, token);
  revision = next;
  return revision;
}
export function clearToken() {
  const next = nextRevision();
  window.sessionStorage.removeItem(TOKEN_KEY);
  revision = next;
  return revision;
}
