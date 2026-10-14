import config from '../../package.json';
import Cookies from 'js-cookie';

// 1、window.localStorage 浏览器永久缓存
export const Local = {
  // 查看 v2.4.3版本更新日志
  setKey(key) {
    // @ts-ignore
    return `${config.name}:${key}`;
  },
  // 设置永久缓存
  set(key, val) {
    window.localStorage.setItem(Local.setKey(key), JSON.stringify(val));
  },
  // 获取永久缓存
  get(key) {
    let json = window.localStorage.getItem(Local.setKey(key));
    return JSON.parse(json);
  },
  // 移除永久缓存
  remove(key) {
    window.localStorage.removeItem(Local.setKey(key));
  },
  // 移除全部永久缓存
  clear() {
    clearPlatformStorage(window.localStorage);
  },
};

// 2、window.sessionStorage 浏览器临时缓存
export const Session = {
  // 设置临时缓存
  set(key, val) {
    window.sessionStorage.setItem(Local.setKey(key), JSON.stringify(val));
  },
  // 获取临时缓存
  get(key) {
    let json = window.sessionStorage.getItem(Local.setKey(key));
    return JSON.parse(json);
  },
  // 移除临时缓存
  remove(key) {
    window.sessionStorage.removeItem(Local.setKey(key));
  },
  // 移除全部临时缓存
  clear() {
    clearPlatformStorage(window.sessionStorage);
  },
};

// Only this complete platform namespace belongs to these helpers.
export const PLATFORM_STORAGE_PREFIX = `${config.name}:`;
export const PLATFORM_VUEX_KEY = `${PLATFORM_STORAGE_PREFIX}vuex`;
let platformStateReset = null;

export function getPlatformToken() {
  const token = Cookies.get(`${config.name}:token`);
  return token && token !== 'undefined' ? token : '';
}

export function bindPlatformStateReset(reset) {
  if (typeof reset !== 'function') throw new TypeError('Platform state reset must be a function');
  platformStateReset = reset;
}

export function clearPlatformStorage(storage) {
  const keys = [];
  for (let i = 0; i < storage.length; i++) {
    const key = storage.key(i);
    if (key !== null && key.startsWith(PLATFORM_STORAGE_PREFIX)) keys.push(key);
  }
  keys.forEach((key) => storage.removeItem(key));
}

export function clearPlatformAuth() {
  ['token', 'expires_time', 'uuid'].forEach((key) => Cookies.remove(`${config.name}:${key}`));
  if (platformStateReset) platformStateReset();
  Local.clear();
  Session.clear();
}

const RAW_KEYS = ['ADMIN_TITLE', 'tagNaveList', 'local', 'slider', 'point', 'DELIVERY_DATA', 'itemObj'];
const LEGACY_COPY_KEYS = ['ADMIN_TITLE', 'local', 'slider', 'point', 'DELIVERY_DATA', 'itemObj'];
function rawKey(key) {
  if (!RAW_KEYS.includes(key)) throw new RangeError('Unknown platform raw storage key');
  return `${PLATFORM_STORAGE_PREFIX}raw:${key}`;
}
function compatibleLegacyValue(key, raw) {
  if (key === 'slider' || key === 'point') return new RegExp(`^${key}-[0-9a-f-]{36}$`).test(raw);
  if (key === 'local') return typeof raw === 'string' && /^[a-zA-Z-]{1,16}$/.test(raw);
  if (key === 'ADMIN_TITLE') return typeof raw === 'string';
  let value;
  try {
    value = JSON.parse(raw);
  } catch (error) {
    if (error instanceof SyntaxError) return false;
    throw error;
  }
  return value !== null && typeof value === 'object';
}

// Preserve raw codecs. Compatible legacy preferences/drafts are copied, never deleted.
// Navigation/authorization snapshots deliberately have no legacy fallback.
export const PlatformRaw = {
  get(key) {
    const physical = rawKey(key);
    const current = window.localStorage.getItem(physical);
    if (current !== null) return current;
    if (!LEGACY_COPY_KEYS.includes(key)) return null;
    const legacy = window.localStorage.getItem(key);
    if (legacy !== null && compatibleLegacyValue(key, legacy)) {
      window.localStorage.setItem(physical, legacy);
      return legacy;
    }
    return null;
  },
  set(key, value) {
    window.localStorage.setItem(rawKey(key), value);
  },
};
