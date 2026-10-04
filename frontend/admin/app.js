import { escapeHtml, statusLabel } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { historyTable, localTime } from '../shared/history.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';

// Yalnızca yönetici: iş emirlerini istediği istasyona, Bekliyor'a veya İptal'e taşır.
const session = requireSession(['admin']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();

const $ = (sel) => document.querySelector(sel);

let workOrders = [];
let stations = [];
let filter = '';
let openId = null;

let toastTimer;
function toast(message) {
  const el = $('#toast');
  el.textContent = message;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2500);
}

// ---------- Liste ----------

function matches(wo, q) {
  if (filter && wo.status !== filter) return false;
  if (!q) return true;
  return [wo.po_number, wo.customer, wo.ma_code, wo.barcode].some((v) => String(v ?? '').toLowerCase().includes(q));
}

function render() {
  const q = $('#search').value.trim().toLowerCase();
  const list = workOrders.filter((wo) => matches(wo, q));
  $('#count').textContent = list.length === workOrders.length ? String(list.length) : `${list.length}/${workOrders.length}`;
  $('#filter').querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.status === filter)));

  $('#rows').innerHTML = list.length ? list.map((wo) => `
    <tr class="wo ${wo.id === openId ? 'wo-open' : ''}" data-id="${wo.id}">
      <td class="mono">${escapeHtml(wo.po_number)}</td>
      <td class="mono">${escapeHtml(wo.ma_code)}</td>
      <td class="mono">${escapeHtml(wo.barcode)}</td>
      <td>${escapeHtml(wo.description || '—')}</td>
      <td class="num">${wo.quantity}</td>
      <td><span class="state ${wo.status}">${escapeHtml(statusLabel(wo))}</span></td>
      <td class="mono">${escapeHtml(localTime(wo.last_scan_at))}</td>
    </tr>`).join('') : '<tr><td colspan="7" class="empty muted">Eşleşen iş emri yok.</td></tr>';

  const row = openId && $(`#rows tr[data-id="${openId}"]`);
  if (row) openDetail(row, workOrders.find((w) => w.id === openId));
}

// ---------- Taşıma paneli ----------

function targetsHtml(wo) {
  const btn = (attrs, label, sub, current, cls = '') => `
    <button type="button" ${attrs} class="${cls} ${current ? 'current' : ''}" ${current ? 'disabled' : ''}>
      <small>${escapeHtml(sub)}</small>${escapeHtml(label)}
    </button>`;
  return [
    btn('data-status="open"', 'Bekliyor', 'KAYIT', wo.status === 'open'),
    ...stations.map((s, i) => btn(
      `data-station="${s.id}"`, s.name, `${String(i + 1).padStart(2, '0')}${s.is_final ? ' · SON' : ''}`,
      wo.current_station_id === s.id && wo.status !== 'cancelled' && wo.status !== 'open',
    )),
    btn('data-status="cancelled"', 'İptal', 'İPTAL', wo.status === 'cancelled', 't-cancel'),
  ].join('');
}

async function openDetail(tr, wo) {
  document.querySelectorAll('tr.wo-detail').forEach((r) => r.remove());
  document.querySelectorAll('tr.wo-open').forEach((r) => r.classList.remove('wo-open'));
  openId = wo.id;
  tr.classList.add('wo-open');

  const detail = document.createElement('tr');
  detail.className = 'wo-detail';
  detail.innerHTML = `
    <td colspan="${tr.cells.length}">
      <div class="move">
        <div class="move-title">Taşı · <span class="mono">${escapeHtml(wo.ma_code)}</span></div>
        <div class="targets">${targetsHtml(wo)}</div>
        <div class="field-error" data-error></div>
      </div>
      <div data-history><div class="hist muted">Yükleniyor…</div></div>
    </td>`;
  tr.after(detail);

  detail.querySelector('.targets').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    const target = b.dataset.station ? { station_id: Number(b.dataset.station) } : { status: b.dataset.status };
    move(wo, target, detail);
  });

  try {
    const { data } = await api.workOrderHistory(wo.id);
    if (detail.isConnected) detail.querySelector('[data-history]').innerHTML = historyTable(wo, data);
  } catch (err) {
    if (detail.isConnected) detail.querySelector('[data-history]').innerHTML = `<div class="hist field-error">${escapeHtml(err.message)}</div>`;
  }
}

function closeDetail() {
  openId = null;
  document.querySelectorAll('tr.wo-detail').forEach((r) => r.remove());
  document.querySelectorAll('tr.wo-open').forEach((r) => r.classList.remove('wo-open'));
}

async function move(wo, target, detail) {
  detail.querySelectorAll('.targets button').forEach((b) => { b.disabled = true; });
  try {
    const { data } = await api.moveWorkOrder(wo.id, target);
    Object.assign(wo, data, { last_scan_at: new Date().toISOString().slice(0, 19).replace('T', ' ') });
    toast(`${wo.ma_code} → ${statusLabel(wo)}`);
    render();
  } catch (err) {
    detail.querySelector('[data-error]').textContent = err.message;
    detail.querySelector('.targets').innerHTML = targetsHtml(wo);
  }
}

$('#rows').addEventListener('click', (e) => {
  const tr = e.target.closest('tr.wo');
  if (!tr) return;
  const id = Number(tr.dataset.id);
  if (id === openId) closeDetail();
  else openDetail(tr, workOrders.find((w) => w.id === id));
});

$('#filter').addEventListener('click', (e) => {
  const b = e.target.closest('button[data-status]');
  if (!b) return;
  filter = b.dataset.status;
  render();
});

$('#search').addEventListener('input', render);
$('#refresh').addEventListener('click', load);

// ---------- Yükleme ----------

async function load() {
  try {
    const [{ data: pos }, { data: st }] = await Promise.all([api.overview(), api.stations()]);
    stations = st;
    workOrders = pos.flatMap((po) => po.work_orders);
    render();
    setConnection(true, 'Sunucu bağlı');
  } catch (err) {
    setConnection(false, `Bağlantı yok: ${err.message}`);
  }
}

load();
