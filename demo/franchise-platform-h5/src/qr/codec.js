const VERSION = 1;
const SUPPORTED_TYPES = new Set(['booking', 'order']);

function checksum(text) {
  let hash = 5381;
  for (let index = 0; index < text.length; index += 1) {
    hash = ((hash << 5) + hash) ^ text.charCodeAt(index);
  }
  return (hash >>> 0).toString(36);
}

function encodeBase64Url(text) {
  const bytes = new TextEncoder().encode(text);
  let binary = '';
  bytes.forEach((value) => {
    binary += String.fromCharCode(value);
  });
  return btoa(binary)
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replaceAll('=', '');
}

function decodeBase64Url(value) {
  const normalized = value.replaceAll('-', '+').replaceAll('_', '/');
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
  const binary = atob(padded);
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  return new TextDecoder().decode(bytes);
}

function withoutHash(baseUrl) {
  return String(baseUrl).replace(/#.*$/, '');
}

export function buildStoreUrl(baseUrl, storeId) {
  if (!storeId) {
    throw new Error('门店编号不能为空');
  }
  return `${withoutHash(baseUrl)}#/c/store/${encodeURIComponent(storeId)}`;
}

export function buildBusinessUrl(baseUrl, input) {
  if (!SUPPORTED_TYPES.has(input.type)) {
    throw new Error('不支持的业务类型');
  }
  if (!input.id || !input.storeId || !Number.isFinite(input.issuedAt)) {
    throw new Error('二维码业务字段不完整');
  }

  const body = [
    VERSION,
    input.type,
    input.id,
    input.storeId,
    input.issuedAt,
  ].join('|');
  return `${withoutHash(baseUrl)}#/verify?p=${encodeBase64Url(body)}&c=${checksum(body)}`;
}

export function parseBusinessUrl(
  value,
  baseUrl = globalThis.location?.href || 'http://localhost/',
) {
  let body;
  try {
    const parsed = new URL(value, baseUrl);
    const query = parsed.hash.split('?')[1] || '';
    const params = new URLSearchParams(query);
    const encoded = params.get('p');
    const expectedChecksum = params.get('c');
    if (!encoded || !expectedChecksum) {
      throw new Error('missing payload');
    }

    body = decodeBase64Url(encoded);
    if (checksum(body) !== expectedChecksum) {
      throw new Error('checksum mismatch');
    }
  } catch (_error) {
    throw new Error('二维码内容已损坏');
  }

  const [version, type, id, storeId, issuedAt] = body.split('|');
  if (Number(version) !== VERSION || !SUPPORTED_TYPES.has(type)) {
    throw new Error('不支持的二维码版本');
  }
  if (!id || !storeId || !Number.isFinite(Number(issuedAt))) {
    throw new Error('二维码内容已损坏');
  }
  return {
    version: Number(version),
    type,
    id,
    storeId,
    issuedAt: Number(issuedAt),
  };
}
