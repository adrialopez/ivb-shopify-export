<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convierte pedidos de WooCommerce a filas con la estructura de la plantilla
 * Matrixify "Orders" (una fila por pedido/línea, agrupadas por columna "Number").
 *
 * Asunciones (revisar antes de la migración definitiva; ver también
 * https://matrixify.app/documentation/orders/):
 * - "Command" siempre NEW, "Send Receipt" siempre FALSE (no reenviar emails al importar).
 * - "Inventory Behaviour" = bypass, para no tocar stock de Shopify al importar histórico.
 * - Se añade la etiqueta "SINCRONIZADO" en Tags (obligatorio según nota de la plantilla,
 *   si no se generan facturas de nuevo).
 * - "Source" = web (no hay forma fiable de distinguir canal en WooCommerce).
 * - "Line: Price" es el precio unitario BRUTO (antes de descuento), redondeado al
 *   alza cuando el subtotal no divide exacto entre la cantidad (ver
 *   line_unit_price). El descuento se exporta UNA sola vez, a nivel de pedido
 *   (fila "Discount"), nunca repetido en cada "Line Item" — si no, Shopify lo
 *   resta dos veces. Shopify no admite descuentos por línea vía API.
 * - Company (B2B) no se exporta — no aplica al modelo de datos actual.
 * - Impuestos SOLO a nivel de LÍNEA (Line: Tax N ...), nunca a nivel de pedido:
 *   Shopify ignora el impuesto de pedido y lo recalcula desde la tasa, y el
 *   resultado difiere por céntimos. Matrixify: "If any Line Item or Shipping Line
 *   has tax applied in the import file, then order level tax will not be imported".
 * - El IVA que viaja es EXACTAMENTE el que declaró WooCommerce. Todos los céntimos
 *   de redondeo (precio unitario, recargo, total del pedido) los absorbe la fila
 *   "Discount", que se despeja para que las filas sumen lo que se cobró de verdad.
 *   Un impuesto es una declaración a Hacienda; un descuento, un número comercial.
 * - Transaction: Force Gateway / Test = FALSE siempre, para evitar disparar pagos
 *   reales en la pasarela durante la migración.
 * - Email de pedido/cliente: si el email de WooCommerce no es válido, se omite de
 *   los campos Email (Shopify rechaza emails inválidos) y se deja constancia en Note.
 */
class ISE_Order_Exporter {

    /** @var int contador global de "Number" de pedido, incremental para todo el export */
    private $sequence = 0;

    /** @var int[]|null caché de rate_ids de recargo, calculada una vez por export */
    private $re_rate_ids = null;

    public function reset_sequence() {
        $this->sequence = 0;
    }

    /**
     * @param WC_Order $order
     * @return array[] lista de filas (assoc header => valor)
     */
    public function rows_for_order(WC_Order $order) {
        $this->sequence++;
        $number = $this->sequence;

        $rows = array();

        $base = array_fill_keys(ISE_Matrixify_Columns::headers(), '');
        $base['Command']              = 'NEW';
        $base['Send Receipt']         = 'FALSE';
        $base['Inventory Behaviour']  = 'bypass';
        $base['Number']               = $number;
        $base['Cancel: Send Receipt'] = 'FALSE';
        $base['Cancel: Refund']       = 'FALSE';
        $base['Currency']             = $order->get_currency();
        $base['Source']               = 'web';

        $line_items     = $order->get_items('line_item');
        $shipping_items = $order->get_items('shipping');
        $refunds        = $order->get_refunds();
        $has_refunds    = !empty($refunds);

        $first = true;
        // Un pedido completado se marca como enviado poniendo los campos
        // Fulfillment:* en CADA fila "Line Item" (no solo en la primera):
        // Matrixify fulfilla únicamente la línea en la que rellenas esos
        // campos, así que si solo se ponen en una línea el pedido queda
        // "partial" en Shopify aunque esté completado en WooCommerce.
        $fulfill_lines = ($order->get_status() === 'completed');
        // Fulfillment: ID debe ser numérico para Matrixify (no admite prefijos
        // de texto); reutilizamos el propio Number del pedido, que ya es único
        // dentro de este export.
        $fulfillment_id = $number;

        // El recargo de equivalencia no es un producto en WooCommerce (es un
        // impuesto compuesto), pero en Shopify se modela como una línea de
        // producto aparte apuntando a un Product ID fijo (ver configuración
        // del plugin). Lo sacamos de los totales de impuesto del pedido para
        // no contarlo dos veces en Tax: Total.
        $re_amount = $this->re_surcharge_amount($order);

        // Se calcula una sola vez por pedido: la reconciliación necesita ver
        // TODAS las líneas a la vez para repartir el resto del redondeo.
        $line_taxes = $this->reconciled_line_taxes($order);

        // Importe definitivo de la línea de recargo, que puede llevar un céntimo
        // de ajuste para que el descuento no salga negativo (ver re_line_amount).
        $re_line = $re_amount > 0.0001
            ? $this->re_line_amount($order, $re_amount, $line_taxes)
            : 0.0;

        foreach ($line_items as $item_id => $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_inline_sale($row, $order, $has_refunds);
                $first = false;
            }
            $this->fill_line_item_fields($row, $item);
            if (!empty($line_taxes[$item_id])) {
                $this->fill_line_tax_fields($row, $line_taxes[$item_id]);
            }
            if ($fulfill_lines) {
                $this->fill_fulfillment_fields($row, $order, $fulfillment_id);
            }
            $rows[] = $row;
        }

        if ($re_amount > 0.0001) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_inline_sale($row, $order, $has_refunds);
                $first = false;
            }
            $this->fill_re_line_fields($row, $re_line);
            if ($fulfill_lines) {
                // Aunque no es un envío físico, se marca como entregada igual
                // que el resto de líneas para que el pedido histórico quede
                // completamente "Entregado" y no se quede un pendiente colgado.
                $this->fill_fulfillment_fields($row, $order, $fulfillment_id);
            }
            $rows[] = $row;
        }

        foreach ($shipping_items as $item_id => $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_inline_sale($row, $order, $has_refunds);
                $first = false;
            }
            $this->fill_shipping_line_fields($row, $item);
            if (!empty($line_taxes[$item_id])) {
                $this->fill_line_tax_fields($row, $line_taxes[$item_id]);
            }
            $rows[] = $row;
        }

        // Una única fila Discount con el descuento REAL del pedido, venga de
        // donde venga. Antes se emitía una fila por cupón, lo que dejaba fuera
        // cualquier descuento que no fuese un cupón nativo (ver
        // real_discount_total). Shopify no admite descuentos por línea vía API,
        // así que Matrixify obliga a que vayan a nivel de pedido y los reparte
        // él entre las líneas.
        $discount_amount = $this->real_discount_total($order, $re_line, $line_taxes);
        if ($discount_amount > 0.0001) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_inline_sale($row, $order, $has_refunds);
                $first = false;
            }
            $this->fill_discount_line_fields($row, $discount_amount, $this->coupon_codes($order));
            $rows[] = $row;
        }

        // Si el pedido no tiene ninguna línea (caso raro), al menos una fila con los datos del pedido.
        if (empty($rows)) {
            $row = $base;
            $this->fill_order_fields($row, $order);
            $this->fill_inline_sale($row, $order, $has_refunds);
            $rows[] = $row;
        }

        // Con reembolsos, la venta va en su PROPIA fila Transaction (no inline en
        // una Line Item): en cuanto el fichero tiene una fila Line: Type =
        // Transaction —y el reembolso genera una—, Matrixify ignora las columnas
        // de transacción de las demás filas. Si la venta se quedara inline,
        // Matrixify no la vería y el reembolso fallaría con "Unable to find a
        // transaction to refund". Debe ir ANTES que las filas de reembolso.
        if ($has_refunds) {
            $venta = $base;
            $venta['Line: Type'] = 'Transaction';
            $this->fill_transaction_fields($venta, $order);
            if (!empty($venta['Transaction: Kind'])) {  // vacío si nunca se cobró
                $rows[] = $venta;
            }
        }

        foreach ($refunds as $refund) {
            foreach ($this->refund_rows($base, $refund, $order) as $refund_row) {
                $rows[] = $refund_row;
            }
        }

        return $rows;
    }

    private function fill_order_fields(array &$row, WC_Order $order) {
        $status = $order->get_status();
        $email  = $order->get_billing_email();
        $note   = $order->get_customer_note();

        $phone_raw = $this->normalize_phone($order->get_billing_phone(), $order->get_billing_country());
        $row['Phone'] = $this->usable_phone($phone_raw);
        if ($phone_raw && !$row['Phone']) {
            $note = trim($note . "\nTeléfono original (no válido para Shopify): {$phone_raw}");
        }

        if ($email && is_email($email)) {
            $row['Email'] = $email;
        } elseif ($email) {
            $note = trim($note . "\nEmail original (no válido para Shopify): {$email}");
        }
        $row['Note'] = $note;
        $row['Tags'] = 'SINCRONIZADO';

        if ($status === 'cancelled') {
            $row['Cancelled At'] = $this->format_date($order->get_date_modified());
        }

        $row['Processed At'] = $this->format_date($order->get_date_created());
        if (in_array($status, array('completed'), true)) {
            $row['Closed At'] = $this->format_date($order->get_date_completed() ?: $order->get_date_modified());
        }

        // Los impuestos van SOLO a nivel de línea (ver fill_line_tax_fields), así
        // que aquí no se rellenan ni Tax N: ... ni Tax: Total. Matrixify descarta
        // el impuesto de pedido en cuanto alguna línea trae el suyo, y dejarlo
        // puesto solo invitaba a confusión.
        //
        // Hasta la 0.4.0 esto llevaba un Tax: Total calculado por despeje
        // (reconciled_tax_total), partiendo de que Shopify lo aceptaría tal cual.
        // Es falso: Shopify recalcula desde la tasa, línea a línea. Medido sobre
        // 11 pedidos reales, 7 quedaban con un "Reembolsa 0,0X €" por la
        // diferencia. Peor aún, el despeje llegaba a producir impuestos NEGATIVOS
        // (-34,94 €) en pedidos con el 100% de descuento.
        $row['Tax: Included'] = wc_prices_include_tax() ? 'TRUE' : 'FALSE';
        $row['Payment: Status'] = $this->map_payment_status($status);

        // Trazabilidad Woo <-> Shopify: número de pedido original de WooCommerce
        // (el humano, p.ej. 25872), no el ID interno. Uso interno de IVB.
        $row['Metafield: custom.pedido_woo [single_line_text_field]'] = $order->get_order_number();

        // Auxiliar para la reconciliación (ver comentario en la columna): nombre
        // de usuario de WordPress del cliente, normalmente su email original.
        $wp_user = $order->get_user();
        $row['Woo User Login'] = $wp_user ? $wp_user->user_login : '';

        $customer_id = $order->get_customer_id();
        $row['Customer: ID']         = $customer_id ?: '';
        if ($email && is_email($email)) {
            $row['Customer: Email'] = $email;
        }
        $row['Customer: Phone']      = $row['Phone'];
        $row['Customer: First Name'] = $order->get_billing_first_name();
        $row['Customer: Last Name']  = $order->get_billing_last_name();

        $row['Billing: First Name']    = $order->get_billing_first_name();
        $row['Billing: Last Name']     = $order->get_billing_last_name();
        $row['Billing: Name']          = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $row['Billing: Company']       = $order->get_billing_company();
        $row['Billing: Phone']         = $row['Phone'];
        $row['Billing: Address 1']     = $order->get_billing_address_1();
        $row['Billing: Address 2']     = $order->get_billing_address_2();
        $row['Billing: Zip']           = $order->get_billing_postcode();
        $row['Billing: City']          = $order->get_billing_city();
        $row['Billing: Province']      = $order->get_billing_state();
        $row['Billing: Country']       = $this->country_name($order->get_billing_country());
        $row['Billing: Country Code']  = $order->get_billing_country();

        $row['Shipping: First Name']   = $order->get_shipping_first_name();
        $row['Shipping: Last Name']    = $order->get_shipping_last_name();
        $row['Shipping: Name']         = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $row['Shipping: Company']      = $order->get_shipping_company();
        $row['Shipping: Phone']        = $this->usable_phone(
            $this->normalize_phone($order->get_shipping_phone(), $order->get_shipping_country())
        ) ?: $row['Phone'];
        $row['Shipping: Address 1']    = $order->get_shipping_address_1();
        $row['Shipping: Address 2']    = $order->get_shipping_address_2();
        $row['Shipping: Zip']          = $order->get_shipping_postcode();
        $row['Shipping: City']         = $order->get_shipping_city();
        $row['Shipping: Province']     = $order->get_shipping_state();
        $row['Shipping: Country']      = $this->country_name($order->get_shipping_country());
        $row['Shipping: Country Code'] = $order->get_shipping_country();
    }

    /**
     * El recargo de equivalencia (RE) en esta tienda vive dentro de clases de
     * impuesto dedicadas ("Tarifas estándar + RE", "Tarifas Tasa reducida +
     * RE" — ver WooCommerce > Ajustes > Impuestos), cada una con DOS tasas:
     * la base (21% o 10%) y el propio recargo (5,2% o 1,4%, los porcentajes
     * fijados por ley para RE). Identificamos la tasa de recargo por ser la
     * que, dentro de esas clases, coincide con uno de esos porcentajes
     * legales — así no dependemos de cómo esté escrito el nombre de la tasa.
     * Como red de seguridad, también se acepta que el nombre contenga el
     * texto configurado (por defecto "recargo").
     */
    private function is_re_rate($tax_total) {
        return $this->is_re_rate_id($tax_total->rate_id, $tax_total->label);
    }

    /**
     * Igual que is_re_rate() pero partiendo de un rate_id suelto, que es lo que
     * devuelven las líneas en $item->get_taxes(). El label se busca solo si hace
     * falta (los rate_id configurados resuelven la mayoría sin consultar nada).
     */
    private function is_re_rate_id($rate_id, $label = null) {
        if (in_array((int) $rate_id, $this->re_rate_ids(), true)) {
            return true;
        }
        $keyword = trim((string) get_option('ise_re_tax_keyword', 'recargo'));
        if ($keyword === '') {
            return false;
        }
        if ($label === null) {
            $label = WC_Tax::get_rate_label($rate_id);
        }
        return stripos((string) $label, $keyword) !== false;
    }

    /**
     * Impuesto de cada línea del pedido, ya redondeado a céntimos y reconciliado
     * para que la SUMA coincida exactamente con el IVA que declaró WooCommerce.
     *
     * Hace falta reconciliar porque WooCommerce redondea el impuesto a nivel de
     * subtotal, no por línea: cada línea guarda su parte con precisión de más de
     * dos decimales, y al redondearlas por separado y sumarlas no vuelve el total
     * original — sum(round(x)) != round(sum(x)). Medido sobre los pedidos del 1-2
     * de julio, 213 de 416 se desviaban así, en ambas direcciones.
     *
     * El resto se carga entero a la línea con más impuesto de esa tasa: es donde
     * un céntimo pesa menos en proporción. La consecuencia es que una línea puede
     * llevar un céntimo de más o de menos, pero el TOTAL de IVA del pedido es el
     * que se declaró — que es lo que importa fiscalmente y lo que Shopify suma.
     *
     * Se usa get_taxes()['total'] y no ['subtotal']: el primero es el impuesto
     * sobre lo que de verdad se cobró, ya con el descuento aplicado.
     *
     * El recargo de equivalencia se excluye: viaja como línea de producto aparte
     * (ver fill_re_line_fields), así que contarlo aquí lo duplicaría.
     *
     * @return array item_id => array( rate_id => importe redondeado )
     */
    private function reconciled_line_taxes(WC_Order $order) {
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        $por_linea = array();
        foreach (array('line_item', 'shipping') as $type) {
            foreach ($order->get_items($type) as $item_id => $item) {
                $taxes = $item->get_taxes();
                if (empty($taxes['total'])) {
                    continue;
                }
                foreach ($taxes['total'] as $rate_id => $amount) {
                    $amount = (float) $amount;
                    if (!$amount || $this->is_re_rate_id($rate_id)) {
                        continue;
                    }
                    $por_linea[$item_id][(int) $rate_id] = round($amount, $decimals);
                }
            }
        }

        // Objetivo por tasa: el importe agregado que WooCommerce da por bueno.
        foreach ($order->get_tax_totals() as $tax_total) {
            if ($this->is_re_rate($tax_total)) {
                continue;
            }
            $rate_id = (int) $tax_total->rate_id;
            $target  = round((float) $tax_total->amount, $decimals);

            $suma = 0.0;
            $mayor_id = null;
            $mayor    = -INF;
            foreach ($por_linea as $item_id => $tasas) {
                if (!isset($tasas[$rate_id])) {
                    continue;
                }
                $suma += $tasas[$rate_id];
                if ($tasas[$rate_id] > $mayor) {
                    $mayor    = $tasas[$rate_id];
                    $mayor_id = $item_id;
                }
            }

            $resto = round($target - $suma, $decimals);
            if ($mayor_id !== null && abs($resto) >= 0.0000001) {
                $por_linea[$mayor_id][$rate_id] = round(
                    $por_linea[$mayor_id][$rate_id] + $resto, $decimals
                );
            }
        }

        return $por_linea;
    }

    /**
     * Vuelca en la fila los impuestos ya reconciliados de esa línea.
     *
     * @param array $taxes rate_id => importe, tal y como los devuelve
     *                     reconciled_line_taxes() para este item
     */
    private function fill_line_tax_fields(array &$row, array $taxes) {
        $slots = array(1, 2, 3);

        foreach ($taxes as $rate_id => $amount) {
            if (!$amount) {
                continue;
            }
            $slot = array_shift($slots);
            if (!$slot) {
                break; // Matrixify solo admite 3 impuestos por línea
            }
            $rate = WC_Tax::get_rate_percent_value($rate_id);
            $row["Line: Tax {$slot} Title"] = WC_Tax::get_rate_label($rate_id);
            $row["Line: Tax {$slot} Rate"]  = $rate !== '' ? $this->num($rate / 100) : '';
            $row["Line: Tax {$slot} Price"] = $this->money($amount);
        }
    }

    private function re_rate_ids() {
        if ($this->re_rate_ids !== null) {
            return $this->re_rate_ids;
        }

        // Porcentajes de recargo de equivalencia fijados por ley en España
        // (general 5,2 %, reducido 1,4 %, superreducido 0,5 %, tabaco 1,75 %).
        $re_percentages = array(0.5, 1.4, 1.75, 5.2);
        $classes = array_filter(array_map('trim', explode(
            ',',
            (string) get_option('ise_re_tax_classes', 'estandar-re,tasa-reducida-re')
        )));

        $rate_ids = array();
        foreach ($classes as $class) {
            foreach (WC_Tax::get_rates_for_tax_class($class) as $rate) {
                $percent = round((float) $rate->tax_rate, 2);
                if (in_array($percent, $re_percentages, true)) {
                    $rate_ids[] = (int) $rate->tax_rate_id;
                }
            }
        }

        $this->re_rate_ids = $rate_ids;
        return $rate_ids;
    }

    /**
     * @param WC_Abstract_Order $order pedido o reembolso: el RE es un impuesto en
     *        ambos, y un WC_Order_Refund también expone get_tax_totals().
     */
    private function re_surcharge_amount($order) {
        $amount = 0.0;
        foreach ($order->get_tax_totals() as $tax_total) {
            if ($this->is_re_rate($tax_total)) {
                $amount += (float) $tax_total->amount;
            }
        }
        return $amount;
    }

    /**
     * Descuento real del pedido, deducido de las propias líneas: para cada una,
     * lo que separa el precio de catálogo (get_subtotal) del que se cobró
     * (get_total).
     *
     * NO se usa get_items('coupon') a propósito. Esa vía solo ve los cupones
     * nativos de WooCommerce, y en esta tienda los descuentos los aplica
     * woo-discount-rules, que baja el total de la línea SIN crear ítem de cupón.
     * El resultado era que un pedido con el 100% de descuento se exportaba a
     * precio íntegro y sin descuento alguno: en Shopify aparecía debiendo todo el
     * importe cuando en WooCommerce estaba saldado.
     *
     * La resta subtotal-total funciona con cualquier mecanismo — cupón nativo,
     * regla de precios o ajuste a mano — porque mide el efecto, no la causa.
     */
    /**
     * Precio unitario tal y como se escribe en "Line: Price". Única fuente para
     * ese número: lo usan tanto fill_line_item_fields() como
     * real_discount_total(), y tienen que coincidir al céntimo o el pedido no
     * cuadra. Devuelve string, ya redondeado por money().
     *
     * Hay líneas de WooCommerce que NO se pueden representar en Shopify: Woo
     * guarda el total de la línea y Shopify el precio por unidad, con dos
     * decimales. Un subtotal de 199,71 en 15 unidades son 13,314 por unidad, y
     * ni 13,31 (=199,65) ni 13,32 (=199,80) reconstruyen 199,71.
     *
     * Ante esa imposibilidad se redondea SIEMPRE AL ALZA, para que el bruto
     * quede por encima del subtotal y nunca por debajo. Así la diferencia es
     * siempre un descuento positivo, que real_discount_total() absorbe. Si se
     * redondeara a la baja, la diferencia sería un "descuento" negativo — o sea,
     * dinero que falta — y Shopify no admite tal cosa: el pedido acabaría
     * debiendo unos céntimos.
     */
    private function line_unit_price(WC_Order_Item_Product $item) {
        $qty      = max(1, (int) $item->get_quantity());
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $subtotal = (float) $item->get_subtotal();

        $unit = round($subtotal / $qty, $decimals);

        // Si al reconstruir (unitario x cantidad) se queda corto, sube un céntimo.
        // No se usa ceil() a propósito: ceil(14.13 * 100) puede dar 1414 en vez de
        // 1413 por el error de representación de los flotantes, e inflaría precios
        // que ya eran exactos.
        $step = 1 / pow(10, $decimals);
        if (round($unit * $qty, $decimals) < round($subtotal, $decimals)) {
            $unit += $step;
        }

        return $this->money($unit);
    }

    /**
     * Descuento del pedido: la cifra que hace que las filas del CSV sumen
     * EXACTAMENTE lo que se cobró.
     *
     *     descuento = bruto de las líneas + impuestos - total del pedido
     *
     * Es un despeje, sí — como el que tenía Tax: Total hasta la 0.5.0. La
     * diferencia es dónde cae el residuo, y esa diferencia lo es todo:
     *
     *  - En el IVA (lo viejo): obligaba a declarar un impuesto que no se cobró, y
     *    encima Shopify lo ignoraba y recalculaba, así que ni siquiera cuadraba.
     *    Llegó a producir impuestos negativos de -34,94 EUR.
     *  - En el descuento (esto): el IVA que viaja es el que declaró WooCommerce,
     *    intacto, y el residuo cae en una cifra comercial donde un céntimo no
     *    afirma nada falso sobre nada.
     *
     * Se despeja en vez de ir restando subtotal-total línea a línea porque los
     * céntimos brotan de sitios que no acabas nunca de enumerar: el precio
     * unitario que Shopify solo admite con dos decimales, el IVA que Woo redondea
     * a nivel de subtotal, el propio total del pedido que Woo redondea aparte.
     * Fueron cuatro intentos de cazarlos uno a uno y siempre quedaba otro. Así
     * cuadra por construcción, no por haberlos encontrado todos.
     *
     * El resultado no puede salir negativo: line_unit_price() redondea al alza, de
     * modo que el bruto nunca queda por debajo del subtotal.
     *
     * @param float $re_amount   recargo, que va como línea de producto propia
     * @param array $line_taxes  impuestos ya reconciliados (reconciled_line_taxes)
     */
    private function real_discount_total(WC_Order $order, $re_line, array $line_taxes) {
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        // Bruto tal y como queda escrito en el CSV, con los mismos redondeos:
        // cualquier desvío aquí reaparece como descuadre en Shopify.
        $bruto = (float) $re_line;
        foreach ($order->get_items('line_item') as $item) {
            $qty = max(1, (int) $item->get_quantity());
            $bruto += (float) $this->line_unit_price($item) * $qty;
        }
        foreach ($order->get_items('shipping') as $item) {
            $bruto += (float) $this->money($item->get_total());
        }

        $impuestos = 0.0;
        foreach ($line_taxes as $tasas) {
            foreach ($tasas as $amount) {
                $impuestos += (float) $amount;
            }
        }

        // Total ORIGINAL del pedido, sin restar reembolsos. El CSV representa el
        // pedido tal como se hizo; la devolución la aplican aparte las Refund Line
        // y la transacción 'refund'. Restar aquí el reembolso hacía que, en un
        // reembolso total, el objetivo fuera 0 y el despeje declarara TODO el
        // pedido como descuento: el pedido acababa en Shopify valiendo solo el IVA
        // (13,42 € en vez de 149,54) con un descuento fantasma de 138,87.
        $target = round((float) $order->get_total(), $decimals);

        return round($bruto + $impuestos - $target, $decimals);
    }

    /**
     * Importe a escribir en la línea de recargo, subido un céntimo si hace falta
     * para que el descuento no salga negativo.
     *
     * WooCommerce calcula el recargo con todos los decimales (1,4% de 148,84 son
     * 2,08376) y suma el total con esa precisión. Nosotros solo podemos escribir
     * 2,08, así que el CSV se queda un céntimo corto y el despeje pide un
     * descuento de -0,01 — que es un recargo, y Shopify no admite tal cosa.
     *
     * El céntimo se mete aquí porque la línea de recargo es una línea de
     * PRODUCTO, no una casilla de impuestos: subirla un céntimo no altera ningún
     * IVA declarado. Es el mismo criterio que line_unit_price().
     *
     * @return float importe ya redondeado, listo para la fila
     */
    private function re_line_amount(WC_Order $order, $re_amount, array $line_taxes) {
        $re_line = (float) $this->money($re_amount);

        $discount = $this->real_discount_total($order, $re_line, $line_taxes);
        if ($discount < -0.0001) {
            // Restar un negativo: sube el recargo justo lo que faltaba.
            $re_line = (float) $this->money($re_line - $discount);
        }

        return $re_line;
    }

    /** Códigos de los cupones aplicados, solo para etiquetar la fila Discount. */
    private function coupon_codes(WC_Order $order) {
        $codes = array();
        foreach ($order->get_items('coupon') as $item) {
            $codes[] = $item->get_code();
        }
        return $codes;
    }

    /**
     * Línea de producto para el recargo de equivalencia, apuntando al ID de
     * producto fijo en Shopify (configurable), tal y como muestra la fila de
     * ejemplo de la propia plantilla del cliente (Product ID 14932398932333,
     * Title "re").
     */
    private function fill_re_line_fields(array &$row, $amount) {
        $row['Line: Type']       = 'Line Item';
        $row['Line: Product ID'] = trim((string) get_option('ise_re_shopify_product_id', '14932398932333'));
        $row['Line: Title']      = 're';
        $row['Line: Quantity']   = 1;
        $row['Line: Price']      = $this->money($amount);
        $row['Line: Gift Card']  = 'FALSE';
    }

    /**
     * La venta va "inline" (columnas Transaction en la primera fila del pedido)
     * SOLO si el pedido no tiene reembolsos. Con reembolsos hay filas Line: Type
     * = Transaction, y entonces Matrixify ignora las transacciones inline: la
     * venta se emite en su propia fila Transaction (ver rows_for_order). Los
     * pagados sin reembolso siguen inline, que es como ya estaban probados.
     */
    private function fill_inline_sale(array &$row, WC_Order $order, $has_refunds) {
        if ($has_refunds) {
            return;
        }
        $this->fill_transaction_fields($row, $order);
    }

    private function fill_transaction_fields(array &$row, WC_Order $order) {
        // Pedidos que NUNCA se han cobrado (en espera / pendiente de pago, típico
        // de las transferencias sin validar): no se les pone transacción. Así en
        // Shopify entran como "pendiente de pago" y el importe queda por cobrar,
        // en vez de aparecer como pagados. Se detecta por is_paid() (processing/
        // completed) y por get_date_paid() (por si acaso el estado no basta): un
        // reembolsado SÍ tiene date_paid, así que pasa este filtro y conserva su
        // transacción, correcto — hubo un cobro que luego se devolvió.
        if (!$order->is_paid() && !$order->get_date_paid()) {
            return;
        }

        $row['Transaction: Kind']           = 'sale';
        $row['Transaction: Processed At']   = $this->format_date($order->get_date_paid() ?: $order->get_date_created());
        // Importe COMPLETO originalmente cobrado, NO el neto tras reembolsos. Cada
        // reembolso viaja como su propia transacción 'refund' (ver refund_rows),
        // así que el neto en Shopify sale de sale - refunds. Antes se restaba el
        // reembolso aquí Y se emitía la transacción refund: se descontaba dos
        // veces. Y para un reembolso total no se emitía venta ninguna, dejando un
        // 'refund' sin cobro detrás — y Shopify no admite devolver lo no cobrado
        // ("a refund can only happen after a capture or sale transaction").
        $row['Transaction: Amount']         = $this->money($order->get_total());
        $row['Transaction: Currency']       = $order->get_currency();
        $row['Transaction: Status']         = 'success';
        $row['Transaction: Gateway']        = $order->get_payment_method_title() ?: $order->get_payment_method();
        // FALSE explícito: durante una migración nunca queremos disparar cargos reales
        // en la pasarela ni transacciones de prueba marcadas como reales.
        $row['Transaction: Force Gateway']  = 'FALSE';
        $row['Transaction: Test']           = 'FALSE';
    }

    private function fill_fulfillment_fields(array &$row, WC_Order $order, $fulfillment_id) {
        $row['Fulfillment: ID']              = $fulfillment_id;
        $row['Fulfillment: Status']          = 'success';
        $row['Fulfillment: Processed At']    = $this->format_date($order->get_date_completed() ?: $order->get_date_modified());
        // Obligatorio en cuanto se rellena "Fulfillment: Processed At": todos
        // estos pedidos están completados en WooCommerce, así que se marcan
        // como entregados.
        $row['Fulfillment: Shipment Status'] = 'delivered';
        $row['Fulfillment: Send Receipt']    = 'FALSE';
    }

    private function fill_line_item_fields(array &$row, WC_Order_Item_Product $item) {
        $qty      = max(1, (int) $item->get_quantity());
        $product  = $item->get_product();

        $row['Line: Type']              = 'Line Item';
        $row['Line: Title']             = $item->get_name();
        $row['Line: SKU']               = $product ? $product->get_sku() : '';
        $row['Line: Quantity']          = $qty;
        // Precio unitario BRUTO (antes de cupón). El descuento del cupón NO se
        // repite aquí: ya se exporta una vez, a nivel de pedido, en la fila
        // "Discount" (fill_discount_line_fields). Ponerlo también aquí lo
        // restaría dos veces del total en Shopify.
        $row['Line: Price']             = $this->line_unit_price($item);
        $row['Line: Requires Shipping'] = ($product && $product->needs_shipping()) ? 'TRUE' : 'FALSE';
        $row['Line: Taxable']           = ((float) $item->get_total_tax() > 0 || wc_prices_include_tax()) ? 'TRUE' : 'FALSE';
        $row['Line: Gift Card']         = 'FALSE';

        if ($product) {
            $weight = (float) $product->get_weight();
            if ($weight > 0) {
                $row['Line: Grams'] = (int) round($this->to_grams($weight) * $qty);
            }
            // get_global_unique_id() (GTIN/EAN) no existe hasta WooCommerce 9.1;
            // sin este guard, en versiones anteriores es un fatal que tumba el
            // export entero sin decir por qué.
            if (method_exists($product, 'get_global_unique_id')) {
                $barcode = $product->get_global_unique_id();
                if ($barcode) {
                    $row['Line: Variant Barcode'] = $barcode;
                }
            }
        }
        // Los impuestos de la línea los pone rows_for_order() con los valores ya
        // reconciliados (ver reconciled_line_taxes): esa cuenta necesita ver todas
        // las líneas juntas, así que no se puede hacer aquí.
        //
        // Van a nivel de línea y no de pedido porque Shopify ignora el impuesto de
        // pedido y lo recalcula desde la tasa: medido sobre 11 pedidos importados,
        // 7 acababan con un "Reembolsa 0,0X €". Con el impuesto en la línea no
        // tiene nada que recalcular. Según https://matrixify.app/documentation/orders/
        // "If any Line Item or Shipping Line has tax applied in the import file,
        // then order level tax will not be imported to avoid duplicating taxes".
    }

    private function fill_shipping_line_fields(array &$row, WC_Order_Item_Shipping $item) {
        $row['Line: Type']     = 'Shipping Line';
        $row['Line: Title']    = $item->get_name() ?: __('Envío', 'ivb-shopify-export');
        $row['Line: Price']    = $this->money($item->get_total());
        $row['Line: Taxable']  = ((float) $item->get_total_tax() > 0) ? 'TRUE' : 'FALSE';
        // El impuesto del envío lo pone rows_for_order(), igual que el de los
        // artículos y por el mismo motivo: entra en la reconciliación del pedido.
    }

    /**
     * Matrixify solo soporta descuentos a nivel de pedido (Discount), con
     * Line: Title = "percentage" o "fixed_amount" y Line: Price = el valor
     * del descuento (porcentaje o importe fijo). El importe realmente
     * aplicado va en Line: Discount, en negativo.
     * Ver https://matrixify.app/documentation/orders/ : "Line: Discount ... can
     * be entered only through Shopify Admin ... because Shopify doesn't allow
     * setting line item discounts through API".
     *
     * Siempre se exporta como fixed_amount, nunca como percentage: el importe en
     * euros es un hecho ya consumado del pedido, mientras que el porcentaje
     * obligaría a Shopify a recalcularlo y a discrepar por redondeo. Además, un
     * pedido puede acumular descuentos de varios orígenes a la vez (cupón y regla
     * de precios), y entonces no existe un único porcentaje que los describa.
     *
     * @param float    $amount importe total descontado, en positivo
     * @param string[] $codes  códigos de cupón, solo para etiquetar
     */
    private function fill_discount_line_fields(array &$row, $amount, array $codes = array()) {
        $row['Line: Type']     = 'Discount';
        $row['Line: Name']     = $codes ? implode(', ', $codes) : __('Descuento', 'ivb-shopify-export');
        $row['Line: Title']    = 'fixed_amount';
        $row['Line: Price']    = $this->money($amount);
        $row['Line: Discount'] = $this->money(-1 * $amount);
    }

    /**
     * Genera las filas "Refund Line" / "Refund Shipping" (una por artículo/
     * envío devuelto, cantidad en negativo) más una fila de resumen con los
     * campos Refund y Transaction del reembolso, todas con el mismo
     * Refund: ID para que Matrixify las agrupe.
     */
    private function refund_rows(array $base, WC_Order_Refund $refund, WC_Order $order) {
        $rows      = array();
        $refund_id = $refund->get_id();
        $decimals  = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        foreach ($refund->get_items('line_item') as $item) {
            $row     = $base;
            $product = $item->get_product();
            $qty     = abs((int) $item->get_quantity());

            $row['Line: Type']     = 'Refund Line';
            $row['Line: Title']    = $item->get_name();
            $row['Line: SKU']      = $product ? $product->get_sku() : '';
            $row['Line: Quantity'] = $qty ? -$qty : '';
            $row['Line: Price']    = $qty ? $this->money(abs((float) $item->get_total()) / $qty) : '';
            $row['Refund: ID']     = $refund_id;

            // IVA devuelto de esta línea, igual que en las líneas normales. Sin
            // esto, la Refund Line viaja sin impuesto y Shopify lo infiere a ojo
            // (10% limpio) al reducir current_total_price, difiriendo del IVA real
            // que devolvió WooCommerce por unos céntimos en reembolsos parciales.
            $ref_taxes = array();
            $t = $item->get_taxes();
            if (!empty($t['total'])) {
                foreach ($t['total'] as $rate_id => $amount) {
                    $amount = abs((float) $amount);
                    if ($amount && !$this->is_re_rate_id($rate_id)) {
                        $ref_taxes[(int) $rate_id] = round($amount, $decimals);
                    }
                }
            }
            if ($ref_taxes) {
                $this->fill_line_tax_fields($row, $ref_taxes);
            }

            $rows[] = $row;
        }

        foreach ($refund->get_items('shipping') as $item) {
            $row = $base;
            $row['Line: Type']  = 'Refund Shipping';
            $row['Line: Title'] = $item->get_name();
            $row['Line: Price'] = $this->money(abs((float) $item->get_total()));
            $row['Refund: ID']  = $refund_id;
            $rows[] = $row;
        }

        // El recargo de equivalencia se exporta como línea de producto "re", así
        // que su devolución también tiene que ser una Refund Line propia. En
        // WooCommerce el RE es un impuesto (no una línea), y por eso no aparece en
        // get_items('line_item') del reembolso: sin esta fila, el producto "re" se
        // quedaba SIN reembolsar en Shopify y el pedido "reembolsado" conservaba un
        // residuo por cobrar (el importe del recargo).
        $re_refunded = abs((float) $this->re_surcharge_amount($refund));
        if ($re_refunded > 0.0001) {
            $row = $base;
            $row['Line: Type']       = 'Refund Line';
            $row['Line: Product ID'] = trim((string) get_option('ise_re_shopify_product_id', '14932398932333'));
            $row['Line: Title']      = 're';
            $row['Line: Quantity']   = -1;
            $row['Line: Price']      = $this->money($re_refunded);
            $row['Refund: ID']       = $refund_id;
            $rows[] = $row;
        }

        if (empty($rows)) {
            // Reembolso sin líneas asociadas (p.ej. ajuste manual de importe).
            $row = $base;
            $row['Refund: ID'] = $refund_id;
            $rows[] = $row;
        }

        $rows[0]['Refund: Created At']           = $this->format_date($refund->get_date_created());
        $rows[0]['Refund: Note']                 = $refund->get_reason();
        $rows[0]['Refund: Restock']              = 'FALSE';
        $rows[0]['Refund: Send Receipt']         = 'FALSE';
        // FALSE: la transacción 'refund' se añade explícitamente abajo, en su
        // propia fila, así que Matrixify no debe generar otra.
        $rows[0]['Refund: Generate Transaction'] = 'FALSE';

        // La transacción de reembolso va en una fila propia con Line: Type =
        // "Transaction", NO en la fila "Refund Line". Matrixify rechaza lo
        // segundo: "Transaction columns filled in Refund Line row. ... use row
        // with Line: Type set to Transaction". Se agrupa con el reembolso por
        // Refund: ID. La venta que este refund necesita detrás la emite
        // fill_transaction_fields con el total completo del pedido.
        $tx = $base;
        $tx['Line: Type']                = 'Transaction';
        $tx['Refund: ID']                = $refund_id;
        $tx['Transaction: Kind']         = 'refund';
        $tx['Transaction: Processed At'] = $this->format_date($refund->get_date_created());
        $tx['Transaction: Amount']       = $this->money(abs((float) $refund->get_amount()));
        $tx['Transaction: Currency']     = $order->get_currency();
        $tx['Transaction: Status']       = 'success';
        $tx['Transaction: Gateway']      = $order->get_payment_method_title() ?: $order->get_payment_method();
        $tx['Transaction: Force Gateway'] = 'FALSE';
        $tx['Transaction: Test']         = 'FALSE';
        $rows[] = $tx;

        return $rows;
    }

    private function map_payment_status($wc_status) {
        switch ($wc_status) {
            case 'processing':
            case 'completed':
                return 'paid';
            case 'refunded':
                return 'refunded';
            case 'cancelled':
            case 'failed':
                return 'voided';
            case 'on-hold':
            case 'pending':
            default:
                return 'pending';
        }
    }

    /**
     * Matrixify exige el teléfono en formato internacional completo
     * (p.ej. +34 600 111 222). WooCommerce normalmente solo guarda el
     * número local, así que anteponemos el prefijo del país de la
     * dirección si no lo lleva ya. Solo se cubre España (tienda IVB);
     * para otros países se deja el número tal cual.
     */
    private function normalize_phone($phone, $country_code) {
        $phone = trim((string) $phone);
        if ($phone === '' || strpos($phone, '+') === 0) {
            return $phone;
        }

        $dial_codes = array('ES' => '+34');
        if (isset($dial_codes[$country_code])) {
            return $dial_codes[$country_code] . ' ' . ltrim($phone, '0 ');
        }

        return $phone;
    }

    /**
     * Shopify rechaza el pedido ENTERO si el teléfono no le vale ("Failed to
     * create Customer: Phone is invalid"), igual que hace con los emails mal
     * formados. Un teléfono de más no compensa perder el pedido, así que si no
     * supera un mínimo de plausibilidad se omite y se deja constancia en la nota.
     *
     * No se valida a fondo a propósito: Shopify usa libphonenumber y replicarlo
     * aquí sería tan frágil como inútil. Solo se filtra lo evidentemente
     * inservible — sin dígitos suficientes para ser un teléfono de nadie.
     */
    private function usable_phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return strlen($digits) >= 9 ? $phone : '';
    }

    private function country_name($code) {
        if (!$code) {
            return '';
        }
        $countries = WC()->countries ? WC()->countries->get_countries() : array();
        return $countries[$code] ?? $code;
    }

    private function to_grams($weight) {
        $unit = get_option('woocommerce_weight_unit', 'kg');
        switch ($unit) {
            case 'g':
                return $weight;
            case 'kg':
                return $weight * 1000;
            case 'lbs':
                return $weight * 453.592;
            case 'oz':
                return $weight * 28.3495;
            default:
                return $weight;
        }
    }

    private function format_date($date) {
        if (!$date instanceof WC_DateTime) {
            return '';
        }
        return $date->date('Y-m-d\TH:i:s');
    }

    /** Para tasas (Tax N: Rate) — necesitan más precisión que el dinero, p.ej. 0.077. */
    private function num($value) {
        if ($value === '' || $value === null) {
            return '';
        }
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Para importes en la divisa de la tienda (precios, descuentos, totales).
     * Redondear a más decimales de los que usa la divisa (p.ej. 4 en vez de 2)
     * acumula descuadres al recalcular totales en Shopify: un pedido con varias
     * líneas puede acabar con "Total Outstanding" != 0 por unos pocos céntimos.
     */
    private function money($value) {
        if ($value === '' || $value === null) {
            return '';
        }
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        return number_format((float) $value, $decimals, '.', '');
    }
}
