<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Genera el CSV fila a fila sobre un fichero temporal, para no acumular todos
 * los pedidos en memoria.
 *
 * Se escribe a disco en vez de directamente a php://output a propósito: las
 * cabeceras HTTP de descarga solo se envían cuando el CSV ya está completo
 * (ver deliver()). Con salida en streaming, cualquier fallo a mitad del export
 * — un fatal de PHP, un timeout de PHP-FPM, un OOM — dejaba al navegador con
 * una respuesta truncada y un ERR_INVALID_RESPONSE opaco, sin forma de saber
 * qué pedido lo provocó. Escribiendo primero a disco, un fallo deja el error
 * visible en el admin y en el log, no una descarga rota.
 */
class ISE_CSV_Writer {

    /** @var resource */
    private $out;
    private $headers;
    private $path;
    private $rows = 0;

    /** @var bool si el fichero es temporal (web) o una ruta elegida (CLI) */
    private $is_temp;

    /**
     * @param string|null $path ruta de salida. Si es null se usa un temporal
     *                          (flujo web, se entrega por HTTP y se borra). Si se
     *                          da una ruta, se escribe ahí y NO se borra: es el
     *                          modo CLI, donde el CSV se genera para quedarse.
     * @throws RuntimeException si no se puede crear/abrir el fichero.
     */
    public function __construct($path = null) {
        if ($path === null) {
            $this->path = wp_tempnam('ise-export');
            $this->is_temp = true;
            if (!$this->path) {
                throw new RuntimeException('No se pudo crear el fichero temporal para el CSV.');
            }
        } else {
            $this->path = $path;
            $this->is_temp = false;
        }

        $this->out = fopen($this->path, 'w');
        if (!$this->out) {
            throw new RuntimeException('No se pudo abrir para escritura: ' . $this->path);
        }

        fputs($this->out, "\xEF\xBB\xBF");

        $this->headers = ISE_Matrixify_Columns::headers();
        fputcsv($this->out, $this->headers, ',');
    }

    /** Ruta del fichero que se está escribiendo. */
    public function path() {
        return $this->path;
    }

    public function write_row(array $row) {
        $line = array();
        foreach ($this->headers as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($this->out, $line, ',');
        $this->rows++;
    }

    public function rows_written() {
        return $this->rows;
    }

    /**
     * Cierra el fichero, envía las cabeceras de descarga y vuelca el CSV.
     * Se llama solo cuando el export ha terminado sin errores fatales.
     */
    public function deliver($filename) {
        $this->close();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // Con el fichero ya cerrado sabemos el tamaño exacto, así que el navegador
        // puede detectar una descarga truncada en vez de darla por buena a medias.
        header('Content-Length: ' . filesize($this->path));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($this->path);
        $this->cleanup();
    }

    public function close() {
        if (is_resource($this->out)) {
            fclose($this->out);
            $this->out = null;
        }
    }

    /** Borra el temporal. Seguro de llamar más de una vez. */
    public function cleanup() {
        $this->close();
        if ($this->path && file_exists($this->path)) {
            @unlink($this->path);
            $this->path = null;
        }
    }
}
