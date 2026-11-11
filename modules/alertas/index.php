<?php
declare(strict_types=1);

/**
 * Alertas · listado (SC-14).
 * Financiero y administrador consultan; solo el administrador resuelve o descarta.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$flash = consumirFlash();

$estadoFiltro = (string) ($_GET['estado'] ?? 'abierta');
if (!in_array($estadoFiltro, ['abierta', 'resuelta', 'descartada', 'todas'], true)) {
    $estadoFiltro = 'abierta';
}
$nivelFiltro = (string) ($_GET['nivel'] ?? '');
if (!in_array($nivelFiltro, ['', 'alto', 'medio', 'bajo'], true)) {
    $nivelFiltro = '';
}
$tipoFiltro = (string) ($_GET['tipo'] ?? '');
$tiposValidos = ['factura_duplicada', 'cuenta_diferente', 'cuenta_modificada_reciente', 'valor_inusual', 'otra'];
if (!in_array($tipoFiltro, array_merge([''], $tiposValidos), true)) {
    $tipoFiltro = '';
}
$proveedorFiltro = max(0, (int) ($_GET['proveedor'] ?? 0));
$buscar = trim((string) ($_GET['q'] ?? ''));
$desde = (string) ($_GET['desde'] ?? '');
$hasta = (string) ($_GET['hasta'] ?? '');
if ($desde !== '' && !fechaIsoValida($desde)) {
    $desde = '';
}
if ($hasta !== '' && !fechaIsoValida($hasta)) {
    $hasta = '';
}

$stmt = $pdo->query('SELECT estado, COUNT(*) AS total FROM alertas GROUP BY estado');
$conteos = ['abierta' => 0, 'resuelta' => 0, 'descartada' => 0, 'todas' => 0];
foreach ($stmt->fetchAll() as $fila) {
    $conteos[(string) $fila['estado']] = (int) $fila['total'];
    $conteos['todas'] += (int) $fila['total'];
}

$stmt = $pdo->query(
    "SELECT nivel, COUNT(*) AS total FROM alertas
     WHERE estado = 'abierta' GROUP BY nivel"
);
$nivelesAbiertos = ['alto' => 0, 'medio' => 0, 'bajo' => 0];
foreach ($stmt->fetchAll() as $fila) {
    $nivelesAbiertos[(string) $fila['nivel']] = (int) $fila['total'];
}

$where = [];
$params = [];
if ($estadoFiltro !== 'todas') {
    $where[] = 'a.estado = :estado';
    $params[':estado'] = $estadoFiltro;
}
if ($nivelFiltro !== '') {
    $where[] = 'a.nivel = :nivel';
    $params[':nivel'] = $nivelFiltro;
}
if ($tipoFiltro !== '') {
    $where[] = 'a.tipo = :tipo';
    $params[':tipo'] = $tipoFiltro;
}
if ($proveedorFiltro > 0) {
    $where[] = 'a.id_proveedor = :id_proveedor';
    $params[':id_proveedor'] = $proveedorFiltro;
}
if ($buscar !== '') {
    $where[] = '(a.descripcion LIKE :buscar_descripcion
                 OR pr.razon_social LIKE :buscar_proveedor
                 OR f.numero_factura LIKE :buscar_factura)';
    $params[':buscar_descripcion'] = '%' . $buscar . '%';
    $params[':buscar_proveedor'] = '%' . $buscar . '%';
    $params[':buscar_factura'] = '%' . $buscar . '%';
}
if ($desde !== '') {
    $where[] = 'DATE(a.creada_en) >= :desde';
    $params[':desde'] = $desde;
}
if ($hasta !== '') {
    $where[] = 'DATE(a.creada_en) <= :hasta';
    $params[':hasta'] = $hasta;
}

$sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$pagina = paginaActual();
$porPagina = 20;

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM alertas a
     LEFT JOIN proveedores pr ON pr.id_proveedor = a.id_proveedor
     LEFT JOIN facturas f ON f.id_factura = a.id_factura
     LEFT JOIN pagos p ON p.id_pago = a.id_pago'
    . $sqlWhere
);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = totalPaginas($total, $porPagina);
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$sql = "SELECT a.*, pr.razon_social,
               f.numero_factura,
               p.id_pago,
               c.banco, c.numero_cuenta,
               u.nombre AS responsable
        FROM alertas a
        LEFT JOIN proveedores pr ON pr.id_proveedor = a.id_proveedor
        LEFT JOIN facturas f ON f.id_factura = a.id_factura
        LEFT JOIN pagos p ON p.id_pago = a.id_pago
        LEFT JOIN cuentas_bancarias c ON c.id_cuenta = a.id_cuenta
        LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario_resuelve"
    . $sqlWhere
    . " ORDER BY FIELD(a.estado, 'abierta', 'resuelta', 'descartada'),
                     FIELD(a.nivel, 'alto', 'medio', 'bajo'),
                     a.creada_en DESC, a.id_alerta DESC
        LIMIT " . $porPagina . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alertas = $stmt->fetchAll();

$stmt = $pdo->query('SELECT id_proveedor, razon_social FROM proveedores ORDER BY razon_social');
$proveedores = $stmt->fetchAll();

$etiquetasEstado = [
    'abierta'    => 'Abiertas',
    'resuelta'   => 'Resueltas',
    'descartada' => 'Descartadas',
    'todas'      => 'Todas',
];

$pageTitle = 'Alertas';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Alertas</h1>
        <p class="text-muted mb-0">Revise y resuelva las alertas generadas por las reglas de control.</p>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Abiertas</div>
                <div class="fs-3 fw-bold"><?= (int) $conteos['abierta'] ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Riesgo alto abiertas</div>
                <div class="fs-3 fw-bold text-danger"><?= (int) $nivelesAbiertos['alto'] ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Riesgo medio abiertas</div>
                <div class="fs-3 fw-bold text-warning"><?= (int) $nivelesAbiertos['medio'] ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Total histórico</div>
                <div class="fs-3 fw-bold"><?= (int) $conteos['todas'] ?></div>
            </div></div>
        </div>
    </div>

    <ul class="nav nav-pills flex-wrap gap-1 mb-3" aria-label="Filtro por estado de alerta">
        <?php foreach ($etiquetasEstado as $clave => $etiqueta): ?>
            <li class="nav-item">
                <a class="nav-link <?= $estadoFiltro === $clave ? 'active' : '' ?>"
                   href="?<?= e(http_build_query(['estado' => $clave, 'nivel' => $nivelFiltro, 'tipo' => $tipoFiltro, 'proveedor' => $proveedorFiltro, 'q' => $buscar, 'desde' => $desde, 'hasta' => $hasta])) ?>">
                    <?= e($etiqueta) ?>
                    <span class="badge text-bg-light border ms-1"><?= (int) $conteos[$clave] ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/alertas/index.php" class="row g-2 align-items-end">
                <input type="hidden" name="estado" value="<?= e($estadoFiltro) ?>">
                <div class="col-lg-3">
                    <label class="form-label" for="q">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control" id="q" name="q" type="search" value="<?= e($buscar) ?>"
                               placeholder="Descripción, proveedor o factura">
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="nivel">Nivel</label>
                    <select class="form-select" id="nivel" name="nivel">
                        <option value="">Todos</option>
                        <option value="alto" <?= $nivelFiltro === 'alto' ? 'selected' : '' ?>>Alto</option>
                        <option value="medio" <?= $nivelFiltro === 'medio' ? 'selected' : '' ?>>Medio</option>
                        <option value="bajo" <?= $nivelFiltro === 'bajo' ? 'selected' : '' ?>>Bajo</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="tipo">Tipo</label>
                    <select class="form-select" id="tipo" name="tipo">
                        <option value="">Todos</option>
                        <?php foreach ($tiposValidos as $tipo): ?>
                            <option value="<?= e($tipo) ?>" <?= $tipoFiltro === $tipo ? 'selected' : '' ?>>
                                <?= e(etiquetaTipoAlerta($tipo)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="col-6 col-lg-1">
                    <label class="form-label" for="desde">Desde</label>
                    <input class="form-control" id="desde" name="desde" type="date" value="<?= e($desde) ?>">
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label" for="hasta">Hasta</label>
                    <input class="form-control" id="hasta" name="hasta" type="date" value="<?= e($hasta) ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar</button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/alertas/index.php">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!tieneRol('administrador')): ?>
        <div class="alert alert-info small" role="status">
            <i class="bi bi-lock me-1" aria-hidden="true"></i>
            Solo un administrador puede resolver o descartar alertas. Esta vista es de consulta.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nivel</th>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th>Proveedor / factura</th>
                        <th>Creada</th>
                        <th>Estado</th>
                        <th class="text-end">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($alertas === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-shield-check d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-2">No hay alertas con esos filtros.</p>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/alertas/index.php">Limpiar filtros</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alertas as $alerta): ?>
                        <tr>
                            <td><?= badgeRiesgo((string) $alerta['nivel']) ?></td>
                            <td class="small fw-semibold"><?= e(etiquetaTipoAlerta((string) $alerta['tipo'])) ?></td>
                            <td>
                                <div class="small"><?= e($alerta['descripcion']) ?></div>
                                <?php if ($alerta['justificacion'] !== null): ?>
                                    <div class="small text-muted mt-1">Resolución: <?= e($alerta['justificacion']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= e($alerta['razon_social'] ?? '—') ?>
                                <?php if ($alerta['numero_factura'] !== null): ?>
                                    <div>
                                        <a class="text-decoration-none" href="<?= e(BASE_URL) ?>/modules/facturas/detalle.php?id=<?= (int) $alerta['id_factura'] ?>">
                                            Factura <?= e($alerta['numero_factura']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($alerta['id_pago'] !== null): ?>
                                    <div>
                                        <a class="text-decoration-none" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int) $alerta['id_pago'] ?>">
                                            Pago #<?= (int) $alerta['id_pago'] ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($alerta['numero_cuenta'] !== null): ?>
                                    <div class="text-muted"><?= e($alerta['banco']) ?> · <?= e(enmascararCuenta((string) $alerta['numero_cuenta'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= e(formatearFecha((string) $alerta['creada_en'], true)) ?></td>
                            <td><?= badgeEstado((string) $alerta['estado']) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/alertas/detalle.php?id=<?= (int) $alerta['id_alerta'] ?>">
                                    <?= $alerta['estado'] === 'abierta' ? 'Revisar' : 'Ver' ?>
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
                <nav aria-label="Paginación de alertas">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'estado' => $estadoFiltro,
                                'nivel' => $nivelFiltro,
                                'tipo' => $tipoFiltro,
                                'proveedor' => $proveedorFiltro,
                                'q' => $buscar,
                                'desde' => $desde,
                                'hasta' => $hasta,
                                'pagina' => max(1, $pagina - 1),
                            ])) ?>">Anterior</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
                        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'estado' => $estadoFiltro,
                                'nivel' => $nivelFiltro,
                                'tipo' => $tipoFiltro,
                                'proveedor' => $proveedorFiltro,
                                'q' => $buscar,
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
