import { escapeHtml, statusLabel } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { groupByLocation, groupColor, percent } from '../shared/distribution.js';
import { dueStatus, isComplete } from '../shared/due.js';
import { closeHistory, isHistoryOpen, localTime, openHistory } from '../shared/history.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';

// Sadece müşteri temsilcisi
const session = requireSession(['rep']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();

const state = { pos: [], stations: [] };
const $ = (sel) => document.querySelector(sel);

// ---------- Yardımcılar ----------

/** SVG pasta grafik. Dilimler arasında ince çizgi; üzerine gelince dilim adı ve oranı. */
function pieSvg(groups, total, size) {
  const r = size / 2;
  const open = `<svg class="pie" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" role="img" aria-label="Konum dağılımı">`;
  if (!total) return `${open}<circle class="empty" cx="${r}" cy="${r}" r="${r - 1}"><title>İş emri yok</title></circle></svg>`;

  const slices = groups.filter((g) => g.count);
  const title = (g) => `<title>${escapeHtml(g.label)}: ${g.count} (${percent(g.count, total)})</title>`;
  if (slices.length === 1) {
    const g = slices[0];
    return `${open}<circle cx="${r}" cy="${r}" r="${r - 1}" style="fill:${groupColor(g)}">${title(g)}</circle></svg>`;
  }

  let angle = -Math.PI / 2;
  const point = (a) => `${(r + (r - 1) * Math.cos(a)).toFixed(2)},${(r + (r - 1) * Math.sin(a)).toFixed(2)}`;
  const paths = slices.map((g) => {
    const next = angle + (g.count / total) * Math.PI * 2;
    const large = next - angle > Math.PI ? 1 : 0;
    const d = `M${r},${r} L${point(angle)} A${r - 1},${r - 1} 0 ${large} 1 ${point(next)} Z`;
    angle = next;
    return `<path d="${d}" style="fill:${groupColor(g)}">${title(g)}</path>`;
  }).join('');
  return `${open}${paths}</svg>`;
}

function legend(groups, total) {
  return `
    <table class="legend">
      <thead><tr><th>Konum</th><th class="num">İE</th><th class="num">%</th></tr></thead>
      <tbody>${groups.map((g) => `
        <tr class="${g.count ? '' : 'zero'}">
          <td><span class="sw" style="background:${groupColor(g)}"></span>${escapeHtml(g.label)}</td>
          <td class="num">${g.count}</td>
          <td class="num">${percent(g.count, total)}</td>
        </tr>`).join('')}
      </tbody>
    </table>`;
}

function pieBlock(workOrders, size) {
  const groups = groupByLocation(workOrders, state.stations);
  return `${pieSvg(groups, workOrders.length, size)}${legend(groups, workOrders.length)}`;
}

// ---------- Genel durum ----------

function renderSummary() {
  const all = state.pos.flatMap((po) => po.work_orders);
  const done = all.filter((wo) => wo.status === 'done').length;
  const completePos = state.pos.filter((po) => isComplete(po.work_orders)).length;
  const dues = state.pos.map((po) => dueStatus(po.due_date, isComplete(po.work_orders)).kind);

  $('#overall-pie').innerHTML = pieBlock(all, 190);
  $('#figures').innerHTML = [
    ['PO', state.pos.length],
    ['Tamamlanan PO', completePos],
    ['Geciken PO', dues.filter((k) => k === 'late').length],
    ['Termini yaklaşan', dues.filter((k) => k === 'soon').length],
    ['İş emri', all.length],
    ['Hatta', all.filter((wo) => wo.status === 'in_progress').length],
    ['Tamamlanan İE', done],
    ['Tamamlanma', percent(done, all.length)],
  ].map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('');
  $('#updated-at').textContent = `Güncelleme ${new Date().toLocaleTimeString('tr-TR')}`;
}

// ---------- PO kartları ----------

function matches(po, q) {
  if (!q) return true;
  const hay = [po.po_number, po.customer, ...po.work_orders.flatMap((wo) => [wo.ma_code, wo.barcode])]
    .filter(Boolean).join(' ').toLocaleLowerCase('tr-TR');
  return hay.includes(q);
}

function poCard(po) {
  const wos = po.work_orders;
  const done = wos.filter((wo) => wo.status === 'done').length;
  const complete = isComplete(wos);
  const due = dueStatus(po.due_date, complete);
  const rows = wos.length
    ? wos.map((wo) => `
        <tr data-wo="${wo.id}">
          <td class="mono">${escapeHtml(wo.ma_code)}</td>
          <td class="mono">${escapeHtml(wo.barcode)}</td>
          <td>${escapeHtml(wo.description || '')}</td>
          <td class="num">${wo.quantity}</td>
          <td><span class="state ${wo.status}">${escapeHtml(statusLabel(wo))}</span></td>
          <td class="mono">${escapeHtml(localTime(wo.last_scan_at))}</td>
          <td class="mono">${escapeHtml(localTime(wo.created_at))}</td>
        </tr>`).join('')
    : '<tr><td colspan="7" class="empty">Bu PO\'da iş emri yok.</td></tr>';

  return `
    <section class="pane po-card due-${due.kind}">
      <div class="pane-head">
        <div class="po-title">
          <span class="po-no">${escapeHtml(po.po_number)}</span>
          <span class="meta">Müşteri: <b>${escapeHtml(po.customer || '—')}</b></span>
          <span class="meta">Kayıt: <b class="mono">${escapeHtml(localTime(po.created_at))}</b></span>
          <span class="meta">İş emri: <b>${done}/${wos.length}</b></span>
          <span class="meta" title="${escapeHtml(due.title)}">Termin: <b class="due ${due.kind}">${escapeHtml(due.kind === 'late' || due.kind === 'soon' ? `${due.title.replace('Termin ', '')} · ${due.label}` : due.label)}</b></span>
        </div>
        <span class="po-tools">
          <span class="po-done ${complete ? 'complete' : ''}" title="Tamamlanma">${percent(done, wos.length)}</span>
          <button type="button" class="xls" data-export="${po.id}">Excel'e aktar</button>
        </span>
      </div>
      <div class="po-body">
        <div class="pie-block">${pieBlock(wos, 120)}</div>
        <div class="wo-wrap">
          <table>
            <thead>
              <tr>
                <th>MA kodu</th><th>Barkod</th><th>Açıklama</th><th class="num">Adet</th>
                <th>Durum</th><th>Son hareket</th><th>Kayıt</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>
    </section>`;
}

function renderCards() {
  const q = $('#search').value.trim().toLocaleLowerCase('tr-TR');
  const hideDone = $('#hide-done').checked;
  const onlyRisk = $('#only-risk').checked;
  const list = state.pos.filter((po) => {
    const complete = isComplete(po.work_orders);
    const kind = dueStatus(po.due_date, complete).kind;
    return matches(po, q) && !(hideDone && complete) && !(onlyRisk && kind !== 'late' && kind !== 'soon');
  });

  $('#po-cards').innerHTML = list.length
    ? list.map(poCard).join('')
    : `<div class="pane empty-list">${state.pos.length ? 'Eşleşen PO yok.' : 'Henüz PO yok.'}</div>`;
}

// ---------- Yükleme ----------

async function load() {
  const [{ data: pos }, { data: stations }] = await Promise.all([api.overview(), api.stations()]);
  state.pos = pos;
  state.stations = stations;
  renderSummary();
  renderCards();
}

let searchTimer;
$('#search').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(renderCards, 150);
});
$('#hide-done').addEventListener('change', renderCards);
$('#only-risk').addEventListener('change', renderCards);

// Excel'e aktar butonu; iş emri satırına tıklamak istasyon geçmişini açar / kapatır
$('#po-cards').addEventListener('click', async (e) => {
  const btn = e.target.closest('button[data-export]');
  if (btn) {
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = 'Hazırlanıyor…';
    try {
      await api.exportPo(Number(btn.dataset.export));
    } catch (err) {
      setConnection(false, err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = label;
    }
    return;
  }
  const tr = e.target.closest('tr[data-wo]');
  if (!tr) return;
  if (isHistoryOpen(tr)) return closeHistory();
  const id = Number(tr.dataset.wo);
  const wo = state.pos.flatMap((po) => po.work_orders).find((w) => w.id === id);
  if (wo) openHistory(api, tr, wo);
});
$('#refresh').addEventListener('click', () => load().catch((err) => setConnection(false, err.message)));

(async () => {
  try {
    await load();
    setConnection(true, 'Sunucu bağlı');
  } catch (err) {
    setConnection(false, `Bağlantı yok: ${err.message}`);
  }
})();
