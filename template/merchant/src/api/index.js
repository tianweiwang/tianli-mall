import { request, ApiError, staleSessionError } from './request';
import { captureSession, isCurrentSession } from './session';
import { orderRecordId } from '../domain/order';
function id(value) { if (!Number.isSafeInteger(value) || value <= 0) throw new Error('缺少有效的实际记录编号'); return value; }
function mediaId(value) { if (!Number.isSafeInteger(value) || value < 0 || value > 8388607) throw new Error('缺少有效的商品媒体上下文'); return value; }
function actorId(value) { if (typeof value === 'string' && /^[1-9][0-9]*$/.test(value)) value = Number(value); return Number.isSafeInteger(value) && value > 0 && value <= 4294967295 ? value : null; }
const mediaMimes = {
  image: ['image/jpg', 'image/jpeg', 'image/gif', 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'],
  video: ['video/mp4', 'application/mp4'],
  audio: ['audio/mpeg', 'audio/mp3', 'audio/x-mp3', 'audio/wav', 'audio/wave', 'audio/x-wav', 'audio/vnd.wave', 'audio/amr', 'audio/amr-wb', 'audio/x-ms-wma', 'audio/x-ms-asf', 'video/x-ms-asf', 'application/vnd.ms-asf'],
};
export function mediaKind(row) { return Object.keys(mediaMimes).find((kind) => mediaMimes[kind].includes(row.att_type)) || ''; }
export function mediaRow(row, expectedKind = null) {
  const fields = ['att_id', 'pid', 'real_name', 'att_dir', 'satt_dir', 'att_size', 'att_type', 'time', 'type', 'url', 'thumbnail_url'];
  if (!row || Array.isArray(row) || Object.keys(row).length !== fields.length || fields.some((key) => !Object.prototype.hasOwnProperty.call(row, key))
    || !Number.isSafeInteger(row.att_id) || row.att_id < 1 || !Number.isSafeInteger(row.pid) || row.pid < 0
    || ![0, 1].includes(row.type) || !Number.isSafeInteger(row.time) || row.time < 0
    || ['real_name', 'att_dir', 'satt_dir', 'url', 'thumbnail_url'].some((key) => typeof row[key] !== 'string')
    || !row.att_dir || !row.url || (expectedKind && mediaKind(row) !== expectedKind)) throw new Error('素材资料返回不完整，请重新读取');
  return row;
}
export function captureMediaContext(store, productId) {
  if (productId !== null) mediaId(productId);
  const state = store.state; const profile = state.profile; const session = captureSession();
  const merchant = profile && profile.merchant && actorId(profile.merchant.mer_id); const employee = profile && profile.employee && actorId(profile.employee.id);
  if (!merchant || !employee || actorId(profile.employee.mer_id) !== merchant || !profile.role || !Array.isArray(profile.role.permissions)
    || state.token !== session.token || state.sessionRevision !== session.revision || !session.token
    || !Number.isSafeInteger(state.authIntent) || !isCurrentSession(session)) throw new Error('当前商家身份未确认，请重新登录');
  return Object.freeze({ store, session, authIntent: state.authIntent, merchant, employee, productId });
}
export function currentMediaContext(context) {
  if (!context) return false;
  const state = context.store.state; const profile = state.profile;
  return isCurrentSession(context.session) && state.token === context.session.token && state.sessionRevision === context.session.revision
    && state.authIntent === context.authIntent && !!profile && !!profile.merchant && !!profile.employee
    && actorId(profile.merchant.mer_id) === context.merchant && actorId(profile.employee.id) === context.employee && actorId(profile.employee.mer_id) === context.merchant;
}
export function mediaReceipt(value) {
  const fields = ['complete', 'phase', 'physical_changed', 'physical_complete', 'database_committed', 'cleanup_complete'];
  if (!value || Object.keys(value).length !== fields.length || fields.some((key) => !Object.prototype.hasOwnProperty.call(value, key))
    || value.complete !== false || typeof value.phase !== 'string' || !/^[a-z][a-z0-9_]*$/.test(value.phase)
    || ['physical_changed', 'database_committed', 'cleanup_complete'].some((key) => ![true, false, null].includes(value[key]))
    || typeof value.physical_complete !== 'boolean') return null;
  const copy = {}; fields.forEach((key) => { copy[key] = value[key]; }); return Object.freeze(copy);
}
export function mediaReceiptText(receipt) {
  if (!receipt) return '';
  const state = (value) => value === null ? '未知' : (value ? '是' : '否');
  return '阶段：' + receipt.phase + '；物理变化：' + state(receipt.physical_changed) + '；物理完成：' + state(receipt.physical_complete)
    + '；数据库提交：' + state(receipt.database_committed) + '；清理完成：' + state(receipt.cleanup_complete) + '。保留本次结果，刷新后核对，勿直接重复上传。';
}
async function uploadProductVideoChunks(productId, file, categoryId, context, isCurrent) {
  const product = mediaId(productId);
  if (!Number.isSafeInteger(categoryId) || categoryId < 0 || categoryId > 2147483647) throw new Error('素材分类不正确');
  const current = () => {
    if (!context || context.productId !== product || !currentMediaContext(context) || typeof isCurrent !== 'function' || isCurrent() !== true) throw staleSessionError();
  };
  const endpoint = 'products/' + product + '/media/videos/upload'; let issued;
  try {
    current();
    issued = await request('POST', endpoint, { upload_operation: 'issue', category_id: categoryId, filename: file.name, total_size: file.size });
    current();
    if (!issued || typeof issued.upload_reference !== 'string' || !/^[0-9a-f]{32}$/.test(issued.upload_reference)
      || issued.chunk_size !== 3145728 || issued.total_chunks !== Math.ceil(file.size / issued.chunk_size)
      || issued.code !== 1 || issued.file_path !== '') throw new ApiError(503, '视频暂存响应不完整');
    for (let number = 1; number <= issued.total_chunks; number += 1) {
      current(); const start = (number - 1) * issued.chunk_size;
      const body = new FormData(); body.append('file', file.slice(start, Math.min(start + issued.chunk_size, file.size), file.type), file.name);
      body.append('upload_operation', 'chunk'); body.append('category_id', String(categoryId));
      body.append('upload_reference', issued.upload_reference); body.append('chunkNumber', String(number));
      const part = await request('POST', endpoint, body); current();
      const last = number === issued.total_chunks;
      if (!part || part.code !== (last ? 2 : 1) || part.file_path !== (last ? issued.upload_reference : '')) throw new ApiError(503, '视频分片结果不完整');
    }
  } catch (error) {
    // These finite operations cannot publish an attachment. Retain private staging for the original timer.
    if (!error.staleSession) error.mediaStagingOnly = true;
    throw error;
  }
  current();
  // Keep original ApiError/unknown-commit semantics after publication may have started.
  const result = await request('POST', endpoint, { upload_operation: 'finalize', category_id: categoryId, upload_reference: issued.upload_reference });
  current();
  try { return mediaRow(result, 'video'); } catch (error) { throw new ApiError(0, '视频保存结果尚未确认'); }
}
export const api = {
  login: (body) => request('POST', 'login', body, { public: true }),
  setupPassword: (body) => request('POST', 'password/setup', body, { public: true }),
  me: () => request('GET', 'me'),
  logout: () => request('POST', 'logout', {}),
  refresh: () => request('POST', 'refresh', {}),
  orders: (query) => request('GET', 'orders', query),
  order: (value) => request('GET', 'orders/' + orderRecordId(value)),
  orderPackages: (value, page = 1, limit = 20) => Promise.resolve().then(() => {
    if (!Number.isSafeInteger(page) || page < 1 || !Number.isSafeInteger(limit) || limit < 1 || limit > 100) throw new Error('包裹页码不正确');
    return request('GET', 'orders/' + orderRecordId(value) + '/packages', { page, limit });
  }),
  fulfillmentPackage: (value) => Promise.resolve().then(() => request('GET', 'packages/' + orderRecordId(value))),
  shipOrder: (value, body) => Promise.resolve().then(() => request('POST', 'orders/' + orderRecordId(value) + '/shipments', body)),
  products: (query) => request('GET', 'products', query),
  product: (value) => request('GET', 'products/' + id(value)),
  saveProduct: (value, body) => value ? request('PUT', 'products/' + id(value), body) : request('POST', 'products', body),
  submitReview: (value) => request('PUT', 'products/' + id(value) + '/submit-review', {}),
  showProduct: (value, show) => request('PUT', 'products/' + id(value) + '/show', { show }),
  deleteProduct: (value) => request('DELETE', 'products/' + id(value), {}),
  adjustStock: (value, body) => request('PUT', 'products/' + id(value) + '/stock', body),
  categories: () => request('GET', 'categories'),
  attachments: (query) => request('GET', 'attachments', query),
  attachmentMedia: (kind, query) => { if (!['video', 'audio'].includes(kind)) throw new Error('素材类型不正确'); return request('GET', 'attachments/' + (kind === 'video' ? 'videos' : 'audio'), query); },
  attachmentCategories: (type = 0) => { if (![0, 1].includes(type)) throw new Error('分类类型不正确'); return request('GET', 'attachment-categories', type === 1 ? { type: 1 } : undefined); },
  saveAttachmentCategory: (value, body) => value ? request('PUT', 'attachment-categories/' + id(value), body) : request('POST', 'attachment-categories', body),
  deleteVideoCategory: (value) => request('DELETE', 'attachment-categories/' + id(value), { type: 1 }),
  uploadAttachment: (file, categoryId) => { const body = new FormData(); body.append('file', file); body.append('category_id', String(categoryId)); return request('POST', 'attachments/upload', body); },
  uploadProductMedia: (productId, kind, file, categoryId, options = {}) => {
    if (!['video', 'audio'].includes(kind)) throw new Error('素材类型不正确');
    if (kind === 'video' && options.chunked === true && file instanceof File && file.size > 3145728) {
      return uploadProductVideoChunks(productId, file, categoryId, options.context, options.isCurrent);
    }
    const body = new FormData(); body.append('file', file); body.append('category_id', String(categoryId));
    return request('POST', 'products/' + mediaId(productId) + '/media/' + (kind === 'video' ? 'videos' : 'audio') + '/upload', body);
  },
  deleteAttachments: (ids) => request('DELETE', 'attachments', { ids }),
  freightTemplates: (query) => request('GET', 'freight-templates', query),
  freightTemplate: (value) => request('GET', 'freight-templates/' + id(value)),
  saveFreight: (value, body) => value ? request('PUT', 'freight-templates/' + id(value), body) : request('POST', 'freight-templates', body),
  deleteFreight: (value) => request('DELETE', 'freight-templates/' + id(value), {}),
  freightCities: () => request('GET', 'freight-cities'),
};
