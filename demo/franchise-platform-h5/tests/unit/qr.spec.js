import QRCode from 'qrcode';
import jsQR from 'jsqr';
import { PNG } from 'pngjs';
import {
  buildBusinessUrl,
  buildStoreUrl,
  parseBusinessUrl,
} from '../../src/qr/codec';

function checksum(text) {
  let hash = 5381;
  for (let index = 0; index < text.length; index += 1) {
    hash = ((hash << 5) + hash) ^ text.charCodeAt(index);
  }
  return (hash >>> 0).toString(36);
}

describe('QR payload codec', () => {
  it('builds a store hash URL without carrying an existing hash', () => {
    expect(buildStoreUrl(
      'https://demo.example.com/franchise/#/brand/dashboard',
      'TL1001',
    )).toBe('https://demo.example.com/franchise/#/c/store/TL1001');
  });

  it('round-trips a versioned booking payload', () => {
    const url = buildBusinessUrl('https://demo.example.com/franchise/', {
      type: 'booking',
      id: 'BK202607302006',
      storeId: 'TL1001',
      issuedAt: 1785375000,
    });

    expect(parseBusinessUrl(url)).toEqual({
      version: 1,
      type: 'booking',
      id: 'BK202607302006',
      storeId: 'TL1001',
      issuedAt: 1785375000,
    });
  });

  it('rejects a damaged checksum', () => {
    const url = buildBusinessUrl('https://demo.example.com/franchise/', {
      type: 'booking',
      id: 'BK202607302006',
      storeId: 'TL1001',
      issuedAt: 1785375000,
    });
    const damaged = url.replace(/c=[^&]+/, 'c=broken');

    expect(() => parseBusinessUrl(damaged)).toThrow('二维码内容已损坏');
  });

  it('distinguishes a valid but unsupported payload version', () => {
    const body = '2|booking|BK202607302006|TL1001|1785375000';
    const payload = btoa(body)
      .replaceAll('+', '-')
      .replaceAll('/', '_')
      .replaceAll('=', '');
    const url = `https://demo.example.com/#/verify?p=${payload}&c=${checksum(body)}`;

    expect(() => parseBusinessUrl(url)).toThrow('不支持的二维码版本');
  });

  it('decodes the generated PNG back to the exact URL', async () => {
    const url = buildBusinessUrl('https://demo.example.com/franchise/', {
      type: 'booking',
      id: 'BK202607302006',
      storeId: 'TL1001',
      issuedAt: 1785375000,
    });
    const buffer = await QRCode.toBuffer(url, { width: 320, margin: 2 });
    const png = PNG.sync.read(buffer);

    const decoded = jsQR(
      new Uint8ClampedArray(png.data),
      png.width,
      png.height,
    );

    expect(decoded?.data).toBe(url);
  });
});
