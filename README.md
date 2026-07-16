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

- `Command` siempre `NEW`, `Send Receipt` siempre `FALSE` (para no reenviar
  emails de confirmación a clientes al importar histórico).
- `Inventory Behaviour` = `bypass`, para no tocar el stock de Shopify.
- Se añade la etiqueta `SINCRONIZADO` en `Tags` en todos los pedidos
  (obligatorio según nota de la propia plantilla: si no, se regeneran
  facturas).
- `Source` = `web` (WooCommerce no distingue canal de venta de forma fiable).
- `Line: Price` es el precio unitario **antes** de descuento; el descuento
  de línea (si lo hay) va aparte en `Line: Discount`.
- Los pedidos con reembolso generan una fila adicional de tipo
  `Refund` + `Transaction: Kind = refund`.
- No se exportan datos de `Company` (B2B) — no aplica al modelo de datos
  actual de la tienda.
- El peso (`Line: Grams`) se convierte a gramos según la unidad de peso
  configurada en WooCommerce (`woocommerce_weight_unit`).

## Pendiente / no cubierto en esta primera versión

- `Fulfillment: Tracking *` (no hay info de tracking en WooCommerce por
  defecto).
- Pedidos con múltiples pagos parciales (se exporta un único `Transaction`
  de tipo `sale` por el total cobrado).
- Roles/campos específicos de `ivb-pedidos-comerciales` (comercial, SEPA,
  RE) — no se mapean a ningún campo Matrixify por ahora; si se necesitan en
  Shopify habría que decidir dónde encajarlos (p.ej. `Tags` o
  `Additional Details`).

Probar con un export pequeño (un rango de fechas corto) e importarlo en un
entorno de pruebas de Shopify antes de hacer la migración completa.
