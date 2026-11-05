# Suscripciones de Hogar Por Favor

## Estructura inicial

- Gratis: hasta 3 propiedades activas, pausadas o en borrador.
- Asesor: $99 MXN al mes, hasta 10 propiedades, una cuenta de asesor.
- Asesor Plus: $199 MXN al mes, hasta 25 propiedades, una cuenta de asesor y estadísticas de visitas, favoritos y clics de contacto.
- Ilimitado: $299 MXN al mes, publicaciones sin límite, estadísticas y 4 destacados por mes calendario; cada destacado dura 7 días, se puede aplicar a una publicación activa y los créditos no se acumulan.

Las propiedades rentadas o vendidas liberan un espacio. Si una suscripción termina o queda sin renovar, se conserva el contenido y se bloquean nuevas publicaciones/reactivaciones que excedan el límite gratuito. No se borra inventario de forma automática.

## Estado de la conexión

Stripe Checkout y el portal usan el modo de la configuración privada de Hogar Por Favor. Los webhooks live están registrados. El precio anterior de Asesor Plus ($200 MXN) y el de Ilimitado ($300 MXN) se desactivaron para nuevas suscripciones; las suscripciones existentes conservan su precio actual. Los nuevos precios live y de prueba son mensuales en MXN:

| Plan | Modo de prueba | Modo live |
| --- | --- | --- |
| Asesor | `price_1UYDZlEc2x5xSI7gyEm9TjOT` · $99 | `price_1UYDjaICz2Enrh7I6BrbfO9f` · $99 |
| Asesor Plus | `price_1UYHOdEc2x5xSI7goPl6uz3Y` · $199 | `price_1UYHNaICz2Enrh7IB232Eu3C` · $199 |
| Ilimitado | `price_1UYHOdEc2x5xSI7gU6beuqcd` · $299 | `price_1UYHNaICz2Enrh7ITt2KbiQd` · $299 |

Ilimitado incluye 4 destacados por mes calendario; cada uno dura 7 días. Los IDs de precio no son secretos. Las claves privadas y los secretos de firma deben mantenerse fuera del repositorio y de cualquier carpeta pública.

Hogar usa `https://hogarporfavor.com/stripe-webhook.php`; los eventos de suscripción y pagos se procesan con firma e idempotencia. Para usar Checkout, instalar la configuración privada con los precios del entorno elegido, verificar la firma de un evento de prueba y mantener `billing_enabled => true` solo cuando la configuración coincida con ese entorno. El portal de facturación puede administrar cancelaciones; la app confirma el plan con los eventos de Stripe.

Stripe Tax no se activa automáticamente. Antes de cobrar, confirmar con la asesoría contable las obligaciones fiscales, los datos del responsable y si Stripe Tax debe usarse con registros fiscales activos.

## Por qué estos precios

Precios públicos consultados el 4 de noviembre de 2026: EasyBroker publica $490 MXN/mes por 10 anuncios y $990 por 25, con funciones de CRM, equipo y vinculación con portales; no es una comparación exacta porque Hogar Por Favor vende un plan individual más sencillo. MiInmo ofrece niveles gratuitos/de entrada y también venta de publicaciones destacadas por periodos. Referencias: https://www.easybroker.com/planes y https://miinmo.com.mx/precios.

Los $99, $199 y $299 colocan a Hogar Por Favor como alternativa de entrada muy accesible, aproximadamente 80% por debajo de esos niveles de EasyBroker para el mismo número de propiedades. La propuesta compite por simplicidad, publicación directa y bajo costo; conviene presentarla como acceso individual, sin CRM de agencia, trabajo en equipo ni difusión garantizada.

Con la tarifa pública de Stripe México consultada en esa fecha (3.6% + $3 MXN por tarjeta nacional y 0.7% de Stripe Billing; las tarifas excluyen IVA), el ingreso aproximado después de esas dos comisiones sería $91.74 de un cobro de $99, $187.44 de uno de $199 y $283.14 de uno de $299, antes del IVA sobre comisiones, impuestos del negocio, alojamiento, soporte, reembolsos y contracargos. Referencia: https://stripe.com/mx/pricing. Son precios de lanzamiento para validar conversión; no asumir rentabilidad hasta medir usuarios pagados, cancelaciones, soporte y costo de adquisición.

## Evolución recomendada

Mantener Gratis (3), Asesor (10), Asesor Plus (25 con analíticas) e Ilimitado (sin límite, 4 destacados mensuales) durante la validación inicial. No añadir niveles ni cobrar por contactos todavía. Medir activaciones, anuncios completos, consultas reales y conversión a pago. Cuando exista suficiente demanda, experimentar con una sola promoción opcional de anuncio destacado por tiempo limitado, con precio y duración transparentes; comparar el ingreso incremental con una alternativa gratuita para no degradar el catálogo ni la confianza.

Para que el portal permita cambios entre los tres precios, habilitar en Stripe Billing Portal la actualización de suscripciones y seleccionar Asesor, Asesor Plus e Ilimitado como precios permitidos. Mantener cancelación al final del periodo; la app conserva el plan mientras el periodo continúe vigente. Confirmar que el webhook incluya cambios de suscripción para reflejar cualquier precio elegido.

El plan Ilimitado concede 4 usos por mes calendario de Ciudad de México. Un uso coloca una publicación activa al inicio de los resultados durante 7 días; los créditos no se acumulan. La app registra cada uso en la base privada, muestra el saldo en Mi cuenta y valida el plan/cupo en el servidor.
