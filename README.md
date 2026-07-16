# IVB Shopify Export

Plugin de WordPress/WooCommerce para exportar pedidos al formato Matrixify
("Orders"), como paso previo a la migración de la tienda a Shopify.

## Uso

WooCommerce → **Exportar a Shopify**. Filtra por rango de fechas, cliente
(email o ID) y/o estados de pedido, y pulsa "Exportar CSV". El CSV generado
tiene exactamente las 131 columnas de `Plantilla migración pedidos.xlsx` y
puede importarse directamente con Matrixify en Shopify.

Cada pedido genera varias filas (una por línea de producto, envío y
descuento) agrupadas por la columna `Number`, tal y como espera Matrixify.

**Vínculo con productos existentes en Shopify**: el CSV solo manda
`Line: SKU` (no `Product ID` ni `Product Handle`), así que Matrixify
vincula cada línea al producto de Shopify que tenga ese SKU. El nombre del
producto **no** tiene que coincidir entre WooCommerce y Shopify — solo el
SKU. Si un SKU no existe en Shopify, Matrixify crea un producto nuevo con
ese SKU en vez de fallar (revisar el resultado del import para detectar
SKUs que no debían ser nuevos, p.ej. por errores tipográficos).

## Asunciones tomadas (revisar antes de la migración definitiva)

Basadas en la [documentación oficial de Matrixify Orders](https://matrixify.app/documentation/orders/):

- `Command` siempre `NEW`, `Send Receipt` siempre `FALSE` (para no reenviar
  emails de confirmación a clientes al importar histórico).
- `Inventory Behaviour` = `bypass`, para no tocar el stock de Shopify.
- Se añade la etiqueta `SINCRONIZADO` en `Tags` en todos los pedidos
  (obligatorio según nota de la propia plantilla: si no, se regeneran
  facturas).
- `Source` = `web` (WooCommerce no distingue canal de venta de forma fiable).
- **Impuestos solo a nivel de pedido** (`Tax 1/2/3`), nunca también a nivel
  de línea — Matrixify indica explícitamente que rellenar ambos duplica o
  hace fallar el import, y la plantilla del cliente solo usa el nivel de
  pedido.
- `Line: Price` es el precio unitario **antes** de descuento.
- Descuentos (`Line: Type = Discount`): `Line: Title` es `percentage` o
  `fixed_amount` (no el código del cupón), `Line: Price` es el valor del
  descuento (% o importe fijo), y `Line: Discount` es el importe realmente
  aplicado, en negativo — tal cual se ve en la fila de ejemplo de la
  plantilla del cliente.
- Reembolsos: una fila `Refund Line` (o `Refund Shipping`) por artículo
  devuelto con cantidad en negativo, más `Transaction: Kind = refund`.
  `Refund: Generate Transaction = FALSE` porque la transacción ya se añade
  explícitamente (evita duplicarla).
- Fulfillment: si el pedido está `completed` en WooCommerce, se rellenan los
  campos `Fulfillment: *` en **cada** fila `Line Item` del pedido (con el
  mismo `Fulfillment: ID`) para que Shopify marque el pedido como enviado
  por completo. Rellenarlo solo en una línea (como hacía la v0.1.1) deja el
  pedido en estado `partial` en Shopify, aunque esté completado en origen.
- Los importes (`Line: Price`, `Line: Discount`, `Tax N: Price`,
  `Transaction: Amount`...) se redondean a los decimales de la divisa de la
  tienda (`wc_get_price_decimals()`, normalmente 2) en vez de a 4 — con más
  precisión de la cuenta, Shopify puede recalcular el total del pedido con
  unos céntimos de diferencia y dejar el pedido con "Total Outstanding"
  distinto de cero.
- `Transaction: Force Gateway` y `Transaction: Test` siempre `FALSE`, para
  no arriesgarse a disparar cargos reales en la pasarela durante la
  migración.
- Si el email de WooCommerce no es un email válido, se omite de los campos
  `Email`/`Customer: Email` (Shopify rechaza emails inválidos) y se anota en
  `Note` para no perder el dato.
- El teléfono se normaliza a formato internacional (`+34 ...`) para España,
  ya que Matrixify/Shopify requieren el prefijo de país.
- No se exportan datos de `Company` (B2B) — no aplica al modelo de datos
  actual de la tienda.
- El peso (`Line: Grams`) se convierte a gramos según la unidad de peso
  configurada en WooCommerce (`woocommerce_weight_unit`).

## Recargo de equivalencia

En WooCommerce el recargo de equivalencia se aplica como un impuesto
compuesto (no como un producto), pero en Shopify el cliente lo quiere como
una línea de producto aparte apuntando a un Product ID fijo ya creado en la
tienda — así lo muestra la fila de ejemplo de su propia plantilla
(`Product ID 14932398932333`, `Title "re"`).

WooCommerce → **Exportar a Shopify** tiene un bloque de configuración con
dos campos (persistidos como opciones de WordPress, no hay que repetirlos
en cada export):

- **Texto que identifica el recargo** en el nombre de la tasa de impuesto
  (WooCommerce > Ajustes > Impuestos), por defecto `recargo`. Se busca sin
  distinguir mayúsculas en el label de cada tasa del pedido
  (`$order->get_tax_totals()`).
- **Product ID en Shopify** al que debe apuntar esa línea, por defecto
  `14932398932333` (el de la plantilla del cliente).

Cuando se detecta esa tasa: se excluye de las columnas `Tax N`, se resta de
`Tax: Total` (para no contarla dos veces en el total del pedido) y se
añade una fila `Line Item` con ese Product ID, cantidad 1 y precio = el
importe del recargo de ese pedido.

**Pendiente de confirmar con el cliente**: el nombre exacto de la tasa en
WooCommerce > Ajustes > Impuestos — revisarlo y ajustar el campo de
configuración si no contiene la palabra "recargo".

## Rendimiento en exports grandes

El export procesa los pedidos por lotes (100 por defecto, filtrable con
`ise_export_batch_size`) y limpia la caché de objetos entre lotes. Esto es
necesario porque WooCommerce con caché de objetos persistente (Redis/
Memcached) va acumulando en memoria los datos de cada pedido consultado
durante toda la petición, y un export de varias semanas puede agotar el
límite de memoria de PHP sin este mecanismo. Si el hosting usa caché
persistente, lanza los exports grandes fuera de horas punta (el
`wp_cache_flush()` entre lotes afecta brevemente al caché de todo el sitio).

## Pendiente / no cubierto en esta primera versión

- `Fulfillment: Tracking *` (no hay info de tracking en WooCommerce por
  defecto).
- Pedidos con múltiples pagos parciales (se exporta un único `Transaction`
  de tipo `sale` por el total cobrado).
- Normalización de teléfono solo cubre España (`+34`); otros países se
  exportan tal cual.
- Roles/campos específicos de `ivb-pedidos-comerciales` (comercial, SEPA,
  RE) — no se mapean a ningún campo Matrixify por ahora; si se necesitan en
  Shopify habría que decidir dónde encajarlos (p.ej. `Tags` o
  `Additional Details`).

Probar con un export pequeño (un rango de fechas corto) e importarlo en un
entorno de pruebas de Shopify antes de hacer la migración completa.
