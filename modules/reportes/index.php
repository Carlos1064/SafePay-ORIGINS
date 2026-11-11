<?php
declare(strict_types=1);

/**
 * Reportes · operaciones sospechosas (SC-18).
 * Filtros por rango, nivel, proveedor y tipo de operación; exportación CSV.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';
require_once __DIR__ . '/_consulta.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$filtros = filtrosReporte($_GET);
$filas = consultarReporte($pdo, $filtros, 300);
$resumen = resumenReporte($pdo, $filtros);

$stmt = $pdo->query('SELECT id_proveedor, razon_social FROM proveedores ORDER BY razon_social');
$proveedores = $stmt->fetchAll();

$urlExportar = BASE_URL . '/modules/reportes/exportar.php?' . http_build_query([
    'desde' => $filtros['desde'],
    'hasta' => $filtros['hasta'],
    'tipo' => $filtros['tipo'],
    'nivel' => $filtros['nivel'],
    'proveedor' => $filtros['proveedor'],
]);

$pageTitle = 'Reportes';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 mb-1">Reportes</h1>
            <p class="text-muted mb-0">Genere el reporte de operaciones sospechosas y expórtelo.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-secondary" type="button" data-print>
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir
            </button>
            <a class="btn btn-primary" href="<?= e($urlExportar) ?>">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Exportar CSV
            </a>
        </div>
    </div>

    <div class="card mb-4 no-print">
        <div class="card-header">Filtros del reporte</div>
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/reportes/index.php" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="desde">Desde *</label>
                    <input class="form-control" id="desde" name="desde" type="date" value="<?= e($filtros['desde']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="hasta">Hasta *</label>
                    <input class="form-control" id="hasta" name="hasta" type="date" value="<?= e($filtros['hasta']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="tipo">Tipo de operación</label>
                    <select class="form-select" id="tipo" name="tipo">
                        <option value="alertas" <?= $filtros['tipo'] === 'alertas' ? 'selected' : '' ?>>Alertas</option>
                        <option value="pagos_bloqueados" <?= $filtros['tipo'] === 'pagos_bloqueados' ? 'selected' : '' ?>>Pagos bloqueados</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="nivel">Nivel de riesgo</label>
                    <select class="form-select" id="nivel" name="nivel">
                        <option value="">Todos</option>
                        <option value="alto" <?= $filtros['nivel'] === 'alto' ? 'selected' : '' ?>>Alto</option>
                        <option value="medio" <?= $filtros['nivel'] === 'medio' ? 'selected' : '' ?>>Medio</option>
                        <option value="bajo" <?= $filtros['nivel'] === 'bajo' ? 'selected' : '' ?>>Bajo</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="proveedor">Proveedor</label>
                    <select class="form-select" id="proveedor" name="proveedor">
                        <option value="0">Todos</option>
                        <?php foreach ($proveedores as $proveedor): ?>
                            <option value="<?= (int) $proveedor['id_proveedor'] ?>"
                                <?= $filtros['proveedor'] === (int) $proveedor['id_proveedor'] ? 'selected' : '' ?>>
                                <?= e($proveedor['razon_social']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="d-none d-print-block mb-4">
        <h2 class="h4">SafePay · Reporte de operaciones sospechosas</h2>
        <p class="mb-0">Período: <?= e(formatearFecha($filtros['desde'])) ?> al <?= e(formatearFecha($filtros['hasta'])) ?></p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Alertas en el rango</div>
                <div class="fs-3 fw-bold"><?= (int) $resumen['alertas'] ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Proveedores afectados</div>
                <div class="fs-3 fw-bold"><?= (int) $resumen['proveedores'] ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Monto en alertas</div>
                <div class="fs-5 fw-bold currency"><?= e(formatearMoneda($resumen['monto_alertas'])) ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Pagos bloqueados</div>
                <div class="fs-3 fw-bold"><?= (int) $resumen['pagos_bloqueados'] ?></div>
                <div class="small text-muted"><?= e(formatearMoneda($resumen['monto_bloqueado'])) ?></div>
            </div></div>
        </div>
    </div>

    <div class="alert alert-info small no-print" role="status">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        El reporte se calcula sobre las alertas creadas y los pagos bloqueados dentro del rango de fechas.
        La exportación conserva todos los filtros seleccionados.
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                <?= $filtros['tipo'] === 'alertas' ? 'Alertas del período' : 'Pagos bloqueados del período' ?>
            </span>
            <span class="small text-muted">
                <?= count($filas) ?> fila(s)<?= count($filas) >= 300 ? ' · vista limitada a 300, exporte para ver todas' : '' ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Proveedor</th>
                        <th>Factura / pago</th>
                        <th class="text-end">Valor</th>
                        <th>Nivel</th>
                        <th>Estado</th>
                        <th>Responsable / solicitante</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($filas === []): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-bar-chart d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-0">No hay operaciones que coincidan con el reporte.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filas as $fila): ?>
                        <tr>
                            <td class="text-nowrap small"><?= e(formatearFecha((string) $fila['fecha'], true)) ?></td>
                            <td class="small">
                                <div class="fw-semibold"><?= e($fila['origen']) ?></div>
                                <?php if ($fila['origen'] === 'Alerta'): ?>
                                    <div class="text-muted"><?= e($fila['tipo_legible']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e($fila['razon_social'] ?? '—') ?></td>
                            <td class="small">
                                <?php if ($fila['numero_factura'] !== null): ?>
                                    <div>Factura <?= e($fila['numero_factura']) ?></div>
                                <?php endif; ?>
                                <?php if ($fila['id_pago'] !== null): ?>
                                    <a class="text-decoration-none" href="<?= e($fila['url']) ?>">Pago #<?= (int) $fila['id_pago'] ?></a>
                                <?php else: ?>
                                    <a class="text-decoration-none" href="<?= e($fila['url']) ?>">Ver detalle</a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end currency"><?= e(formatearMoneda($fila['valor'])) ?></td>
                            <td><?= badgeRiesgo($fila['nivel'] !== null ? (string) $fila['nivel'] : null) ?></td>
                            <td><?= badgeEstado((string) $fila['estado']) ?></td>
                            <td class="small">
                                <?= e($fila['responsable'] ?? $fila['solicitante'] ?? '—') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            Generado el <?= e(formatearFecha(date('Y-m-d H:i:s'), true)) ?> por <?= e((string) usuarioActual()['nombre']) ?>.
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
