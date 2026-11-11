<?php
declare(strict_types=1);

/**
 * Pagos · listado (SC-10).
 * Los empleados solo ven sus propias solicitudes.
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

$estadoFiltro = (string) ($_GET['estado'] ?? 'pendiente');
if (!in_array($estadoFiltro, ['pendiente', 'bloqueado', 'aprobado', 'rechazado', 'ejecutado', 'todos'], true)) {
    $estadoFiltro = 'pendiente';
}
$buscar = trim((string) ($_GET['q'] ?? ''));
$proveedorFiltro = max(0, (int) ($_GET['proveedor'] ?? 0));
$desde = (string) ($_GET['desde'] ?? '');
$hasta = (string) ($_GET['hasta'] ?? '');
if ($desde !== '' && !fechaIsoValida($desde)) {
    $desde = '';
}
if ($hasta !== '' && !fechaIsoValida($hasta)) {
    $hasta = '';
}

$baseWhere = [];
$baseParams = [];
if ($esEmpleado) {
    $baseWhere[] = 'p.id_usuario_solicita = :id_usuario';
    $baseParams[':id_usuario'] = (int) $usuario['id'];
}
$sqlBaseWhere = $baseWhere !== [] ? ' WHERE ' . implode(' AND ', $baseWhere) : '';

$stmt = $pdo->prepare('SELECT p.estado, COUNT(*) AS total FROM pagos p' . $sqlBaseWhere . ' GROUP BY p.estado');
$stmt->execute($baseParams);
$conteos = [
    'pendiente' => 0,
    'bloqueado' => 0,
    'aprobado'  => 0,
    'rechazado' => 0,
    'ejecutado' => 0,
];
$totalGeneral = 0;
foreach ($stmt->fetchAll() as $fila) {
    $conteos[(string) $fila['estado']] = (int) $fila['total'];
    $totalGeneral += (int) $fila['total'];
}
$conteos['todos'] = $totalGeneral;

$where = $baseWhere;
$params = $baseParams;

if ($estadoFiltro !== 'todos') {
    $where[] = 'p.estado = :estado';
    $params[':estado'] = $estadoFiltro;
}
if ($buscar !== '') {
    $where[] = '(f.numero_factura LIKE :buscar_factura
                 OR pr.razon_social LIKE :buscar_proveedor
                 OR u.nombre LIKE :buscar_solicitante)';
    $params[':buscar_factura'] = '%' . $buscar . '%';
    $params[':buscar_proveedor'] = '%' . $buscar . '%';
    $params[':buscar_solicitante'] = '%' . $buscar . '%';
}
if ($proveedorFiltro > 0) {
    $where[] = 'f.id_proveedor = :id_proveedor';
    $params[':id_proveedor'] = $proveedorFiltro;
}
if ($desde !== '') {
    $where[] = 'DATE(p.solicitado_en) >= :desde';
    $params[':desde'] = $desde;
}
if ($hasta !== '') {
    $where[] = 'DATE(p.solicitado_en) <= :hasta';
    $params[':hasta'] = $hasta;
}

$sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$pagina = paginaActual();
$porPagina = 20;

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM pagos p
     INNER JOIN facturas f ON f.id_factura = p.id_factura
     INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
     INNER JOIN usuarios u ON u.id_usuario = p.id_usuario_solicita'
    . $sqlWhere
);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = totalPaginas($total, $porPagina);
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$sql = "SELECT p.*, f.numero_factura, f.fecha_emision, f.estado AS factura_estado,
               pr.razon_social, pr.id_proveedor,
               c.banco, c.tipo_cuenta, c.numero_cuenta, c.es_principal, c.verificada,
               u.nombre AS solicitante,
               COALESCE((
                   SELECT MAX(CASE a.nivel WHEN 'alto' THEN 3 WHEN 'medio' THEN 2 ELSE 1 END)
                   FROM alertas a
                   WHERE a.estado = 'abierta'
                     AND (a.id_pago = p.id_pago
                          OR a.id_factura = p.id_factura
                          OR a.id_proveedor = f.id_proveedor
                          OR a.id_cuenta = p.id_cuenta_destino)
               ), 0) AS riesgo_score,
               (SELECT COUNT(*) FROM alertas a2
                WHERE a2.estado = 'abierta'
                  AND (a2.id_pago = p.id_pago OR a2.id_factura = p.id_factura
                       OR a2.id_proveedor = f.id_proveedor OR a2.id_cuenta = p.id_cuenta_destino)) AS alertas_abiertas
        FROM pagos p
        INNER JOIN facturas f ON f.id_factura = p.id_factura
        INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
        INNER JOIN cuentas_bancarias c ON c.id_cuenta = p.id_cuenta_destino
        INNER JOIN usuarios u ON u.id_usuario = p.id_usuario_solicita"
    . $sqlWhere
    . ' ORDER BY p.solicitado_en DESC, p.id_pago DESC LIMIT ' . $porPagina . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll();

$stmt = $pdo->query('SELECT id_proveedor, razon_social FROM proveedores ORDER BY razon_social');
$proveedores = $stmt->fetchAll();

$niveles = [3 => 'alto', 2 => 'medio', 1 => 'bajo'];
$etiquetas = [
    'pendiente' => 'Pendientes',
    'bloqueado' => 'Bloqueados',
    'aprobado'  => 'Aprobados',
    'rechazado' => 'Rechazados',
    'ejecutado' => 'Ejecutados',
    'todos'     => 'Todos',
];

$pageTitle = 'Pagos';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Pagos</h1>
            <p class="text-muted mb-0">Priorice los pagos pendientes y revise los que están bloqueados.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/facturas/index.php?estado=registrada">
            <i class="bi bi-send me-1" aria-hidden="true"></i>Solicitar pago
        </a>
    </div>

    <?= htmlFlash($flash) ?>

    <ul class="nav nav-pills flex-wrap gap-1 mb-3" aria-label="Filtro por estado de pago">
        <?php foreach ($etiquetas as $clave => $etiqueta): ?>
            <li class="nav-item">
                <a class="nav-link <?= $estadoFiltro === $clave ? 'active' : '' ?>"
                   href="?<?= e(http_build_query(['estado' => $clave, 'q' => $buscar, 'proveedor' => $proveedorFiltro, 'desde' => $desde, 'hasta' => $hasta])) ?>">
                    <?= e($etiqueta) ?>
                    <span class="badge text-bg-light border ms-1"><?= (int) $conteos[$clave] ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/pagos/index.php" class="row g-2 align-items-end">
                <input type="hidden" name="estado" value="<?= e($estadoFiltro) ?>">
                <div class="col-lg-4">
                    <label class="form-label" for="q">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control" id="q" name="q" type="search" value="<?= e($buscar) ?>"
                               placeholder="Factura, proveedor o solicitante">
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
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="desde">Desde</label>
                    <input class="form-control" id="desde" name="desde" type="date" value="<?= e($desde) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="hasta">Hasta</label>
                    <input class="form-control" id="hasta" name="hasta" type="date" value="<?= e($hasta) ?>">
                </div>
                <div class="col-12 col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">Filtrar</button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/pagos/index.php">Limpiar</a>
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
                        <th class="text-end">Valor</th>
                        <th>Cuenta destino</th>
                        <th>Solicitante</th>
                        <th>Fecha</th>
                        <th>Riesgo</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pagos === []): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="bi bi-cash-coin d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-2">No hay pagos en este estado con los filtros seleccionados.</p>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/pagos/index.php">Limpiar filtros</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pagos as $pago): ?>
                        <?php $riesgo = $niveles[(int) $pago['riesgo_score']] ?? null; ?>
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $pago['id_factura'] ?>">
                                    <?= e($pago['numero_factura']) ?>
                                </a>
                                <div class="small text-muted"><?= e(formatearFecha((string) $pago['fecha_emision'])) ?></div>
                            </td>
                            <td class="small"><?= e($pago['razon_social']) ?></td>
                            <td class="text-end currency"><?= e(formatearMoneda($pago['valor'])) ?></td>
                            <td>
                                <div class="small"><?= e($pago['banco']) ?> · <?= e(enmascararCuenta((string) $pago['numero_cuenta'])) ?></div>
                                <span class="badge text-bg-light border"><?= (int) $pago['es_principal'] === 1 ? 'Principal' : 'Secundaria' ?></span>
                                <?php if ((int) $pago['verificada'] !== 1): ?>
                                    <span class="badge text-bg-warning">Sin verificar</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($pago['solicitante']) ?></td>
                            <td class="text-muted"><?= e(formatearFecha((string) $pago['solicitado_en'])) ?></td>
                            <td><?= badgeRiesgo($riesgo) ?></td>
                            <td>
                                <?= badgeEstado((string) $pago['estado']) ?>
                                <?php if ((int) $pago['alertas_abiertas'] > 0): ?>
                                    <div class="small text-danger mt-1"><?= (int) $pago['alertas_abiertas'] ?> alerta(s)</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $pago['id_pago'] ?>">Ver</a>
                                <?php if ($pago['estado'] === 'pendiente' && !$esEmpleado): ?>
                                    <a class="btn btn-sm btn-link text-decoration-none" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $pago['id_pago'] ?>#decision">Revisar</a>
                                <?php endif; ?>
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
                <nav aria-label="Paginación de pagos">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'estado' => $estadoFiltro,
                                'q' => $buscar,
                                'proveedor' => $proveedorFiltro,
                                'desde' => $desde,
                                'hasta' => $hasta,
                                'pagina' => max(1, $pagina - 1),
                            ])) ?>">Anterior</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
                        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'estado' => $estadoFiltro,
                                'q' => $buscar,
                                'proveedor' => $proveedorFiltro,
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

    <p class="small text-muted mt-3 mb-0">
        <i class="bi bi-lock me-1" aria-hidden="true"></i>
        Un pago bloqueado no puede aprobarse hasta que se resuelvan o descarten todas sus alertas.
    </p>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
