// Ana menü: sayfalara yönlendirme + sunucu durumu. Anahtar gerektirmez (/health herkese açık).
const API_BASE = 'http://localhost:8000';

document.getElementById('today').textContent = new Date().toLocaleDateString('tr-TR', {
  weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
});

// Klavye kısayolları: 1 → PO Oluşturma, 2 → İş Emri Okut
document.addEventListener('keydown', (e) => {
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  const tile = document.querySelector(`.tile[data-key="${e.key}"]`);
  if (tile) location.href = tile.href;
});

(async () => {
  const dot = document.getElementById('status-dot');
  const text = document.getElementById('server-status');
  try {
    const res = await fetch(`${API_BASE}/api/v1/health`);
    if (!res.ok) throw new Error();
    dot.className = 'dot ok';
    text.textContent = 'Sunucu bağlı';
  } catch {
    dot.className = 'dot down';
    text.textContent = 'Sunucuya ulaşılamıyor';
  }
})();
