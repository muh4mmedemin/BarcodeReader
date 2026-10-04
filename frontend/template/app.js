import { escapeHtml } from '../shared/api.js';
import { apiFor, mountUserChrome, requireSession } from '../shared/auth.js';
import { localTime } from '../shared/history.js';
import { initStatusBar, setConnection } from '../shared/statusbar.js';

// Sadece müşteri temsilcisi
const session = requireSession(['rep']);
const api = apiFor(session);
mountUserChrome(session, api);
initStatusBar();

const $ = (sel) => document.querySelector(sel);

function showResult(kind, text) {
  const el = $('#result');
  el.className = `result ${kind}`;
  el.textContent = text;
}

function renderInfo(info) {
  $('#tpl-state').className = `state ${info.custom ? 'in_progress' : 'open'}`;
  $('#tpl-state').textContent = info.custom ? 'Özel şablon kullanılıyor' : 'Varsayılan şablon kullanılıyor';
  const kb = `${Math.max(1, Math.round(info.size / 1024))} KB`;
  $('#tpl-meta').textContent = info.custom ? `Yüklenme: ${localTime(info.updated_at)} · ${kb}` : kb;
  $('#reset').hidden = !info.custom;
}

function renderPlaceholders(list) {
  const groups = new Map();
  for (const p of list) {
    if (!groups.has(p.group)) groups.set(p.group, []);
    groups.get(p.group).push(p);
  }
  $('#placeholders').innerHTML = [...groups].map(([group, items]) => `
    <div class="ph-group">
      <h3>${escapeHtml(group)}</h3>
      <table>
        <tbody>${items.map((p) => `
          <tr>
            <td class="ph-key" data-copy="{${escapeHtml(p.key)}}" title="Kopyala">{${escapeHtml(p.key)}}</td>
            <td>${escapeHtml(p.description)}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`).join('');
}

// Yer tutucuya tıklayınca panoya kopyala
$('#placeholders').addEventListener('click', async (e) => {
  const cell = e.target.closest('[data-copy]');
  if (!cell) return;
  try {
    await navigator.clipboard.writeText(cell.dataset.copy);
    cell.classList.add('copied');
    $('#copy-note').textContent = `${cell.dataset.copy} kopyalandı`;
    setTimeout(() => cell.classList.remove('copied'), 1200);
  } catch {
    $('#copy-note').textContent = 'Kopyalanamadı; elle seçip kopyalayın';
  }
});

$('#download').addEventListener('click', async () => {
  try {
    await api.downloadTemplate();
  } catch (err) {
    showResult('error', err.message);
  }
});

$('#upload').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  e.target.value = '';
  if (!file) return;
  const label = document.querySelector('label[for="upload"]');
  label.classList.add('busy');
  showResult('', 'Şablon kontrol ediliyor…');
  try {
    const { data } = await api.uploadTemplate(file);
    renderInfo(data);
    showResult('ok', `${file.name} yüklendi. Bundan sonraki dışa aktarmalar bu şablonu kullanır.`);
  } catch (err) {
    showResult('error', err.message);
  } finally {
    label.classList.remove('busy');
  }
});

$('#reset').addEventListener('click', async () => {
  if (!confirm('Yüklenen şablon silinsin ve varsayılan şablona dönülsün mü?')) return;
  try {
    const { data } = await api.resetTemplate();
    renderInfo(data);
    showResult('ok', 'Varsayılan şablona dönüldü.');
  } catch (err) {
    showResult('error', err.message);
  }
});

(async () => {
  try {
    const { data } = await api.templateInfo();
    renderInfo(data);
    renderPlaceholders(data.placeholders);
    setConnection(true, 'Sunucu bağlı');
  } catch (err) {
    setConnection(false, `Bağlantı yok: ${err.message}`);
  }
})();
