<?php
declare(strict_types=1);

/**
 * Pago · solicitud (SC-11).
 * Crea el pago, genera las alertas de cuenta y lo deja pendiente o bloqueado.
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
$esEmpleado = tieneRol('empleado');

$idFactura = max(0, (int) ($_GET['factura'] ?? $_POST['id_factura'] ?? 0));

$stmt = $pdo->prepare(
    'SELECT f.*, pr.razon_social, pr.nit, pr.estado AS proveedor_estado,
            p.id_pago, u.nombre AS registrada_por
     FROM facturas f
     INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
     INNER JOIN usuarios u ON u.id_usuario = f.id_usuario_registra
     LEFT JOIN pagos p ON p.id_factura = f.id_factura
     WHERE f.id_factura = :id
     LIMIT 1'
);
$stmt->execute([':id' => $idFactura]);
$factura = $stmt->fetch();

if (!$factura) {
    redirigirConFlash('/modules/facturas/index.php', 'danger', 'La factura indicada no existe.');
}
if ($esEmpleado && (int) $factura['id_usuario_registra'] !== (int) $usuario['id']) {
    denegarAcceso('Intento de solicitar pago de la factura #' . $idFactura . ' de otro usuario.');
}
if ($factura['id_pago'] !== null) {
    redirigirConFlash(
        '/modules/pagos/detalle.php?id=' . (int) $factura['id_pago'],
        'info',
        'Esta factura ya tiene un pago asociado.'
    );
}
if (!in_array((string) $factura['estado'], ['registrada', 'observada'], true)) {
    redirigirConFlash(
        '/modules/facturas/detalle.php?id=' . $idFactura,
        'warning',
        'El estado actual de la factura no permite solicitar un pago.'
    );
}

$stmt = $pdo->prepare(
    'SELECT * FROM cuentas_bancarias
     WHERE id_proveedor = :id_proveedor
     ORDER BY es_principal DESC, creada_en DESC, id_cuenta DESC'
);
$stmt->execute([':id_proveedor' => (int) $factura['id_proveedor']]);
$cuentas = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT id_cuenta, COUNT(*) AS total
     FROM alertas
     WHERE estado = 'abierta' AND id_cuenta IS NOT NULL
     GROUP BY id_cuenta"
);
$stmt->execute();
$alertasPorCuenta = [];
foreach ($stmt->fetchAll() as $fila) {
    $alertasPorCuenta[(int) $fila['id_cuenta']] = (int) $fila['total'];
}

$alertasBase = alertasAbiertasRelacionadas(
    $pdo,
    $idFactura,
    null,
    (int) $factura['id_proveedor'],
    null
);

$cuentaPrincipal = null;
foreach ($cuentas as $cuenta) {
    if ((int) $cuenta['es_principal'] === 1) {
        $cuentaPrincipal = $cuenta;
        break;
    }
}

$cuentaSeleccionada = (int) ($_POST['id_cuenta'] ?? ($cuentaPrincipal['id_cuenta'] ?? 0));
$errores = [];
$errorGeneral = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión del formulario expiró. Recargue la página e intente de nuevo.';
    }

    $cuenta = null;
    foreach ($cuentas as $cuentaListado) {
        if ((int) $cuentaListado['id_cuenta'] === $cuentaSeleccionada) {
            $cuenta = $cuentaListado;
            break;
        }
    }

    if ($cuenta === null) {
        $errores[] = 'Seleccione una cuenta bancaria válida del proveedor.';
    }

    if ($errores === [] && $cuenta !== null) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT f.id_factura, f.estado, f.valor, f.id_proveedor
                 FROM facturas f
                 WHERE f.id_factura = :id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':id' => $idFactura]);
            $facturaBloqueada = $stmt->fetch();

            if (!$facturaBloqueada || !in_array((string) $facturaBloqueada['estado'], ['registrada', 'observada'], true)) {
                throw new DomainException('La factura cambió de estado. Recargue la página.');
            }

            $stmt = $pdo->prepare('SELECT id_pago FROM pagos WHERE id_factura = :id LIMIT 1');
            $stmt->execute([':id' => $idFactura]);
            if ($stmt->fetchColumn() !== false) {
                throw new DomainException('Esta factura ya tiene un pago asociado.');
            }

            $stmt = $pdo->prepare(
                'SELECT id_cuenta FROM cuentas_bancarias
                 WHERE id_cuenta = :id_cuenta AND id_proveedor = :id_proveedor
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([
                ':id_cuenta'    => $cuentaSeleccionada,
                ':id_proveedor' => (int) $factura['id_proveedor'],
            ]);
            if ($stmt->fetchColumn() === false) {
                throw new DomainException('La cuenta seleccionada no pertenece al proveedor.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO pagos (id_factura, id_cuenta_destino, valor, estado, id_usuario_solicita)
                 VALUES (:id_factura, :id_cuenta, :valor, \'pendiente\', :id_usuario)'
            );
            $stmt->execute([
                ':id_factura' => $idFactura,
                ':id_cuenta'  => $cuentaSeleccionada,
                ':valor'      => $factura['valor'],
                ':id_usuario' => (int) $usuario['id'],
            ]);
            $idPago = (int) $pdo->lastInsertId();

            $hallazgos = $validador->evaluarPago($idPago);
            guardarHallazgos($pdo, $hallazgos);

            sincronizarEstados($pdo, $idPago);

            $stmt = $pdo->prepare('SELECT estado FROM pagos WHERE id_pago = :id');
            $stmt->execute([':id' => $idPago]);
            $estadoFinal = (string) $stmt->fetchColumn();

            registrarAuditoria(
                $pdo,
                (int) $usuario['id'],
                'solicitar_pago',
                'pagos',
                $idPago,
                'Pago de la factura ' . $factura['numero_factura'] . ' por ' . formatearMoneda($factura['valor'])
                    . ' a la cuenta ' . enmascararCuenta((string) $cuenta['numero_cuenta'])
                    . ' (' . $cuenta['banco'] . '). Estado inicial: ' . $estadoFinal
                    . '. Alertas del motor: ' . count($hallazgos) . '.'
            );

            $pdo->commit();

            if ($estadoFinal === 'bloqueado') {
                redirigirConFlash(
                    '/modules/pagos/detalle.php?id=' . $idPago,
                    'warning',
                    'Pago solicitado y bloqueado por riesgo. Un administrador debe revisar las alertas antes de aprobarlo.'
                );
            }

            redirigirConFlash(
                '/modules/pagos/detalle.php?id=' . $idPago,
                'success',
                'Pago solicitado correctamente. Queda pendiente de aprobación.'
            );
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errores[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SafePay solicitar pago: ' . $error->getMessage());
            $errorGeneral = 'No se pudo solicitar el pago. Intente de nuevo.';
        }
    }
}

$pageTitle = 'Solicitar pago';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= $idFactura ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Factura <?= e($factura['numero_factura']) ?>
        </a>
    </div>

    <div class="mb-4">
        <h1 class="h3 mb-1">Solicitar pago</h1>
        <p class="text-muted mb-0">Factura <?= e($factura['numero_factura']) ?> · <?= e($factura['razon_social']) ?></p>
    </div>

    <?php if ($errorGeneral !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e($errorGeneral) ?>
        </div>
    <?php endif; ?>

    <?php if ($errores !== []): ?>
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-1">Revise la solicitud:</div>
            <ul class="mb-0 ps-3">
                <?php foreach (array_unique($errores) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($cuentas === []): ?>
        <div class="alert alert-warning" role="alert">
            El proveedor no tiene cuentas bancarias registradas. Un administrador debe registrar y verificar una cuenta.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(BASE_URL) ?>/modules/pagos/form.php?factura=<?= $idFactura ?>" novalidate>
        <?= csrfCampo() ?>
        <input type="hidden" name="id_factura" value="<?= $idFactura ?>">

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card mb-4">
                    <div class="card-header">Factura</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Número</div>
                                <div class="fw-semibold"><?= e($factura['numero_factura']) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Proveedor</div>
                                <div class="fw-semibold"><?= e($factura['razon_social']) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Valor</div>
                                <div class="fw-semibold currency"><?= e(formatearMoneda($factura['valor'])) ?></div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted">Estado</div>
                                <?= badgeEstado((string) $factura['estado']) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Datos del pago</div>
                    <div class="card-body">
                        <p class="small text-muted">Los campos con <span class="text-danger">*</span> son obligatorios.</p>

                        <div class="mb-3">
                            <label class="form-label" for="id_cuenta">Cuenta destino *</label>
                            <select class="form-select" id="id_cuenta" name="id_cuenta" required <?= $cuentas === [] ? 'disabled' : '' ?>>
                                <?php foreach ($cuentas as $cuenta): ?>
                                    <option value="<?= (int) $cuenta['id_cuenta'] ?>"
                                            data-principal="<?= (int) $cuenta['es_principal'] === 1 ? '1' : '0' ?>"
                                            data-verificada="<?= (int) $cuenta['verificada'] === 1 ? '1' : '0' ?>"
                                            data-alertas="<?= (int) ($alertasPorCuenta[(int) $cuenta['id_cuenta']] ?? 0) ?>"
                                        <?= $cuentaSeleccionada === (int) $cuenta['id_cuenta'] ? 'selected' : '' ?>>
                                        <?= e($cuenta['banco']) ?> · <?= e(ucfirst((string) $cuenta['tipo_cuenta'])) ?>
                                        · <?= e(enmascararCuenta((string) $cuenta['numero_cuenta'])) ?>
                                        <?= (int) $cuenta['es_principal'] === 1 ? ' · Principal' : ' · Secundaria' ?>
                                        <?= (int) $cuenta['verificada'] === 1 ? ' · Verificada' : ' · Sin verificar' ?>
                                        <?= (int) ($alertasPorCuenta[(int) $cuenta['id_cuenta']] ?? 0) > 0 ? ' · Con alerta' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Solo se muestran cuentas del proveedor seleccionado.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="valor">Valor a pagar</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input class="form-control currency" id="valor" type="text" readonly
                                       value="<?= e(formatearMoneda($factura['valor'])) ?>">
                            </div>
                            <div class="form-text">En el MVP se paga el valor total de la factura.</div>
                        </div>

                        <div id="avisoPago" class="alert alert-info small mb-0" role="status">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            Complete la cuenta destino para ver el riesgo del pago.
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button class="btn btn-primary" type="submit" <?= $cuentas === [] ? 'disabled' : '' ?>>
                                <i class="bi bi-send me-1" aria-hidden="true"></i>Solicitar pago
                            </button>
                            <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= $idFactura ?>">Cancelar</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-4">
                    <div class="card-header">Qué ocurrirá</div>
                    <div class="card-body small text-muted">
                        <ul class="mb-0 ps-3">
                            <li>El pago queda en estado <strong>pendiente</strong> o <strong>bloqueado</strong> según el riesgo.</li>
                            <li>Se registra en auditoría con su usuario y la fecha.</li>
                            <li>Si la cuenta no es la principal o no está verificada, se genera una alerta.</li>
                            <li>Usted no podrá aprobar su propia solicitud.</li>
                        </ul>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Cuenta principal</div>
                    <div class="card-body">
                        <?php if ($cuentaPrincipal === null): ?>
                            <p class="small text-muted mb-0">El proveedor no tiene cuenta principal.</p>
                        <?php else: ?>
                            <div class="fw-semibold"><?= e($cuentaPrincipal['banco']) ?></div>
                            <div class="small text-muted"><?= e(ucfirst((string) $cuentaPrincipal['tipo_cuenta'])) ?> · <?= e(enmascararCuenta((string) $cuentaPrincipal['numero_cuenta'])) ?></div>
                            <div class="mt-2">
                                <?php if ((int) $cuentaPrincipal['verificada'] === 1): ?>
                                    <span class="badge text-bg-success">Verificada</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">Sin verificar</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script nonce="<?= e(cspNonce()) ?>">
    (() => {
        const cuenta = document.getElementById('id_cuenta');
        const aviso = document.getElementById('avisoPago');
        if (!cuenta || !aviso) return;

        const alertasBase = <?= count($alertasBase) ?>;

        const actualizar = () => {
            const opcion = cuenta.options[cuenta.selectedIndex];
            if (!opcion) return;
            const principal = opcion.dataset.principal === '1';
            const verificada = opcion.dataset.verificada === '1';
            const alertasCuenta = Number(opcion.dataset.alertas || 0);

            if (alertasBase > 0) {
                aviso.className = 'alert alert-danger small mb-0';
                aviso.innerHTML = '<i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i> ' +
                    'Hay ' + alertasBase + ' alerta(s) abierta(s) en la factura o el proveedor. El pago se creará bloqueado.';
            } else if (!principal || !verificada || alertasCuenta > 0) {
                aviso.className = 'alert alert-warning small mb-0';
                aviso.innerHTML = '<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> ' +
                    (!principal ? 'Esta cuenta no es la principal. ' : '') +
                    (!verificada ? 'La cuenta no está verificada. ' : '') +
                    (alertasCuenta > 0 ? 'La cuenta tiene alertas abiertas. ' : '') +
                    'El pago se creará bloqueado y se generará una alerta.';
            } else {
                aviso.className = 'alert alert-success small mb-0';
                aviso.innerHTML = '<i class="bi bi-check-circle me-1" aria-hidden="true"></i> Cuenta principal verificada. El pago quedará pendiente de aprobación.';
            }
        };

        cuenta.addEventListener('change', actualizar);
        actualizar();
    })();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
