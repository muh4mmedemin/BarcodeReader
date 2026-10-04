import { ApiError, escapeHtml, statusLabel } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { groupByLocation, percent } from '../shared/distribution.js';
import { dueStatus, isComplete } from '../shared/due.js';
import { closeHistory, isHistoryOpen, localTime, openHistory } from '../shared/history.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';

const session = requireSession(['rep']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();

const state = { selectedPoId: null, stations: [], workOrders: [], openWoId: null };

const $ = (sel, root = document) => root.querySelector(sel);

// ---------- Yardımcılar ----------

let toastTimer;
function toast(message) {
  const el = $('#toast');
  el.textContent = message;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}

function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function setFieldError(form, field, message) {
  const input = form.elements[field];
  const box = form.querySelector(`[data-error-for="${field}"]`);
  input?.classList.toggle('invalid', Boolean(message));
  if (box) box.textContent = message || '';
}

function clearErrors(form) {
  form.querySelectorAll('[data-error-for]').forEach((el) => setFieldError(form, el.dataset.errorFor, ''));
}

/** API hatasını ilgili alanın altına yazar; alan yoksa toast gösterir. */
function showError(form, err) {
  if (err instanceof ApiError && err.field && form?.querySelector(`[data-error-for="${err.field}"]`)) {
    setFieldError(form, err.field, err.message);
    form.elements[err.field]?.focus();
  } else {
    toast(err.message);
  }
}

/** data-unique alanlarında, alan terk edilince benzersizliği anlık kontrol eder. */
function attachUniqueChecks(form) {
  form.querySelectorAll('[data-unique]').forEach((input) => {
    input.addEventListener('blur', async () => {
      const value = input.value.trim();
      if (!value) return;
      try {
        const { data } = await api.check(input.dataset.unique, value);
        setFieldError(form, input.name, data.available ? '' : `"${value}" zaten kullanılıyor.`);
      } catch { /* anlık kontrol başarısızsa kayıt sırasında sunucu yine kontrol eder */ }
    });
    input.addEventListener('input', () => setFieldError(form, input.name, ''));
  });
}

// ---------- PO listesi ----------

state.pos = [];
state.poFilter = 'all';

/** PO'nun liste özeti: tamamlanma, termin durumu, filtre sınıfı. */
function poInfo(po) {
  const total = po.work_order_count;
  const complete = total > 0 && po.done_count === total;
  return { total, complete, due: dueStatus(po.due_date, complete) };
}

const FILTERS = {
  all: () => true,
  open: (i) => !i.complete,
  late: (i) => i.due.kind === 'late' || i.due.kind === 'soon',
  done: (i) => i.complete,
};

function renderFilters() {
  const infos = state.pos.map(poInfo);
  document.querySelectorAll('#po-filters button').forEach((btn) => {
    const key = btn.dataset.filter;
    btn.setAttribute('aria-checked', String(key === state.poFilter));
    btn.querySelector('b').textContent = infos.filter(FILTERS[key]).length;
  });
}

function poItem(po) {
  const { total, complete, due } = poInfo(po);
  const open = total - po.done_count - po.in_progress_count;
  const w = (n) => (total ? ((n / total) * 100).toFixed(1) : 0);
  const dueText = due.kind === 'late' || due.kind === 'soon' ? due.label : due.kind === 'none' ? '' : `Termin ${due.label}`;
  const cls = ['po-item', `due-${due.kind}`, po.id === state.selectedPoId ? 'active' : '', complete ? 'complete' : ''].join(' ');
  return `
    <li data-id="${po.id}" class="${cls}" tabindex="0">
      <div class="li-top">
        <span class="li-no mono">${escapeHtml(po.po_number)}</span>
        <span class="li-pct mono">${percent(po.done_count, total)}</span>
      </div>
      <div class="li-mid">
        <span class="li-cust">${escapeHtml(po.customer || '—')}</span>
        ${dueText ? `<span class="li-due" title="${escapeHtml(due.title)}">${escapeHtml(dueText)}</span>` : ''}
      </div>
      <div class="li-bot">
        <span class="li-bar" title="${po.done_count} tamamlandı · ${po.in_progress_count} hatta · ${open} bekliyor">
          <i class="b-done" style="width:${w(po.done_count)}%"></i><i class="b-prog" style="width:${w(po.in_progress_count)}%"></i>
        </span>
        <span class="li-count mono">${total ? `${po.done_count}/${total}` : 'İE yok'}</span>
      </div>
    </li>`;
}

function renderPoList() {
  renderFilters();
  const search = $('#po-search').value.trim();
  const list = state.pos.filter((po) => FILTERS[state.poFilter](poInfo(po)));
  $('#po-list').innerHTML = list.length
    ? list.map(poItem).join('')
    : `<li class="ledger-empty">${search || state.poFilter !== 'all' ? 'Eşleşen PO yok.' : 'Henüz PO yok.'}</li>`;
}

async function loadPoList() {
  const search = $('#po-search').value.trim();
  const { data, meta } = await api.listPos(search, 200);
  state.pos = data;
  $('#po-total').textContent = data.length < meta.total ? `${data.length}/${meta.total}` : String(meta.total);
  renderPoList();
  if (!meta.total && !search) toggleNewPo(true);
}

$('#po-list').addEventListener('click', (e) => {
  const item = e.target.closest('li[data-id]');
  if (item) selectPo(Number(item.dataset.id));
});
$('#po-list').addEventListener('keydown', (e) => {
  const item = e.target.closest('li[data-id]');
  if (item && (e.key === 'Enter' || e.key === ' ')) {
    e.preventDefault();
    selectPo(Number(item.dataset.id));
  }
});

$('#po-filters').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-filter]');
  if (!btn) return;
  state.poFilter = btn.dataset.filter;
  renderPoList();
});

