// Ana menü: modüllere yönlendirme + sunucu durumu. Anahtar gerektirmez (/health herkese açık).
import { apiBaseUrl } from './shared/api-base.js';
import { apiFor, mountUserChrome, requireSession } from './shared/auth.js';
import { initStatusBar, setConnection } from './shared/statusbar.js';

// Ana menü temsilci içindir; üretim kullanıcısı doğrudan okutma ekranına yönlenir.
const session = requireSession(['rep']);
mountUserChrome(session, apiFor(session));
initStatusBar();

document.getElementById('today').textContent = new Date().toLocaleDateString('tr-TR', {
  weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
});

const go = (row) => { if (row) location.href = row.dataset.href; };

// Klavye: 1 → PO Oluşturma, 2 → İş Emri Okut
document.addEventListener('keydown', (e) => {
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  go(document.querySelector(`tr[data-key="${e.key}"]`));
});

// Satırın tamamı tıklanabilir
document.querySelector('.modules tbody').addEventListener('click', (e) => {
  if (!e.target.closest('a')) go(e.target.closest('tr[data-href]'));
});

(async () => {
  try {
    const res = await fetch(`${apiBaseUrl()}/api/v1/health`);
    if (!res.ok) throw new Error();
    setConnection(true, 'Sunucu bağlı');
  } catch {
    setConnection(false, 'Sunucuya ulaşılamıyor');
  }
})();
