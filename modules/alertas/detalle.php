<?php
declare(strict_types=1);

/**
 * Alerta · detalle (SC-15).
 * El administrador resuelve o descarta con justificación obligatoria.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$usuario = usuarioActual();
$esAdmin = tieneRol('administrador');
$id = max(1, (int) ($_GET['id'] ?? $_POST['id_alerta'] ?? 0));

$stmt = $pdo->prepare(
    'SELECT a.*, pr.razon_social, pr.nit, pr.estado AS proveedor_estado,
            f.numero_factura, f.valor AS factura_valor, f.estado AS factura_estado,
            p.id_pago, p.valor AS pago_valor, p.estado AS pago_estado,
            c.banco, c.tipo_cuenta, c.numero_cuenta, c.es_principal, c.verificada,
            u.nombre AS responsable
     FROM alertas a
     LEFT JOIN proveedores pr ON pr.id_proveedor = a.id_proveedor
     LEFT JOIN facturas f ON f.id_factura = a.id_factura
     LEFT JOIN pagos p ON p.id_pago = a.id_pago
     LEFT JOIN cuentas_bancarias c ON c.id_cuenta = a.id_cuenta
     LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario_resuelve
     WHERE a.id_alerta = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$alerta = $stmt->fetch();

if (!$alerta) {
    redirigirConFlash('/modules/alertas/index.php', 'danger', 'La alerta solicitada no existe.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esAdmin) {
        denegarAcceso('Intento de resolver la alerta #' . $id . ' sin ser administrador.');
    }
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        redirigirConFlash('/modules/alertas/detalle.php?id=' . $id, 'danger', 'La sesión del formulario expiró. Intente de nuevo.');
    }

    $accion = (string) ($_POST['accion'] ?? '');
    $justificacion = trim((string) ($_POST['justificacion'] ?? ''));

    if (!in_array($accion, ['resolver', 'descartar'], true)) {
        redirigirConFlash('/modules/alertas/detalle.php?id=' . $id, 'danger', 'Seleccione una acción válida.');
    }
    if (mb_strlen($justificacion) < 20 || mb_strlen($justificacion) > 255) {
        redirigirConFlash('/modules/alertas/detalle.php?id=' . $id, 'danger', 'Escriba una justificación entre 20 y 255 caracteres.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM alertas WHERE id_alerta = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $alertaBloqueada = $stmt->fetch();

        if (!$alertaBloqueada) {
            throw new DomainException('La alerta ya no existe.');
        }
        if ((string) $alertaBloqueada['estado'] !== 'abierta') {
            throw new DomainException('Esta alerta ya fue procesada.');
        }

        $nuevoEstado = $accion === 'resolver' ? 'resuelta' : 'descartada';
        $pdo->prepare(
            'UPDATE alertas
             SET estado = :estado, id_usuario_resuelve = :id_usuario,
                 resuelta_en = NOW(), justificacion = :justificacion
             WHERE id_alerta = :id'
        )->execute([
            ':estado'        => $nuevoEstado,
            ':id_usuario'    => (int) $usuario['id'],
            ':justificacion' => $justificacion,
            ':id'            => $id,
        ]);

        registrarAuditoria(
            $pdo,
            (int) $usuario['id'],
            $accion === 'resolver' ? 'resolver_alerta' : 'descartar_alerta',
            'alertas',
            $id,
            ucfirst($accion) . ' alerta de tipo ' . $alerta['tipo'] . ' (' . $alerta['nivel'] . '). Justificación: ' . $justificacion
        );

        $condiciones = [];
        $params = [];
        if ($alerta['id_pago'] !== null) {
            $condiciones[] = 'p.id_pago = :id_pago';
            $params[':id_pago'] = (int) $alerta['id_pago'];
        }
        if ($alerta['id_factura'] !== null) {
            $condiciones[] = 'p.id_factura = :id_factura';
            $params[':id_factura'] = (int) $alerta['id_factura'];
        }
        if ($alerta['id_cuenta'] !== null) {
            $condiciones[] = 'p.id_cuenta_destino = :id_cuenta';
            $params[':id_cuenta'] = (int) $alerta['id_cuenta'];
        }
        if ($alerta['id_proveedor'] !== null) {
            $condiciones[] = 'p.id_factura IN (SELECT f2.id_factura FROM facturas f2 WHERE f2.id_proveedor = :id_proveedor)';
            $params[':id_proveedor'] = (int) $alerta['id_proveedor'];
        }

        $idsPagos = [];
        if ($condiciones !== []) {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT p.id_pago FROM pagos p WHERE ' . implode(' OR ', $condiciones)
            );
            $stmt->execute($params);
            $idsPagos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($idsPagos as $idPago) {
            sincronizarEstados($pdo, (int) $idPago);
        }

        if ($alerta['id_factura'] !== null) {
            sincronizarFactura($pdo, (int) $alerta['id_factura']);
        }

        $pdo->commit();

        redirigirConFlash(
            '/modules/alertas/detalle.php?id=' . $id,
            'success',
            'Alerta ' . ($accion === 'resolver' ? 'resuelta' : 'descartada') . ' y pagos relacionados recalculados.'
        );
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirigirConFlash('/modules/alertas/detalle.php?id=' . $id, 'danger', $error->getMessage());
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SafePay alerta: ' . $error->getMessage());
        redirigirConFlash('/modules/alertas/detalle.php?id=' . $id, 'danger', 'No se pudo procesar la alerta. Intente de nuevo.');
    }
}

$flash = consumirFlash();

$pageTitle = 'Alerta #' . $id;
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/alertas/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Alertas
        </a>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1 class="h3 mb-0">Alerta #<?= $id ?></h1>
                <?= badgeRiesgo((string) $alerta['nivel']) ?>
                <?= badgeEstado((string) $alerta['estado']) ?>
            </div>
            <p class="text-muted mb-0">
                <?= e(etiquetaTipoAlerta((string) $alerta['tipo'])) ?> ·
                Creada el <?= e(formatearFecha((string) $alerta['creada_en'], true)) ?>
            </p>
        </div>
        <?php if ($esAdmin && $alerta['estado'] === 'abierta'): ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalDecision"
                        data-accion-alerta="resolver">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Resolver
                </button>
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#modalDecision"
                        data-accion-alerta="descartar">
                    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Descartar
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$esAdmin && $alerta['estado'] === 'abierta'): ?>
        <div class="alert alert-info" role="status">
            <i class="bi bi-lock me-1" aria-hidden="true"></i>
            Solo un administrador puede resolver o descartar esta alerta.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header">Descripción</div>
                <div class="card-body">
                    <p class="fs-6 mb-3"><?= e($alerta['descripcion']) ?></p>
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Nivel</div>
                            <?= badgeRiesgo((string) $alerta['nivel']) ?>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Tipo</div>
                            <div class="fw-semibold"><?= e(etiquetaTipoAlerta((string) $alerta['tipo'])) ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Estado</div>
                            <?= badgeEstado((string) $alerta['estado']) ?>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="small text-muted">Creación</div>
                            <div class="fw-semibold"><?= e(formatearFecha((string) $alerta['creada_en'], true)) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Entidades relacionadas</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if ($alerta['id_proveedor'] !== null): ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="small text-muted mb-1">Proveedor</div>
                                    <div class="fw-semibold"><?= e($alerta['razon_social'] ?? '—') ?></div>
                                    <?php if ($alerta['nit'] !== null): ?>
                                        <div class="small text-muted">NIT <?= e(formatearNit((string) $alerta['nit'])) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2"><?= badgeEstado((string) $alerta['proveedor_estado']) ?></div>
                                    <a class="btn btn-sm btn-outline-primary mt-2"
                                       href="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= (int) $alerta['id_proveedor'] ?>">Ver proveedor</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($alerta['id_factura'] !== null): ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="small text-muted mb-1">Factura</div>
                                    <div class="fw-semibold"><?= e($alerta['numero_factura'] ?? '—') ?></div>
                                    <div class="small text-muted"><?= e(formatearMoneda($alerta['factura_valor'])) ?></div>
                                    <div class="mt-2"><?= badgeEstado((string) $alerta['factura_estado']) ?></div>
                                    <a class="btn btn-sm btn-outline-primary mt-2"
                                       href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $alerta['id_factura'] ?>">Ver factura</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($alerta['id_pago'] !== null): ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="small text-muted mb-1">Pago</div>
                                    <div class="fw-semibold">#<?= (int) $alerta['id_pago'] ?></div>
                                    <div class="small text-muted"><?= e(formatearMoneda($alerta['pago_valor'])) ?></div>
                                    <div class="mt-2"><?= badgeEstado((string) $alerta['pago_estado']) ?></div>
                                    <a class="btn btn-sm btn-outline-primary mt-2"
                                       href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $alerta['id_pago'] ?>">Ver pago</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($alerta['id_cuenta'] !== null): ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="small text-muted mb-1">Cuenta bancaria</div>
                                    <div class="fw-semibold"><?= e($alerta['banco'] ?? '—') ?></div>
                                    <div><?= e(enmascararCuenta((string) $alerta['numero_cuenta'])) ?></div>
                                    <div class="mt-2 d-flex flex-wrap gap-1">
                                        <span class="badge text-bg-light border"><?= (int) $alerta['es_principal'] === 1 ? 'Principal' : 'Secundaria' ?></span>
                                        <?php if ((int) $alerta['verificada'] === 1): ?>
                                            <span class="badge text-bg-success">Verificada</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning">Sin verificar</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($alerta['estado'] !== 'abierta'): ?>
                <div class="card">
                    <div class="card-header">Resolución</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Estado</div>
                                <?= badgeEstado((string) $alerta['estado']) ?>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Responsable</div>
                                <div class="fw-semibold"><?= e($alerta['responsable'] ?? '—') ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Fecha</div>
                                <div class="fw-semibold"><?= e(formatearFecha((string) $alerta['resuelta_en'], true)) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Justificación</div>
                                <div class="small"><?= e($alerta['justificacion'] ?? '—') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">Efecto de la decisión</div>
                <div class="card-body small text-muted">
                    <ul class="mb-0 ps-3">
                        <li class="mb-2">Resolver confirma que la situación fue revisada y atendida.</li>
                        <li class="mb-2">Descartar indica que la alerta fue un falso positivo.</li>
                        <li class="mb-2">Ambas acciones exigen justificación y quedan en auditoría.</li>
                        <li>Si se cierra la última alerta relacionada, el pago bloqueado vuelve a pendiente.</li>
                    </ul>
                </div>
            </div>

            <?php if ($alerta['estado'] === 'abierta'): ?>
                <div class="alert alert-warning small" role="alert">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                    Verifique el proveedor y la cuenta por un canal independiente antes de resolver una alerta de cuenta.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($esAdmin && $alerta['estado'] === 'abierta'): ?>
<div class="modal fade" id="modalDecision" tabindex="-1" aria-labelledby="modalDecisionTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(BASE_URL) ?>/modules/alertas/detalle.php?id=<?= $id ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="id_alerta" value="<?= $id ?>">
                <input type="hidden" name="accion" id="accionAlerta" value="resolver">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalDecisionTitulo">Resolver alerta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="justificacion">Justificación *</label>
                        <textarea class="form-control" id="justificacion" name="justificacion" rows="4"
                                  minlength="20" maxlength="255" required
                                  placeholder="Explique qué verificó y por qué la alerta puede cerrarse"></textarea>
                        <div class="form-text">Entre 20 y 255 caracteres. Quedará en auditoría.</div>
                    </div>
                    <div id="avisoDecision" class="alert alert-info small mb-0" role="status">
                        La alerta quedará resuelta.
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit" id="botonDecision">Confirmar resolución</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= e(cspNonce()) ?>">
    (() => {
        const modal = document.getElementById('modalDecision');
        if (!modal) return;
        modal.addEventListener('show.bs.modal', (event) => {
            const boton = event.relatedTarget;
            const accion = boton?.dataset.accionAlerta === 'descartar' ? 'descartar' : 'resolver';
            document.getElementById('accionAlerta').value = accion;
            document.getElementById('modalDecisionTitulo').textContent = accion === 'descartar' ? 'Descartar alerta' : 'Resolver alerta';
            document.getElementById('botonDecision').textContent = accion === 'descartar' ? 'Confirmar descarte' : 'Confirmar resolución';
            document.getElementById('botonDecision').className = accion === 'descartar' ? 'btn btn-outline-secondary' : 'btn btn-primary';
            document.getElementById('avisoDecision').textContent = accion === 'descartar'
                ? 'La alerta quedará descartada como falso positivo.'
                : 'La alerta quedará resuelta.';
        });
    })();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
