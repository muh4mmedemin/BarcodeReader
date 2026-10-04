// Tüm sayfalardaki alt durum çubuğu: bağlantı, API adresi, saat.
import { apiBaseUrl } from './api-base.js';

export function initStatusBar() {
  const api = document.getElementById('sb-api');
  if (api) api.textContent = apiBaseUrl().replace(/^https?:\/\//, '');

  const clock = document.getElementById('sb-clock');
  if (clock) {
    const tick = () => {
      clock.textContent = new Date().toLocaleString('tr-TR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
    };
    tick();
    setInterval(tick, 1000);
  }
}

export function setConnection(ok, text) {
  document.getElementById('sb-led').className = `sq ${ok ? 'ok' : 'alarm'}`;
  document.getElementById('sb-conn').textContent = text;
}
