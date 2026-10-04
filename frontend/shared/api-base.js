// API adresi, sayfanın açıldığı adresten türetilir: 192.168.1.10:5173'ten açılan sayfa
// API'yi 192.168.1.10:8000'de arar. Böylece aynı dosyalar hem bu bilgisayarda hem ağdaki
// diğer cihazlarda ayar yapmadan çalışır. API başka bir makinedeyse bu fonksiyon yerine
// config dosyasına sabit adres yazılabilir.
// HTTPS girişinden (scripts/https-proxy.mjs) açılan sayfada API aynı adrestedir.
export const API_PORT = 8000;

export function apiBaseUrl(port = API_PORT) {
  if (location.protocol === 'https:') return location.origin;
  return `${location.protocol}//${location.hostname}:${port}`;
}
