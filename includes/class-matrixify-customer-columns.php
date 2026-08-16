<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cabeceras para la plantilla Matrixify "Customers".
 *
 * A diferencia de class-matrixify-columns.php (Orders), estas columnas NO se
 * han validado contra una plantilla .xlsx real del cliente todavía — son las
 * estándar documentadas en https://matrixify.app/documentation/customers/.
 * Revisar y ajustar el orden/nombres exactos contra la plantilla real antes
 * de la importación definitiva.
 */
class ISE_Matrixify_Customer_Columns {

    public static function headers() {
        return array(
            'ID',
            'Command',
            'First Name',
            'Last Name',
            'Email',
            'Accepts Email Marketing',
            'Company',
            'Address1',
            'Address2',
            'City',
            'Province',
            'Zip',
            'Country',
            'Phone',
            'Accepts SMS Marketing',
            'Note',
            'Tax Exempt',
            'Tags',
            'Tags Command',
            // Auxiliar (no es de Matrixify): id de usuario de WordPress, para
            // trazabilidad y para casar con el export de Companies.
            'Woo User ID',
        );
    }
}
