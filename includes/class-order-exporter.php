<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convierte pedidos de WooCommerce a filas con la estructura de la plantilla
 * Matrixify "Orders" (una fila por pedido/línea, agrupadas por columna "Number").
 *
 * Asunciones (revisar antes de la migración definitiva):
 * - "Command" siempre NEW, "Send Receipt" siempre FALSE (no reenviar emails al importar).
 * - "Inventory Behaviour" = bypass, para no tocar stock de Shopify al importar histórico.
 * - Se añade la etiqueta "SINCRONIZADO" en Tags (obligatorio según nota de la plantilla,
 *   si no se generan facturas de nuevo).
 * - "Source" = web (no hay forma fiable de distinguir canal en WooCommerce).
 * - "Line: Price" es el precio unitario ANTES de descuento; el descuento de línea
 *   (si lo hay) va en "Line: Discount".
 * - Company (B2B) no se exporta — no aplica al modelo de datos actual.
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
            $rows[] = $this->refund_row($base, $refund, $order);
        }

        return $rows;
    }

    private function fill_order_fields(array &$row, WC_Order $order) {
        $status = $order->get_status();

        $row['Phone'] = $order->get_billing_phone();
        $row['Email'] = $order->get_billing_email();
        $row['Note']  = $order->get_customer_note();
        $row['Tags']  = 'SINCRONIZADO';

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
        $row['Customer: Email']      = $order->get_billing_email();
        $row['Customer: Phone']      = $order->get_billing_phone();
        $row['Customer: First Name'] = $order->get_billing_first_name();
        $row['Customer: Last Name']  = $order->get_billing_last_name();

        $row['Billing: First Name']    = $order->get_billing_first_name();
        $row['Billing: Last Name']     = $order->get_billing_last_name();
        $row['Billing: Name']          = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $row['Billing: Company']       = $order->get_billing_company();
        $row['Billing: Phone']         = $order->get_billing_phone();
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
        $row['Shipping: Phone']        = $order->get_shipping_phone() ?: $order->get_billing_phone();
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

        $this->fill_line_taxes($row, $item);
    }

    private function fill_line_taxes(array &$row, WC_Order_Item $item) {
        $taxes = $item->get_taxes();
        if (empty($taxes['total'])) {
            return;
        }

        $slots = array(1, 2, 3);
        foreach ($taxes['total'] as $rate_id => $amount) {
            if ($amount === '' || $amount === null) {
                continue;
            }
            $slot = array_shift($slots);
            if (!$slot) {
                break;
            }
            $rate = WC_Tax::get_rate_percent_value($rate_id);
            $row["Line: Tax {$slot} Title"] = WC_Tax::get_rate_label($rate_id);
            $row["Line: Tax {$slot} Rate"]  = $rate !== '' ? $this->num($rate / 100) : '';
            $row["Line: Tax {$slot} Price"] = $this->num($amount);
        }
    }

    private function fill_shipping_line_fields(array &$row, WC_Order_Item_Shipping $item) {
        $row['Line: Type']     = 'Shipping Line';
        $row['Line: Title']    = $item->get_name() ?: __('Envío', 'ivb-shopify-export');
        $row['Line: Price']    = $this->num($item->get_total());
        $row['Line: Taxable']  = ((float) $item->get_total_tax() > 0) ? 'TRUE' : 'FALSE';
        $this->fill_line_taxes($row, $item);
    }

    private function fill_discount_line_fields(array &$row, WC_Order_Item_Coupon $item) {
        $row['Line: Type']  = 'Discount';
        $row['Line: Name']  = $item->get_code();
        $row['Line: Title'] = $item->get_code();
        $amount = (float) $item->get_discount();
        $row['Line: Price']    = 0;
        $row['Line: Discount'] = $this->num($amount);
    }

    private function refund_row(array $base, WC_Order_Refund $refund, WC_Order $order) {
        $row = $base;
        $row['Refund: ID']                     = $refund->get_id();
        $row['Refund: Created At']             = $this->format_date($refund->get_date_created());
        $row['Refund: Note']                   = $refund->get_reason();
        $row['Refund: Restock']                = 'FALSE';
        $row['Refund: Send Receipt']           = 'FALSE';
        $row['Refund: Generate Transaction']   = 'TRUE';
        $row['Transaction: Kind']              = 'refund';
        $row['Transaction: Processed At']      = $this->format_date($refund->get_date_created());
        $row['Transaction: Amount']            = $this->num(abs((float) $refund->get_amount()));
        $row['Transaction: Currency']          = $order->get_currency();
        $row['Transaction: Status']            = 'success';
        $row['Transaction: Gateway']           = $order->get_payment_method_title() ?: $order->get_payment_method();
        return $row;
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
