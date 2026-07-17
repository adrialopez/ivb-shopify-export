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
 * - "Line: Price" es el precio unitario BRUTO (antes de descuento). El
 *   descuento del cupón se exporta UNA sola vez, a nivel de pedido (fila
 *   "Discount"), nunca repetido también en cada "Line Item" — si no, Shopify
 *   lo resta dos veces del total.
 * - Company (B2B) no se exporta — no aplica al modelo de datos actual.
 * - Impuestos SOLO a nivel de pedido (Tax 1/2/3), nunca también a nivel de línea:
 *   Matrixify indica explícitamente que rellenar ambos duplica/falla el import.
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
        $coupon_items   = $order->get_items('coupon');
        $refunds        = $order->get_refunds();

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

        foreach ($line_items as $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order, $re_amount);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_line_item_fields($row, $item);
            if ($fulfill_lines) {
                $this->fill_fulfillment_fields($row, $order, $fulfillment_id);
            }
            $rows[] = $row;
        }

        if ($re_amount > 0.0001) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order, $re_amount);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_re_line_fields($row, $re_amount);
            $rows[] = $row;
        }

        foreach ($shipping_items as $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order, $re_amount);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_shipping_line_fields($row, $item);
            $rows[] = $row;
        }

        foreach ($coupon_items as $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order, $re_amount);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_discount_line_fields($row, $item);
            $rows[] = $row;
        }

        // Si el pedido no tiene ninguna línea (caso raro), al menos una fila con los datos del pedido.
        if (empty($rows)) {
            $row = $base;
            $this->fill_order_fields($row, $order, $re_amount);
            $this->fill_transaction_fields($row, $order);
            $rows[] = $row;
        }

        foreach ($refunds as $refund) {
            foreach ($this->refund_rows($base, $refund, $order) as $refund_row) {
                $rows[] = $refund_row;
            }
        }

        return $rows;
    }

    private function fill_order_fields(array &$row, WC_Order $order, $re_amount = 0.0) {
        $status = $order->get_status();
        $email  = $order->get_billing_email();
        $note   = $order->get_customer_note();

        $row['Phone'] = $this->normalize_phone($order->get_billing_phone(), $order->get_billing_country());

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

        $this->fill_tax_totals($row, $order);
        $row['Tax: Included'] = wc_prices_include_tax() ? 'TRUE' : 'FALSE';
        // El recargo (ya movido a su propia línea de producto) se resta aquí
        // para no sumarlo dos veces al total del pedido en Shopify.
        $row['Tax: Total']    = $this->money($order->get_total_tax() - $re_amount);
        $row['Payment: Status'] = $this->map_payment_status($status);

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
        $row['Shipping: Phone']        = $this->normalize_phone($order->get_shipping_phone(), $order->get_shipping_country()) ?: $row['Phone'];
        $row['Shipping: Address 1']    = $order->get_shipping_address_1();
        $row['Shipping: Address 2']    = $order->get_shipping_address_2();
        $row['Shipping: Zip']          = $order->get_shipping_postcode();
        $row['Shipping: City']         = $order->get_shipping_city();
        $row['Shipping: Province']     = $order->get_shipping_state();
        $row['Shipping: Country']      = $this->country_name($order->get_shipping_country());
        $row['Shipping: Country Code'] = $order->get_shipping_country();
    }

    private function fill_tax_totals(array &$row, WC_Order $order) {
        $tax_totals = $order->get_tax_totals();
        $slots = array(1, 2, 3);

        foreach ($tax_totals as $tax_total) {
            if ($this->is_re_rate($tax_total)) {
                continue; // se exporta como línea de producto aparte, ver fill_re_line_fields()
            }
            $slot = array_shift($slots);
            if (!$slot) {
                break;
            }
            $rate = WC_Tax::get_rate_percent_value($tax_total->rate_id);
            $row["Tax {$slot}: Title"] = $tax_total->label;
            $row["Tax {$slot}: Rate"]  = $rate !== '' ? $this->num($rate / 100) : '';
            $row["Tax {$slot}: Price"] = $this->money($tax_total->amount);
        }
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
        if (in_array((int) $tax_total->rate_id, $this->re_rate_ids(), true)) {
            return true;
        }
        $keyword = trim((string) get_option('ise_re_tax_keyword', 'recargo'));
        return $keyword !== '' && stripos((string) $tax_total->label, $keyword) !== false;
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

    private function re_surcharge_amount(WC_Order $order) {
        $amount = 0.0;
        foreach ($order->get_tax_totals() as $tax_total) {
            if ($this->is_re_rate($tax_total)) {
                $amount += (float) $tax_total->amount;
            }
        }
        return $amount;
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

    private function fill_transaction_fields(array &$row, WC_Order $order) {
        if ((float) $order->get_total_refunded() > 0 && (float) $order->get_total_refunded() >= (float) $order->get_total()) {
            return; // el reembolso total se registra como su propia transacción vía refund_row()
        }

        $row['Transaction: Kind']           = 'sale';
        $row['Transaction: Processed At']   = $this->format_date($order->get_date_paid() ?: $order->get_date_created());
        $row['Transaction: Amount']         = $this->money($order->get_total() - $order->get_total_refunded());
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
        $subtotal = (float) $item->get_subtotal();

        $row['Line: Type']              = 'Line Item';
        $row['Line: Title']             = $item->get_name();
        $row['Line: SKU']               = $product ? $product->get_sku() : '';
        $row['Line: Quantity']          = $qty;
        // Precio unitario BRUTO (antes de cupón). El descuento del cupón NO se
        // repite aquí: ya se exporta una vez, a nivel de pedido, en la fila
        // "Discount" (fill_discount_line_fields). Ponerlo también aquí lo
        // restaría dos veces del total en Shopify.
        $row['Line: Price']             = $this->money($subtotal / $qty);
        $row['Line: Requires Shipping'] = ($product && $product->needs_shipping()) ? 'TRUE' : 'FALSE';
        $row['Line: Taxable']           = ((float) $item->get_total_tax() > 0 || wc_prices_include_tax()) ? 'TRUE' : 'FALSE';
        $row['Line: Gift Card']         = 'FALSE';

        if ($product) {
            $weight = (float) $product->get_weight();
            if ($weight > 0) {
                $row['Line: Grams'] = (int) round($this->to_grams($weight) * $qty);
            }
            $barcode = $product->get_global_unique_id();
            if ($barcode) {
                $row['Line: Variant Barcode'] = $barcode;
            }
        }
        // Nota: no se rellenan impuestos a nivel de línea (Line: Tax N ...) a propósito.
        // Matrixify solo admite impuestos a nivel de pedido O de línea, nunca ambos a
        // la vez (ver fill_tax_totals) — y la propia plantilla del cliente usa solo
        // el nivel de pedido.
    }

    private function fill_shipping_line_fields(array &$row, WC_Order_Item_Shipping $item) {
        $row['Line: Type']     = 'Shipping Line';
        $row['Line: Title']    = $item->get_name() ?: __('Envío', 'ivb-shopify-export');
        $row['Line: Price']    = $this->money($item->get_total());
        $row['Line: Taxable']  = ((float) $item->get_total_tax() > 0) ? 'TRUE' : 'FALSE';
    }

    /**
     * Matrixify solo soporta descuentos a nivel de pedido (Discount), con
     * Line: Title = "percentage" o "fixed_amount" y Line: Price = el valor
     * del descuento (porcentaje o importe fijo). El importe realmente
     * aplicado va en Line: Discount, en negativo.
     */
    private function fill_discount_line_fields(array &$row, WC_Order_Item_Coupon $item) {
        $amount = (float) $item->get_discount();
        $type   = method_exists($item, 'get_discount_type') ? $item->get_discount_type() : '';

        if (!$type && function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($item->get_code())) {
            $coupon = new WC_Coupon($item->get_code());
            $type   = $coupon->get_discount_type();
        }

        $row['Line: Type'] = 'Discount';
        $row['Line: Name'] = $item->get_code();

        if ($type === 'percent' && isset($coupon)) {
            $row['Line: Title'] = 'percentage';
            $row['Line: Price'] = $this->money($coupon->get_amount());
        } else {
            $row['Line: Title'] = 'fixed_amount';
            $row['Line: Price'] = $this->money($amount);
        }

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
        // FALSE: la transacción de tipo "refund" ya se añade explícitamente abajo,
        // así que no necesitamos que Matrixify genere una automáticamente.
        $rows[0]['Refund: Generate Transaction'] = 'FALSE';
        $rows[0]['Transaction: Kind']            = 'refund';
        $rows[0]['Transaction: Processed At']    = $this->format_date($refund->get_date_created());
        $rows[0]['Transaction: Amount']          = $this->money(abs((float) $refund->get_amount()));
        $rows[0]['Transaction: Currency']        = $order->get_currency();
        $rows[0]['Transaction: Status']          = 'success';
        $rows[0]['Transaction: Gateway']         = $order->get_payment_method_title() ?: $order->get_payment_method();
        $rows[0]['Transaction: Force Gateway']   = 'FALSE';
        $rows[0]['Transaction: Test']            = 'FALSE';

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
