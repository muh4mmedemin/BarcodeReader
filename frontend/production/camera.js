// Telefon kamerasıyla barkod okuma.
// Tarayıcının yerleşik BarcodeDetector'ı varsa (Android Chrome) o kullanılır; yoksa (iOS Safari,
// Firefox) aynı arayüzü sağlayan barcode-detector paketi CDN'den yüklenir — bu durumda telefonun
// internete erişimi olmalı. Kamera yalnızca güvenli bağlamda (HTTPS veya localhost) açılır.

const POLYFILL_URL = 'https://cdn.jsdelivr.net/npm/barcode-detector@2/pure/+esm';
const FORMATS = ['code_128', 'code_39', 'code_93', 'codabar', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'qr_code', 'data_matrix'];
const SAME_CODE_MS = 2500;   // aynı barkod bu süre içinde tekrar gönderilmez

/** Telefon/tablet gibi dokunmatik cihaz mı (kamera bölümü yalnızca bunlarda gösterilir). */
export function isPhone() {
  return matchMedia('(pointer: coarse)').matches && matchMedia('(max-width: 1024px)').matches;
}

/** Kamera kullanılamıyorsa nedeni, kullanılabiliyorsa null. */
export function cameraUnavailableReason() {
  if (!window.isSecureContext) {
    return 'Kamera yalnızca HTTPS bağlantıda açılır. Sayfayı https:// adresinden açın.';
  }
  if (!navigator.mediaDevices?.getUserMedia) return 'Bu tarayıcı kamerayı desteklemiyor.';
  return null;
}

async function createDetector() {
  let Detector = window.BarcodeDetector;
  if (Detector) {
    const supported = await Detector.getSupportedFormats();
    const formats = FORMATS.filter((f) => supported.includes(f));
    if (formats.length) return new Detector({ formats });
  }
  try {
    ({ BarcodeDetector: Detector } = await import(POLYFILL_URL));
  } catch {
    throw new Error('Barkod okuyucu yüklenemedi (internet bağlantısı gerekli).');
  }
  return new Detector({ formats: FORMATS });
}

/**
 * @param {HTMLVideoElement} video
 * @param {(code: string) => void} onCode  okunan her yeni barkod için çağrılır
 */
export function createScanner(video, onCode) {
  let stream = null;
  let detector = null;
  let running = false;
  let last = { code: null, at: 0 };

  async function loop() {
    if (!running) return;
    if (video.readyState >= 2) {
      try {
        const codes = await detector.detect(video);
        const code = codes[0]?.rawValue?.trim();
        const now = Date.now();
        if (code && (code !== last.code || now - last.at > SAME_CODE_MS)) {
          last = { code, at: now };
          onCode(code);
        } else if (code) {
          last.at = now;   // kamera aynı barkodda durdukça tekrar gönderme
        }
      } catch { /* tek kare hatası: sonraki kareyle devam */ }
    }
    if (running) setTimeout(() => requestAnimationFrame(loop), 120);
  }

  return {
    get running() { return running; },

    async start() {
      detector ??= await createDetector();
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: false,
        });
      } catch (err) {
        throw new Error(err.name === 'NotAllowedError'
          ? 'Kamera izni verilmedi. Tarayıcı ayarlarından izin verin.'
          : 'Kamera açılamadı.');
      }
      video.srcObject = stream;
      await video.play();
      running = true;
      last = { code: null, at: 0 };
      loop();
    },

    stop() {
      running = false;
      stream?.getTracks().forEach((t) => t.stop());
      stream = null;
      video.srcObject = null;
    },
  };
}
