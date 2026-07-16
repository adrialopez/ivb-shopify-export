<?php
/**
 * Plugin Name: IVB Shopify Export
 * Plugin URI: https://thinkingidea.com/
 * Description: Exporta pedidos de WooCommerce al formato Matrixify (Orders) para la migración a Shopify. Filtro por fechas y/o cliente.
 * Version: 0.2.1
 * Author: Thinking Idea
 * Author URI: https://thinkingidea.com/
 * Text Domain: ivb-shopify-export
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>IVB Shopify Export</strong> requiere WooCommerce para funcionar.</p></div>';
    });
    return;
}

define('ISE_VERSION', '0.2.1');
define('ISE_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once ISE_PLUGIN_DIR . 'includes/class-matrixify-columns.php';
require_once ISE_PLUGIN_DIR . 'includes/class-csv-writer.php';
require_once ISE_PLUGIN_DIR . 'includes/class-order-exporter.php';

class IVB_Shopify_Export {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_export'));
        add_action('admin_init', array($this, 'handle_save_settings'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Exportar a Shopify', 'ivb-shopify-export'),
            __('Exportar a Shopify', 'ivb-shopify-export'),
            'manage_woocommerce',
            'ivb-shopify-export',
            array($this, 'render_page')
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Exportar pedidos a Shopify (Matrixify)', 'ivb-shopify-export'); ?></h1>
            <p><?php esc_html_e('Genera un CSV con la estructura de la plantilla Matrixify "Orders", listo para importar en Shopify.', 'ivb-shopify-export'); ?></p>

            <?php if (!empty($_GET['ise_settings_saved'])) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Configuración guardada.', 'ivb-shopify-export'); ?></p></div>
            <?php endif; ?>

            <form method="post" style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:24px;margin-bottom:28px;display:flex;flex-wrap:wrap;gap:24px;align-items:flex-end;box-shadow:0 2px 8px rgba(0,0,0,.04);max-width:900px;">
                <?php wp_nonce_field('ise_settings'); ?>

                <div>
                    <label for="ise_re_tax_classes"><strong><?php esc_html_e('Clases de impuesto con recargo de equivalencia (slugs, separados por coma)', 'ivb-shopify-export'); ?></strong></label><br>
                    <input type="text" id="ise_re_tax_classes" name="ise_re_tax_classes" style="min-width:280px;" value="<?php echo esc_attr(get_option('ise_re_tax_classes', 'estandar-re,tasa-reducida-re')); ?>">
                    <p class="description"><?php esc_html_e('El slug de la URL en WooCommerce > Ajustes > Impuestos > [pestaña de esa clase], p.ej. "...&section=tasa-reducida-re".', 'ivb-shopify-export'); ?></p>
                </div>

                <div>
                    <label for="ise_re_tax_keyword"><strong><?php esc_html_e('Texto de respaldo en el nombre de la tasa (por si el slug no coincide)', 'ivb-shopify-export'); ?></strong></label><br>
                    <input type="text" id="ise_re_tax_keyword" name="ise_re_tax_keyword" style="min-width:200px;" value="<?php echo esc_attr(get_option('ise_re_tax_keyword', 'recargo')); ?>">
                </div>

                <div>
                    <label for="ise_re_shopify_product_id"><strong><?php esc_html_e('Product ID en Shopify para el recargo', 'ivb-shopify-export'); ?></strong></label><br>
                    <input type="text" id="ise_re_shopify_product_id" name="ise_re_shopify_product_id" style="min-width:200px;" value="<?php echo esc_attr(get_option('ise_re_shopify_product_id', '14932398932333')); ?>">
                </div>

                <div>
                    <button type="submit" name="ise_save_settings" value="1" class="button">
                        <?php esc_html_e('Guardar configuración', 'ivb-shopify-export'); ?>
                    </button>
                </div>
            </form>
            <p class="description" style="max-width:900px;margin-top:-16px;margin-bottom:28px;">
                <?php esc_html_e('Se identifica el recargo por las tasas configuradas en esas clases de impuesto que coincidan con los porcentajes legales de RE (5,2%, 1,4%, 0,5%, 1,75%), y como respaldo por el texto en el nombre de la tasa. Si se detecta, se saca de las columnas Tax N (y de Tax: Total) y se exporta como una línea de producto aparte apuntando a ese Product ID de Shopify, tal y como espera la plantilla del cliente.', 'ivb-shopify-export'); ?>
            </p>

            <form method="post" style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:24px;margin-bottom:28px;display:flex;flex-wrap:wrap;gap:24px;align-items:flex-end;box-shadow:0 2px 8px rgba(0,0,0,.04);max-width:900px;">
                <?php wp_nonce_field('ise_export'); ?>

                <div>
                    <label for="ise_date_from"><strong><?php esc_html_e('Desde', 'ivb-shopify-export'); ?></strong></label><br>
                    <input type="date" id="ise_date_from" name="date_from">
                </div>

                <div>
                    <label for="ise_date_to"><strong><?php esc_html_e('Hasta', 'ivb-shopify-export'); ?></strong></label><br>
                    <input type="date" id="ise_date_to" name="date_to">
                </div>

                <div>
                    <label for="ise_customer"><strong><?php esc_html_e('Cliente (email o ID)', 'ivb-shopify-export'); ?></strong></label><br>
                    <input type="text" id="ise_customer" name="customer" placeholder="<?php esc_attr_e('Opcional', 'ivb-shopify-export'); ?>">
                </div>

                <div>
                    <label for="ise_status"><strong><?php esc_html_e('Estados de pedido', 'ivb-shopify-export'); ?></strong></label><br>
                    <select id="ise_status" name="statuses[]" multiple size="4" style="min-width:200px;">
                        <?php foreach (wc_get_order_statuses() as $key => $label) :
                            $key = str_replace('wc-', '', $key);
                            $selected = in_array($key, array('completed', 'processing'), true);
                            ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($selected); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" name="ise_export_csv" value="1" class="button button-primary button-hero">
                        <?php esc_html_e('Exportar CSV', 'ivb-shopify-export'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings() {
        if (empty($_POST['ise_save_settings']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!check_admin_referer('ise_settings')) {
            return;
        }

        update_option('ise_re_tax_classes', sanitize_text_field($_POST['ise_re_tax_classes'] ?? 'estandar-re,tasa-reducida-re'));
        update_option('ise_re_tax_keyword', sanitize_text_field($_POST['ise_re_tax_keyword'] ?? 'recargo'));
        update_option('ise_re_shopify_product_id', sanitize_text_field($_POST['ise_re_shopify_product_id'] ?? ''));

        wp_safe_redirect(add_query_arg('ise_settings_saved', '1', wp_get_referer() ?: admin_url('admin.php?page=ivb-shopify-export')));
        exit;
    }

    public function handle_export() {
        if (empty($_POST['ise_export_csv']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!check_admin_referer('ise_export')) {
            return;
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $customer  = sanitize_text_field($_POST['customer'] ?? '');
        $statuses  = array_map('sanitize_key', (array) ($_POST['statuses'] ?? array()));

        $args = array(
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'ids',
        );

        if ($statuses) {
            $args['status'] = $statuses;
        }

        if ($date_from || $date_to) {
            $from = $date_from ?: '1970-01-01';
            $to   = $date_to ?: gmdate('Y-m-d');
            $args['date_created'] = $from . '...' . $to . ' 23:59:59';
        }

        if ($customer !== '') {
            if (is_email($customer)) {
                $args['billing_email'] = $customer;
            } elseif (is_numeric($customer)) {
                $args['customer_id'] = (int) $customer;
            }
        }

        // Exports grandes agotan memoria con caché de objetos persistente (Redis/etc.):
        // cada wc_get_order() va acumulando datos en el object cache sin liberarse
        // durante toda la petición. Procesamos por lotes y limpiamos caché entre ellos.
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $order_ids = wc_get_orders($args);

        $writer   = new ISE_CSV_Writer('shopify-orders-' . gmdate('Y-m-d-His') . '.csv');
        $exporter = new ISE_Order_Exporter();

        $batch_size = (int) apply_filters('ise_export_batch_size', 100);

        foreach (array_chunk($order_ids, $batch_size) as $batch) {
            foreach ($batch as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }
                foreach ($exporter->rows_for_order($order) as $row) {
                    $writer->write_row($row);
                }
                $order = null;
                // Libera del caché (persistente o no) los datos de este pedido en
                // concreto; más quirúrgico que un flush completo en cada iteración.
                clean_post_cache($order_id);
            }

            // Suelta el buffer de salida al servidor para no acumular todo el CSV
            // en memoria del lado del servidor.
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();

            // Red de seguridad para lo que clean_post_cache() no cubre (caché de
            // sesión/productos de WooCommerce). wp_cache_flush() vacía el caché
            // de objetos de todo el sitio, así que si el hosting usa caché
            // persistente (Redis/Memcached) mejor lanzar exports grandes fuera
            // de horas punta.
            wp_cache_flush();
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $writer->close();
        exit;
    }
}

add_action('plugins_loaded', array('IVB_Shopify_Export', 'get_instance'));
