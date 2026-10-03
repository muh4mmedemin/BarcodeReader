import { ApiClient, escapeHtml } from '../shared/api.js';
import config from './config.js';

const api = new ApiClient(config);

const input = document.getElementById('barcode-input');
const stationSelect = document.getElementById('station-select');
const result = document.getElementById('result');
const history = document.getElementById('history');
const HISTORY_LIMIT = 15;
const STATION_STORAGE_KEY = 'barkod.production.stationId';

// ---------- İstasyon seçimi ----------

async function loadStations() {
  const { data } = await api.stations();
  stationSelect.insertAdjacentHTML('beforeend', data.map((s) =>
    `<option value="${s.id}">${escapeHtml(s.name)}${s.is_final ? ' (son istasyon)' : ''}</option>`).join(''));

  // Bu cihazda en son seçilen istasyonu hatırla
  const saved = readStorage(STATION_STORAGE_KEY);
  if (saved && data.some((s) => String(s.id) === saved)) stationSelect.value = saved;
  markStationMissing(false);
}

stationSelect.addEventListener('change', () => {
  writeStorage(STATION_STORAGE_KEY, stationSelect.value);
  markStationMissing(false);
  input.focus();
});

function markStationMissing(missing) {
  stationSelect.classList.toggle('missing', missing);
}

function selectedStationName() {
  return stationSelect.selectedOptions[0]?.textContent.replace(' (son istasyon)', '') ?? '';
}

// ---------- Okutma ----------

// Okuyucu tabancası klavye gibi çalışır: istasyon seçilmiyorsa input hep odakta kalmalı.
const keepFocus = () => setTimeout(() => {
  if (document.activeElement !== stationSelect) input.focus();
}, 0);
input.addEventListener('blur', keepFocus);
document.addEventListener('click', keepFocus);

let busy = false;

document.getElementById('scan-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const barcode = input.value.trim();
  input.value = '';
  if (!barcode || busy) return;

  if (!stationSelect.value) {
    markStationMissing(true);
    showResult('error', 'İSTASYON SEÇİN', 'Okutmadan önce yukarıdan istasyon seçmelisiniz.');
    beep(220, 0.35);
    return;
  }

  busy = true;
  try {
    const { data } = await api.scan(barcode, Number(stationSelect.value));
    const wo = data.work_order;
    const done = wo.status === 'done';
    showResult(done ? 'ok' : 'warn',
      `${wo.ma_code} → ${wo.current_station_name}`,
      `PO ${wo.po_number}${wo.description ? ' · ' + wo.description : ''}${done ? ' · TAMAMLANDI' : ''}`);
    addHistory(barcode, `${wo.ma_code} → ${wo.current_station_name}`, false);
    beep(done ? 880 : 660);
  } catch (err) {
    showResult('error', 'HATA', err.message);
    addHistory(barcode, err.message, true);
    beep(220, 0.35);
  } finally {
    busy = false;
    input.focus();
  }
});

function showResult(kind, title, body) {
  result.className = `result ${kind}`;
  result.innerHTML = `
    <div class="result-title mono">${escapeHtml(title)}</div>
    <div class="result-body">${escapeHtml(body)}</div>`;
}

function addHistory(barcode, text, isError) {
  const li = document.createElement('li');
  li.className = isError ? 'error' : '';
  const time = new Date().toLocaleTimeString('tr-TR');
  li.innerHTML = `<span class="mono">${escapeHtml(barcode)}</span><span>${escapeHtml(text)}</span>`
    + `<span class="muted">${escapeHtml(selectedStationName())} · ${time}</span>`;
  history.prepend(li);
  while (history.children.length > HISTORY_LIMIT) history.lastChild.remove();
}

// ---------- Yardımcılar ----------

function readStorage(key) {
  try { return localStorage.getItem(key); } catch { return null; }
}

function writeStorage(key, value) {
  try { localStorage.setItem(key, value); } catch { /* depolama kapalıysa her açılışta seçilir */ }
}

// Sesli geri bildirim: operatör ekrana bakmadan sonucu anlar.
let audio;
function beep(freq, duration = 0.12) {
  try {
    audio ??= new AudioContext();
    const osc = audio.createOscillator();
    const gain = audio.createGain();
    osc.frequency.value = freq;
    gain.gain.value = 0.15;
    osc.connect(gain).connect(audio.destination);
    osc.start();
    osc.stop(audio.currentTime + duration);
  } catch { /* ses desteklenmiyorsa sessiz devam */ }
}

(async () => {
  try {
    await api.health();
    await loadStations();
    document.getElementById('server-status').textContent = 'Bağlı';
  } catch (err) {
    document.getElementById('server-status').textContent = `Bağlantı yok: ${err.message}`;
  }
  input.focus();
})();
