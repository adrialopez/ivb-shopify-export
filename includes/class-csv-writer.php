<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Genera el CSV en streaming directo a la salida, fila a fila,
 * para no acumular todos los pedidos en memoria.
 */
class ISE_CSV_Writer {

    /** @var resource */
    private $out;
    private $headers;

    public function __construct($filename) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $this->out = fopen('php://output', 'w');
        fputs($this->out, "\xEF\xBB\xBF");

        $this->headers = ISE_Matrixify_Columns::headers();
        fputcsv($this->out, $this->headers, ',');
    }

    public function write_row(array $row) {
        $line = array();
        foreach ($this->headers as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($this->out, $line, ',');
    }

    public function close() {
        fclose($this->out);
    }
}
