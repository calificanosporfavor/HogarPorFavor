<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Acceso de administración | Hogar Por Favor</title>
  <link rel="icon" type="image/png" sizes="96x96" href="/favicon.png?v=5">
  <link rel="stylesheet" href="/style.css?v=32"><link rel="stylesheet" href="/admin.css?v=5">
  <script src="/admin-login.js?v=1" defer></script>
<link rel="stylesheet" href="/interactions.css?v=1"></head>
<body class="admin-page">
  <header><a class="brand" href="/" aria-label="Hogar Por Favor, inicio"><img src="/logo.png?v=2" alt="Hogar Por Favor" width="220" height="50"></a><span class="admin-label">ACCESO RESERVADO</span><a class="text-link" href="/">Ver sitio</a></header>
  <main class="admin-main">
    <section id="admin-access" aria-labelledby="admin-login-title">
      <span class="eyebrow">ADMINISTRACIÓN · IMPERIO PORFAVOR</span>
      <h1 id="admin-login-title">Entra a tu panel.</h1>
      <p>El panel y sus datos son exclusivos de la cuenta superadmin con correo confirmado.</p>
      <form id="admin-login">
        <label>Correo electrónico<input name="email" type="email" autocomplete="username" required maxlength="254"></label>
        <label>Contraseña<input name="password" type="password" autocomplete="current-password" required maxlength="72"></label>
        <button class="button full" type="submit">Entrar al panel</button>
      </form>
      <p id="admin-login-status" class="form-status" role="status" aria-live="polite"></p>
      <button type="button" id="admin-login-logout" class="plain" hidden>Cerrar la sesión actual</button>
      <p><a class="plain" href="/">Volver al sitio para confirmar el correo o recuperar el acceso</a></p>
    </section>
  </main>
</body>
</html>
