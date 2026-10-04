import { escapeHtml } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { duration } from '../shared/history.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';

// Sadece müşteri temsilcisi
const session = requireSession(['rep']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();

const $ = (sel) => document.querySelector(sel);
const PERIOD_KEY = 'barkod.analysis.days';

let days = Number(readStorage(PERIOD_KEY)) || 30;

const fmt = (sec) => (sec === null || sec === undefined ? '—' : duration(sec * 1000));

// ---------- Özet ----------

function renderFigures(r) {
  const todayRow = r.daily.find((d) => d.day === r.today);
  const doneInPeriod = r.daily.reduce((n, d) => n + d.completed, 0);
  const wip = r.stations.reduce((n, s) => n + s.wip, 0);
  $('#figures').innerHTML = [
    ['Bugün tamamlanan', todayRow?.completed ?? 0],
    [`Son ${r.days} günde tamamlanan`, doneInPeriod],
    ['Ort. üretim süresi', fmt(r.lead.avg_seconds), r.lead.count ? `${r.lead.count} iş emri` : ''],
    ['Ort. ilk istasyona bekleme', fmt(r.queue.avg_seconds), r.queue.count ? `${r.queue.count} iş emri` : ''],
    ['Şu an hatta', wip],
    ['Bekliyor (henüz okutulmadı)', r.open],
  ].map(([k, v, note]) => `<div><dt>${k}</dt><dd>${v}${note ? `<small>${note}</small>` : ''}</dd></div>`).join('');
}

// ---------- İstasyonlar ----------

function renderStations(r) {
  // Darboğaz: ortalama süresi en uzun olan, ölçümü bulunan, son olmayan istasyon
  const measured = r.stations.filter((s) => !s.is_final && s.avg_seconds !== null);
  const maxAvg = Math.max(0, ...measured.map((s) => s.avg_seconds));
  const bottleneck = measured.length > 1 ? measured.find((s) => s.avg_seconds === maxAvg) : null;

  $('#station-rows').innerHTML = r.stations.map((s) => {
    const pct = maxAvg && s.avg_seconds !== null ? (s.avg_seconds / maxAvg) * 100 : 0;
    const name = `${escapeHtml(s.name)}${s.active ? '' : ' <span class="inactive">(pasif)</span>'}`;
    return `
      <tr class="${s === bottleneck ? 'bottleneck' : ''}">
        <td>${name}${s === bottleneck ? '<span class="tag">DARBOĞAZ</span>' : ''}</td>
        <td class="num">${s.passes}</td>
        <td class="num mono">${s.is_final ? '<span class="muted">son istasyon</span>' : fmt(s.avg_seconds)}</td>
        <td class="num mono">${s.is_final ? '—' : fmt(s.max_seconds)}</td>
        <td class="num">${s.is_final ? '—' : s.wip}</td>
        <td>${s.is_final ? '' : `<span class="hbar"><i style="width:${pct.toFixed(1)}%"></i></span>`}</td>
      </tr>`;
  }).join('');
}

// ---------- Günlük grafik ----------

/** Dönemdeki her gün (YYYY-MM-DD), bugün dahil, eskiden yeniye. */
function dayRange(today, count) {
  const end = Date.parse(`${today}T00:00:00Z`);
  return Array.from({ length: count }, (_, i) => new Date(end - (count - 1 - i) * 86400000).toISOString().slice(0, 10));
}

function renderDaily(r) {
  const byDay = new Map(r.daily.map((d) => [d.day, d.completed]));
  const series = dayRange(r.today, r.days).map((day) => ({ day, value: byDay.get(day) ?? 0 }));
  const total = series.reduce((n, d) => n + d.value, 0);
  $('#daily-total').textContent = `Toplam ${total}`;

  const W = 1000, H = 220, padL = 34, padB = 26, padT = 18;
  const max = Math.max(1, ...series.map((d) => d.value));
  const step = (W - padL) / series.length;
  const barW = Math.max(2, step * 0.7);
  const y = (v) => H - padB - (v / max) * (H - padB - padT);
  const labelEvery = Math.ceil(series.length / 15);

  const grid = [0, 0.5, 1].map((f) => {
    const v = Math.round(max * f);
    return `<line class="grid" x1="${padL}" x2="${W}" y1="${y(v)}" y2="${y(v)}"/><text x="${padL - 6}" y="${y(v) + 4}" text-anchor="end">${v}</text>`;
  }).join('');

  const bars = series.map((d, i) => {
    const x = padL + i * step + (step - barW) / 2;
    const [, m, dd] = d.day.split('-');
    const label = i % labelEvery === 0 || i === series.length - 1
      ? `<text x="${x + barW / 2}" y="${H - 8}" text-anchor="middle">${dd}.${m}</text>` : '';
    const val = d.value && series.length <= 31 ? `<text class="val" x="${x + barW / 2}" y="${y(d.value) - 4}" text-anchor="middle">${d.value}</text>` : '';
    return `<rect class="bar ${d.day === r.today ? 'today' : ''}" x="${x.toFixed(1)}" y="${y(d.value).toFixed(1)}" width="${barW.toFixed(1)}" height="${(H - padB - y(d.value)).toFixed(1)}"><title>${dd}.${m}: ${d.value}</title></rect>${val}${label}`;
  }).join('');

  $('#daily-chart').innerHTML = `
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Günlük tamamlanan iş emri">
      ${grid}${bars}
      <line class="axis" x1="${padL}" x2="${W}" y1="${H - padB}" y2="${H - padB}"/>
      ${total ? '' : `<text class="empty" x="${W / 2}" y="${H / 2}" text-anchor="middle">Bu dönemde tamamlanan iş emri yok</text>`}
    </svg>`;
}

// ---------- Yükleme ----------

async function load() {
  document.querySelectorAll('#period button').forEach((b) => b.setAttribute('aria-checked', String(Number(b.dataset.days) === days)));
  const { data } = await api.stationReport(days);
  renderFigures(data);
  renderStations(data);
  renderDaily(data);
  $('#updated-at').textContent = `Güncelleme ${new Date().toLocaleTimeString('tr-TR')}`;
}

$('#period').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-days]');
  if (!btn) return;
  days = Number(btn.dataset.days);
  writeStorage(PERIOD_KEY, String(days));
  load().catch((err) => setConnection(false, err.message));
});

function readStorage(key) {
  try { return localStorage.getItem(key); } catch { return null; }
}
function writeStorage(key, value) {
  try { localStorage.setItem(key, value); } catch { /* yok say */ }
}

(async () => {
  try {
    await load();
    setConnection(true, 'Sunucu bağlı');
  } catch (err) {
    setConnection(false, `Bağlantı yok: ${err.message}`);
  }
})();
