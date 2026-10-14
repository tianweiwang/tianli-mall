import axios from 'axios';
import { clearToken, readToken } from './session';

const apiBase = process.env.VUE_APP_API_BASE || '/merchantapi';
if (apiBase !== '/merchantapi') throw new Error('商家接口基础路径必须为 /merchantapi');
const client = axios.create({ baseURL: apiBase, timeout: 20000 });
let unauthenticated = () => {};
export function onUnauthenticated(handler) { unauthenticated = handler; }
export class ApiError extends Error {
  constructor(status, message, data) { super(message); this.name = 'ApiError'; this.status = status; this.data = data; }
}
function failure(status, payload) {
  const messages = { 401: '登录已失效，请重新登录', 403: '没有权限执行此操作', 404: '不存在或无权访问' };
  const message = messages[status] || (payload && typeof payload.msg === 'string' ? payload.msg : '请求未完成，请重试');
  if (status === 401) { clearToken(); unauthenticated(); }
  return new ApiError(status, message, payload && payload.data);
}
export async function request(method, url, data, options = {}) {
  if (!['GET', 'POST', 'PUT', 'DELETE'].includes(method) || !/^[a-z0-9][a-z0-9/-]*$/.test(url)) throw new Error('商家请求路径不正确');
  const headers = {};
  const token = readToken();
  if (!options.public && token) headers.Authorization = 'Bearer ' + token;
  const config = { method, url, headers };
  if (method === 'GET') config.params = data;
  else config.data = data;
  let response;
  try { response = await client(config); }
  catch (error) {
    if (error.response) throw failure(error.response.status, error.response.data);
    throw new ApiError(0, '网络请求未完成，请重试');
  }
  const payload = response.data;
  if (response.status !== 200 || !payload || payload.status !== 200 || typeof payload.msg !== 'string') throw failure(response.status === 200 ? (payload && payload.status) : response.status, payload);
  return payload.data;
}
