# Hogar Por Favor

Plataforma inmobiliaria inicial de hogarporfavor.com, un proyecto de Imperio. HTML, CSS, JavaScript y PHP 8.1+ con PDO SQLite, mbstring, fileinfo y GD. Sin dependencias de compilación.

## Estado real

Cuentas con contraseña protegida, inicio/cierre de sesión, confirmación de correo y recuperación de contraseña por correo. Anuncios gratuitos con 1–8 fotos, precio, estado, ciudad, colonia, características y contacto público autorizado por WhatsApp, teléfono o correo. Los propietarios pueden editar, pausar y reactivar sus propios anuncios. Búsqueda con filtros y paginación; ficha con galería y enlace compartible. Los reportes se revisan desde el panel privado.

La verificación y la recuperación usan códigos de 6 dígitos, de un solo uso, con vigencia de 10 minutos y hasta 5 intentos. Los códigos se guardan con hash de contraseña, ligados a cuenta y propósito. El reenvío invalida el código anterior; recuperar la contraseña revoca las sesiones, códigos y enlaces anteriores. Los correos nuevos no contienen enlaces de acceso. Los enlaces ya enviados siguen funcionando hasta su vencimiento para mantener compatibilidad. La solicitud de recuperación no revela si un correo está registrado. Las cuentas previas deben confirmar su correo para publicar o reactivar anuncios; pueden iniciar sesión y pausar los existentes. Los códigos de respaldo antiguos siguen disponibles para esas cuentas.

No hay verificación de identidad o titularidad, pagos, chat interno ni revisión automática. Confirmar el correo no acredita una propiedad. El panel de administración permite revisar reportes, corregir y archivar anuncios, suspender y reactivar cuentas, y consultar el historial de cambios.

El envío utiliza la función mail() del alojamiento, con remitente no-reply@hogarporfavor.com. La aplicación limita los envíos a 8 por minuto y 80 por día, además de límites por destinatario e IP; el alojamiento comparte sus límites con otros sitios. La aceptación por el servidor no demuestra entrega en bandeja de entrada. Para mayor volumen, migrar a un proveedor SMTP autenticado. En desarrollo los mensajes se capturan únicamente en hogar-test-data/mail-test y nunca se envían a destinatarios reales.

En producción, los datos persisten en .hogar-private al lado de public_html, fuera del repositorio y de las carpetas versionadas de Git. Contiene hogar.sqlite, photos y sessions. Debe incluirse en los respaldos del alojamiento. Nunca publicarlo, moverlo a public_html ni incluirlo en Git. Las fotos se vuelven a codificar en JPEG sin metadatos y con tamaño máximo de 1600 píxeles; reemplazar fotos elimina las anteriores. Pausar es reversible y oculta ficha y fotos a visitantes. Los datos se conservan hasta su retiro por el operador.

Protecciones: autorización por propietario, consultas preparadas, cookies HttpOnly/Secure/SameSite, CSRF, límites por IP, regeneración y caducidad de sesión, revocación de sesiones al recuperar acceso, salida de texto escapada y validación de imagen en servidor. Los registros de límite usan IP resumida con hash y caducan. No se guardan contraseñas o códigos en texto plano.

## Publicación en Hostinger

Hostinger despliega main a public_html automáticamente. Conservar api.php, auth-mail.php, .user.ini y .htaccess junto con index.html y los recursos. .user.ini configura 6 MB por foto y 52 MB por petición; sus cambios pueden tardar en aplicarse. No subir respaldos ni credenciales. api.php?action=health confirma disponibilidad sin exponer rutas ni configuración sensible.

## Desarrollo

Usar un servidor PHP, no un servidor solo estático. No necesita npm install. Comprobar sintaxis con `php -l api.php` y `node --check app.js`. En el servidor de desarrollo integrado de PHP, los datos se guardan en la carpeta hermana hogar-test-data, separada de producción.

## Recursos

Logo horizontal proporcionado en Hogar Por favor (2).zip. El símbolo de casa de Logo minimalista de casa con techo verde.png reemplaza la H decorativa del bloque de confianza y se utiliza como favicon PNG de 96 × 96. Fotografía de portada: Unsplash, https://images.unsplash.com/photo-1600596542815-ffad4c1539a9 . Tipografías DM Sans y Manrope servidas por Google Fonts; Arial como alternativa.

Hostinger está conectado al repositorio calificanosporfavor/HogarPorFavor, rama main, con destino public_html de hogarporfavor.com. Revisar el interruptor Auto-deployment en hPanel para el despliegue automático; también se puede usar Redeploy. El sitio reutiliza el espacio que antes ocupaba AgroPorFavor. Los archivos anteriores se conservaron fuera de public_html y en una copia ZIP local antes de publicar.

## Administración y ubicaciones

El panel /admin.html está reservado exclusivamente a cueva@hogarporfavor.com con correo confirmado. No se crean credenciales predeterminadas ni existe un mecanismo público para otorgar roles. La cuenta debe registrarse y confirmar su correo. Suspender una cuenta revoca sus sesiones y oculta todos sus anuncios y fotos; al reactivarla debe iniciar sesión nuevamente. El superadmin no puede suspenderse. Archivar un anuncio lo oculta y solo el superadmin puede restaurarlo. Los cambios se registran en admin_audit.

Los filtros por estado, ciudad/municipio y colonia son dependientes y comparan nombres completos sin distinguir acentos o mayúsculas. El formulario de publicación ofrece las mismas sugerencias y admite capturar zonas nuevas. places.php sirve un índice local comprimido con nombres de lugares de las 32 entidades; no requiere servicios externos. Fuente de los nombres: catálogo de Correos de México actualizado al 31 de octubre de 2026, https://www.correosdemexico.gob.mx/SSLServicios/ConsultaCP/CodigoPostal_Exportar.aspx . El índice no incluye códigos postales. Conservar places-mx.json.gz, places.php, places.js, location-keys.php y admin-api.php junto con los demás archivos al desplegar.
