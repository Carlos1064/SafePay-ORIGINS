<?php
declare(strict_types=1);

/**
 * Proveedor · detalle (SC-05).
 * Incluye cuentas bancarias, historial y acciones exclusivas del administrador.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$esAdmin = tieneRol('administrador');
$usuario = usuarioActual();
$id = max(1, (int) ($_GET['id'] ?? 0));

$stmt = $pdo->prepare('SELECT * FROM proveedores WHERE id_proveedor = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$proveedor = $stmt->fetch();

if (!$proveedor) {
    redirigirConFlash('/modules/proveedores/index.php', 'danger', 'El proveedor solicitado no existe.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdmin) {
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', 'La sesión del formulario expiró. Intente de nuevo.');
    }

    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'crear_cuenta') {
        $banco = trim((string) ($_POST['banco'] ?? ''));
        $tipo = (string) ($_POST['tipo_cuenta'] ?? '');
        $numero = preg_replace('/\s+/', '', (string) ($_POST['numero_cuenta'] ?? '')) ?? '';
        $errores = [];

        if (mb_strlen($banco) < 3 || mb_strlen($banco) > 80) {
            $errores[] = 'El banco debe tener entre 3 y 80 caracteres.';
        }
        if (!in_array($tipo, ['ahorros', 'corriente'], true)) {
            $errores[] = 'Seleccione un tipo de cuenta válido.';
        }
        if (!preg_match('/^\d{6,30}$/', $numero)) {
            $errores[] = 'El número de cuenta debe contener entre 6 y 30 dígitos.';
        }
        if (empty($_POST['confirmar_cambio'])) {
            $errores[] = 'Confirme que verificó el cambio por un canal independiente.';
        }

        if ($errores !== []) {
            redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', implode(' ', $errores));
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id_cuenta, numero_cuenta, es_principal
                 FROM cuentas_bancarias
                 WHERE id_proveedor = :id
                 ORDER BY es_principal DESC, id_cuenta DESC
                 FOR UPDATE'
            );
            $stmt->execute([':id' => $id]);
            $cuentas = $stmt->fetchAll();

            foreach ($cuentas as $cuentaExistente) {
                if (hash_equals((string) $cuentaExistente['numero_cuenta'], $numero)) {
                    throw new DomainException('Ese número de cuenta ya está registrado para el proveedor.');
                }
            }

            $anterior = null;
            foreach ($cuentas as $cuentaExistente) {
                if ((int) $cuentaExistente['es_principal'] === 1) {
                    $anterior = (string) $cuentaExistente['numero_cuenta'];
                    break;
                }
            }

            $pdo->prepare(
                'UPDATE cuentas_bancarias
                 SET es_principal = 0, modificada_en = NOW()
                 WHERE id_proveedor = :id AND es_principal = 1'
            )->execute([':id' => $id]);

            $stmt = $pdo->prepare(
                'INSERT INTO cuentas_bancarias
                    (id_proveedor, banco, tipo_cuenta, numero_cuenta, es_principal,
                     verificada, creada_en, modificada_en)
                 VALUES
                    (:id_proveedor, :banco, :tipo, :numero, 1, 0, NOW(), NOW())'
            );
            $stmt->execute([
                ':id_proveedor' => $id,
                ':banco'        => $banco,
                ':tipo'         => $tipo,
                ':numero'       => $numero,
            ]);
            $idCuenta = (int) $pdo->lastInsertId();

            crearAlerta(
                $pdo,
                null,
                null,
                $id,
                $idCuenta,
                'cuenta_modificada_reciente',
                'alto',
                'Se registró una nueva cuenta principal (' . $banco . ' ' . enmascararCuenta($numero)
                    . ') el ' . date('d/m/Y') . ' y aún no está verificada.'
            );
            sincronizarPagosDeProveedor($pdo, $id);

            registrarAuditoria(
                $pdo,
                (int) $usuario['id'],
                'cambiar_cuenta',
                'cuentas_bancarias',
                $idCuenta,
                'Cuenta anterior: ' . ($anterior ?? 'ninguna') . '. Nueva cuenta: ' . $numero
                    . ' (' . $banco . ', ' . $tipo . '), principal y sin verificar.'
            );

            $pdo->commit();

            redirigirConFlash(
                '/modules/proveedores/detalle.php?id=' . $id,
                'warning',
                'Cuenta registrada como principal. Queda sin verificar y se generó una alerta de riesgo alto.'
            );
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', $error->getMessage());
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $error->getCode() === '23000') {
                redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', 'Ese número de cuenta ya está registrado para el proveedor.');
            }
            error_log('SafePay nueva cuenta: ' . $error->getMessage());
            redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', 'No se pudo registrar la cuenta. Intente de nuevo.');
        }
    }

    if ($accion === 'verificar_cuenta') {
        $idCuenta = (int) ($_POST['id_cuenta'] ?? 0);
        $constancia = trim((string) ($_POST['constancia'] ?? ''));

        if (mb_strlen($constancia) < 10 || mb_strlen($constancia) > 255) {
            redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', 'Escriba una constancia de verificación de al menos 10 caracteres.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT * FROM cuentas_bancarias
                 WHERE id_cuenta = :id_cuenta AND id_proveedor = :id_proveedor
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':id_cuenta' => $idCuenta, ':id_proveedor' => $id]);
            $cuenta = $stmt->fetch();

            if (!$cuenta) {
                throw new DomainException('La cuenta no pertenece al proveedor.');
            }
            if ((int) $cuenta['verificada'] === 1) {
                throw new DomainException('La cuenta ya estaba verificada.');
            }

            $pdo->prepare(
                'UPDATE cuentas_bancarias
                 SET verificada = 1, id_usuario_verifica = :id_usuario,
                     verificada_en = NOW(), modificada_en = NOW()
                 WHERE id_cuenta = :id_cuenta'
            )->execute([
                ':id_usuario' => (int) $usuario['id'],
                ':id_cuenta'  => $idCuenta,
            ]);

            $stmt = $pdo->prepare(
                "SELECT id_alerta FROM alertas
                 WHERE estado = 'abierta' AND id_cuenta = :id_cuenta
                   AND tipo = 'cuenta_modificada_reciente'"
            );
            $stmt->execute([':id_cuenta' => $idCuenta]);
            $idsAlertas = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmtUpdate = $pdo->prepare(
                "UPDATE alertas
                 SET estado = 'resuelta', id_usuario_resuelve = :id_usuario,
                     resuelta_en = NOW(), justificacion = :justificacion
                 WHERE id_alerta = :id_alerta AND estado = 'abierta'"
            );
            foreach ($idsAlertas as $idAlerta) {
                $stmtUpdate->execute([
                    ':id_usuario'   => (int) $usuario['id'],
                    ':justificacion' => mb_substr($constancia, 0, 255),
                    ':id_alerta'    => (int) $idAlerta,
                ]);
                registrarAuditoria(
                    $pdo,
                    (int) $usuario['id'],
                    'resolver_alerta',
                    'alertas',
                    (int) $idAlerta,
                    'Alerta resuelta al verificar la cuenta ' . $idCuenta . ': ' . $constancia
                );
            }

            registrarAuditoria(
                $pdo,
                (int) $usuario['id'],
                'verificar_cuenta',
                'cuentas_bancarias',
                $idCuenta,
                'Cuenta ' . enmascararCuenta((string) $cuenta['numero_cuenta']) . ' verificada. Constancia: ' . $constancia
            );

            sincronizarPagosDeProveedor($pdo, $id);

            $pdo->commit();

            redirigirConFlash(
                '/modules/proveedores/detalle.php?id=' . $id,
                'success',
                'Cuenta verificada. Las alertas relacionadas se resolvieron y los pagos aplicables se desbloquearon.'
            );
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', $error->getMessage());
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SafePay verificar cuenta: ' . $error->getMessage());
            redirigirConFlash('/modules/proveedores/detalle.php?id=' . $id, 'danger', 'No se pudo verificar la cuenta. Intente de nuevo.');
        }
    }
}

$flash = consumirFlash();

$stmt = $pdo->prepare(
    'SELECT c.*, u.nombre AS verificada_por
     FROM cuentas_bancarias c
     LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario_verifica
     WHERE c.id_proveedor = :id
     ORDER BY c.es_principal DESC, c.creada_en DESC, c.id_cuenta DESC'
);
$stmt->execute([':id' => $id]);
$cuentas = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT f.*, u.nombre AS registrada_por
     FROM facturas f
     INNER JOIN usuarios u ON u.id_usuario = f.id_usuario_registra
     WHERE f.id_proveedor = :id
     ORDER BY f.fecha_emision DESC, f.id_factura DESC
     LIMIT 12'
);
$stmt->execute([':id' => $id]);
$facturas = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT p.*, f.numero_factura, c.banco, c.numero_cuenta, c.es_principal,
            u.nombre AS solicitante
     FROM pagos p
     INNER JOIN facturas f ON f.id_factura = p.id_factura
     INNER JOIN cuentas_bancarias c ON c.id_cuenta = p.id_cuenta_destino
     INNER JOIN usuarios u ON u.id_usuario = p.id_usuario_solicita
     WHERE f.id_proveedor = :id
     ORDER BY p.solicitado_en DESC, p.id_pago DESC
     LIMIT 12'
);
$stmt->execute([':id' => $id]);
$pagos = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(valor), 0) FROM facturas WHERE id_proveedor = :id'
);
$stmt->execute([':id' => $id]);
$totalFacturado = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(p.valor), 0)
     FROM pagos p
     INNER JOIN facturas f ON f.id_factura = p.id_factura
     WHERE f.id_proveedor = :id AND p.estado = 'ejecutado'"
);
$stmt->execute([':id' => $id]);
$totalPagado = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM alertas
     WHERE estado = 'abierta'
       AND (id_proveedor = :id_proveedor
            OR id_cuenta IN (SELECT id_cuenta FROM cuentas_bancarias WHERE id_proveedor = :id_proveedor_cuenta))"
);
$stmt->execute([':id_proveedor' => $id, ':id_proveedor_cuenta' => $id]);
$alertasAbiertas = (int) $stmt->fetchColumn();

$cuentaPrincipal = null;
foreach ($cuentas as $cuenta) {
    if ((int) $cuenta['es_principal'] === 1) {
        $cuentaPrincipal = $cuenta;
        break;
    }
}

$idsCuentas = array_map(static fn (array $cuenta): int => (int) $cuenta['id_cuenta'], $cuentas);
$historialCuentas = [];
if ($idsCuentas !== []) {
    $marcas = implode(',', array_fill(0, count($idsCuentas), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.*, u.nombre AS usuario
         FROM auditoria a
         LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario
         WHERE a.entidad = 'cuentas_bancarias' AND a.id_entidad IN ($marcas)
         ORDER BY a.fecha DESC, a.id_auditoria DESC
         LIMIT 20"
    );
    $stmt->execute($idsCuentas);
    $historialCuentas = $stmt->fetchAll();
}

$pageTitle = 'Proveedor · ' . $proveedor['razon_social'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/proveedores/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Proveedores
        </a>
    </div>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1 class="h3 mb-0"><?= e($proveedor['razon_social']) ?></h1>
                <?= badgeEstado((string) $proveedor['estado']) ?>
                <?php if ($alertasAbiertas > 0): ?>
                    <span class="badge text-bg-danger"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i><?= $alertasAbiertas ?> alerta(s) abierta(s)</span>
                <?php endif; ?>
            </div>
            <p class="text-muted mb-0">
                NIT <?= e(formatearNit((string) $proveedor['nit'])) ?> ·
                Alta <?= e(formatearFecha((string) $proveedor['creado_en'])) ?>
            </p>
        </div>
        <?php if ($esAdmin): ?>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/proveedores/form.php?id=<?= $id ?>">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar
                </a>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalCuenta">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Registrar cuenta
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?= htmlFlash($flash) ?>

    <?php if ($cuentaPrincipal !== null && (int) $cuentaPrincipal['verificada'] !== 1): ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            <strong>La cuenta principal no está verificada.</strong>
            Los pagos que la usen quedarán bloqueados hasta que un administrador verifique la cuenta.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Correo</div>
                <div class="fw-semibold small"><?= e($proveedor['correo'] ?? '—') ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Teléfono</div>
                <div class="fw-semibold"><?= e($proveedor['telefono'] ?? '—') ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Total facturado</div>
                <div class="fw-semibold currency"><?= e(formatearMoneda($totalFacturado)) ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Total pagado</div>
                <div class="fw-semibold currency"><?= e(formatearMoneda($totalPagado)) ?></div>
            </div></div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabCuentas" type="button" role="tab">
                Cuentas bancarias (<?= count($cuentas) ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabFacturas" type="button" role="tab">
                Facturas
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPagos" type="button" role="tab">
                Pagos
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tabCuentas" role="tabpanel">
            <?php if ($cuentas === []): ?>
                <div class="card">
                    <div class="card-body empty-state">
                        <i class="bi bi-bank d-block fs-2 mb-2" aria-hidden="true"></i>
                        <p class="mb-2">Este proveedor aún no tiene cuentas bancarias.</p>
                        <?php if ($esAdmin): ?>
                            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalCuenta">
                                Registrar cuenta
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($cuentas as $cuenta): ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                                    <div>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <strong><?= e($cuenta['banco']) ?></strong>
                                            <span class="badge text-bg-light border"><?= e(ucfirst((string) $cuenta['tipo_cuenta'])) ?></span>
                                            <?php if ((int) $cuenta['es_principal'] === 1): ?>
                                                <span class="badge text-bg-primary">Principal</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-light border">Secundaria</span>
                                            <?php endif; ?>
                                            <?php if ((int) $cuenta['verificada'] === 1): ?>
                                                <span class="badge text-bg-success">Verificada</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning">Sin verificar</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-1"><?= e(enmascararCuenta((string) $cuenta['numero_cuenta'])) ?></div>
                                        <div class="small text-muted">
                                            Creada el <?= e(formatearFecha((string) $cuenta['creada_en'])) ?>
                                            <?php if ($cuenta['verificada_en'] !== null): ?>
                                                · Verificada el <?= e(formatearFecha((string) $cuenta['verificada_en'])) ?>
                                                <?= $cuenta['verificada_por'] !== null ? 'por ' . e((string) $cuenta['verificada_por']) : '' ?>
                                            <?php endif; ?>
                                            <?php if ($cuenta['modificada_en'] !== null): ?>
                                                · Actualizada el <?= e(formatearFecha((string) $cuenta['modificada_en'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-start gap-2">
                                        <?php if ($esAdmin && (int) $cuenta['verificada'] !== 1): ?>
                                            <button class="btn btn-sm btn-outline-primary" type="button"
                                                    data-bs-toggle="modal" data-bs-target="#modalVerificar"
                                                    data-cuenta-id="<?= (int) $cuenta['id_cuenta'] ?>"
                                                    data-cuenta-numero="<?= e(enmascararCuenta((string) $cuenta['numero_cuenta'])) ?>">
                                                Marcar como verificada
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($historialCuentas !== []): ?>
                <div class="card mt-4">
                    <div class="card-header">Historial de cuentas</div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0 sp-timeline">
                            <?php foreach ($historialCuentas as $evento): ?>
                                <li>
                                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-1">
                                        <strong class="small"><?= e($evento['usuario'] ?? 'Sistema') ?> · <?= e(etiquetaAccion((string) $evento['accion'])) ?></strong>
                                        <span class="small text-muted"><?= e(formatearFecha((string) $evento['fecha'], true)) ?></span>
                                    </div>
                                    <div class="small text-muted"><?= e($evento['detalle'] ?? '') ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="tabFacturas" role="tabpanel">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Número</th><th>Fecha</th><th class="text-end">Valor</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
                        <tbody>
                        <?php if ($facturas === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Sin facturas registradas.</td></tr>
                        <?php else: ?>
                            <?php foreach ($facturas as $factura): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($factura['numero_factura']) ?></td>
                                    <td class="text-muted"><?= e(formatearFecha((string) $factura['fecha_emision'])) ?></td>
                                    <td class="text-end currency"><?= e(formatearMoneda($factura['valor'])) ?></td>
                                    <td><?= badgeEstado((string) $factura['estado']) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $factura['id_factura'] ?>">Ver</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tabPagos" role="tabpanel">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Factura</th><th>Cuenta</th><th>Fecha</th><th class="text-end">Valor</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
                        <tbody>
                        <?php if ($pagos === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Sin pagos solicitados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pagos as $pago): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($pago['numero_factura']) ?></td>
                                    <td class="small"><?= e($pago['banco']) ?> · <?= e(enmascararCuenta((string) $pago['numero_cuenta'])) ?></td>
                                    <td class="text-muted"><?= e(formatearFecha((string) $pago['solicitado_en'])) ?></td>
                                    <td class="text-end currency"><?= e(formatearMoneda($pago['valor'])) ?></td>
                                    <td><?= badgeEstado((string) $pago['estado']) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $pago['id_pago'] ?>">Ver</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($esAdmin): ?>
<div class="modal fade" id="modalCuenta" tabindex="-1" aria-labelledby="modalCuentaTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= $id ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="accion" value="crear_cuenta">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalCuentaTitulo">Registrar cuenta bancaria</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small" role="alert">
                        La cuenta anterior no se elimina. La nueva quedará como principal, sin verificar y generará
                        una alerta de riesgo alto.
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="banco">Banco *</label>
                        <input class="form-control" id="banco" name="banco" maxlength="80" required placeholder="Ej. Bancolombia">
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="tipo_cuenta">Tipo *</label>
                            <select class="form-select" id="tipo_cuenta" name="tipo_cuenta" required>
                                <option value="ahorros">Ahorros</option>
                                <option value="corriente">Corriente</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="numero_cuenta">Número *</label>
                            <input class="form-control" id="numero_cuenta" name="numero_cuenta" inputmode="numeric"
                                   maxlength="30" required placeholder="0000000000">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="confirmar_cambio" name="confirmar_cambio" value="1" required>
                        <label class="form-check-label small" for="confirmar_cambio">
                            Confirmé el cambio por llamada al número registrado del proveedor.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit">Registrar cuenta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalVerificar" tabindex="-1" aria-labelledby="modalVerificarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= $id ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="accion" value="verificar_cuenta">
                <input type="hidden" name="id_cuenta" id="verificarCuentaId" value="">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalVerificarTitulo">Verificar cuenta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Verificará la cuenta <strong id="verificarCuentaNumero"></strong>. Registre la constancia
                        de la llamada de verificación.
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="constancia">Constancia de verificación *</label>
                        <textarea class="form-control" id="constancia" name="constancia" rows="4" minlength="10" maxlength="255"
                                  required placeholder="Persona contactada, cargo, fecha y resultado de la llamada"></textarea>
                        <div class="form-text">Mínimo 10 caracteres. Quedará en auditoría.</div>
                    </div>
                    <div class="alert alert-info small mb-0" role="status">
                        Al verificar la cuenta se resolverán sus alertas de cuenta reciente sin verificar.
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit">Confirmar verificación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= e(cspNonce()) ?>">
    (() => {
        const modal = document.getElementById('modalVerificar');
        if (!modal) return;
        modal.addEventListener('show.bs.modal', (event) => {
            const boton = event.relatedTarget;
            if (!boton) return;
            document.getElementById('verificarCuentaId').value = boton.dataset.cuentaId || '';
            document.getElementById('verificarCuentaNumero').textContent = boton.dataset.cuentaNumero || '';
        });
    })();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
