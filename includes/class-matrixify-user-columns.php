<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cabeceras para el export de usuarios/empresas.
 *
 * En este negocio cada usuario de WordPress ES una empresa (B2B puro, sin
 * clientes particulares sueltos): no tiene sentido separarlos en dos CSV de
 * Customer y Company como hojas independientes, así que aquí van juntos en
 * un único fichero — datos de contacto + dirección + los 30 metacampos de
 * empresa (namespace upng), como columnas "Metafield: namespace.key [tipo]"
 * (misma convención que ya usa class-matrixify-columns.php para
 * custom.pedido_woo).
 *
 * Las columnas base NO se han validado contra una plantilla .xlsx real del
 * cliente — son un punto de partida razonable para Matrixify Customers/
 * Companies (ver https://matrixify.app/documentation/). Revisar antes de
 * importar si Shopify exige repartirlas en dos hojas al final.
 *
 * El tipo entre corchetes de cada metafield es el que dio el cliente al
 * listar los 30 campos. Antes de importar, confirmar que coincide EXACTO
 * con el tipo configurado en Shopify para ese metafield (p.ej. "text" en
 * Shopify admin puede corresponder a "single_line_text_field" en Matrixify);
 * si no coincide, Matrixify crea el valor pero no lo casa con la definición.
 */
class ISE_Matrixify_User_Columns {

    /**
     * Metacampos upng.* que SÍ se pueden rellenar con lo que hay hoy en
     * WordPress (ver ISE_User_Exporter::company_metafields). El resto se
     * exporta igualmente como columna (para tener ya la estructura completa
     * lista), pero siempre vacío hasta que se localice su meta_key de origen.
     */
    const CAMPOS_CON_DATO = array(
        'historico_unidades',
        'unidades_compradas_mes',
        'sepa_disponible',
        'minimo_sepa',
        'limite_credito_sepa',
        'vencimiento_sepa',
        'maximo_unidades_mes',
        'escala_actual',
        'escala_forzada',
    );

    public static function metafields() {
        return array(
            'historico_unidades'                => 'number_integer',
            'historico_unidades_migracion'       => 'number_integer',
            'historico_unidades_promocionales'   => 'json',
            'historico_promociones'              => 'json',
            'historico_productos_no_promo'       => 'json',
            'unidades_compradas_mes'             => 'number_integer',
            'codigo_agente_asignado'             => 'single_line_text_field',
            'codigo_agente_asignado2'            => 'single_line_text_field',
            'forma_de_pago'                      => 'single_line_text_field',
            'credito_consumido'                  => 'number_decimal',
            'facturas_vencidas'                  => 'boolean',
            'cliente_bloqueado'                  => 'boolean',
            'codigos_cliente_relacionados'       => 'multi_line_text_field',
            'zona_fiscal'                        => 'single_line_text_field',
            'tarifa'                             => 'single_line_text_field',
            'company-discount'                   => 'number_decimal',
            'sepa_disponible'                    => 'boolean',
            'vencimiento_sepa'                   => 'single_line_text_field',
            'minimo_sepa'                        => 'money',
            'limite_credito_sepa'                => 'money',
            'puede_escoger_pago'                 => 'boolean',
            'escala_actual'                      => 'single_line_text_field',
            'escala_forzada'                     => 'single_line_text_field',
            'maximo_unidades_mes'                => 'number_integer',
            'catalogos_exclusivos'               => 'multi_line_text_field',
            'agents_carts'                       => 'list.metaobject_reference',
            'estado_lead'                        => 'single_line_text_field',
            'lead_motivo_rechazo'                => 'multi_line_text_field',
            'shopify_company_id'                 => 'single_line_text_field',
            'shopify_sucursal_id'                => 'single_line_text_field',
        );
    }

    public static function metafield_column($key, $type) {
        return "Metafield: upng.{$key} [{$type}]";
    }

    public static function headers() {
        $base = array(
            'Customer: ID',
            'Customer: Email',
            'First Name',
            'Last Name',
            'Phone',
            'Company: Name',
            'Address 1',
            'Address 2',
            'City',
            'Province',
            'Zip',
            'Country',
            // Auxiliares (no son de Matrixify): NIF y código de cliente A3,
            // útiles para cuadrar el import a mano; quitar antes de subir el
            // CSV si Matrixify los interpreta como columna desconocida.
            'NIF',
            'Codigo Cliente A3',
        );

        $metafields = array();
        foreach (self::metafields() as $key => $type) {
            $metafields[] = self::metafield_column($key, $type);
        }

        return array_merge($base, $metafields);
    }
}
