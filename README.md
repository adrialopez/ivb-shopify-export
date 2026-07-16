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
