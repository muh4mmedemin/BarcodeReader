import { escapeHtml } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';
import { cameraUnavailableReason, createScanner, isPhone } from './camera.js';

const session = requireSession(['rep', 'production']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();

// Üretim kullanıcısının istasyonu hesabına sabittir; temsilci istasyonu kendisi seçer.
const fixedStationId = session.user.role === 'production' ? session.user.station_id : null;
if (fixedStationId) document.getElementById('tb-menu').hidden = true;

const input = document.getElementById('barcode-input');
const stationsEl = document.getElementById('stations');
const result = document.getElementById('result');
const history = document.getElementById('history');
const HISTORY_LIMIT = 15;
const STATION_STORAGE_KEY = 'barkod.production.stationId';

let stations = [];
let stationId = null;
/** Son başarılı okutmadaki iş emri; bulunduğu istasyon işaretlenir. */
let lastWorkOrder = null;

// ---------- İstasyon seçimi ----------

async function loadStations() {
  const { data } = await api.stations();
  stations = data;

  if (fixedStationId) {
    stationId = fixedStationId;
  } else {
    // Bu cihazda en son seçilen istasyonu hatırla
    const saved = Number(readStorage(STATION_STORAGE_KEY));
    stationId = stations.some((s) => s.id === saved) ? saved : null;
  }
  renderStations();
}

function selectStation(id) {
  if (fixedStationId) return;
  stationId = id;
  writeStorage(STATION_STORAGE_KEY, String(id));
  stationsEl.classList.remove('missing');
  renderStations();
  focusInput();
}

stationsEl.addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-id]');
  if (btn) selectStation(Number(btn.dataset.id));
});

function currentStation() {
  return stations.find((s) => s.id === stationId) ?? null;
}

function renderStations() {
  const at = lastWorkOrder?.current_station_id;
  const done = lastWorkOrder?.status === 'done';
  stationsEl.innerHTML = stations.map((s, i) => `
    <button type="button" role="radio" class="st ${s.id === at ? 'at' : ''} ${s.id === at && done ? 'done' : ''}"
            data-id="${s.id}" aria-checked="${s.id === stationId}"
            ${fixedStationId && s.id !== fixedStationId ? 'disabled' : ''}>
      <span class="st-no">${String(i + 1).padStart(2, '0')} · ${escapeHtml(s.code)}</span>
      <span class="st-name">${escapeHtml(s.name)}</span>
      ${s.is_final ? '<span class="st-tag">SON</span>' : ''}
    </button>`).join('');

  const st = currentStation();
  document.getElementById('sb-station').textContent = st ? st.name : '—';
}

// ---------- Okutma ----------

// Okuyucu klavye gibi çalışır: barkod alanı hep odakta kalır.
// Telefonda odak zorlanmaz: her dokunuşta klavye açılırdı.
const phone = isPhone();
const focusInput = () => { if (!phone) input.focus(); };
const keepFocus = () => setTimeout(focusInput, 0);
if (!phone) {
  input.addEventListener('blur', keepFocus);
  document.addEventListener('click', keepFocus);
}

let busy = false;

document.getElementById('scan-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const barcode = input.value.trim();
  input.value = '';
  submitBarcode(barcode);
});

async function submitBarcode(barcode) {
  if (!barcode || busy) return;

  if (!stationId) {
    stationsEl.classList.add('missing');
    showResult('error', 'Okutma reddedildi', 'İstasyon seçilmedi', []);
    addHistory(barcode, '—', 'İstasyon seçilmedi', true);
    beep(220, 0.35);
    return;
  }

  busy = true;
  const stationName = currentStation()?.name ?? '';
  try {
    const { data } = await api.scan(barcode, stationId);
    const wo = data.work_order;
    const done = wo.status === 'done';
    lastWorkOrder = wo;
    renderStations();
    showResult(done ? 'ok' : 'warn',
      done ? 'Tamamlandı' : `${wo.current_station_name} istasyonuna alındı`,
      wo.ma_code,
      [['PO', wo.po_number], ['Barkod', wo.barcode], ['Adet', wo.quantity], ['Açıklama', wo.description || '—']]);
    addHistory(barcode, stationName, `${wo.ma_code}${done ? ' · tamamlandı' : ''}`, false);
    beep(done ? 880 : 660);
  } catch (err) {
    showResult('error', 'Okutma reddedildi', err.message, [['Barkod', barcode], ['İstasyon', stationName]]);
    addHistory(barcode, stationName, err.message, true);
    beep(220, 0.35);
  } finally {
    busy = false;
    focusInput();
  }
}

// ---------- Telefon kamerası ----------

if (phone) {
  const box = document.getElementById('camera');
  const view = document.getElementById('camera-view');
  const toggle = document.getElementById('camera-toggle');
  const msg = document.getElementById('camera-msg');
  const setMsg = (text, isError = false) => { msg.textContent = text; msg.className = `camera-msg ${isError ? 'error' : 'muted'}`; };

  const scanner = createScanner(document.getElementById('camera-video'), (code) => {
    navigator.vibrate?.(80);
    view.classList.add('hit');
    setTimeout(() => view.classList.remove('hit'), 400);
    submitBarcode(code);
  });

  const stopCamera = () => {
    scanner.stop();
    view.hidden = true;
    toggle.textContent = 'Kamerayı aç';
  };

  box.hidden = false;
  const reason = cameraUnavailableReason();
  if (reason) {
    toggle.hidden = true;
    setMsg(reason, true);
  }

  toggle.addEventListener('click', async () => {
    if (scanner.running) { stopCamera(); setMsg(''); return; }
    toggle.disabled = true;
    setMsg('Kamera açılıyor…');
    try {
      view.hidden = false;
      await scanner.start();
      toggle.textContent = 'Kamerayı kapat';
      setMsg('Barkodu çerçevenin içine getirin.');
    } catch (err) {
      stopCamera();
      setMsg(err.message, true);
    } finally {
      toggle.disabled = false;
    }
  });

  // Ekran kapanınca / sekme değişince kamera kapanır
  document.addEventListener('visibilitychange', () => { if (document.hidden && scanner.running) stopCamera(); });
}

function showResult(kind, status, main, details) {
  result.className = `result ${kind}`;
  result.innerHTML = `
    <div class="result-status">${escapeHtml(status)}</div>
    <div class="result-main mono">${escapeHtml(main)}</div>
    <dl class="result-detail">${details.map(([k, v]) =>
      `<div><dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd></div>`).join('')}</dl>`;
}

function addHistory(barcode, stationName, text, isError) {
  history.querySelector('tr.empty')?.remove();
  const tr = document.createElement('tr');
  tr.className = isError ? 'error' : '';
  tr.innerHTML = `<td class="mono">${new Date().toLocaleTimeString('tr-TR')}</td>`
    + `<td class="mono">${escapeHtml(barcode)}</td>`
    + `<td>${escapeHtml(stationName)}</td>`
    + `<td><span class="state ${isError ? 'cancelled' : 'done'}">${escapeHtml(text)}</span></td>`;
  history.prepend(tr);
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
    setConnection(true, 'Sunucu bağlı');
  } catch (err) {
    setConnection(false, `Bağlantı yok: ${err.message}`);
  }
  focusInput();
})();
