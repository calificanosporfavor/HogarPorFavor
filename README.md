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

El panel /admin.php está reservado exclusivamente a cueva@hogarporfavor.com con correo confirmado. No se crean credenciales predeterminadas ni existe un mecanismo público para otorgar roles. La cuenta debe registrarse y confirmar su correo. Suspender una cuenta revoca sus sesiones y oculta todos sus anuncios y fotos; al reactivarla debe iniciar sesión nuevamente. El superadmin no puede suspenderse. Archivar un anuncio lo oculta y solo el superadmin puede restaurarlo. Los cambios se registran en admin_audit.

Los filtros por estado, ciudad/municipio y colonia son dependientes y comparan nombres completos sin distinguir acentos o mayúsculas. El formulario de publicación ofrece las mismas sugerencias y admite capturar zonas nuevas. places.php sirve un índice local comprimido con nombres de lugares de las 32 entidades; no requiere servicios externos. Fuente de los nombres: catálogo de Correos de México actualizado al 31 de octubre de 2026, https://www.correosdemexico.gob.mx/SSLServicios/ConsultaCP/CodigoPostal_Exportar.aspx . El índice no incluye códigos postales. Conservar places-mx.json.gz, places.php, places.js, location-keys.php y admin-api.php junto con los demás archivos al desplegar.

El panel se entrega desde PHP únicamente a la sesión activa del superadmin verificado; las demás visitas reciben 404. No hay enlaces de administración en la página principal, incluso para el superadmin. Iniciar sesión normalmente y abrir directamente /admin.php. La antigua dirección admin.html también pasa por el control de acceso del servidor.

## Términos y aceptación

Términos vigentes: terminos.html, versión 2026-11-01.1; copia histórica terminos-2026-11-01.html. terms.php valida aceptación explícita y versión actual al registrar, crear/editar anuncios y reactivarlos. terms_acceptances conserva cuenta, propósito, anuncio, versión, SHA-256 del documento y fecha del servidor. No registra aceptación por navegación ni atribuye retroactivamente consentimiento a cuentas existentes. Pausar no exige aceptación. Las casillas se reinician desmarcadas. No sobrescribir copias históricas al cambiar los términos: agregar nueva copia, actualizar versión y archivo de hash, formularios y página actual. Incluir terms.php y ambas páginas en cada despliegue.

El titular legal y domicilio todavía no fueron definidos por el propietario; el texto identifica el proyecto y utiliza cueva@hogarporfavor.com para contacto. Completar esos datos y revisar el texto y el aviso de privacidad con asesoría legal antes de considerarlos documentación legal completa.

## Aviso de privacidad

privacidad.html documenta datos, finalidades, contacto público, alojamiento, Google Fonts, cookie de sesión, solicitudes ARCO y eliminación manual por cueva@hogarporfavor.com. La eliminación desde Mi cuenta es automática tras verificar contraseña y confirmación; las solicitudes enviadas por correo requieren revisión manual. Pausar no equivale a borrar. Mantener el aviso alineado con cambios reales del servicio. Sigue pendiente completar identidad y domicilio del titular legal y revisar jurídicamente el aviso.

## Eliminación desde Mi cuenta

account-delete.php implementa delete-account: exige sesión, CSRF, contraseña actual, confirmación ELIMINAR y casilla explícita. Borra en transacción la cuenta, anuncios/fotos por cascada, reportes, códigos/tokens, constancias y acciones de moderación vinculadas. Las fotos se apartan de su ruta pública antes de confirmar la transacción; un fallo restaura los archivos. El borrado físico se reintenta en peticiones posteriores si falla; archivos aún referenciados por una transacción no se borran y una operación interrumpida se recupera tras 10 minutos. Invalida y elimina las sesiones identificables de la cuenta. SQLite utiliza borrado seguro y se intenta truncar su registro WAL. No elimina copias históricas del alojamiento o conversaciones externas. No probar borrados con cuentas reales del usuario.

## Edición de perfil

account-profile.php permite update-name y change-password con sesión, CSRF, contraseña actual y límite de intentos. Solo modifica la cuenta autenticada: no admite cambiar correo, rol o titular. El nombre actualiza las fichas de anuncios mediante la relación con users. Cambiar contraseña exige confirmación, mínimo 12 caracteres, máximo 72 bytes y una clave distinta; rota sesión y CSRF, conserva el acceso actual y revoca otros accesos, códigos de correo y recuperación de respaldo anteriores. La recuperación por correo sigue disponible. Probar con cuentas sintéticas locales, sin modificar credenciales de usuarios reales.

## Cambio de correo

account-email.php gestiona request-email-change, confirm-email-change y cancel-email-change. Exige sesión y CSRF; solicitar el cambio verifica contraseña actual y aplica cuotas de envío. Envía un código de 6 dígitos con 10 minutos y 5 intentos a la dirección nueva. pending_email_changes vincula cuenta, destino, correo anterior, código, autenticación y sesión; el correo no cambia hasta verificar. Reenviar invalida el código anterior, se vuelve a comprobar disponibilidad al confirmar y el cambio revoca otras sesiones, tokens y códigos previos. La cuenta conserva su contraseña. El contacto de los anuncios no se modifica. La dirección superadmin permanece reservada. Las solicitudes temporales caducan y se eliminan en futuras peticiones o por cascada al borrar la cuenta. Conservar account-email.php en el despliegue junto con auth-mail.php.
