<?php
declare(strict_types=1);

/**
 * Exportación CSV del reporte de operaciones sospechosas.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';
require_once __DIR__ . '/_consulta.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$usuario = usuarioActual();
$filtros = filtrosReporte($_GET);
$filas = consultarReporte($pdo, $filtros, null);

try {
    registrarAuditoria(
        $pdo,
        (int) $usuario['id'],
        'exportar_reporte',
        'reportes',
        null,
        'Exportación CSV de ' . $filtros['tipo'] . ' del ' . $filtros['desde'] . ' al ' . $filtros['hasta']
            . '. Nivel: ' . ($filtros['nivel'] !== '' ? $filtros['nivel'] : 'todos')
            . '. Proveedor: ' . ($filtros['proveedor'] > 0 ? (string) $filtros['proveedor'] : 'todos')
            . '. Filas: ' . count($filas) . '.'
    );
} catch (Throwable $error) {
    error_log('SafePay exportar: ' . $error->getMessage());
}

/** Evita fórmulas al abrir el CSV en Excel. */
function celdaCsv(mixed $valor): string
{
    $texto = str_replace(["\r", "\n", "\t"], ' ', (string) $valor);
    if (preg_match('/^[=+\-@]/', $texto) === 1) {
        $texto = "'" . $texto;
    }

    return $texto;
}

$nombreArchivo = 'safepay-reporte-' . $filtros['desde'] . '-' . $filtros['hasta'] . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$salida = fopen('php://output', 'w');
if ($salida === false) {
    exit;
}

fwrite($salida, "\xEF\xBB\xBF");
fputcsv(
    $salida,
    ['Fecha', 'Origen', 'Tipo', 'Proveedor', 'Factura', 'Pago', 'Valor', 'Nivel', 'Estado', 'Responsable / solicitante', 'Descripción'],
    ';',
    '"',
    '\\',
    "\r\n"
);

foreach ($filas as $fila) {
    $descripcion = (string) ($fila['descripcion'] ?? '');
    if (!empty($fila['justificacion'])) {
        $descripcion .= ($descripcion !== '' ? ' ' : '') . 'Resolución: ' . $fila['justificacion'];
    }

    $nivel = $fila['nivel'] !== null && $fila['nivel'] !== ''
        ? etiquetaNivel((string) $fila['nivel'])
        : 'Sin alertas';

    fputcsv(
        $salida,
        array_map('celdaCsv', [
            formatearFecha((string) $fila['fecha'], true),
            $fila['origen'],
            $fila['tipo_legible'],
            $fila['razon_social'] ?? '',
            $fila['numero_factura'] ?? '',
            $fila['id_pago'] !== null ? 'Pago #' . $fila['id_pago'] : '',
            formatearMoneda($fila['valor']),
            $nivel,
            etiquetaEstado((string) $fila['estado']),
            $fila['responsable'] ?? $fila['solicitante'] ?? '',
            $descripcion,
        ]),
        ';',
        '"',
        '\\',
        "\r\n"
    );
}

fclose($salida);
exit;
