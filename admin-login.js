'use strict';
const $ = selector => document.querySelector(selector);
let csrf = '';
async function adminLoginApi(action, body) {
  const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
  if (body !== undefined) {
    options.method = 'POST';
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = csrf;
    options.body = JSON.stringify(body);
  }
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, options);
  let data;
  try { data = await response.json(); }
  catch { throw new Error('El servidor no pudo completar la operación.'); }
  if (data.csrf) csrf = data.csrf;
  if (!response.ok) throw new Error(data.error || 'No pudimos completar la operación.');
  return data;
}

const form = $('#admin-login');
const status = $('#admin-login-status');
const logoutButton = $('#admin-login-logout');
adminLoginApi('session').then(({ user }) => {
  if (user?.role === 'superadmin') { location.replace('/admin.php'); return; }
  if (user) {
    status.textContent = 'La sesión actual no tiene acceso al panel. Entra con la cuenta superadmin.';
    logoutButton.hidden = false;
  }
}).catch(error => { status.textContent = error.message; });

form.addEventListener('submit', async event => {
  event.preventDefault();
  const button = event.submitter;
  if (button?.disabled) return;
  if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
  status.textContent = 'Verificando el acceso…';
  try {
    const data = await adminLoginApi('admin-login', Object.fromEntries(new FormData(form)));
    if (data.user?.role !== 'superadmin') throw new Error('Esta cuenta no tiene acceso al panel.');
    location.replace('/admin.php');
  } catch (error) {
    status.textContent = error.message;
    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
  }
});

logoutButton.addEventListener('click', async () => {
  logoutButton.disabled = true;
  try {
    await adminLoginApi('logout', {});
    logoutButton.hidden = true;
    status.textContent = 'Sesión cerrada. Ya puedes entrar con la cuenta de administración.';
  } catch (error) {
    status.textContent = error.message;
    logoutButton.disabled = false;
  }
});
