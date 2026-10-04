import { apiFor, getSession, homeFor, saveSession } from './shared/auth.js';
import { initStatusBar, setConnection } from './shared/statusbar.js';

// Zaten giriş yapılmışsa doğrudan kendi sayfasına
const existing = getSession();
if (existing?.token && existing.user) location.replace(homeFor(existing.user));

initStatusBar();
const api = apiFor(null);

const form = document.getElementById('login-form');
const error = document.getElementById('login-error');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  error.textContent = '';
  const button = form.querySelector('button');
  button.disabled = true;
  try {
    const { data } = await api.login(form.username.value, form.password.value);
    saveSession(data);
    location.replace(homeFor(data.user));
  } catch (err) {
    error.textContent = err.message;
    form.password.select();
  } finally {
    button.disabled = false;
  }
});

api.health()
  .then(() => setConnection(true, 'Sunucu bağlı'))
  .catch(() => setConnection(false, 'Sunucuya ulaşılamıyor'));
