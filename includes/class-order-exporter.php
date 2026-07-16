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
 * - "Line: Price" es el precio unitario ANTES de descuento; el descuento de línea
 *   (si lo hay) va en "Line: Discount".
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

        foreach ($line_items as $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_line_item_fields($row, $item);
            $rows[] = $row;
        }

        foreach ($shipping_items as $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_shipping_line_fields($row, $item);
            $rows[] = $row;
        }

        foreach ($coupon_items as $item) {
            $row = $base;
            if ($first) {
                $this->fill_order_fields($row, $order);
                $this->fill_transaction_fields($row, $order);
                $first = false;
            }
            $this->fill_discount_line_fields($row, $item);
            $rows[] = $row;
        }

        // Si el pedido no tiene ninguna línea (caso raro), al menos una fila con los datos del pedido.
        if (empty($rows)) {
            $row = $base;
            $this->fill_order_fields($row, $order);
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

    private function fill_order_fields(array &$row, WC_Order $order) {
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
        $row['Tax: Total']    = $this->num($order->get_total_tax());
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
            $slot = array_shift($slots);
            if (!$slot) {
                break;
            }
            $rate = WC_Tax::get_rate_percent_value($tax_total->rate_id);
            $row["Tax {$slot}: Title"] = $tax_total->label;
            $row["Tax {$slot}: Rate"]  = $rate !== '' ? $this->num($rate / 100) : '';
            $row["Tax {$slot}: Price"] = $this->num($tax_total->amount);
        }
    }

    private function fill_transaction_fields(array &$row, WC_Order $order) {
        if ((float) $order->get_total_refunded() > 0 && (float) $order->get_total_refunded() >= (float) $order->get_total()) {
            return; // el reembolso total se registra como su propia transacción vía refund_row()
        }

        $row['Transaction: Kind']           = 'sale';
        $row['Transaction: Processed At']   = $this->format_date($order->get_date_paid() ?: $order->get_date_created());
        $row['Transaction: Amount']         = $this->num($order->get_total() - $order->get_total_refunded());
        $row['Transaction: Currency']       = $order->get_currency();
        $row['Transaction: Status']         = 'success';
        $row['Transaction: Gateway']        = $order->get_payment_method_title() ?: $order->get_payment_method();
        // FALSE explícito: durante una migración nunca queremos disparar cargos reales
        // en la pasarela ni transacciones de prueba marcadas como reales.
        $row['Transaction: Force Gateway']  = 'FALSE';
        $row['Transaction: Test']           = 'FALSE';
        $row['Fulfillment: Status']         = ($order->get_status() === 'completed') ? 'success' : '';
    }

    private function fill_line_item_fields(array &$row, WC_Order_Item_Product $item) {
        $qty      = max(1, (int) $item->get_quantity());
        $product  = $item->get_product();
        $subtotal = (float) $item->get_subtotal();
        $total    = (float) $item->get_total();
        $discount = $subtotal - $total;

        $row['Line: Type']              = 'Line Item';
        $row['Line: Title']             = $item->get_name();
        $row['Line: SKU']               = $product ? $product->get_sku() : '';
        $row['Line: Quantity']          = $qty;
        $row['Line: Price']             = $this->num($subtotal / $qty);
        if ($discount > 0.0001) {
            $row['Line: Discount'] = $this->num($discount);
        }
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
        $row['Line: Price']    = $this->num($item->get_total());
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
            $row['Line: Price'] = $this->num($coupon->get_amount());
        } else {
            $row['Line: Title'] = 'fixed_amount';
            $row['Line: Price'] = $this->num($amount);
        }

        $row['Line: Discount'] = $this->num(-1 * $amount);
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
            $row['Line: Price']    = $qty ? $this->num(abs((float) $item->get_total()) / $qty) : '';
            $row['Refund: ID']     = $refund_id;
            $rows[] = $row;
        }

        foreach ($refund->get_items('shipping') as $item) {
            $row = $base;
            $row['Line: Type']  = 'Refund Shipping';
            $row['Line: Title'] = $item->get_name();
            $row['Line: Price'] = $this->num(abs((float) $item->get_total()));
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
        $rows[0]['Transaction: Amount']          = $this->num(abs((float) $refund->get_amount()));
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

    private function num($value) {
        if ($value === '' || $value === null) {
            return '';
        }
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
