<?php
declare(strict_types=1);

/**
 * Auditoría · consulta inmutable (SC-16).
 * Solo lectura para financiero y administrador.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$usuario = usuarioActual();

$buscar = trim((string) ($_GET['q'] ?? ''));
$usuarioFiltro = max(0, (int) ($_GET['usuario'] ?? 0));
$accionFiltro = trim((string) ($_GET['accion'] ?? ''));
$entidadFiltro = trim((string) ($_GET['entidad'] ?? ''));
$desde = (string) ($_GET['desde'] ?? '');
$hasta = (string) ($_GET['hasta'] ?? '');
if ($desde !== '' && !fechaIsoValida($desde)) {
    $desde = '';
}
if ($hasta !== '' && !fechaIsoValida($hasta)) {
    $hasta = '';
}

$where = [];
$params = [];
if ($buscar !== '') {
    $where[] = '(a.detalle LIKE :buscar_detalle
                 OR a.ip LIKE :buscar_ip
                 OR u.nombre LIKE :buscar_usuario
                 OR u.correo LIKE :buscar_correo)';
    $params[':buscar_detalle'] = '%' . $buscar . '%';
    $params[':buscar_ip'] = '%' . $buscar . '%';
    $params[':buscar_usuario'] = '%' . $buscar . '%';
    $params[':buscar_correo'] = '%' . $buscar . '%';
}
if ($usuarioFiltro > 0) {
    $where[] = 'a.id_usuario = :id_usuario';
    $params[':id_usuario'] = $usuarioFiltro;
}
if ($accionFiltro !== '') {
    $where[] = 'a.accion = :accion';
    $params[':accion'] = $accionFiltro;
}
if ($entidadFiltro !== '') {
    $where[] = 'a.entidad = :entidad';
    $params[':entidad'] = $entidadFiltro;
}
if ($desde !== '') {
    $where[] = 'DATE(a.fecha) >= :desde';
    $params[':desde'] = $desde;
}
if ($hasta !== '') {
    $where[] = 'DATE(a.fecha) <= :hasta';
    $params[':hasta'] = $hasta;
}

$sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$pagina = paginaActual();
$porPagina = 50;

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario'
    . $sqlWhere
);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = totalPaginas($total, $porPagina);
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$stmt = $pdo->prepare(
    'SELECT a.*, u.nombre AS usuario, u.correo
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario'
    . $sqlWhere
    . ' ORDER BY a.fecha DESC, a.id_auditoria DESC LIMIT ' . $porPagina . ' OFFSET ' . $offset
);
$stmt->execute($params);
$eventos = $stmt->fetchAll();

$usuarios = $pdo->query('SELECT id_usuario, nombre, correo FROM usuarios ORDER BY nombre')->fetchAll();
$acciones = $pdo->query('SELECT DISTINCT accion FROM auditoria ORDER BY accion')->fetchAll(PDO::FETCH_COLUMN);
$entidades = $pdo->query('SELECT DISTINCT entidad FROM auditoria ORDER BY entidad')->fetchAll(PDO::FETCH_COLUMN);

$entidadesEtiquetas = [
    'usuarios'          => 'Usuarios',
    'proveedores'       => 'Proveedores',
    'cuentas_bancarias' => 'Cuentas bancarias',
    'facturas'          => 'Facturas',
    'pagos'             => 'Pagos',
    'alertas'           => 'Alertas',
    'aprobaciones'      => 'Aprobaciones',
];

$resumenFiltros = [];
if ($buscar !== '') {
    $resumenFiltros[] = 'texto=' . $buscar;
}
if ($usuarioFiltro > 0) {
    $resumenFiltros[] = 'usuario=' . $usuarioFiltro;
}
if ($accionFiltro !== '') {
    $resumenFiltros[] = 'acción=' . $accionFiltro;
}
if ($entidadFiltro !== '') {
    $resumenFiltros[] = 'entidad=' . $entidadFiltro;
}
if ($desde !== '') {
    $resumenFiltros[] = 'desde=' . $desde;
}
if ($hasta !== '') {
    $resumenFiltros[] = 'hasta=' . $hasta;
}

try {
    registrarAuditoria(
        $pdo,
        (int) $usuario['id'],
        'consultar_auditoria',
        'auditoria',
        null,
        'Consulta de auditoría. Filtros: ' . ($resumenFiltros !== [] ? implode(', ', $resumenFiltros) : 'ninguno')
            . '. Página ' . $pagina . '.'
    );
} catch (Throwable $error) {
    error_log('SafePay auditoría consulta: ' . $error->getMessage());
}

$pageTitle = 'Auditoría';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Auditoría</h1>
        <p class="text-muted mb-0">Historial inmutable de las acciones del sistema. Solo lectura.</p>
    </div>

    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-lock mt-1" aria-hidden="true"></i>
        <div>
            <strong>Registro inmutable.</strong> Los eventos no se editan ni se eliminan. Cada acción guarda
            usuario, fecha, entidad, detalle e IP. Esta consulta también se registra.
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/auditoria/index.php" class="row g-2 align-items-end">
                <div class="col-lg-3">
                    <label class="form-label" for="q">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control" id="q" name="q" type="search" value="<?= e($buscar) ?>"
                               placeholder="Detalle, usuario o IP">
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="usuario">Usuario</label>
                    <select class="form-select" id="usuario" name="usuario">
                        <option value="0">Todos</option>
                        <?php foreach ($usuarios as $usuarioListado): ?>
                            <option value="<?= (int) $usuarioListado['id_usuario'] ?>"
                                <?= $usuarioFiltro === (int) $usuarioListado['id_usuario'] ? 'selected' : '' ?>>
                                <?= e($usuarioListado['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="accion">Acción</label>
                    <select class="form-select" id="accion" name="accion">
                        <option value="">Todas</option>
                        <?php foreach ($acciones as $accion): ?>
                            <option value="<?= e((string) $accion) ?>" <?= $accionFiltro === (string) $accion ? 'selected' : '' ?>>
                                <?= e(etiquetaAccion((string) $accion)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="entidad">Entidad</label>
                    <select class="form-select" id="entidad" name="entidad">
                        <option value="">Todas</option>
                        <?php foreach ($entidades as $entidad): ?>
                            <option value="<?= e((string) $entidad) ?>" <?= $entidadFiltro === (string) $entidad ? 'selected' : '' ?>>
                                <?= e($entidadesEtiquetas[(string) $entidad] ?? ucfirst(str_replace('_', ' ', (string) $entidad))) ?>
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
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/auditoria/index.php">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Entidad</th>
                        <th>Detalle</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($eventos === []): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-journal-text d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-0">No hay eventos con esos filtros.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($eventos as $evento): ?>
                        <tr>
                            <td class="text-nowrap small"><?= e(formatearFecha((string) $evento['fecha'], true)) ?></td>
                            <td class="small">
                                <div class="fw-semibold"><?= e($evento['usuario'] ?? 'Sistema') ?></div>
                                <?php if ($evento['correo'] !== null): ?>
                                    <div class="text-muted"><?= e($evento['correo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small fw-semibold"><?= e(etiquetaAccion((string) $evento['accion'])) ?></td>
                            <td class="small">
                                <?= e($entidadesEtiquetas[(string) $evento['entidad']] ?? ucfirst(str_replace('_', ' ', (string) $evento['entidad']))) ?>
                                <?php if ($evento['id_entidad'] !== null): ?>
                                    <span class="text-muted">#<?= (int) $evento['id_entidad'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($evento['detalle'] ?? '—') ?></td>
                            <td class="small text-muted text-nowrap"><?= e($evento['ip'] ?? '—') ?></td>
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
                    : 'Mostrando ' . ($offset + 1) . '–' . min($offset + $porPagina, $total) . ' de ' . $total . ' eventos' ?>
            </span>
            <?php if ($paginas > 1): ?>
                <nav aria-label="Paginación de auditoría">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'q' => $buscar,
                                'usuario' => $usuarioFiltro,
                                'accion' => $accionFiltro,
                                'entidad' => $entidadFiltro,
                                'desde' => $desde,
                                'hasta' => $hasta,
                                'pagina' => max(1, $pagina - 1),
                            ])) ?>">Anterior</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
                        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query([
                                'q' => $buscar,
                                'usuario' => $usuarioFiltro,
                                'accion' => $accionFiltro,
                                'entidad' => $entidadFiltro,
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
