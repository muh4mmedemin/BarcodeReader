import { escapeHtml } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { dueStatus, todayLocal } from '../shared/due.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';

// Pano hesabı yalnızca bu sayfayı görür; temsilci de açabilir.
const session = requireSession(['board', 'rep']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();
if (session.user.role === 'board') document.getElementById('tb-menu').hidden = true;

const CHECK_MS = 3_000;   // değişiklik kontrolü (küçük istek)
const FULL_MS = 60_000;   // değişiklik görünmese de tam yenileme (güvenlik payı)
const $ = (sel) => document.querySelector(sel);

// ---------- Saat ----------

function tick() {
  const now = new Date();
  $('#clock').textContent = now.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
  $('#date').textContent = now.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
tick();
setInterval(tick, 5_000);

// ---------- Tam ekran ----------

$('#tb-fullscreen').addEventListener('click', (e) => {
  e.preventDefault();
  if (document.fullscreenElement) document.exitFullscreen?.();
  else document.documentElement.requestFullscreen?.().catch(() => {});
});

// ---------- Çizim ----------

const time = (utc) => new Date(String(utc).replace(' ', 'T') + 'Z')
  .toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });

/** Önceki çizimdeki değerler: değişenler vurgulanır. İlk çizimde vurgu yok. */
let prev = null;

function render(b) {
  const first = prev === null;
  const next = { kpi: {}, st: {}, recent: new Set() };
  const changed = (map, key, value) => {
    next[map][key] = value;
    return !first && prev[map][key] !== value;
  };

  const wip = b.stations.reduce((n, s) => n + s.wip, 0);
  $('#kpis').innerHTML = [
    ['', 'Bekliyor', b.open],
    ['warn', 'Hatta', wip],
    ['ok', 'Bugün tamamlanan', b.done_today],
    ['', 'Bugün okutma', b.scans_today],
    [b.late.length ? 'alarm' : '', 'Geciken PO', b.late.length],
  ].map(([cls, k, v]) => `<div class="${cls} ${changed('kpi', k, v) ? 'bump' : ''}"><dt>${k}</dt><dd>${v}</dd></div>`).join('');

  $('#stations').innerHTML = b.stations.map((s) => {
    const value = s.is_final ? b.done_today : s.wip;
    const bump = changed('st', s.id, value) ? 'bump' : '';
    if (s.is_final) {
      return `
        <div class="st final ${bump}">
          <div class="st-name">${escapeHtml(s.name)}<small>son istasyon</small></div>
          <div class="st-count">${value}</div>
          <div class="st-note">bugün tamamlanan</div>
        </div>`;
    }
    return `
      <div class="st ${s.wip ? 'busy' : 'idle'} ${bump}">
        <div class="st-name">${escapeHtml(s.name)}${s.active ? '' : '<small>pasif</small>'}</div>
        <div class="st-count">${value}</div>
        <div class="st-note">iş emri</div>
      </div>`;
  }).join('');

  $('#recent').innerHTML = b.recent.length
    ? b.recent.map((r) => {
        const key = `${r.scanned_at}|${r.ma_code}|${r.station_name}`;
        next.recent.add(key);
        const fresh = !first && !prev.recent.has(key);
        return `
        <tr class="${fresh ? 'fresh' : ''}">
          <td class="mono">${time(r.scanned_at)}</td>
          <td class="mono">${escapeHtml(r.ma_code)}</td>
          <td class="mono">${escapeHtml(r.po_number)}</td>
          <td><span class="state ${r.is_final ? 'done' : 'in_progress'}">${escapeHtml(r.station_name ?? '—')}</span></td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="4" class="empty">Henüz okutma yok.</td></tr>';

  // Geciken PO'lar önce, sonra termini yaklaşanlar
  const today = todayLocal();
  const due = [...b.late, ...b.due_soon];
  $('#due').innerHTML = due.length
    ? due.map((p) => {
        const st = dueStatus(p.due_date, false, today);
        return `
          <tr class="${st.kind}">
            <td class="mono">${escapeHtml(p.po_number)}</td>
            <td>${escapeHtml(p.customer || '—')}</td>
            <td>${escapeHtml(st.label)}</td>
            <td class="num">${p.finished}/${p.total}</td>
          </tr>`;
      }).join('')
    : '<tr><td colspan="4" class="empty">Geciken veya termini yaklaşan PO yok.</td></tr>';

  prev = next;
}

// ---------- Canlı güncelleme ----------
// Sık aralıkla yalnızca sürüm sorulur; sürüm değişince tüm veri çekilip çizilir.
// Sekme/ekran gizliyken sorgu durur, görünür olunca hemen kontrol edilir.

let version = null;
let lastFull = 0;
let timer = null;
let busy = false;

async function loadBoard() {
  const { data } = await api.board();
  const changed = data.version !== version;
  version = data.version;
  lastFull = Date.now();
  render(data);
  // "Son değişiklik": verinin gerçekten değiştiği an (tam yenilemelerde değişmez)
  if (changed) $('#sb-updated').textContent = new Date().toLocaleTimeString('tr-TR');
}

async function check() {
  if (busy) return;
  busy = true;
  try {
    if (version === null || Date.now() - lastFull > FULL_MS) {
      await loadBoard();
    } else {
      const { data } = await api.boardVersion();
      if (data.version !== version) await loadBoard();
    }
    setConnection(true, 'Canlı');
  } catch (err) {
    // Bağlantı koparsa son veri ekranda kalır; durum çubuğu kırmızı olur
    setConnection(false, `Bağlantı yok: ${err.message}`);
  } finally {
    busy = false;
    schedule();
  }
}

function schedule() {
  clearTimeout(timer);
  if (!document.hidden) timer = setTimeout(check, CHECK_MS);
}

document.addEventListener('visibilitychange', () => {
  clearTimeout(timer);
  if (!document.hidden) check();
});

check();
