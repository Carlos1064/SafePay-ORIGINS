<?php
declare(strict_types=1);

/**
 * Factura · detalle (SC-09).
 * Muestra el estado, las alertas, el pago y la auditoría de la factura.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereLogin();

$pdo = conexionBd();
$usuario = usuarioActual();
$esEmpleado = tieneRol('empleado');
$puedeVerProveedor = tieneRol(['financiero', 'administrador']);
$id = max(1, (int) ($_GET['id'] ?? 0));

$stmt = $pdo->prepare(
    'SELECT f.*,
            pr.razon_social, pr.nit, pr.correo, pr.telefono, pr.estado AS proveedor_estado,
            u.nombre AS registrada_por,
            p.id_pago, p.valor AS pago_valor, p.estado AS pago_estado,
            p.id_cuenta_destino, p.solicitado_en,
            c.banco, c.tipo_cuenta, c.numero_cuenta, c.es_principal, c.verificada,
            us.nombre AS solicitante
     FROM facturas f
     INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
     INNER JOIN usuarios u ON u.id_usuario = f.id_usuario_registra
     LEFT JOIN pagos p ON p.id_factura = f.id_factura
     LEFT JOIN cuentas_bancarias c ON c.id_cuenta = p.id_cuenta_destino
     LEFT JOIN usuarios us ON us.id_usuario = p.id_usuario_solicita
     WHERE f.id_factura = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$factura = $stmt->fetch();

if (!$factura) {
    redirigirConFlash('/modules/facturas/index.php', 'danger', 'La factura solicitada no existe.');
}

if ($esEmpleado && (int) $factura['id_usuario_registra'] !== (int) $usuario['id']) {
    denegarAcceso('Intento de consultar la factura #' . $id . ' de otro usuario.');
}

$flash = consumirFlash();
$alertas = alertasDeFactura($pdo, $id);
$alertasAbiertas = array_values(array_filter(
    $alertas,
    static fn (array $alerta): bool => $alerta['estado'] === 'abierta'
));
$riesgo = nivelMasAlto(array_column($alertasAbiertas, 'nivel'));

$stmt = $pdo->prepare(
    "SELECT a.*, u.nombre AS usuario
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario
     WHERE a.entidad = 'facturas' AND a.id_entidad = :id
     ORDER BY a.fecha DESC, a.id_auditoria DESC
     LIMIT 30"
);
$stmt->execute([':id' => $id]);
$auditoria = $stmt->fetchAll();

$puedeSolicitar = $factura['id_pago'] === null
    && in_array((string) $factura['estado'], ['registrada', 'observada'], true);

$pageTitle = 'Factura ' . $factura['numero_factura'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/facturas/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Facturas
        </a>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1 class="h3 mb-0">Factura <?= e($factura['numero_factura']) ?></h1>
                <?= badgeEstado((string) $factura['estado']) ?>
                <?= badgeRiesgo($riesgo) ?>
            </div>
            <p class="text-muted mb-0">
                <?= e($factura['razon_social']) ?> · Emitida el <?= e(formatearFecha((string) $factura['fecha_emision'])) ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($factura['archivo_pdf'] !== null): ?>
                <a class="btn btn-outline-primary" target="_blank" rel="noopener"
                   href="<?= e(BASE_URL) ?>/modules/facturas/pdf.php?id=<?= $id ?>">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Ver PDF
                </a>
            <?php endif; ?>
            <?php if ($factura['id_pago'] !== null): ?>
                <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $factura['id_pago'] ?>">
                    <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Ver pago
                </a>
            <?php elseif ($puedeSolicitar): ?>
                <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/pagos/form.php?factura=<?= $id ?>">
                    <i class="bi bi-send me-1" aria-hidden="true"></i>Solicitar pago
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alertasAbiertas !== []): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
            <strong>Esta factura tiene <?= count($alertasAbiertas) ?> alerta(s) abierta(s).</strong>
            El pago no podrá aprobarse hasta que se resuelvan o descarten.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header">Resumen</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Valor</div>
                            <div class="fs-5 fw-bold currency"><?= e(formatearMoneda($factura['valor'])) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Emisión</div>
                            <div class="fw-semibold"><?= e(formatearFecha((string) $factura['fecha_emision'])) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Registrada por</div>
                            <div class="fw-semibold"><?= e($factura['registrada_por']) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Alertas abiertas</div>
                            <div class="fw-semibold"><?= count($alertasAbiertas) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Alertas de la factura</span>
                    <span class="badge text-bg-light border"><?= count($alertas) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($alertas === []): ?>
                        <div class="empty-state py-3">
                            <i class="bi bi-shield-check d-block fs-3 mb-2 text-success" aria-hidden="true"></i>
                            <p class="mb-0">Esta factura no tiene alertas.</p>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($alertas as $alerta): ?>
                                <div class="border rounded-3 p-3">
                                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <?= badgeRiesgo((string) $alerta['nivel']) ?>
                                                <?= badgeEstado((string) $alerta['estado']) ?>
                                            </div>
                                            <strong><?= e(etiquetaTipoAlerta((string) $alerta['tipo'])) ?></strong>
                                            <p class="small text-muted mb-0"><?= e($alerta['descripcion']) ?></p>
                                            <?php if ($alerta['justificacion'] !== null): ?>
                                                <p class="small mb-0 mt-2">
                                                    <span class="text-muted">Resolución:</span> <?= e($alerta['justificacion']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (tieneRol(['financiero', 'administrador'])): ?>
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="<?= e(BASE_URL) ?>/modules/alertas/detalle.php?id=<?= (int) $alerta['id_alerta'] ?>">
                                                <?= $alerta['estado'] === 'abierta' ? 'Revisar' : 'Ver' ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Pago asociado</div>
                <div class="card-body">
                    <?php if ($factura['id_pago'] === null): ?>
                        <div class="empty-state py-3">
                            <i class="bi bi-cash-coin d-block fs-3 mb-2 text-primary" aria-hidden="true"></i>
                            <p class="mb-2">Aún no se ha solicitado el pago de esta factura.</p>
                            <?php if ($puedeSolicitar): ?>
                                <a class="btn btn-sm btn-primary" href="<?= e(BASE_URL) ?>/modules/pagos/form.php?factura=<?= $id ?>">Solicitar pago</a>
                            <?php else: ?>
                                <span class="small text-muted">El estado actual de la factura no permite solicitar pago.</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="row g-3 align-items-center">
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Estado</div>
                                <?= badgeEstado((string) $factura['pago_estado']) ?>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Valor</div>
                                <div class="fw-semibold currency"><?= e(formatearMoneda($factura['pago_valor'])) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Cuenta destino</div>
                                <div class="small"><?= e($factura['banco']) ?> · <?= e(enmascararCuenta((string) $factura['numero_cuenta'])) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3 text-lg-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $factura['id_pago'] ?>">Ver pago</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Auditoría de la factura</div>
                <div class="card-body">
                    <?php if ($auditoria === []): ?>
                        <p class="small text-muted mb-0">Sin eventos registrados.</p>
                    <?php else: ?>
                        <ul class="list-unstyled sp-timeline mb-0">
                            <?php foreach ($auditoria as $evento): ?>
                                <li>
                                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-1">
                                        <strong class="small"><?= e($evento['usuario'] ?? 'Sistema') ?> · <?= e(etiquetaAccion((string) $evento['accion'])) ?></strong>
                                        <span class="small text-muted"><?= e(formatearFecha((string) $evento['fecha'], true)) ?></span>
                                    </div>
                                    <div class="small text-muted"><?= e($evento['detalle'] ?? '') ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">Proveedor</div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="sp-avatar-lg"><?= e(iniciales((string) $factura['razon_social'])) ?></span>
                        <div>
                            <div class="fw-semibold"><?= e($factura['razon_social']) ?></div>
                            <div class="small text-muted">NIT <?= e(formatearNit((string) $factura['nit'])) ?></div>
                            <div class="mt-1"><?= badgeEstado((string) $factura['proveedor_estado']) ?></div>
                        </div>
                    </div>
                    <div class="small text-muted mb-1"><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= e($factura['correo'] ?? '—') ?></div>
                    <div class="small text-muted"><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= e($factura['telefono'] ?? '—') ?></div>
                    <?php if ($puedeVerProveedor): ?>
                        <a class="btn btn-sm btn-outline-primary w-100 mt-3"
                           href="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= (int) $factura['id_proveedor'] ?>">Ver proveedor</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Documento</div>
                <div class="card-body">
                    <?php if ($factura['archivo_pdf'] !== null): ?>
                        <div class="sp-pdf">
                            <i class="bi bi-file-earmark-pdf d-block fs-1 mb-2" aria-hidden="true"></i>
                            <div class="fw-semibold small">Factura <?= e($factura['numero_factura']) ?> · PDF</div>
                            <a class="btn btn-sm btn-outline-primary mt-2" target="_blank" rel="noopener"
                               href="<?= e(BASE_URL) ?>/modules/facturas/pdf.php?id=<?= $id ?>">Abrir PDF</a>
                            <a class="btn btn-sm btn-outline-secondary mt-2" href="<?= e(BASE_URL) ?>/modules/facturas/pdf.php?id=<?= $id ?>&descargar=1">Descargar</a>
                        </div>
                    <?php elseif ($factura['archivo_pdf'] === null): ?>
                        <p class="small text-muted mb-0">Esta factura histórica no tiene PDF adjunto.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info small" role="status">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                El pago se bloquea si existen alertas abiertas, si la cuenta destino no es la principal
                o si no está verificada por un administrador.
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