let searchTimer;
$('#po-search').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadPoList, 250);
});

// ---------- Yeni PO (açılır form) ----------

const poForm = $('#po-form');
const newPoToggle = $('#new-po-toggle');

function toggleNewPo(open) {
  poForm.hidden = !open;
  newPoToggle.setAttribute('aria-expanded', String(open));
  if (open) poForm.elements.po_number.focus();
  else { poForm.reset(); clearErrors(poForm); }
}

newPoToggle.addEventListener('click', () => toggleNewPo(poForm.hidden));
$('#new-po-cancel').addEventListener('click', () => toggleNewPo(false));
poForm.addEventListener('keydown', (e) => { if (e.key === 'Escape') toggleNewPo(false); });

// N: yeni PO formunu aç (yazı alanında değilken)
document.addEventListener('keydown', (e) => {
  if (e.key.toLowerCase() !== 'n' || e.ctrlKey || e.metaKey || e.altKey) return;
  if (e.target.closest('input, textarea, select, [contenteditable]')) return;
  e.preventDefault();
  toggleNewPo(true);
});

attachUniqueChecks(poForm);
poForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearErrors(poForm);
  try {
    const { data } = await api.createPo(formData(poForm));
    toggleNewPo(false);
    toast(`${data.po_number} kaydedildi.`);
    await selectPo(data.id);
  } catch (err) {
    showError(poForm, err);
  }
});

// ---------- PO detayı ve iş emirleri ----------

async function selectPo(id) {
  if (state.selectedPoId !== id) state.openWoId = null;
  state.selectedPoId = id;
  const { data: po } = await api.getPo(id);

  const detail = $('#po-detail');
  detail.replaceChildren($('#po-detail-template').content.cloneNode(true));
  $('[data-bind="po_number"]', detail).textContent = po.po_number;
  $('[data-bind="customer"]', detail).textContent = po.customer || '—';
  $('[data-bind="created_at"]', detail).textContent = localTime(po.created_at);

  // Termin tarihi: değiştirilince hemen kaydedilir
  state.dueDate = po.due_date;
  const dueInput = $('#due-input', detail);
  dueInput.value = po.due_date ?? '';
  dueInput.addEventListener('change', async () => {
    try {
      const { data } = await api.updatePo(id, { due_date: dueInput.value || null });
      state.dueDate = data.due_date;
      renderDue();
      await loadPoList();
    } catch (err) {
      dueInput.value = state.dueDate ?? '';
      toast(err.message);
    }
  });

  renderWorkOrders(po.work_orders);

  const woForm = $('#wo-form', detail);
  attachUniqueChecks(woForm);
  woForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(woForm);
    try {
      await api.createWorkOrder(id, formData(woForm));
      woForm.reset();
      woForm.elements.ma_code.focus();
      await refreshSelected();
    } catch (err) {
      showError(woForm, err);
    }
  });

  $('[data-action="export-po"]', detail).addEventListener('click', (e) => exportPo(e.currentTarget, id));

  $('[data-action="delete-po"]', detail).addEventListener('click', async () => {
    if (!confirm(`${po.po_number} ve bağlı tüm iş emirleri kalıcı olarak silinsin mi?`)) return;
    await api.deletePo(id);
    state.selectedPoId = null;
    detail.innerHTML = '<div class="pane-head">İş emirleri</div>';
    await loadPoList();
  });

  await loadPoList();
}

/** PO'yu Excel şablonuna doldurup indirir; hazırlanırken buton kilitlenir. */
async function exportPo(button, id) {
  const label = button.textContent;
  button.disabled = true;
  button.textContent = 'Hazırlanıyor…';
  try {
    const name = await api.exportPo(id);
    toast(`${name} indirildi.`);
  } catch (err) {
    toast(err.message);
  } finally {
    button.disabled = false;
    button.textContent = label;
  }
}

