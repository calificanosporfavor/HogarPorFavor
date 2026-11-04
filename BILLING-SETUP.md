# Suscripciones de Hogar Por Favor

## Estructura inicial

- Gratis: hasta 3 propiedades activas, pausadas o en borrador.
- Asesor: $99 MXN al mes, hasta 10 propiedades, una cuenta de asesor.
- Asesor Plus: $200 MXN al mes, hasta 25 propiedades, una cuenta de asesor y estadísticas de visitas, favoritos y clics de contacto.
- Ilimitado: $300 MXN al mes, publicaciones sin límite, estadísticas y 4 destacados por mes calendario; cada destacado dura 7 días, se puede aplicar a una publicación activa y los créditos no se acumulan.

Las propiedades rentadas o vendidas liberan un espacio. Si una suscripción termina o queda sin renovar, se conserva el contenido y se bloquean nuevas publicaciones/reactivaciones que excedan el límite gratuito. No se borra inventario de forma automática.

## Estado de la conexión

El código admite Checkout de Stripe alojado, el portal de facturación y webhooks con firma e idempotencia. Los tres productos y sus precios recurrentes ya están creados en modo de prueba y live. Aún se requieren las claves privadas y los secretos de webhook correspondientes en Hostinger para habilitar Checkout. No se incluyen secretos en este repositorio.

Productos creados en Stripe, con entornos separados (precios mensuales en MXN):

1. `Hogar Por Favor — Asesor`, $99 mensuales. Precio `price_1UYDZlEc2x5xSI7gyEm9TjOT`.
2. `Hogar Por Favor — Asesor Plus`, $200 mensuales. Precio `price_1UYDZrEc2x5xSI7g7q2mpYP6`.
3. `Hogar Por Favor — Ilimitado`, $300 mensuales. Precio `price_1UYDZsEc2x5xSI7gq2OwEGts`. Incluye 4 destacados por mes calendario; cada uno dura 7 días.

Los precios live correspondientes son `price_1UYDjaICz2Enrh7I6BrbfO9f` (Asesor), `price_1UYDjaICz2Enrh7IhtYlpTo0` (Asesor Plus) y `price_1UYDjcICz2Enrh7IQAj6tW8d` (Ilimitado). Los identificadores de precio no son secretos; las claves privadas sí.

Configurar el webhook de prueba `https://hogarporfavor.com/stripe-webhook.php` para `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid` y `invoice.payment_failed`.

Copiar `stripe-config.example.php` a `.hogar-private/stripe-config.php`, directorio privado hermano de `public_html`. Para probar, usar `mode => test`, una clave `sk_test_...` y el secreto del webhook de prueba. Para cobrar, usar `mode => live`, una clave `sk_live_...` y el secreto del webhook live. El código selecciona los precios del entorno indicado. Activar `billing_enabled => true` solo después de instalar el webhook y comprobar el flujo. Mantener el archivo real fuera de `public_html`, de Git y de cualquier respaldo público. El ejemplo está desactivado a propósito.

Antes de activar pagos reales, verificar el Checkout y las renovaciones con tarjetas de prueba, confirmar las reglas fiscales y de facturación del negocio y configurar los productos, endpoint, clave y precios del entorno **live** por separado. No reutilizar claves de prueba. Publicar precios e impuestos aplicables con claridad antes de cobrar. Revisar con asesoría contable/legal las condiciones de renovación, cancelación, reembolsos y comprobantes fiscales.

La interfaz indica que ampliar el plan es opcional y conserva el acceso gratuito. Las publicaciones previas no se eliminan al cambiar o terminar el plan. Stripe es la fuente de estado de pago; el navegador nunca habilita un plan por sí solo.

## Por qué estos precios

Precios públicos consultados el 4 de noviembre de 2026: EasyBroker publica $490 MXN/mes por 10 anuncios y $990 por 25, con funciones de CRM, equipo y vinculación con portales; no es una comparación exacta porque Hogar Por Favor vende un plan individual más sencillo. MiInmo ofrece niveles gratuitos/de entrada y también venta de publicaciones destacadas por periodos. Referencias: https://www.easybroker.com/planes y https://miinmo.com.mx/precios.

Los $99 y $200 colocan a Hogar Por Favor como alternativa de entrada muy accesible, aproximadamente 80% por debajo de esos niveles de EasyBroker para el mismo número de propiedades. La propuesta compite por simplicidad, publicación directa y bajo costo; conviene presentarla como acceso individual, sin CRM de agencia, trabajo en equipo ni difusión garantizada.

Con la tarifa pública de Stripe México consultada en esa fecha (3.6% + $3 MXN por tarjeta nacional y 0.7% de Stripe Billing; las tarifas excluyen IVA), el ingreso aproximado después de esas dos comisiones sería $91.74 de un cobro de $99 y $188.40 de uno de $200, antes del IVA sobre comisiones, impuestos del negocio, alojamiento, soporte, reembolsos y contracargos. Referencia: https://stripe.com/mx/pricing. Son precios de lanzamiento para validar conversión; no asumir rentabilidad hasta medir usuarios pagados, cancelaciones, soporte y costo de adquisición.

## Evolución recomendada

Mantener Gratis (3), Asesor (10), Asesor Plus (25 con analíticas) e Ilimitado (sin límite, 4 destacados mensuales) durante la validación inicial. No añadir niveles ni cobrar por contactos todavía. Medir activaciones, anuncios completos, consultas reales y conversión a pago. Cuando exista suficiente demanda, experimentar con una sola promoción opcional de anuncio destacado por tiempo limitado, con precio y duración transparentes; comparar el ingreso incremental con una alternativa gratuita para no degradar el catálogo ni la confianza.

Para que el portal permita cambios entre los tres precios, habilitar en Stripe Billing Portal la actualización de suscripciones y seleccionar Asesor, Asesor Plus e Ilimitado como precios permitidos. Mantener cancelación al final del periodo; la app conserva el plan mientras el periodo continúe vigente. Confirmar que el webhook incluya cambios de suscripción para reflejar cualquier precio elegido.

El plan Ilimitado concede 4 usos por mes calendario de Ciudad de México. Un uso coloca una publicación activa al inicio de los resultados durante 7 días; los créditos no se acumulan. La app registra cada uso en la base privada, muestra el saldo en Mi cuenta y valida el plan/cupo en el servidor.
