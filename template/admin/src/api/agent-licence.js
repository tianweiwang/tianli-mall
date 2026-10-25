import setting from '@/setting';
import { captureContext, currentContext, closeContext } from '@/api/marketplace-aftersale';
export { captureContext, currentContext, closeContext };

const mediaTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
function unavailable() { return new Error('执照图片读取未完成，请重新加载申请'); }
export function isPrivateAgentLicence(ref) { return typeof ref === 'string' && ref.startsWith('mp-licence:'); }
function assetId(ref) {
  if (typeof ref !== 'string' || !ref.startsWith('mp-licence:v1:')) throw unavailable();
  const id = ref.slice('mp-licence:v1:'.length);
  if (!/^[1-9][0-9]{0,19}(?![\s\S])/.test(id) || id.length === 20 && id > '18446744073709551615') throw unavailable(); return id;
}
function applicationId(value) {
  if (Number.isSafeInteger(value) && value > 0 && value <= 2147483647) return String(value);
  if (typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value) && (value.length < 10 || value <= '2147483647')) return value;
  throw unavailable();
}
function requireCurrent(context) { if (!currentContext(context)) throw unavailable(); }
export async function privateAgentLicenceImage(context, application, ref) {
  requireCurrent(context); const id = applicationId(application.id), file = assetId(ref);
  if (!Array.isArray(application.images) || !application.images.includes(ref)) throw unavailable();
  const path = 'agent/division/apply/' + id + '/licence/' + file + '/content';
  const base = new URL(setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw unavailable();
  base.pathname = base.pathname.replace(/\/+$/, '') + '/'; const target = new URL(path, base);
  if (target.origin !== base.origin || target.pathname !== base.pathname + path || target.search || target.hash || target.username || target.password) throw unavailable();
  const controller = new AbortController(), cancel = () => controller.abort(); context.life.cancels.add(cancel);
  const timer = window.setTimeout(cancel, 20000);
  try {
    const response = await fetch(target.href, { method: 'GET', headers: { 'Authori-zation': 'Bearer ' + context.token }, mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
    requireCurrent(context); const type = response.headers.get('Content-Type'), length = response.headers.get('Content-Length');
    if (response.status !== 200 || response.redirected || !mediaTypes.includes(type) || typeof length !== 'string' || !/^[1-9][0-9]{0,8}(?![\s\S])/.test(length) || Number(length) > 52428800 || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
    const blob = await response.blob(); requireCurrent(context);
    if (!(blob instanceof Blob) || blob.size !== Number(length) || blob.type !== type || applicationId(application.id) !== id || !application.images.includes(ref)) throw unavailable();
    const url = window.URL.createObjectURL(blob); if (!currentContext(context)) { window.URL.revokeObjectURL(url); throw unavailable(); } return url;
  } catch (error) { requireCurrent(context); throw unavailable(); }
  finally { window.clearTimeout(timer); context.life.cancels.delete(cancel); }
}
