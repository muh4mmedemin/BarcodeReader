// Oturum: giriş bilgisi bu tarayıcıda saklanır; her sayfa açılışta oturum ister.
import { ApiClient } from './api.js';
import { apiBaseUrl } from './api-base.js';

const SESSION_KEY = 'barkod.session';
const ROLE_LABELS = { rep: 'Müşteri temsilcisi', production: 'Üretim', board: 'Pano', admin: 'Yönetici' };

// Yollar bu dosyanın konumuna göre çözülür (frontend/shared/auth.js).
const url = (path) => new URL(path, import.meta.url).href;
export const LOGIN_URL = url('../login.html');

export function getSession() {
  try { return JSON.parse(localStorage.getItem(SESSION_KEY)); } catch { return null; }
}

export function saveSession(session) {
  try { localStorage.setItem(SESSION_KEY, JSON.stringify(session)); } catch { /* yoksa her açılışta giriş */ }
}

export function clearSession() {
  try { localStorage.removeItem(SESSION_KEY); } catch { /* yok say */ }
}

/** Rolün açılış sayfası: üretim → okutma ekranı, pano → atölye panosu, yönetici → iş emri taşıma, temsilci → ana menü. */
export function homeFor(user) {
  if (user.role === 'production') return url('../production/');
  if (user.role === 'admin') return url('../admin/');
  if (user.role === 'board') return url('../board/');
  return url('../');
}

/**
 * Oturum yoksa giriş sayfasına, rol bu sayfaya yetkili değilse kendi sayfasına yönlendirir.
 * Yönlendirme olursa sayfanın geri kalanı çalışmasın diye hata fırlatır.
 */
export function requireSession(roles) {
  const session = getSession();
  if (!session?.token || !session.user) {
    location.replace(LOGIN_URL);
    throw new Error('Giriş gerekli');
  }
  if (roles && !roles.includes(session.user.role)) {
    location.replace(homeFor(session.user));
    throw new Error('Yetkisiz sayfa');
  }
  return session;
}

export function apiFor(session) {
  return new ApiClient({
    baseUrl: apiBaseUrl(),
    token: session?.token ?? null,
    onUnauthorized: () => { clearSession(); location.replace(LOGIN_URL); },
  });
}

/** Başlık çubuğuna kullanıcı bilgisini ve Çıkış bağlantısını yerleştirir. */
export function mountUserChrome(session, api) {
  const { user } = session;
  const who = document.getElementById('tb-user');
  if (who) {
    who.textContent = [user.username, ROLE_LABELS[user.role], user.station_name].filter(Boolean).join(' · ');
  }
  document.getElementById('tb-logout')?.addEventListener('click', async (e) => {
    e.preventDefault();
    try { await api.logout(); } catch { /* sunucuya ulaşılamasa da yerelde çıkış yap */ }
    clearSession();
    location.replace(LOGIN_URL);
  });
}
