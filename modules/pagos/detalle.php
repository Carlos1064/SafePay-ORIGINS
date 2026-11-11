<?php
declare(strict_types=1);

/**
 * Pago · detalle y decisión (SC-12).
 * Aprobación y rechazo con separación de funciones, bloqueo por alertas y auditoría.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereLogin();

$pdo = conexionBd();
$usuario = usuarioActual();
$esEmpleado = tieneRol('empleado');
$puedeDecidir = tieneRol(['financiero', 'administrador']);
$id = max(1, (int) ($_GET['id'] ?? $_POST['id_pago'] ?? 0));

$stmt = $pdo->prepare(
    'SELECT p.*, f.numero_factura, f.fecha_emision, f.valor AS factura_valor,
            f.estado AS factura_estado, f.archivo_pdf, f.id_usuario_registra,
            pr.id_proveedor, pr.razon_social, pr.nit, pr.correo, pr.telefono, pr.estado AS proveedor_estado,
            c.banco, c.tipo_cuenta, c.numero_cuenta, c.es_principal, c.verificada,
            c.creada_en AS cuenta_creada_en, c.verificada_en AS cuenta_verificada_en,
            us.nombre AS solicitante, us.correo AS solicitante_correo,
            ap.decision, ap.comentario, ap.decidido_en,
            ua.nombre AS decidido_por
     FROM pagos p
     INNER JOIN facturas f ON f.id_factura = p.id_factura
     INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
     INNER JOIN cuentas_bancarias c ON c.id_cuenta = p.id_cuenta_destino
     INNER JOIN usuarios us ON us.id_usuario = p.id_usuario_solicita
     LEFT JOIN aprobaciones ap ON ap.id_pago = p.id_pago
     LEFT JOIN usuarios ua ON ua.id_usuario = ap.id_usuario
     WHERE p.id_pago = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$pago = $stmt->fetch();

if (!$pago) {
    redirigirConFlash('/modules/pagos/index.php', 'danger', 'El pago solicitado no existe.');
}
if ($esEmpleado && (int) $pago['id_usuario_solicita'] !== (int) $usuario['id']) {
    denegarAcceso('Intento de consultar el pago #' . $id . ' de otro usuario.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$puedeDecidir) {
        denegarAcceso('Intento de decidir el pago #' . $id . ' sin rol financiero.');
    }
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', 'La sesión del formulario expiró. Intente de nuevo.');
    }

    $accion = (string) ($_POST['accion'] ?? '');
    $comentario = trim((string) ($_POST['comentario'] ?? ''));

    if ($accion === 'aprobar' && (int) $pago['id_usuario_solicita'] === (int) $usuario['id']) {
        try {
            registrarAuditoria(
                $pdo,
                (int) $usuario['id'],
                'autoaprobacion',
                'pagos',
                $id,
                'Intento de aprobar su propia solicitud de pago.'
            );
        } catch (Throwable $error) {
            error_log('SafePay autoaprobación: ' . $error->getMessage());
        }
        denegarAcceso('Intento de autoaprobación del pago #' . $id . '.');
    }

    if (in_array($accion, ['aprobar', 'rechazar'], true)) {
        if ($accion === 'rechazar' && mb_strlen($comentario) < 10) {
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', 'El rechazo requiere un motivo de al menos 10 caracteres.');
        }
        if (mb_strlen($comentario) > 255) {
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', 'El comentario no puede superar 255 caracteres.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM pagos WHERE id_pago = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $pagoBloqueado = $stmt->fetch();

            if (!$pagoBloqueado) {
                throw new DomainException('El pago ya no existe.');
            }

            $estadoActual = (string) $pagoBloqueado['estado'];

            if ($accion === 'aprobar') {
                if (!in_array($estadoActual, ['pendiente', 'bloqueado'], true)) {
                    throw new DomainException('Solo se puede aprobar un pago pendiente. Estado actual: ' . etiquetaEstado($estadoActual) . '.');
                }
                if ((int) $pagoBloqueado['id_usuario_solicita'] === (int) $usuario['id']) {
                    throw new DomainException('No puede aprobar un pago que usted solicitó.');
                }

                sincronizarEstados($pdo, $id);

                $stmt = $pdo->prepare('SELECT estado FROM pagos WHERE id_pago = :id');
                $stmt->execute([':id' => $id]);
                $estadoSincronizado = (string) $stmt->fetchColumn();

                if ($estadoSincronizado !== 'pendiente') {
                    registrarAuditoria(
                        $pdo,
                        (int) $usuario['id'],
                        'aprobacion_bloqueada',
                        'pagos',
                        $id,
                        'Intento de aprobación rechazado por alertas abiertas. Estado: '
                            . etiquetaEstado($estadoSincronizado) . '.'
                    );
                    $pdo->commit();
                    redirigirConFlash(
                        '/modules/pagos/detalle.php?id=' . $id,
                        'danger',
                        'El pago tiene alertas abiertas y no puede aprobarse. Permanece bloqueado para revisión.'
                    );
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO aprobaciones (id_pago, id_usuario, decision, comentario)
                     VALUES (:id_pago, :id_usuario, \'aprobado\', :comentario)'
                );
                $stmt->execute([
                    ':id_pago'    => $id,
                    ':id_usuario' => (int) $usuario['id'],
                    ':comentario' => $comentario !== '' ? $comentario : null,
                ]);

                $pdo->prepare("UPDATE pagos SET estado = 'aprobado' WHERE id_pago = :id")
                    ->execute([':id' => $id]);
                $pdo->prepare("UPDATE facturas SET estado = 'aprobada' WHERE id_factura = :id")
                    ->execute([':id' => (int) $pago['id_factura']]);

                registrarAuditoria(
                    $pdo,
                    (int) $usuario['id'],
                    'aprobar_pago',
                    'pagos',
                    $id,
                    'Pago aprobado por ' . formatearMoneda($pago['valor']) . ' a la cuenta '
                        . enmascararCuenta((string) $pago['numero_cuenta']) . '.'
                        . ($comentario !== '' ? ' Comentario: ' . $comentario : '')
                );

                $pdo->commit();
                redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'success', 'Pago aprobado correctamente.');
            }

            if (!in_array($estadoActual, ['pendiente', 'bloqueado'], true)) {
                throw new DomainException('Este pago ya fue procesado y no puede rechazarse.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO aprobaciones (id_pago, id_usuario, decision, comentario)
                 VALUES (:id_pago, :id_usuario, \'rechazado\', :comentario)'
            );
            $stmt->execute([
                ':id_pago'    => $id,
                ':id_usuario' => (int) $usuario['id'],
                ':comentario' => $comentario,
            ]);

            $pdo->prepare("UPDATE pagos SET estado = 'rechazado' WHERE id_pago = :id")
                ->execute([':id' => $id]);
            $pdo->prepare("UPDATE facturas SET estado = 'rechazada' WHERE id_factura = :id")
                ->execute([':id' => (int) $pago['id_factura']]);

            registrarAuditoria(
                $pdo,
                (int) $usuario['id'],
                'rechazar_pago',
                'pagos',
                $id,
                'Pago rechazado. Motivo: ' . $comentario
            );

            $pdo->commit();
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'success', 'Pago rechazado correctamente.');
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', $error->getMessage());
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SafePay decisión pago: ' . $error->getMessage());
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', 'No se pudo registrar la decisión. Intente de nuevo.');
        }
    }

    if ($accion === 'ejecutar') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM pagos WHERE id_pago = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $pagoBloqueado = $stmt->fetch();

            if (!$pagoBloqueado || (string) $pagoBloqueado['estado'] !== 'aprobado') {
                throw new DomainException('Solo se puede marcar como ejecutado un pago aprobado.');
            }

            $pdo->prepare("UPDATE pagos SET estado = 'ejecutado' WHERE id_pago = :id")
                ->execute([':id' => $id]);
            $pdo->prepare("UPDATE facturas SET estado = 'pagada' WHERE id_factura = :id")
                ->execute([':id' => (int) $pago['id_factura']]);

            registrarAuditoria(
                $pdo,
                (int) $usuario['id'],
                'ejecutar_pago',
                'pagos',
                $id,
                'Pago marcado como ejecutado fuera de SafePay. La factura quedó pagada.'
                    . ($comentario !== '' ? ' Comentario: ' . $comentario : '')
            );

            $pdo->commit();
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'success', 'Pago marcado como ejecutado. La factura quedó pagada.');
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', $error->getMessage());
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SafePay ejecutar pago: ' . $error->getMessage());
            redirigirConFlash('/modules/pagos/detalle.php?id=' . $id, 'danger', 'No se pudo marcar el pago como ejecutado.');
        }
    }
}

$flash = consumirFlash();
$alertas = alertasDePago($pdo, $id, (int) $pago['id_factura'], (int) $pago['id_proveedor'], (int) $pago['id_cuenta_destino']);
$alertasAbiertas = array_values(array_filter(
    $alertas,
    static fn (array $alerta): bool => $alerta['estado'] === 'abierta'
));
$riesgo = nivelMasAlto(array_column($alertasAbiertas, 'nivel'));

$stmt = $pdo->prepare(
    "SELECT a.*, u.nombre AS usuario
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario
     WHERE a.entidad IN ('pagos', 'aprobaciones') AND a.id_entidad = :id
     ORDER BY a.fecha DESC, a.id_auditoria DESC
     LIMIT 40"
);
$stmt->execute([':id' => $id]);
$auditoria = $stmt->fetchAll();

$esPropio = (int) $pago['id_usuario_solicita'] === (int) $usuario['id'];
$puedeAprobar = $puedeDecidir && $pago['estado'] === 'pendiente' && !$esPropio && $alertasAbiertas === [];
$puedeRechazar = $puedeDecidir && in_array((string) $pago['estado'], ['pendiente', 'bloqueado'], true);
$puedeEjecutar = $puedeDecidir && $pago['estado'] === 'aprobado';

$pageTitle = 'Pago #' . $id;
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/pagos/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Pagos
        </a>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1 class="h3 mb-0">Pago #<?= $id ?></h1>
                <?= badgeEstado((string) $pago['estado']) ?>
                <?= badgeRiesgo($riesgo) ?>
            </div>
            <p class="text-muted mb-0">
                Factura <?= e($pago['numero_factura']) ?> · <?= e($pago['razon_social']) ?> ·
                Solicitado el <?= e(formatearFecha((string) $pago['solicitado_en'], true)) ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($puedeEjecutar): ?>
                <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#modalEjecutar">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Marcar ejecutado
                </button>
            <?php endif; ?>
            <?php if ($puedeAprobar): ?>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalAprobar">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar
                </button>
            <?php endif; ?>
            <?php if ($puedeRechazar): ?>
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#modalRechazar">
                    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pago['estado'] === 'bloqueado'): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
            <strong>Pago bloqueado.</strong>
            No puede aprobarse mientras existan alertas abiertas de la factura, el proveedor o la cuenta destino.
            <?php if ($alertasAbiertas !== []): ?>
                Hay <?= count($alertasAbiertas) ?> alerta(s) sin resolver.
            <?php endif; ?>
        </div>
    <?php elseif ($puedeDecidir && $esPropio && $pago['estado'] === 'pendiente'): ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-person-lock me-1" aria-hidden="true"></i>
            Usted solicitó este pago. La separación de funciones impide que lo apruebe.
        </div>
    <?php elseif ($puedeDecidir && $alertasAbiertas !== [] && $pago['estado'] === 'pendiente'): ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Este pago tiene alertas abiertas; no podrá aprobarse hasta resolverlas o descartarlas.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header">Resumen del pago</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Valor</div>
                            <div class="fs-5 fw-bold currency"><?= e(formatearMoneda($pago['valor'])) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Solicitante</div>
                            <div class="fw-semibold"><?= e($pago['solicitante']) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Factura</div>
                            <a class="fw-semibold text-decoration-none" href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $pago['id_factura'] ?>">
                                <?= e($pago['numero_factura']) ?>
                            </a>
                            <div><?= badgeEstado((string) $pago['factura_estado']) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Fecha de solicitud</div>
                            <div class="fw-semibold"><?= e(formatearFecha((string) $pago['solicitado_en'], true)) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Cuenta destino</div>
                <div class="card-body">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <strong><?= e($pago['banco']) ?></strong>
                                <span class="badge text-bg-light border"><?= e(ucfirst((string) $pago['tipo_cuenta'])) ?></span>
                                <?php if ((int) $pago['es_principal'] === 1): ?>
                                    <span class="badge text-bg-primary">Principal</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border">Secundaria</span>
                                <?php endif; ?>
                                <?php if ((int) $pago['verificada'] === 1): ?>
                                    <span class="badge text-bg-success">Verificada</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">Sin verificar</span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-1"><?= e(enmascararCuenta((string) $pago['numero_cuenta'])) ?></div>
                            <div class="small text-muted">
                                Registrada el <?= e(formatearFecha((string) $pago['cuenta_creada_en'])) ?>
                                <?php if ($pago['cuenta_verificada_en'] !== null): ?>
                                    · Verificada el <?= e(formatearFecha((string) $pago['cuenta_verificada_en'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (tieneRol(['financiero', 'administrador'])): ?>
                            <a class="btn btn-sm btn-outline-primary align-self-start"
                               href="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= (int) $pago['id_proveedor'] ?>">
                                Ver proveedor
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Alertas relacionadas</span>
                    <span class="badge text-bg-light border"><?= count($alertas) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($alertas === []): ?>
                        <div class="empty-state py-3">
                            <i class="bi bi-shield-check d-block fs-3 mb-2 text-success" aria-hidden="true"></i>
                            <p class="mb-0">Sin alertas asociadas.</p>
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
                                                <p class="small mb-0 mt-2"><span class="text-muted">Resolución:</span> <?= e($alerta['justificacion']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$esEmpleado): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/alertas/detalle.php?id=<?= (int) $alerta['id_alerta'] ?>">
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

            <?php if ($pago['decision'] !== null): ?>
                <div class="card mb-4">
                    <div class="card-header">Decisión</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Decisión</div>
                                <?= badgeEstado((string) $pago['decision']) ?>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Responsable</div>
                                <div class="fw-semibold"><?= e($pago['decidido_por'] ?? '—') ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Fecha</div>
                                <div class="fw-semibold"><?= e(formatearFecha((string) $pago['decidido_en'], true)) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Comentario</div>
                                <div class="small"><?= e($pago['comentario'] ?? '—') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card" id="decision">
                <div class="card-header">Auditoría del pago</div>
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
                        <span class="sp-avatar-lg"><?= e(iniciales((string) $pago['razon_social'])) ?></span>
                        <div>
                            <div class="fw-semibold"><?= e($pago['razon_social']) ?></div>
                            <div class="small text-muted">NIT <?= e(formatearNit((string) $pago['nit'])) ?></div>
                            <div class="mt-1"><?= badgeEstado((string) $pago['proveedor_estado']) ?></div>
                        </div>
                    </div>
                    <div class="small text-muted mb-1"><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= e($pago['correo'] ?? '—') ?></div>
                    <div class="small text-muted"><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= e($pago['telefono'] ?? '—') ?></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Solicitante</div>
                <div class="card-body">
                    <div class="fw-semibold"><?= e($pago['solicitante']) ?></div>
                    <div class="small text-muted"><?= e($pago['solicitante_correo']) ?></div>
                    <?php if ($esPropio): ?>
                        <div class="alert alert-warning small mt-3 mb-0" role="status">
                            <i class="bi bi-person-lock me-1" aria-hidden="true"></i>
                            Usted es el solicitante. La separación de funciones impide aprobar este pago.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info small mb-0" role="status">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                SafePay no ejecuta transferencias. El pago se marca como ejecutado después de realizarlo en el banco.
            </div>
        </div>
    </div>
</div>

<?php if ($puedeAprobar): ?>
<div class="modal fade" id="modalAprobar" tabindex="-1" aria-labelledby="modalAprobarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= $id ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="id_pago" value="<?= $id ?>">
                <input type="hidden" name="accion" value="aprobar">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalAprobarTitulo">Aprobar pago</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Confirme la aprobación del pago por <strong><?= e(formatearMoneda($pago['valor'])) ?></strong>.</p>
                    <div class="mb-3">
                        <label class="form-label" for="comentarioAprobar">Comentario (opcional)</label>
                        <textarea class="form-control" id="comentarioAprobar" name="comentario" rows="3" maxlength="255"></textarea>
                    </div>
                    <div class="alert alert-info small mb-0" role="status">
                        Se registrará su usuario, la fecha y su comentario en auditoría.
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit">Confirmar aprobación</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($puedeRechazar): ?>
<div class="modal fade" id="modalRechazar" tabindex="-1" aria-labelledby="modalRechazarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= $id ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="id_pago" value="<?= $id ?>">
                <input type="hidden" name="accion" value="rechazar">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalRechazarTitulo">Rechazar pago</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="comentarioRechazar">Motivo del rechazo *</label>
                        <textarea class="form-control" id="comentarioRechazar" name="comentario" rows="4" minlength="10" maxlength="255" required></textarea>
                        <div class="form-text">Mínimo 10 caracteres. Quedará en auditoría.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-danger" type="submit">Confirmar rechazo</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($puedeEjecutar): ?>
<div class="modal fade" id="modalEjecutar" tabindex="-1" aria-labelledby="modalEjecutarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= $id ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="id_pago" value="<?= $id ?>">
                <input type="hidden" name="accion" value="ejecutar">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalEjecutarTitulo">Marcar pago como ejecutado</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Confirme que la transferencia ya se realizó en el banco. SafePay no ejecuta el pago;
                        solo actualiza el estado interno y la factura.
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="comentarioEjecutar">Referencia o comentario (opcional)</label>
                        <input class="form-control" id="comentarioEjecutar" name="comentario" maxlength="255" placeholder="Número de transacción">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-success" type="submit">Confirmar ejecución</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
