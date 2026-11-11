<?php
declare(strict_types=1);

/**
 * Factura · formulario de registro (SC-07 / SC-08).
 * Valida el PDF, detecta duplicados exactos y nunca sobrescribe archivos.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';
require_once __DIR__ . '/../../services/ValidadorFraude.php';

requiereLogin();

$pdo = conexionBd();
$validador = new ValidadorFraude($pdo);
$usuario = usuarioActual();
$maxPdfMb = max(1, (int) PDF_MAX_MB);
$errores = [];
$errorGeneral = '';

$stmt = $pdo->query(
    'SELECT pr.id_proveedor, pr.razon_social, pr.nit, pr.estado,
            c.banco, c.numero_cuenta, c.es_principal, c.verificada
     FROM proveedores pr
     LEFT JOIN cuentas_bancarias c
            ON c.id_proveedor = pr.id_proveedor AND c.es_principal = 1
     ORDER BY pr.razon_social'
);
$proveedores = $stmt->fetchAll();

$datos = [
    'id_proveedor'    => (int) ($_POST['id_proveedor'] ?? 0),
    'numero_factura'  => strtoupper(trim((string) ($_POST['numero_factura'] ?? ''))),
    'fecha_emision'   => (string) ($_POST['fecha_emision'] ?? date('Y-m-d')),
    'valor'           => trim((string) ($_POST['valor'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión del formulario expiró. Recargue la página e intente de nuevo.';
    }

    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{2,39}$/', $datos['numero_factura'])) {
        $errores[] = 'El número de factura debe tener entre 3 y 40 caracteres: letras, números, punto, guion, guion bajo o barra.';
    }

    if (!fechaIsoValida($datos['fecha_emision'])) {
        $errores[] = 'Seleccione una fecha de emisión válida.';
    }

    $valorNormalizado = str_replace(',', '.', $datos['valor']);
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $valorNormalizado)) {
        $errores[] = 'El valor debe ser un monto positivo con máximo dos decimales.';
        $valor = 0.0;
    } else {
        $valor = round((float) $valorNormalizado, 2);
        if ($valor <= 0) {
            $errores[] = 'El valor debe ser mayor que cero.';
        }
    }

    $proveedor = null;
    foreach ($proveedores as $proveedorListado) {
        if ((int) $proveedorListado['id_proveedor'] === $datos['id_proveedor']) {
            $proveedor = $proveedorListado;
            break;
        }
    }
    if ($proveedor === null) {
        $errores[] = 'Seleccione un proveedor válido.';
    }

    if (($valor ?? 0) > 0) {
        $duplicada = $validador->evaluarDuplicado($datos['id_proveedor'], $datos['numero_factura']);

        if ($duplicada !== null) {
            $errores[] = 'Ya existe una factura con ese número para el proveedor.';
            $errorGeneral = 'El registro no se creó. Se generó una alerta de factura duplicada y el intento quedó en auditoría.';

            try {
                $pdo->beginTransaction();
                guardarHallazgos($pdo, [$duplicada]);
                sincronizarFactura($pdo, (int) $duplicada['id_factura']);
                sincronizarPagosDeProveedor($pdo, (int) $datos['id_proveedor']);
                registrarAuditoria(
                    $pdo,
                    (int) $usuario['id'],
                    'factura_duplicada_intento',
                    'facturas',
                    (int) $duplicada['id_factura'],
                    'Intento de duplicado: ' . $datos['numero_factura'] . ' por ' . formatearMoneda($valor)
                        . '. Factura existente #' . $duplicada['id_factura'] . '.'
                );
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('SafePay duplicado: ' . $error->getMessage());
            }
        }
    }

    $archivo = $_FILES['archivo_pdf'] ?? null;
    $nombreArchivo = null;
    if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errores[] = 'Adjunte la factura en PDF.';
    } elseif ((int) ($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errores[] = match ((int) $archivo['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El PDF supera el tamaño máximo de ' . $maxPdfMb . ' MB.',
            UPLOAD_ERR_PARTIAL => 'El PDF no se subió completo. Intente de nuevo.',
            default => 'No se pudo recibir el PDF. Intente de nuevo.',
        };
    } else {
        $tamano = (int) ($archivo['size'] ?? 0);
        $nombreOriginal = (string) ($archivo['name'] ?? '');
        $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        $temporal = (string) ($archivo['tmp_name'] ?? '');

        if ($tamano <= 0) {
            $errores[] = 'El PDF está vacío.';
        } elseif ($tamano > $maxPdfMb * 1024 * 1024) {
            $errores[] = 'El PDF supera el tamaño máximo de ' . $maxPdfMb . ' MB.';
        } elseif ($extension !== 'pdf') {
            $errores[] = 'El archivo adjunto debe tener extensión .pdf.';
        } elseif (!is_uploaded_file($temporal)) {
            $errores[] = 'No se pudo validar el archivo adjunto.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($temporal);
            $firma = (string) file_get_contents($temporal, false, null, 0, 5);

            if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true) || $firma !== '%PDF-') {
                $errores[] = 'El archivo no es un PDF válido.';
            } elseif (pdfTieneContenidoActivo($temporal)) {
                $errores[] = 'El PDF contiene contenido activo no permitido. Genere una versión sin formularios ni scripts.';
            } else {
                $nombreArchivo = bin2hex(random_bytes(20)) . '.pdf';
            }
        }
    }

    if ($errores === [] && $proveedor !== null && $nombreArchivo !== null) {
        if (!is_dir(UPLOADS_PATH) && !mkdir(UPLOADS_PATH, 0775, true) && !is_dir(UPLOADS_PATH)) {
            $errorGeneral = 'No existe la carpeta de adjuntos. Contacte al administrador.';
        } else {
            $rutaDestino = UPLOADS_PATH . DIRECTORY_SEPARATOR . $nombreArchivo;
            $temporal = (string) ($_FILES['archivo_pdf']['tmp_name'] ?? '');

            if (!move_uploaded_file($temporal, $rutaDestino)) {
                $errorGeneral = 'No se pudo guardar el PDF. Intente de nuevo.';
            } else {
                @chmod($rutaDestino, 0640);

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'INSERT INTO facturas
                            (id_proveedor, numero_factura, fecha_emision, valor, archivo_pdf,
                             estado, id_usuario_registra)
                         VALUES
                            (:id_proveedor, :numero, :fecha, :valor, :archivo, \'registrada\', :id_usuario)'
                    );
                    $stmt->execute([
                        ':id_proveedor' => $datos['id_proveedor'],
                        ':numero'       => $datos['numero_factura'],
                        ':fecha'        => $datos['fecha_emision'],
                        ':valor'        => number_format($valor, 2, '.', ''),
                        ':archivo'      => $nombreArchivo,
                        ':id_usuario'   => (int) $usuario['id'],
                    ]);
                    $idFactura = (int) $pdo->lastInsertId();

                    $hallazgos = $validador->evaluarFactura($idFactura);
                    guardarHallazgos($pdo, $hallazgos);
                    sincronizarFactura($pdo, $idFactura);
                    sincronizarPagosDeProveedor($pdo, (int) $datos['id_proveedor']);

                    registrarAuditoria(
                        $pdo,
                        (int) $usuario['id'],
                        'crear_factura',
                        'facturas',
                        $idFactura,
                        'Factura ' . $datos['numero_factura'] . ' de ' . $proveedor['razon_social']
                            . ' por ' . formatearMoneda($valor) . '. PDF: ' . $nombreArchivo
                            . '. Alertas generadas: ' . count($hallazgos) . '.'
                    );

                    $pdo->commit();

                    redirigirConFlash(
                        '/modules/facturas/detalle.php?id=' . $idFactura,
                        'success',
                        'Factura registrada correctamente.'
                    );
                } catch (PDOException $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    @unlink($rutaDestino);
                    if ((string) $error->getCode() === '23000') {
                        $errores[] = 'Ya existe una factura con ese número para el proveedor.';
                    } else {
                        error_log('SafePay factura: ' . $error->getMessage());
                        $errorGeneral = 'No se pudo registrar la factura. Intente de nuevo.';
                    }
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    @unlink($rutaDestino);
                    error_log('SafePay factura: ' . $error->getMessage());
                    $errorGeneral = 'No se pudo registrar la factura. Intente de nuevo.';
                }
            }
        }
    }
}

$pageTitle = 'Nueva factura';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/facturas/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Facturas
        </a>
    </div>

    <div class="mb-4">
        <h1 class="h3 mb-1">Nueva factura</h1>
        <p class="text-muted mb-0">Registre la factura y adjunte el PDF. El sistema valida formato, duplicados y riesgo básico.</p>
    </div>

    <?php if ($errorGeneral !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e($errorGeneral) ?>
        </div>
    <?php endif; ?>

    <?php if ($errores !== []): ?>
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Revise el formulario:</div>
            <ul class="mb-0 ps-3">
                <?php foreach (array_unique($errores) as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($proveedores === []): ?>
        <div class="alert alert-warning" role="alert">
            No hay proveedores registrados. Un administrador debe crear uno antes de registrar facturas.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(BASE_URL) ?>/modules/facturas/form.php" enctype="multipart/form-data" novalidate>
        <?= csrfCampo() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxPdfMb * 1024 * 1024 ?>">

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header">Datos de la factura</div>
                    <div class="card-body">
                        <p class="small text-muted">Los campos con <span class="text-danger">*</span> son obligatorios.</p>

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="id_proveedor">Proveedor *</label>
                                <select class="form-select" id="id_proveedor" name="id_proveedor" required>
                                    <option value="0">Seleccione un proveedor</option>
                                    <?php foreach ($proveedores as $proveedorListado): ?>
                                        <option value="<?= (int) $proveedorListado['id_proveedor'] ?>"
                                            <?= $datos['id_proveedor'] === (int) $proveedorListado['id_proveedor'] ? 'selected' : '' ?>>
                                            <?= e($proveedorListado['razon_social']) ?>
                                            (<?= e(formatearNit((string) $proveedorListado['nit'])) ?>)
                                            <?= (string) $proveedorListado['estado'] === 'inactivo' ? ' — INACTIVO' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="numero_factura">Número de factura *</label>
                                <input class="form-control" id="numero_factura" name="numero_factura" maxlength="40"
                                       value="<?= e($datos['numero_factura']) ?>" placeholder="F-2026-001" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="fecha_emision">Fecha de emisión *</label>
                                <input class="form-control" id="fecha_emision" name="fecha_emision" type="date"
                                       value="<?= e($datos['fecha_emision']) ?>" required>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="valor">Valor total *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input class="form-control" id="valor" name="valor" type="number" min="0.01"
                                           max="999999999999.99" step="0.01" value="<?= e($datos['valor']) ?>"
                                           placeholder="0.00" required>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <label class="form-label" for="archivo_pdf">Factura en PDF *</label>
                        <input class="form-control" id="archivo_pdf" name="archivo_pdf" type="file"
                               accept="application/pdf,.pdf" required aria-describedby="pdfAyuda">
                        <div id="pdfAyuda" class="form-text">
                            Solo PDF real de hasta <?= $maxPdfMb ?> MB. El archivo se guarda con un nombre aleatorio.
                        </div>

                        <div class="alert alert-info small mt-4 mb-0" role="status">
                            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                            Si el número ya existe para el mismo proveedor, la factura no se crea y se genera una
                            alerta de riesgo alto. El motor también revisa duplicados por contenido, valores
                            inusuales, proveedores inactivos y fechas incoherentes. El intento queda en auditoría.
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-primary" type="submit" <?= $proveedores === [] ? 'disabled' : '' ?>>
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Registrar factura
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/facturas/index.php">Cancelar</a>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">Validaciones al guardar</div>
                    <div class="card-body small">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex gap-2 mb-3">
                                <i class="bi bi-file-earmark-pdf text-danger" aria-hidden="true"></i>
                                <span><strong>PDF verificable:</strong> extensión, MIME y firma del archivo.</span>
                            </li>
                            <li class="d-flex gap-2 mb-3">
                                <i class="bi bi-calendar-check text-primary" aria-hidden="true"></i>
                                <span><strong>Fecha:</strong> futura o con más de <?= (int) parametro('antiguedad_maxima_dias', 180) ?> días genera alerta baja.</span>
                            </li>
                            <li class="d-flex gap-2 mb-3">
                                <i class="bi bi-cash-stack text-success" aria-hidden="true"></i>
                                <span><strong>Valor:</strong> positivo y con máximo dos decimales.</span>
                            </li>
                            <li class="d-flex gap-2">
                                <i class="bi bi-fingerprint text-warning" aria-hidden="true"></i>
                                <span><strong>Duplicados:</strong> número exacto y coincidencia de valor y fecha.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">Flujo siguiente</div>
                    <div class="card-body small text-muted">
                        Después de registrarla podrá solicitar el pago al proveedor. El pago no se ejecuta desde SafePay:
                        solo se controla y aprueba internamente.
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
