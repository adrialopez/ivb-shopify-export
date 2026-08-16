<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convierte usuarios de WordPress/WooCommerce a filas Matrixify "Customers"
 * y "Companies" (con metacampos upng.*), siguiendo la misma lógica que la
 * query SQL manual que se usaba antes de tener este plugin.
 *
 * Solo una parte de los 30 metacampos de empresa tiene hoy un meta_key de
 * origen conocido (ver ISE_Matrixify_Company_Columns::CAMPOS_CON_DATO); el
 * resto se deja vacío hasta localizar su meta_key real en ivb_usermeta.
 */
class ISE_User_Exporter {

    /**
     * @param WP_User $user
     * @return array fila con la estructura de ISE_Matrixify_Customer_Columns
     */
    public function row_for_customer(WP_User $user) {
        $row = array_fill_keys(ISE_Matrixify_Customer_Columns::headers(), '');

        $row['Command']    = 'NEW';
        $row['First Name'] = get_user_meta($user->ID, 'billing_first_name', true) ?: $user->first_name;
        $row['Last Name']  = get_user_meta($user->ID, 'billing_last_name', true) ?: $user->last_name;
        $row['Email']      = $user->user_email;
        $row['Company']    = get_user_meta($user->ID, 'billing_company', true);
        $row['Address1']   = get_user_meta($user->ID, 'billing_address_1', true);
        $row['Address2']   = get_user_meta($user->ID, 'billing_address_2', true);
        $row['City']       = get_user_meta($user->ID, 'billing_city', true);
        $row['Province']   = get_user_meta($user->ID, 'billing_state', true);
        $row['Zip']        = get_user_meta($user->ID, 'billing_postcode', true);
        $row['Country']    = get_user_meta($user->ID, 'billing_country', true);
        $row['Phone']      = get_user_meta($user->ID, 'billing_phone', true);
        $row['Woo User ID'] = $user->ID;

        return $row;
    }

    /**
     * @param WP_User $user
     * @return array fila con la estructura de ISE_Matrixify_Company_Columns
     */
    public function row_for_company(WP_User $user) {
        $row = array_fill_keys(ISE_Matrixify_Company_Columns::headers(), '');

        $row['Command']         = 'NEW';
        $row['Company: Name']   = get_user_meta($user->ID, 'billing_company', true);
        $row['Customer: ID']    = $user->ID;
        $row['Customer: Email'] = $user->user_email;

        $row['Location: Name']       = get_user_meta($user->ID, 'billing_company', true);
        $row['Location: Address 1']  = get_user_meta($user->ID, 'billing_address_1', true);
        $row['Location: Address 2']  = get_user_meta($user->ID, 'billing_address_2', true);
        $row['Location: City']       = get_user_meta($user->ID, 'billing_city', true);
        $row['Location: Province']   = get_user_meta($user->ID, 'billing_state', true);
        $row['Location: Zip']        = get_user_meta($user->ID, 'billing_postcode', true);
        $row['Location: Country']    = get_user_meta($user->ID, 'billing_country', true);
        $row['Location: Phone']      = get_user_meta($user->ID, 'billing_phone', true);

        $row['NIF']               = get_user_meta($user->ID, 'cif', true);
        $row['Codigo Cliente A3'] = get_user_meta($user->ID, 'unique_identifier', true);

        foreach ($this->company_metafields($user) as $key => $value) {
            $type = ISE_Matrixify_Company_Columns::metafields()[$key];
            $row[ISE_Matrixify_Company_Columns::metafield_column($key, $type)] = $value;
        }

        return $row;
    }

    /**
     * Los 5 metacampos que hoy se pueden calcular con datos ya presentes en
     * WordPress. La misma lógica de la query SQL manual (ver conversación):
     * capabilities, límites de compra mensuales y SEPA.
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

        // Este es upng.historico_unidades (total histórico acumulado), NO
        // upng.unidades_compradas_mes (mes en curso, sin meta_key propio hoy:
        // se calcula al vuelo por producto en el plugin de límites — ver
        // get_user_monthly_purchase_count() — y aquí se deja vacío).
        $historico_unidades = get_user_meta($user_id, 'unidadesCompradas', true);

        return array(
            'historico_unidades'   => $historico_unidades,
            'sepa_disponible'      => $sepa_disponible,
            'minimo_sepa'          => $sepa_minimo,
            'limite_credito_sepa'  => $sepa_maximo_efectivo,
            // purchase_limit_enabled controla si el tope de abajo se aplica o
            // no; no hay columna upng.* separada para "límite activo" en la
            // lista de 30, así que monthly_purchase_limit solo se exporta si
            // el límite está encendido.
            'maximo_unidades_mes'  => get_user_meta($user_id, 'purchase_limit_enabled', true) == 1
                ? get_user_meta($user_id, 'monthly_purchase_limit', true)
                : '',
        );
    }
}
