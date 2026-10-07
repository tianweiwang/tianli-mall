import { Html5Qrcode } from 'html5-qrcode';

export function createScanner(elementId) {
  const instance = new Html5Qrcode(elementId, { verbose: false });

  return {
    start(onResult, onError) {
      return instance.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        onResult,
        onError,
      );
    },
    scanFile(file) {
      return instance.scanFile(file, true);
    },
    async stop() {
      if (instance.isScanning) {
        await instance.stop();
      }
      await instance.clear();
    },
  };
}
