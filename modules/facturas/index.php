<?php
declare(strict_types=1);

/**
 * Facturas · listado (SC-06).
 * El empleado solo ve las facturas que registró; los demás roles ven todas.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereLogin();

$pdo = conexionBd();
$usuario = usuarioActual();
$esEmpleado = tieneRol('empleado');
$flash = consumirFlash();

$buscar = trim((string) ($_GET['q'] ?? ''));
$proveedorFiltro = max(0, (int) ($_GET['proveedor'] ?? 0));
$estadoFiltro = (string) ($_GET['estado'] ?? 'todos');
if (!in_array($estadoFiltro, ['todos', 'registrada', 'observada', 'aprobada', 'rechazada', 'pagada'], true)) {
    $estadoFiltro = 'todos';
}
$desde = (string) ($_GET['desde'] ?? '');
$hasta = (string) ($_GET['hasta'] ?? '');
if ($desde !== '' && !fechaIsoValida($desde)) {
    $desde = '';
}
if ($hasta !== '' && !fechaIsoValida($hasta)) {
    $hasta = '';
}

$pagina = paginaActual();
$porPagina = 20;

$where = [];
$params = [];

if ($esEmpleado) {
    $where[] = 'f.id_usuario_registra = :id_usuario';
    $params[':id_usuario'] = (int) $usuario['id'];
}
if ($buscar !== '') {
    $where[] = '(f.numero_factura LIKE :buscar_numero
                 OR pr.razon_social LIKE :buscar_razon
                 OR pr.nit LIKE :buscar_nit)';
    $params[':buscar_numero'] = '%' . $buscar . '%';
    $params[':buscar_razon'] = '%' . $buscar . '%';
    $params[':buscar_nit'] = '%' . $buscar . '%';
}
if ($proveedorFiltro > 0) {
    $where[] = 'f.id_proveedor = :id_proveedor';
    $params[':id_proveedor'] = $proveedorFiltro;
}
if ($estadoFiltro !== 'todos') {
    $where[] = 'f.estado = :estado';
    $params[':estado'] = $estadoFiltro;
}
if ($desde !== '') {
    $where[] = 'f.fecha_emision >= :desde';
    $params[':desde'] = $desde;
}
if ($hasta !== '') {
    $where[] = 'f.fecha_emision <= :hasta';
    $params[':hasta'] = $hasta;
}

$sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM facturas f
     INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor'
    . $sqlWhere
);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = totalPaginas($total, $porPagina);
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$sql = "SELECT f.*, pr.razon_social, pr.nit, u.nombre AS registrada_por,
               p.id_pago, p.estado AS pago_estado,
               c.banco, c.numero_cuenta, c.es_principal, c.verificada,
               COALESCE((
                   SELECT MAX(CASE a.nivel WHEN 'alto' THEN 3 WHEN 'medio' THEN 2 ELSE 1 END)
                   FROM alertas a
                   WHERE a.estado = 'abierta'
                     AND (a.id_factura = f.id_factura
                          OR a.id_pago = p.id_pago
                          OR a.id_proveedor = f.id_proveedor
                          OR (p.id_pago IS NOT NULL AND a.id_cuenta = p.id_cuenta_destino))
               ), 0) AS riesgo_score
        FROM facturas f
        INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
        INNER JOIN usuarios u ON u.id_usuario = f.id_usuario_registra
        LEFT JOIN pagos p ON p.id_factura = f.id_factura
        LEFT JOIN cuentas_bancarias c ON c.id_cuenta = p.id_cuenta_destino"
    . $sqlWhere
    . ' ORDER BY f.fecha_emision DESC, f.id_factura DESC LIMIT ' . $porPagina . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$facturas = $stmt->fetchAll();

$stmt = $pdo->query('SELECT id_proveedor, razon_social FROM proveedores ORDER BY razon_social');
$proveedores = $stmt->fetchAll();

$niveles = [3 => 'alto', 2 => 'medio', 1 => 'bajo'];

$pageTitle = 'Facturas';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Facturas</h1>
            <p class="text-muted mb-0">
                <?= $esEmpleado
                    ? 'Consulte las facturas que registró y solicite su pago.'
                    : 'Consulte las facturas, su estado y las alertas asociadas.' ?>
            </p>
        </div>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/facturas/form.php">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva factura
        </a>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/facturas/index.php" class="row g-2 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label" for="q">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control" id="q" name="q" type="search" value="<?= e($buscar) ?>"
                               placeholder="Número, proveedor o NIT">
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="proveedor">Proveedor</label>
                    <select class="form-select" id="proveedor" name="proveedor">
                        <option value="0">Todos</option>
                        <?php foreach ($proveedores as $proveedor): ?>
                            <option value="<?= (int) $proveedor['id_proveedor'] ?>"
                                <?= $proveedorFiltro === (int) $proveedor['id_proveedor'] ? 'selected' : '' ?>>
                                <?= e($proveedor['razon_social']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado">
                        <option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="registrada" <?= $estadoFiltro === 'registrada' ? 'selected' : '' ?>>Registradas</option>
                        <option value="observada" <?= $estadoFiltro === 'observada' ? 'selected' : '' ?>>Observadas</option>
                        <option value="aprobada" <?= $estadoFiltro === 'aprobada' ? 'selected' : '' ?>>Aprobadas</option>
                        <option value="rechazada" <?= $estadoFiltro === 'rechazada' ? 'selected' : '' ?>>Rechazadas</option>
                        <option value="pagada" <?= $estadoFiltro === 'pagada' ? 'selected' : '' ?>>Pagadas</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="desde">Desde</label>
                    <input class="form-control" id="desde" name="desde" type="date" value="<?= e($desde) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="hasta">Hasta</label>
                    <input class="form-control" id="hasta" name="hasta" type="date" value="<?= e($hasta) ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar</button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/facturas/index.php">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Factura</th>
                        <th>Proveedor</th>
                        <th>Emisión</th>
                        <th class="text-end">Valor</th>
                        <th>Riesgo</th>
                        <th>Estado</th>
                        <th>Pago</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($facturas === []): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-receipt d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-2">No encontramos facturas con esos filtros.</p>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/facturas/index.php">Limpiar filtros</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($facturas as $factura): ?>
                        <?php $riesgo = $niveles[(int) $factura['riesgo_score']] ?? null; ?>
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none"
                                   href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $factura['id_factura'] ?>">
                                    <?= e($factura['numero_factura']) ?>
                                </a>
                                <div class="small text-muted">Registró <?= e($factura['registrada_por']) ?></div>
                            </td>
                            <td class="small">
                                <?= e($factura['razon_social']) ?>
                                <div class="text-muted">NIT <?= e(formatearNit((string) $factura['nit'])) ?></div>
                            </td>
                            <td class="text-muted"><?= e(formatearFecha((string) $factura['fecha_emision'])) ?></td>
                            <td class="text-end currency"><?= e(formatearMoneda($factura['valor'])) ?></td>
                            <td><?= badgeRiesgo($riesgo) ?></td>
                            <td><?= badgeEstado((string) $factura['estado']) ?></td>
                            <td>
                                <?php if ($factura['id_pago'] === null): ?>
                                    <span class="small text-muted">Sin pago</span>
                                <?php else: ?>
                                    <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $factura['id_pago'] ?>">
                                        <?= badgeEstado((string) $factura['pago_estado']) ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $factura['id_factura'] ?>">
                                    Ver
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <span class="small text-muted">
                <?= $total === 0
                    ? 'Sin resultados'
                    : 'Mostrando ' . ($offset + 1) . '–' . min($offset + $porPagina, $total) . ' de ' . $total ?>
            </span>
            <?php if ($paginas > 1): ?>
                <nav aria-label="Paginación de facturas">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'q' => $buscar,
                                'proveedor' => $proveedorFiltro,
                                'estado' => $estadoFiltro,
                                'desde' => $desde,
                                'hasta' => $hasta,
                                'pagina' => max(1, $pagina - 1),
                            ])) ?>">Anterior</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
                        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'q' => $buscar,
                                'proveedor' => $proveedorFiltro,
                                'estado' => $estadoFiltro,
                                'desde' => $desde,
                                'hasta' => $hasta,
                                'pagina' => min($paginas, $pagina + 1),
                            ])) ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
