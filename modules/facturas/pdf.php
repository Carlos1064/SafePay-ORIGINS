<?php
declare(strict_types=1);

/**
 * Descarga segura del PDF de una factura.
 * No expone la ruta física y comprueba el alcance por rol.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereLogin();

$pdo = conexionBd();
$usuario = usuarioActual();
$id = max(1, (int) ($_GET['id'] ?? 0));

$stmt = $pdo->prepare(
    'SELECT id_factura, numero_factura, archivo_pdf, id_usuario_registra
     FROM facturas WHERE id_factura = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$factura = $stmt->fetch();

if (!$factura) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'La factura no existe.';
    exit;
}

if (tieneRol('empleado') && (int) $factura['id_usuario_registra'] !== (int) $usuario['id']) {
    denegarAcceso('Intento de abrir el PDF de la factura #' . $id . ' de otro usuario.');
}

if ($factura['archivo_pdf'] === null || $factura['archivo_pdf'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Esta factura no tiene PDF adjunto.';
    exit;
}

$nombre = basename((string) $factura['archivo_pdf']);
$ruta = UPLOADS_PATH . DIRECTORY_SEPARATOR . $nombre;
$rutaReal = realpath($ruta);
$baseReal = realpath(UPLOADS_PATH);

if ($rutaReal === false || $baseReal === false
    || !str_starts_with($rutaReal, $baseReal . DIRECTORY_SEPARATOR)
    || !is_file($rutaReal) || !is_readable($rutaReal)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'El archivo no está disponible.';
    exit;
}

registrarAuditoria(
    $pdo,
    (int) $usuario['id'],
    'ver_pdf',
    'facturas',
    $id,
    'Consulta del PDF de la factura ' . $factura['numero_factura'] . '.'
);

$descargar = isset($_GET['descargar']) && $_GET['descargar'] === '1';
$nombreDescarga = 'factura-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $factura['numero_factura']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($rutaReal));
header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline') . '; filename="' . $nombreDescarga . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($rutaReal);
exit;
