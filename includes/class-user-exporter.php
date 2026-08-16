<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convierte usuarios de WordPress/WooCommerce a filas para el CSV de
 * usuarios/empresas (ver ISE_Matrixify_User_Columns), siguiendo la misma
 * lógica que la query SQL manual que se usaba antes de tener este plugin.
 *
 * Solo una parte de los 30 metacampos de empresa tiene hoy un meta_key de
 * origen conocido (ver ISE_Matrixify_User_Columns::CAMPOS_CON_DATO); el
 * resto se deja vacío hasta localizar su meta_key real en ivb_usermeta.
 */
class ISE_User_Exporter {

    /**
     * @param WP_User $user
     * @return array fila con la estructura de ISE_Matrixify_User_Columns
     */
    public function row_for_user(WP_User $user) {
        $row = array_fill_keys(ISE_Matrixify_User_Columns::headers(), '');

        $row['Customer: ID']    = $user->ID;
        $row['Customer: Email'] = $user->user_email;
        $row['First Name']      = get_user_meta($user->ID, 'billing_first_name', true) ?: $user->first_name;
        $row['Last Name']       = get_user_meta($user->ID, 'billing_last_name', true) ?: $user->last_name;
        $row['Phone']           = get_user_meta($user->ID, 'billing_phone', true);
        $row['Company: Name']   = get_user_meta($user->ID, 'billing_company', true);
        $row['Address 1']       = get_user_meta($user->ID, 'billing_address_1', true);
        $row['Address 2']       = get_user_meta($user->ID, 'billing_address_2', true);
        $row['City']            = get_user_meta($user->ID, 'billing_city', true);
        $row['Province']        = get_user_meta($user->ID, 'billing_state', true);
        $row['Zip']             = get_user_meta($user->ID, 'billing_postcode', true);
        $row['Country']         = get_user_meta($user->ID, 'billing_country', true);
        $row['NIF']              = get_user_meta($user->ID, 'cif', true);
        $row['Codigo Cliente A3'] = get_user_meta($user->ID, 'unique_identifier', true);

        foreach ($this->company_metafields($user) as $key => $value) {
            $type = ISE_Matrixify_User_Columns::metafields()[$key];
            $row[ISE_Matrixify_User_Columns::metafield_column($key, $type)] = $value;
        }

        return $row;
    }

    /**
     * Los metacampos que hoy se pueden calcular con datos ya presentes en
     * WordPress. La misma lógica de la query SQL manual (ver conversación):
     * capabilities, límites de compra mensuales, SEPA y escala.
     *
     * @return array key (sin prefijo upng.) => valor
     */
    private function company_metafields(WP_User $user) {
        $user_id = $user->ID;

        $capabilities = (string) get_user_meta($user_id, 'ivb_capabilities', true);
        $sepa_disponible = (strpos($capabilities, '"sepa"') !== false) ? 'TRUE' : 'FALSE';

        $sepa_max_custom = get_user_meta($user_id, 'sepa_max_amount', true);
        if ($sepa_max_custom !== '') {
            $sepa_maximo_efectivo = $sepa_max_custom;
        } elseif (strpos($capabilities, '"20desc"') !== false || strpos($capabilities, '"15desc"') !== false) {
            $sepa_maximo_efectivo = '20000';
        } elseif (strpos($capabilities, '"10desc"') !== false) {
            $sepa_maximo_efectivo = '10000';
        } else {
            $sepa_maximo_efectivo = '6000';
        }

        $sepa_minimo = get_user_meta($user_id, 'sepa_min_amount', true);
        $sepa_minimo = $sepa_minimo !== '' ? $sepa_minimo : '300';

        $historico_unidades = get_user_meta($user_id, 'unidadesCompradas', true);

        // escalaAuto ya trae calculada la escala según el rol de descuento del
        // usuario (5desc=1, 10desc=2, 15desc=3, 175desc=3.5, 20desc=4, sin
        // ninguno de esos roles=0). Cuando vale 0 (sin rol de escala), el
        // cliente marca la escala como forzada manualmente.
        $escala_actual  = get_user_meta($user_id, 'escalaAuto', true);
        $escala_forzada = ((string) $escala_actual === '0') ? 'TRUE' : 'FALSE';

        return array(
            'historico_unidades'     => $historico_unidades,
            'unidades_compradas_mes' => $this->monthly_purchased_units($user_id),
            'sepa_disponible'        => $sepa_disponible,
            'minimo_sepa'            => $sepa_minimo,
            'limite_credito_sepa'    => $sepa_maximo_efectivo,
            // purchase_limit_enabled controla si el tope de abajo se aplica o
            // no; no hay columna upng.* separada para "límite activo" en la
            // lista de 30, así que monthly_purchase_limit solo se exporta si
            // el límite está encendido.
            'maximo_unidades_mes'    => get_user_meta($user_id, 'purchase_limit_enabled', true) == 1
                ? get_user_meta($user_id, 'monthly_purchase_limit', true)
                : '',
            'escala_actual'          => $escala_actual,
            'escala_forzada'         => $escala_forzada,
        );
    }

    /**
     * Unidades totales compradas en el mes en curso, TODOS los productos.
     *
     * Adaptado de get_user_monthly_purchase_count() del plugin de límites de
     * compra: misma fuente (line items de pedidos completed/processing) y
     * mismo rango de fechas, pero sin filtrar por producto — ahí se usa para
     * comprobar el límite de UN producto, aquí para el total del mes.
     */
    private function monthly_purchased_units($user_id) {
        global $wpdb;

        $start_date = gmdate('Y-m-01 00:00:00');
        $end_date   = gmdate('Y-m-t 23:59:59');

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(oim.meta_value) as total_quantity
            FROM {$wpdb->prefix}woocommerce_order_items as oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta as oim ON oi.order_item_id = oim.order_item_id
            JOIN {$wpdb->posts} as p ON oi.order_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_author = %d
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_qty'
            AND p.post_date BETWEEN %s AND %s",
            $user_id,
            $start_date,
            $end_date
        ));

        return $total !== null ? (int) $total : 0;
    }
}
