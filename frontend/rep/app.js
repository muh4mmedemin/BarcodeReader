import { ApiClient, ApiError, escapeHtml, statusLabel } from '../shared/api.js';
import config from './config.js';

const api = new ApiClient(config);

const state = { selectedPoId: null };

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

async function loadPoList() {
  const search = $('#po-search').value.trim();
  const { data } = await api.listPos(search);
  $('#po-list').innerHTML = data.length
    ? data.map((po) => `
        <li data-id="${po.id}" class="${po.id === state.selectedPoId ? 'active' : ''}">
          <span class="mono">${escapeHtml(po.po_number)}</span>
          <span class="muted">${po.done_count}/${po.work_order_count}</span>
        </li>`).join('')
    : '<li class="muted">Kayıt yok</li>';
}

$('#po-list').addEventListener('click', (e) => {
  const li = e.target.closest('li[data-id]');
  if (li) selectPo(Number(li.dataset.id));
});

let searchTimer;
$('#po-search').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadPoList, 250);
});

const poForm = $('#po-form');
attachUniqueChecks(poForm);
poForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearErrors(poForm);
  try {
    const { data } = await api.createPo(formData(poForm));
    poForm.reset();
    toast(`${data.po_number} oluşturuldu.`);
    await selectPo(data.id);
  } catch (err) {
    showError(poForm, err);
  }
});

// ---------- PO detayı ve iş emirleri ----------

async function selectPo(id) {
  state.selectedPoId = id;
  const { data: po } = await api.getPo(id);

  const detail = $('#po-detail');
  detail.replaceChildren($('#po-detail-template').content.cloneNode(true));
  $('[data-bind="po_number"]', detail).textContent = po.po_number;
  $('[data-bind="customer"]', detail).textContent = po.customer || '—';

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

  $('[data-action="delete-po"]', detail).addEventListener('click', async () => {
    if (!confirm(`${po.po_number} ve tüm iş emirleri silinsin mi?`)) return;
    await api.deletePo(id);
    state.selectedPoId = null;
    detail.innerHTML = '<p class="muted">PO silindi.</p>';
    await loadPoList();
  });

  await loadPoList();
}

async function refreshSelected() {
  const { data: po } = await api.getPo(state.selectedPoId);
  renderWorkOrders(po.work_orders);
  await loadPoList();
}

function renderWorkOrders(rows) {
  $('#wo-rows').innerHTML = rows.length
    ? rows.map((wo) => `
        <tr data-id="${wo.id}">
          <td class="mono">${escapeHtml(wo.ma_code)}</td>
          <td class="mono">${escapeHtml(wo.barcode)}</td>
          <td>${escapeHtml(wo.description || '')}</td>
          <td>${wo.quantity}</td>
          <td><span class="badge ${wo.status}">${escapeHtml(statusLabel(wo))}</span></td>
          <td><button class="danger" data-action="delete-wo" type="button">Sil</button></td>
        </tr>`).join('')
    : '<tr><td colspan="6" class="muted">Bu PO\'da henüz iş emri yok.</td></tr>';
}

$('#po-detail').addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-action="delete-wo"]');
  if (!btn) return;
  const row = btn.closest('tr');
  if (!confirm('İş emri silinsin mi?')) return;
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
    $('#server-status').textContent = 'Bağlı';
    await loadPoList();
  } catch (err) {
    $('#server-status').textContent = `Bağlantı yok: ${err.message}`;
  }
})();
