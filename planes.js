(() => {
  const plans = {
    advisor: { name: 'Asesor', price: 99 },
    advisor_plus: { name: 'Asesor Plus', price: 199 },
    advisor_unlimited: { name: 'Ilimitado', price: 299 }
  };
  const dialog = document.getElementById('subscribe-dialog');
  const form = document.getElementById('subscribe-form');
  const title = document.getElementById('subscribe-title');
  const summary = document.getElementById('subscribe-summary');
  const status = document.getElementById('subscribe-status');
  const terms = document.getElementById('subscribe-terms');
  let csrf = '';
  let selectedPlan = '';

  function showStatus(message) {
    status.textContent = message;
  }

  async function readJson(response) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'No pudimos conectar con el servicio. Inténtalo otra vez.');
    return data;
  }

  async function choosePlan(plan) {
    const choice = plans[plan];
    if (!choice) return;
    selectedPlan = plan;
    title.textContent = 'Suscríbete a ' + choice.name + '.';
    summary.textContent = '$' + choice.price + ' MXN al mes. Inicia sesión, acepta las condiciones y confirma el pago en Stripe.';
    terms.checked = false;
    showStatus('Comprobando tu cuenta…');

    try {
      const session = await readJson(await fetch('/api.php?action=session', { credentials: 'same-origin', cache: 'no-store' }));
      csrf = session.csrf || '';
      if (!session.user) {
        location.assign('/?billing_plan=' + encodeURIComponent(plan));
        return;
      }
      if (!session.user.email_verified) {
        location.assign('/?billing_plan=' + encodeURIComponent(plan));
        return;
      }

      const billing = await readJson(await fetch('/api.php?action=billing-status', { credentials: 'same-origin', cache: 'no-store' }));
      if (billing.plan === 'superadmin') {
        showStatus('La cuenta superadmin no necesita contratar un plan.');
      } else if (['advisor', 'advisor_plus', 'advisor_unlimited'].includes(billing.plan)) {
        showStatus(billing.plan === plan ? 'Este ya es tu plan actual.' : 'Tu cuenta ya tiene una suscripción activa. Adminístrala desde Mi cuenta.');
      } else if (!billing.checkout_available) {
        showStatus('La contratación no está disponible en este momento. Tus publicaciones gratis siguen activas.');
      } else {
        showStatus('');
      }
      if (!dialog.open) dialog.showModal();
    } catch (error) {
      showStatus(error.message || 'No pudimos comprobar tu cuenta.');
      if (!dialog.open) dialog.showModal();
    }
  }

  document.addEventListener('click', event => {
    const link = event.target.closest('[data-subscribe-plan]');
    if (!link) return;
    event.preventDefault();
    choosePlan(link.dataset.subscribePlan);
  });

  document.querySelector('[data-subscribe-close]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!terms.checked) {
      showStatus('Acepta las condiciones de suscripción para continuar.');
      return;
    }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    button.textContent = 'Conectando con Stripe…';
    showStatus('Preparando el pago seguro. No se realizará ningún cobro hasta que lo confirmes en Stripe.');
    try {
      const response = await fetch('/api.php?action=billing-checkout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ plan: selectedPlan, billing_terms: true, billing_terms_version: '2026-11-04.3' })
      });
      const result = await readJson(response);
      if (!result.url || !result.url.startsWith('https://checkout.stripe.com/')) {
        throw new Error('Stripe no devolvió un enlace seguro de pago.');
      }
      location.assign(result.url);
    } catch (error) {
      showStatus(error.message || 'No pudimos iniciar la suscripción. Conservamos tu cuenta y no se hizo ningún cargo.');
    } finally {
      button.disabled = false;
      button.textContent = 'Continuar a Stripe';
    }
  });

  const params = new URLSearchParams(location.search);
  const requestedPlan = params.get('subscribe');
  if (plans[requestedPlan]) {
    params.delete('subscribe');
    history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params.toString() : '') + location.hash);
    choosePlan(requestedPlan);
  }
})();