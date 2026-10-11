import { saveAs } from 'file-saver';
import { getCookies } from '@/libs/util';
import Setting from '@/setting';

function apiBase() {
  const base = new URL(Setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) {
    throw new Error('下载地址不可用');
  }
  base.pathname = base.pathname.replace(/\/+$/, '') + '/';
  return base;
}

function trustedDownload(url) {
  const base = apiBase();
  const target = new URL(url, base);
  const path = base.pathname + 'download';
  if (target.origin !== base.origin || target.username || target.password || target.hash) {
    throw new Error('下载地址不可用');
  }
  if (target.pathname === path) {
    const names = Array.from(target.searchParams.keys());
    const key = target.searchParams.get('key');
    if (names.length !== 1 || names[0] !== 'key' || !key || key.length > 256 || /[\u0000-\u0020\u007f]/.test(key)) {
      throw new Error('下载地址不可用');
    }
  } else if (!new RegExp('^' + path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/[a-f0-9]{32}$').test(target.pathname) || target.search) {
    throw new Error('下载地址不可用');
  }
  return target;
}

function safeFilename(value, fallback) {
  const clean = (name) => typeof name === 'string' ? name.split(/[\\/]/).pop().replace(/[\u0000-\u001f\u007f<>:"|?*]/g, '').trim() : '';
  const name = clean(value);
  return name && name !== '.' && name !== '..' ? name : clean(fallback) || 'download';
}

function filenameFrom(header, fallback) {
  if (header) {
    const extended = /(?:^|;)\s*filename\*\s*=\s*UTF-8'[^']*'([^;]+)/i.exec(header);
    if (extended) {
      try { return safeFilename(decodeURIComponent(extended[1].trim()), fallback); } catch (error) {}
    }
    const plain = /(?:^|;)\s*filename\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^;]*))/i.exec(header);
    if (plain) return safeFilename(plain[1] !== undefined ? plain[1].replace(/\\(.)/g, '$1') : plain[2].trim(), fallback);
  }
  return safeFilename('', fallback);
}

export async function downloadFile(url, fallback = 'download') {
  let target;
  try { target = trustedDownload(url); } catch (error) { throw { msg: '下载地址不可用' }; }
  const token = getCookies('token');
  if (!token) throw { msg: '请先登录' };
  let response;
  try {
    response = await fetch(target.href, {
      method: 'GET',
      headers: { 'Authori-zation': 'Bearer ' + token },
      mode: 'same-origin',
      credentials: 'same-origin',
      redirect: 'error',
      cache: 'no-store',
    });
  } catch (error) {
    throw { msg: '下载失败，请稍后重试' };
  }
  const contentType = response.headers.get('Content-Type') || '';
  if (/\b(?:application\/json|[\w.-]+\/[\w.+-]+\+json)\b/i.test(contentType)) {
    let message = '下载失败，请稍后重试';
    try {
      const result = await response.json();
      if (typeof result.msg === 'string' && result.msg) message = result.msg;
    } catch (error) {}
    throw { msg: message };
  }
  if (!response.ok) throw { msg: '下载失败，请稍后重试' };
  const blob = await response.blob();
  saveAs(blob, filenameFrom(response.headers.get('Content-Disposition'), fallback));
}

export function downloadByKey(key, fallback = 'database-backup.sql.gz') {
  try {
    if (typeof key !== 'string' || !key) throw new Error('download key missing');
    const target = new URL('download', apiBase());
    target.searchParams.set('key', key);
    return downloadFile(target.href, fallback);
  } catch (error) {
    return Promise.reject({ msg: '下载地址不可用' });
  }
}
