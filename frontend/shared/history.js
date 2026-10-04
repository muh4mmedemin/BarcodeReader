// İş emri istasyon geçmişi: satıra tıklayınca altında açılan tablo.
// PO Oluşturma ve PO Takip sayfaları ortak kullanır. Sayfada aynı anda tek geçmiş açık olur.
import { escapeHtml, STATUS_LABELS } from './api.js';

const utcDate = (s) => new Date(String(s).replace(' ', 'T') + 'Z');

/** Sunucu zamanları UTC ("2026-10-01 10:00:00"); yerel saate çevrilir. */
export function localTime(utc) {
  if (!utc) return '—';
  const d = utcDate(utc);
  return Number.isNaN(d.getTime()) ? utc : d.toLocaleString('tr-TR', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

/** "2 g 3 sa", "1 sa 20 dk", "45 dk", "<1 dk" */
export function duration(ms) {
  const min = Math.floor(ms / 60000);
  if (min < 1) return '<1 dk';
  const d = Math.floor(min / 1440);
  const h = Math.floor((min % 1440) / 60);
  const m = min % 60;
  if (d) return `${d} g ${h} sa`;
  if (h) return `${h} sa ${m} dk`;
  return `${m} dk`;
}

/** Kayıt + her okutma; her satırda o konumda geçen süre. */
export function historyTable(wo, scans) {
  const events = [
    { at: wo.created_at, place: 'Kayıt (bekliyor)', user: '—', status: 'open' },
    ...scans.map((s) => ({
      at: s.scanned_at,
      // Yönetici Bekliyor / İptal'e taşıdıysa istasyon yoktur
      place: s.station_name ?? STATUS_LABELS[s.status] ?? '—',
      user: `${s.username ?? '—'}${s.kind === 'move' ? ' · taşıma' : ''}`,
      status: s.status ?? (s.is_final ? 'done' : 'in_progress'),
    })),
  ];
  const rows = events.map((ev, i) => {
    const next = events[i + 1];
    let spent = '—';
    if (next) spent = duration(utcDate(next.at) - utcDate(ev.at));
    else if (wo.status !== 'done' && wo.status !== 'cancelled') spent = `${duration(Date.now() - utcDate(ev.at))} (sürüyor)`;
    return `
      <tr>
        <td class="num idx">${i}</td>
        <td class="mono">${escapeHtml(localTime(ev.at))}</td>
        <td><span class="state ${ev.status}">${escapeHtml(ev.place)}</span></td>
        <td>${escapeHtml(ev.user)}</td>
        <td class="num mono">${spent}</td>
      </tr>`;
  }).join('');
  return `
    <div class="hist">
      <div class="hist-title">İstasyon geçmişi · <span class="mono">${escapeHtml(wo.ma_code)}</span></div>
      <table>
        <thead><tr><th class="num">#</th><th>Tarih</th><th>İstasyon</th><th>Kullanıcı</th><th class="num">Geçen süre</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

export function isHistoryOpen(tr) {
  return tr.classList.contains('wo-open');
}

export function closeHistory() {
  document.querySelectorAll('tr.wo-history').forEach((r) => r.remove());
  document.querySelectorAll('tr.wo-open').forEach((r) => r.classList.remove('wo-open'));
}

/** Satırın altına geçmişi açar. tr: iş emri satırı, wo: iş emri nesnesi. */
export async function openHistory(api, tr, wo) {
  closeHistory();
  tr.classList.add('wo-open');

  const detail = document.createElement('tr');
  detail.className = 'wo-history';
  detail.innerHTML = `<td colspan="${tr.cells.length}"><div class="hist muted">Yükleniyor…</div></td>`;
  tr.after(detail);
  try {
    const { data } = await api.workOrderHistory(wo.id);
    if (detail.isConnected) detail.firstElementChild.innerHTML = historyTable(wo, data);
  } catch (err) {
    if (detail.isConnected) detail.firstElementChild.innerHTML = `<div class="hist field-error">${escapeHtml(err.message)}</div>`;
  }
}