async function refreshSelected() {
  const { data: po } = await api.getPo(state.selectedPoId);
  renderWorkOrders(po.work_orders);
  await loadPoList();
}

/** Durum sayıları: Toplam / Bekliyor / Hatta / Tamamlandı */
function renderCounts(rows) {
  const count = (status) => rows.filter((wo) => wo.status === status).length;
  const items = [
    ['', 'Toplam', rows.length],
    ['open', 'Bekliyor', count('open')],
    ['in_progress', 'Hatta', count('in_progress')],
    ['done', 'Tamamlandı', count('done')],
  ];
  $('#kpis').innerHTML = items.map(([status, label, value]) => `
    <div>
      <div class="c-label">${status ? `<span class="state ${status}"></span>` : ''}${label}</div>
      <div class="c-value">${value}</div>
    </div>`).join('');
}

/** Konum dağılımı: iş emirlerinin kaçı nerede; adet ve iş emri sayısına göre yüzde. */
function renderDistribution(rows) {
  const total = rows.length;
  $('#dist').innerHTML = groupByLocation(rows, state.stations).map((g) => {
    const pct = total ? (g.count / total) * 100 : 0;
    return `
      <tr class="${g.count ? '' : 'zero'}">
        <td><span class="state ${g.status}">${escapeHtml(g.label)}</span></td>
        <td class="num">${g.count}</td>
        <td class="num">${percent(g.count, total)}</td>
        <td><span class="dist-bar ${g.status}"><i style="width:${pct.toFixed(1)}%"></i></span></td>
      </tr>`;
  }).join('');
}

function toggleHistory(tr) {
  if (isHistoryOpen(tr)) {
    closeHistory();
    state.openWoId = null;
    return;
  }
  const wo = state.workOrders.find((w) => w.id === Number(tr.dataset.id));
  if (!wo) return;
  state.openWoId = wo.id;
  openHistory(api, tr, wo);
}

/** Hat konumu: her hücre bir istasyon, dolu hücre iş emrinin şu anki istasyonu. */
function lineCells(wo) {
  if (!state.stations.length || wo.current_station_id === null) return '<span class="muted">—</span>';
  const cells = state.stations.map((s) =>
    `<i class="${s.id === wo.current_station_id ? 'at' : ''}" title="${escapeHtml(s.name)}"></i>`).join('');
  return `<span class="loc ${wo.status === 'done' ? 'done' : ''}">${cells}</span>`;
}

function renderDue() {
  const tag = $('#due-tag');
  if (!tag) return;
  const due = dueStatus(state.dueDate, isComplete(state.workOrders));
  tag.className = `due-tag ${due.kind}`;
  tag.textContent = due.kind === 'late' || due.kind === 'soon' ? due.label : '';
}

function renderWorkOrders(rows) {
  state.workOrders = rows;
  renderDue();
  renderCounts(rows);
  renderDistribution(rows);
  $('#wo-rows').innerHTML = rows.length
    ? rows.map((wo, i) => `
        <tr data-id="${wo.id}">
          <td class="idx num">${i + 1}</td>
          <td class="mono">${escapeHtml(wo.ma_code)}</td>
          <td class="mono">${escapeHtml(wo.barcode)}</td>
          <td>${escapeHtml(wo.description || '')}</td>
          <td class="num">${wo.quantity}</td>
          <td><span class="state ${wo.status}">${escapeHtml(statusLabel(wo))}</span></td>
          <td>${lineCells(wo)}</td>
          <td class="act"><button class="danger" data-action="delete-wo" type="button">Sil</button></td>
        </tr>`).join('')
    : '<tr class="empty"><td colspan="8">Bu PO\'da henüz iş emri yok.</td></tr>';

  // Yenilemeden önce açık olan geçmiş satırını tekrar aç
  const open = state.openWoId && $(`#wo-rows tr[data-id="${state.openWoId}"]`);
  const openWo = open && state.workOrders.find((w) => w.id === state.openWoId);
  if (openWo) openHistory(api, open, openWo);
  else state.openWoId = null;
}

$('#po-detail').addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-action="delete-wo"]');
  if (!btn) {
    // İş emri satırına tıklamak istasyon geçmişini açar / kapatır
    const tr = e.target.closest('#wo-rows tr[data-id]');
    if (tr) toggleHistory(tr);
    return;
  }
  const row = btn.closest('tr');
  if (!confirm('İş emri kalıcı olarak silinsin mi?')) return;
  try {
    await api.deleteWorkOrder(row.dataset.id);
    await refreshSelected();
  } catch (err) {
    toast(err.message);
  }
});

// ---------- Başlangıç ----------

(async () => {
  try {
    await api.health();
    setConnection(true, 'Sunucu bağlı');
    state.stations = (await api.stations()).data;
    await loadPoList();
  } catch (err) {
    setConnection(false, `Bağlantı yok: ${err.message}`);
  }
})();
