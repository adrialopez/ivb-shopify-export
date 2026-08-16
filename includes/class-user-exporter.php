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

        // SEPA disponible: el rol 'sepa' es lo único que habilita el gateway
        // 'cod' en sepa_restrict_payment() — NO depende de ivb_capabilities.
        $sepa_disponible = in_array('sepa', (array) $user->roles, true) ? 'TRUE' : 'FALSE';

        // Límite máximo: el custom guardado en sepa_max_amount si existe
        // (incluido '0', que desactiva SEPA), si no el de sepa_get_default_max_amount()
        // según el rol de escala (misma lógica, no ivb_capabilities):
        // 20desc/15desc->20000, 10desc->10000, el resto (5desc o sin rol)->6000.
        $sepa_max_custom = get_user_meta($user_id, 'sepa_max_amount', true);
        $sepa_maximo_efectivo = $sepa_max_custom !== '' ? $sepa_max_custom : $this->sepa_max_por_rol($user);

        $sepa_minimo = get_user_meta($user_id, 'sepa_min_amount', true);
        $sepa_minimo = $sepa_minimo !== '' ? $sepa_minimo : '300';

        // sepa_days (30/60/90): es un número de DÍAS, no una fecha, aunque el
        // metacampo se llame "Fecha Vencimiento SEPA" en Shopify — confirmar
        // con el cliente si hace falta convertirlo a fecha antes de importar.
        $sepa_dias = get_user_meta($user_id, 'sepa_days', true);
        $sepa_dias = $sepa_dias !== '' ? $sepa_dias : '30';

        $historico_unidades = get_user_meta($user_id, 'unidadesCompradas', true);

        // escala_actual sale del rol de descuento del usuario, NO de
        // escalaAuto. escalaAuto solo se usa para escala_forzada: si vale 0
        // (sin rol de escala), el cliente marca la escala como forzada
        // manualmente.
        $escala_actual  = $this->escala_por_rol($user);
        $escala_forzada = ((string) get_user_meta($user_id, 'escalaAuto', true) === '0') ? 'TRUE' : 'FALSE';

        // purchase_limit_enabled controla tanto si el tope mensual aplica como
        // si tiene sentido calcular lo comprado en lo que va de mes: sin
        // límite activo, ese dato no se usa para nada en el negocio, así que
        // ni se consulta (evita una query por usuario sin motivo).
        $tiene_limite_activo = get_user_meta($user_id, 'purchase_limit_enabled', true) == 1;

        return array(
            'historico_unidades'     => $historico_unidades,
            'unidades_compradas_mes' => $tiene_limite_activo ? $this->monthly_purchased_units($user_id) : '',
            'sepa_disponible'        => $sepa_disponible,
            'minimo_sepa'            => $sepa_minimo,
            'limite_credito_sepa'    => $sepa_maximo_efectivo,
            'vencimiento_sepa'       => $sepa_dias,
            'maximo_unidades_mes'    => $tiene_limite_activo
                ? get_user_meta($user_id, 'monthly_purchase_limit', true)
                : '',
            'escala_actual'          => $escala_actual,
            'escala_forzada'         => $escala_forzada,
        );
    }

    /**
     * Escala según el rol de descuento del usuario. Un usuario solo debería
     * tener uno de estos roles; si tuviera varios, gana el de mayor escala.
     */
    private function escala_por_rol(WP_User $user) {
        $mapa_roles = array(
            '20desc'  => '4',
            '175desc' => '3.5',
            '15desc'  => '3',
            '10desc'  => '2',
            '5desc'   => '1',
        );

        foreach ($mapa_roles as $role => $escala) {
            if (in_array($role, (array) $user->roles, true)) {
                return $escala;
            }
        }

        return '0';
    }

    /**
     * Límite máximo de gasto SEPA por defecto según el rol de escala del
     * usuario, igual que sepa_get_default_max_amount() del plugin de SEPA:
     * 20desc y 15desc -> 20000, 10desc -> 10000, el resto (5desc o ningún
     * rol de escala) -> 6000. sepa_get_default_max_amount() no contempla
     * 175desc (escala 3.5) explícitamente, pero al ser un nivel intermedio
     * entre 15desc y 20desc (ambos 20000) se asume el mismo límite.
     */
    private function sepa_max_por_rol(WP_User $user) {
        $roles = (array) $user->roles;

        if (in_array('20desc', $roles, true) || in_array('175desc', $roles, true) || in_array('15desc', $roles, true)) {
            return '20000';
        }
        if (in_array('10desc', $roles, true)) {
            return '10000';
        }
        return '6000';
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
