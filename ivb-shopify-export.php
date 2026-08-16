<?php
/**
 * Plugin Name: IVB Shopify Export
 * Plugin URI: https://thinkingidea.com/
 * Description: Exporta pedidos de WooCommerce al formato Matrixify (Orders) y usuarios/empresas (Customers/Companies) para la migración a Shopify. Filtro por fechas y/o cliente.
 * Version: 0.9.4
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

define('ISE_VERSION', '0.9.4');
define('ISE_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once ISE_PLUGIN_DIR . 'includes/class-matrixify-columns.php';
require_once ISE_PLUGIN_DIR . 'includes/class-matrixify-user-columns.php';
require_once ISE_PLUGIN_DIR . 'includes/class-csv-writer.php';
require_once ISE_PLUGIN_DIR . 'includes/class-order-exporter.php';
require_once ISE_PLUGIN_DIR . 'includes/class-user-exporter.php';

class IVB_Shopify_Export {

    private static $instance = null;

    /** @var object|null estado del export en curso, leído por el shutdown handler */
    private $estado = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_export'));
        add_action('admin_init', array($this, 'handle_export_users'));
        add_action('admin_init', array($this, 'handle_save_settings'));

        // El comando WP-CLI existe solo cuando se corre desde consola. Es la vía
        // recomendada para el histórico completo: sin timeout web ni límite de
        // una petición HTTP. Ver cli_export().
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('ise export', array($this, 'cli_export'));
        }
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

            <?php
            if (!empty($_GET['ise_export_error'])) {
                $error = get_transient('ise_export_error_' . get_current_user_id());
                delete_transient('ise_export_error_' . get_current_user_id());
                if ($error) {
                    echo '<div class="notice notice-error"><p><strong>'
                        . esc_html__('El export no se completó:', 'ivb-shopify-export')
                        . '</strong><br>' . esc_html($error) . '</p></div>';
                }
            }
            ?>

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

            <h2><?php esc_html_e('Exportar usuarios/empresas', 'ivb-shopify-export'); ?></h2>
            <p class="description" style="max-width:900px;">
                <?php esc_html_e('Genera un único CSV con los datos de contacto/dirección del usuario y los metacampos upng.* de empresa: aquí cada usuario ES una empresa, no hay Customer y Company por separado. Solo una parte de los metacampos tiene hoy meta_key de origen conocido en WordPress (histórico de unidades, SEPA, límite mensual) — el resto sale vacío hasta localizar sus meta_key reales.', 'ivb-shopify-export'); ?>
            </p>
            <form method="post" style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:24px;margin-bottom:28px;display:flex;flex-wrap:wrap;gap:24px;align-items:flex-end;box-shadow:0 2px 8px rgba(0,0,0,.04);max-width:900px;">
                <?php wp_nonce_field('ise_export_users'); ?>

                <div>
                    <label for="ise_user_role"><strong><?php esc_html_e('Rol (opcional)', 'ivb-shopify-export'); ?></strong></label><br>
                    <select id="ise_user_role" name="role">
                        <option value=""><?php esc_html_e('Todos los roles', 'ivb-shopify-export'); ?></option>
                        <?php foreach (wp_roles()->get_names() as $slug => $label) : ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>
                        <input type="checkbox" name="solo_con_empresa" value="1" checked>
                        <?php esc_html_e('Solo usuarios con empresa asociada (billing_company)', 'ivb-shopify-export'); ?>
                    </label>
                </div>

                <div>
                    <button type="submit" name="ise_export_users_csv" value="1" class="button button-primary button-hero">
                        <?php esc_html_e('Exportar Usuarios/Empresas', 'ivb-shopify-export'); ?>
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

    /** Ruta del log propio del export. Separado del debug.log de WordPress, que
     *  se llena de avisos de otros plugins y hace imposible encontrar nada. */
    private function log_path() {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'ise-export.log';
    }

    private function log($mensaje) {
        $linea = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $mensaje . "\n";
        @file_put_contents($this->log_path(), $linea, FILE_APPEND | LOCK_EX);
    }

    /**
     * Un timeout de PHP-FPM o un OOM matan el proceso sin lanzar excepción, así
     * que no hay try/catch que los recoja: el único rastro posible es lo que ya
     * hayamos escrito en el log antes de morir. Este shutdown handler deja
     * constancia del último pedido en curso, que es justo el dato que hace falta
     * para reproducir el fallo.
     */
    private function register_crash_handler() {
        register_shutdown_function(function () {
            $estado = $this->estado;
            if (!$estado || $estado->finalizado) {
                return; // salida limpia (CSV entregado o abortado con aviso)
            }

            $error = error_get_last();
            $fatales = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

            if ($error && ($error['type'] & $fatales)) {
                $this->log(sprintf(
                    'FATAL procesando el pedido #%s: %s en %s:%d',
                    $estado->order_id ?: '?', $error['message'], $error['file'], $error['line']
                ));
            } else {
                // Ni excepción ni fatal de PHP: el proceso murió por fuera
                // (timeout del servidor, OOM del sistema, kill del hosting).
                $this->log(sprintf(
                    'INTERRUMPIDO sin error de PHP en el pedido #%s (%d de %d procesados). '
                    . 'Suele ser timeout o límite de memoria del hosting.',
                    $estado->order_id ?: '?', $estado->procesados, $estado->total
                ));
            }
        });
    }

    /**
     * Construye los argumentos de wc_get_orders() a partir de los filtros.
     * Compartido por el flujo web y el comando WP-CLI.
     */
    private function build_export_args($date_from, $date_to, $customer, array $statuses) {
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

        return $args;
    }

    /**
     * Corazón del export: recorre los pedidos por lotes y los vuelca en el
     * writer. No sabe nada de HTTP ni de CLI — las dos vías (formulario web y
     * comando WP-CLI) lo comparten para no duplicar la lógica delicada (saltar
     * reembolsos, tragar pedidos corruptos, liberar memoria entre lotes).
     *
     * @param int[]         $order_ids ids a exportar, en orden
     * @param ISE_CSV_Writer $writer
     * @param callable|null $on_order (int $order_id, int $index) antes de procesar
     *                                cada pedido; el web actualiza $estado aquí.
     * @param callable|null $on_batch (int $procesados, int $total, int $filas) tras
     *                                cada lote; para barra de progreso / log.
     * @return array{failed: array<int,string>, skipped: array<int,int>}
     */
    private function export_orders_to_writer(array $order_ids, ISE_CSV_Writer $writer,
                                             $on_order = null, $on_batch = null) {
        $exporter = new ISE_Order_Exporter();
        $fallidos = array();
        $omitidos = array();   // id de reembolso => id del pedido padre
        $procesados = 0;
        $total = count($order_ids);

        $batch_size = (int) apply_filters('ise_export_batch_size', 100);

        foreach (array_chunk($order_ids, $batch_size) as $batch) {
            foreach ($batch as $order_id) {
                if ($on_order) {
                    call_user_func($on_order, $order_id, $procesados);
                }

                try {
                    $order = wc_get_order($order_id);
                    if (!$order) {
                        $fallidos[$order_id] = 'no se pudo cargar el pedido';
                        continue;
                    }

                    // wc_get_orders() devuelve también IDs de reembolsos
                    // (WC_Order_Refund, que hereda de WC_Abstract_Order y no de
                    // WC_Order). No son pedidos y no se procesan aquí: cada
                    // reembolso ya se exporta desde rows_for_order() de su pedido
                    // padre, vía $order->get_refunds(). Procesarlos otra vez los
                    // duplicaría; pasarlos a rows_for_order() es un TypeError.
                    if (!$order instanceof WC_Order) {
                        $padre = method_exists($order, 'get_parent_id') ? $order->get_parent_id() : 0;
                        $this->log(sprintf(
                            'Omitido #%d: es un %s, no un pedido%s.',
                            $order_id, get_class($order),
                            $padre ? " (reembolso del pedido #{$padre})" : ''
                        ));
                        $omitidos[$order_id] = $padre;
                        continue;
                    }

                    foreach ($exporter->rows_for_order($order) as $row) {
                        $writer->write_row($row);
                    }
                    $order = null;
                } catch (Throwable $e) {
                    // Throwable, no Exception: en PHP 7+ los errores fatales
                    // recuperables (método inexistente, argumento de tipo
                    // incorrecto) son Error, no Exception. Un pedido corrupto no
                    // debe tumbar el export entero: se anota y se sigue.
                    $fallidos[$order_id] = $e->getMessage();
                    $this->log(sprintf('ERROR en el pedido #%d: %s en %s:%d',
                        $order_id, $e->getMessage(), $e->getFile(), $e->getLine()));
                }

                $procesados++;
                // Libera del caché (persistente o no) los datos de este pedido en
                // concreto; más quirúrgico que un flush completo en cada iteración.
                clean_post_cache($order_id);
            }

            $this->log(sprintf('Progreso: %d/%d pedidos, %d filas, memoria %s',
                $procesados, $total, $writer->rows_written(),
                size_format(memory_get_usage(true))));
            if ($on_batch) {
                call_user_func($on_batch, $procesados, $total, $writer->rows_written());
            }

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

        return array('failed' => $fallidos, 'skipped' => $omitidos);
    }

    /**
     * Reembolsos cuyo pedido padre NO está en el conjunto exportado: se pierden
     * en silencio si no se avisa (un pedido de junio reembolsado en julio, si
     * solo se migra julio). Devuelve refund_id => parent_id.
     */
    private function orphan_refunds(array $skipped, array $order_ids) {
        $huerfanos = array();
        foreach ($skipped as $refund_id => $padre_id) {
            if (!$padre_id || !in_array($padre_id, $order_ids)) {
                $huerfanos[$refund_id] = $padre_id;
            }
        }
        return $huerfanos;
    }

    public function handle_export() {
        if (empty($_POST['ise_export_csv']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!check_admin_referer('ise_export')) {
            return;
        }

        $args = $this->build_export_args(
            sanitize_text_field($_POST['date_from'] ?? ''),
            sanitize_text_field($_POST['date_to'] ?? ''),
            sanitize_text_field($_POST['customer'] ?? ''),
            array_map('sanitize_key', (array) ($_POST['statuses'] ?? array()))
        );

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

        // Estado compartido con el shutdown handler: si el proceso muere de
        // golpe, es lo único que quedará para saber por dónde iba.
        $estado = $this->estado = (object) array(
            'order_id'   => null,
            'procesados' => 0,
            'total'      => count($order_ids),
            'finalizado' => false,
        );
        $this->register_crash_handler();

        $this->log(sprintf('--- Export iniciado (web): %d pedidos (filtros: %s) ---',
            $estado->total, wp_json_encode($args)));

        if (!$order_ids) {
            $this->abortar('No hay pedidos que coincidan con esos filtros.');
        }

        try {
            $writer = new ISE_CSV_Writer();
        } catch (Throwable $e) {
            $this->log('FATAL creando el CSV: ' . $e->getMessage());
            $this->abortar('No se pudo crear el fichero temporal: ' . $e->getMessage());
        }

        $resultado = $this->export_orders_to_writer(
            $order_ids, $writer,
            function ($order_id, $procesados) use ($estado) {
                // Se registra ANTES de procesar: si esto revienta el proceso, el
                // log (vía shutdown handler) ya dice cuál era.
                $estado->order_id  = $order_id;
                $estado->procesados = $procesados;
            },
            function ($procesados) use ($estado) {
                $estado->procesados = $procesados;
            }
        );

        $fallidos = $resultado['failed'];
        $omitidos = $resultado['skipped'];

        if ($fallidos) {
            // Si algún pedido falló, no se entrega un CSV incompleto haciéndolo
            // pasar por bueno: se avisa con los IDs concretos. Un export al que
            // le faltan pedidos en silencio es peor que uno que no se descarga.
            $this->log(sprintf('Export terminado CON %d fallos: %s',
                count($fallidos), wp_json_encode($fallidos)));
            $writer->cleanup();
            $this->abortar(sprintf(
                'Fallaron %d de %d pedidos, así que no se ha generado el CSV. IDs: %s. Detalle en %s',
                count($fallidos), $estado->total,
                implode(', ', array_keys($fallidos)), $this->log_path()
            ));
        }

        $huerfanos = $this->orphan_refunds($omitidos, $order_ids);
        if ($huerfanos) {
            $this->log(sprintf(
                'AVISO: %d reembolso(s) cuyo pedido padre queda fuera de este export, '
                . 'así que NO se exportan: %s. Amplía el rango de fechas para incluir '
                . 'los pedidos padre si los necesitas.',
                count($huerfanos), wp_json_encode($huerfanos)
            ));
        }

        $this->log(sprintf('Export OK: %d pedidos, %d filas, %d reembolso(s) omitido(s).',
            $estado->procesados - count($omitidos), $writer->rows_written(), count($omitidos)));

        // A partir de aquí el shutdown handler ya no debe avisar de nada: si algo
        // se rompe durante readfile() es un problema de red, no del export.
        $estado->finalizado = true;
        $writer->deliver('shopify-orders-' . gmdate('Y-m-d-His') . '.csv');
        exit;
    }

    /**
     * Export de usuarios/empresas: un único CSV (ver ISE_Matrixify_User_Columns)
     * porque en este negocio cada usuario de WordPress ES una empresa, no hay
     * Customer y Company como entidades separadas.
     */
    public function handle_export_users() {
        if (empty($_POST['ise_export_users_csv']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!check_admin_referer('ise_export_users')) {
            return;
        }

        $role            = sanitize_key($_POST['role'] ?? '');
        $solo_con_empresa = !empty($_POST['solo_con_empresa']);

        $args = array('fields' => 'ID');
        if ($role !== '') {
            $args['role'] = $role;
        }
        if ($solo_con_empresa) {
            $args['meta_query'] = array(
                array(
                    'key'     => 'billing_company',
                    'value'   => '',
                    'compare' => '!=',
                ),
            );
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $user_ids = get_users($args);
        if (!$user_ids) {
            $this->abortar('No hay usuarios que coincidan con esos filtros.');
        }

        $exporter = new ISE_User_Exporter();
        $headers  = ISE_Matrixify_User_Columns::headers();
        $filename = 'shopify-usuarios-' . gmdate('Y-m-d-His') . '.csv';
        $builder  = array($exporter, 'row_for_user');

        $this->log(sprintf('--- Export usuarios iniciado: %d usuarios (rol: %s, solo con empresa: %s) ---',
            count($user_ids), $role ?: 'todos', $solo_con_empresa ? 'sí' : 'no'));

        try {
            $writer = new ISE_CSV_Writer(null, $headers);
        } catch (Throwable $e) {
            $this->log('FATAL creando el CSV de usuarios: ' . $e->getMessage());
            $this->abortar('No se pudo crear el fichero temporal: ' . $e->getMessage());
        }

        $fallidos = array();
        $batch_size = (int) apply_filters('ise_export_batch_size', 100);

        foreach (array_chunk($user_ids, $batch_size) as $batch) {
            foreach ($batch as $user_id) {
                $user = get_userdata($user_id);
                if (!$user) {
                    $fallidos[$user_id] = 'no se pudo cargar el usuario';
                    continue;
                }

                try {
                    $writer->write_row(call_user_func($builder, $user));
                } catch (Throwable $e) {
                    $fallidos[$user_id] = $e->getMessage();
                    $this->log(sprintf('ERROR en el usuario #%d: %s en %s:%d',
                        $user_id, $e->getMessage(), $e->getFile(), $e->getLine()));
                }
            }

            wp_cache_flush();
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if ($fallidos) {
            $this->log(sprintf('Export usuarios terminado CON %d fallos: %s',
                count($fallidos), wp_json_encode($fallidos)));
            $writer->cleanup();
            $this->abortar(sprintf(
                'Fallaron %d de %d usuarios, así que no se ha generado el CSV. IDs: %s. Detalle en %s',
                count($fallidos), count($user_ids),
                implode(', ', array_keys($fallidos)), $this->log_path()
            ));
        }

        $this->log(sprintf('Export usuarios OK: %d filas.', $writer->rows_written()));
        $writer->deliver($filename);
        exit;
    }

    /**
     * Comando WP-CLI: genera el CSV a un fichero, sin timeout web ni límites de
     * una petición HTTP. Es la vía recomendada para el histórico completo
     * (decenas de miles de pedidos).
     *
     *   wp ise export --output=/ruta/historico.csv
     *   wp ise export --from=2024-01-01 --to=2024-12-31 --output=/ruta/2024.csv
     *   wp ise export --status=completed,processing --output=/ruta/x.csv
     *
     * Al generar todo en un único fichero, ningún reembolso queda huérfano.
     */
    public function cli_export($args, $assoc_args) {
        $output = $assoc_args['output'] ?? '';
        if (!$output) {
            WP_CLI::error('Falta --output=/ruta/al/fichero.csv');
        }
        // Ruta relativa -> relativa al directorio de trabajo actual.
        if (substr($output, 0, 1) !== '/') {
            $output = rtrim(getcwd(), '/') . '/' . $output;
        }

        $statuses = array();
        if (!empty($assoc_args['status'])) {
            $statuses = array_map('sanitize_key', explode(',', $assoc_args['status']));
        }

        $query = $this->build_export_args(
            $assoc_args['from'] ?? '',
            $assoc_args['to'] ?? '',
            $assoc_args['customer'] ?? '',
            $statuses
        );

        WP_CLI::log('Buscando pedidos...');
        $order_ids = wc_get_orders($query);
        $total = count($order_ids);
        if (!$total) {
            WP_CLI::error('No hay pedidos que coincidan con esos filtros.');
        }

        $this->log(sprintf('--- Export iniciado (CLI): %d pedidos -> %s ---', $total, $output));
        WP_CLI::log(sprintf('%d pedidos. Escribiendo en %s', $total, $output));

        try {
            $writer = new ISE_CSV_Writer($output);
        } catch (Throwable $e) {
            WP_CLI::error('No se pudo crear el fichero: ' . $e->getMessage());
        }

        $barra = WP_CLI\Utils\make_progress_bar('Exportando', $total);
        $vistos = 0;

        $resultado = $this->export_orders_to_writer(
            $order_ids, $writer,
            null,
            function ($procesados) use ($barra, &$vistos) {
                $barra->tick($procesados - $vistos);
                $vistos = $procesados;
            }
        );
        $barra->finish();
        $writer->close();

        $fallidos = $resultado['failed'];
        $omitidos = $resultado['skipped'];

        // A diferencia del web, aquí NO se descarta el CSV si hay fallos: en un
        // histórico de decenas de miles, tirar todo por un pedido corrupto sería
        // absurdo. Se deja el fichero y se listan los fallos para revisarlos.
        if ($fallidos) {
            WP_CLI::warning(sprintf('%d pedido(s) fallaron y NO están en el CSV:', count($fallidos)));
            foreach ($fallidos as $id => $msg) {
                WP_CLI::log(sprintf('  #%d: %s', $id, $msg));
            }
        }

        $huerfanos = $this->orphan_refunds($omitidos, $order_ids);
        if ($huerfanos) {
            WP_CLI::warning(sprintf(
                '%d reembolso(s) con pedido padre fuera del rango; no se exportan. '
                . 'Amplía las fechas si los necesitas.', count($huerfanos)));
        }

        $exportados = $total - count($omitidos) - count($fallidos);
        $this->log(sprintf('Export CLI OK: %d pedidos, %d filas.', $exportados, $writer->rows_written()));
        WP_CLI::success(sprintf(
            '%d pedidos exportados, %d filas, %s. Fichero: %s',
            $exportados, $writer->rows_written(), size_format(filesize($output)), $output
        ));
    }

    /**
     * Vuelve al formulario con un aviso legible en vez de dejar al navegador con
     * una respuesta a medias. Solo funciona mientras no se hayan enviado las
     * cabeceras de descarga, que es precisamente por lo que el CSV se construye
     * antes en un fichero temporal.
     */
    private function abortar($mensaje) {
        // Marca la salida como limpia: sin esto, el exit() de abajo dispara el
        // shutdown handler y el log se llena de "INTERRUMPIDO" falsos.
        if ($this->estado) {
            $this->estado->finalizado = true;
        }
        set_transient('ise_export_error_' . get_current_user_id(), $mensaje, 60);
        wp_safe_redirect(admin_url('admin.php?page=ivb-shopify-export&ise_export_error=1'));
        exit;
    }
}

add_action('plugins_loaded', array('IVB_Shopify_Export', 'get_instance'));
