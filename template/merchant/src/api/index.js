import { request } from './request';
import { orderRecordId } from '../domain/order';
function id(value) { if (!Number.isSafeInteger(value) || value <= 0) throw new Error('缺少有效的实际记录编号'); return value; }
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
  attachmentCategories: () => request('GET', 'attachment-categories'),
  saveAttachmentCategory: (value, body) => value ? request('PUT', 'attachment-categories/' + id(value), body) : request('POST', 'attachment-categories', body),
  uploadAttachment: (file, categoryId) => { const body = new FormData(); body.append('file', file); body.append('category_id', String(categoryId)); return request('POST', 'attachments/upload', body); },
  deleteAttachments: (ids) => request('DELETE', 'attachments', { ids }),
  freightTemplates: (query) => request('GET', 'freight-templates', query),
  freightTemplate: (value) => request('GET', 'freight-templates/' + id(value)),
  saveFreight: (value, body) => value ? request('PUT', 'freight-templates/' + id(value), body) : request('POST', 'freight-templates', body),
  deleteFreight: (value) => request('DELETE', 'freight-templates/' + id(value), {}),
  freightCities: () => request('GET', 'freight-cities'),
};
