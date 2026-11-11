<?php
declare(strict_types=1);

/**
 * Proveedores · listado (SC-03).
 * Financiero: solo lectura. Administrador: creación y edición.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol(['financiero', 'administrador']);

$pdo = conexionBd();
$puedeEditar = tieneRol('administrador');
$flash = consumirFlash();

$buscar = trim((string) ($_GET['q'] ?? ''));
$estadoFiltro = (string) ($_GET['estado'] ?? 'todos');
if (!in_array($estadoFiltro, ['todos', 'activo', 'inactivo'], true)) {
    $estadoFiltro = 'todos';
}

$pagina = paginaActual();
$porPagina = 20;

$where = [];
$params = [];

if ($buscar !== '') {
    $where[] = '(pr.razon_social LIKE :buscar_razon OR pr.nit LIKE :buscar_nit)';
    $params[':buscar_razon'] = '%' . $buscar . '%';
    $params[':buscar_nit'] = '%' . $buscar . '%';
}
if ($estadoFiltro !== 'todos') {
    $where[] = 'pr.estado = :estado';
    $params[':estado'] = $estadoFiltro;
}

$sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare('SELECT COUNT(*) FROM proveedores pr' . $sqlWhere);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = totalPaginas($total, $porPagina);
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$sql = "SELECT pr.*,
               cp.banco AS banco_principal,
               cp.numero_cuenta AS numero_principal,
               cp.verificada AS principal_verificada,
               (SELECT COUNT(*) FROM cuentas_bancarias c WHERE c.id_proveedor = pr.id_proveedor) AS total_cuentas
        FROM proveedores pr
        LEFT JOIN cuentas_bancarias cp
               ON cp.id_proveedor = pr.id_proveedor AND cp.es_principal = 1"
    . $sqlWhere
    . ' ORDER BY pr.razon_social ASC LIMIT ' . $porPagina . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$proveedores = $stmt->fetchAll();

$pageTitle = 'Proveedores';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Proveedores</h1>
            <p class="text-muted mb-0">Consulte los datos, las cuentas bancarias y el historial de cada proveedor.</p>
        </div>
        <?php if ($puedeEditar): ?>
            <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/proveedores/form.php">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo proveedor
            </a>
        <?php endif; ?>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/proveedores/index.php" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="q">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control" id="q" name="q" type="search" value="<?= e($buscar) ?>"
                               placeholder="NIT o razón social">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado">
                        <option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="activo" <?= $estadoFiltro === 'activo' ? 'selected' : '' ?>>Activos</option>
                        <option value="inactivo" <?= $estadoFiltro === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">
                        <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/proveedores/index.php">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Razón social</th>
                        <th>Contacto</th>
                        <th>Cuentas</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($proveedores === []): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="bi bi-building d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-2">No encontramos proveedores con esos filtros.</p>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(BASE_URL) ?>/modules/proveedores/index.php">Limpiar filtros</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($proveedores as $proveedor): ?>
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none"
                                   href="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= (int) $proveedor['id_proveedor'] ?>">
                                    <?= e($proveedor['razon_social']) ?>
                                </a>
                                <div class="small text-muted">NIT <?= e(formatearNit((string) $proveedor['nit'])) ?></div>
                            </td>
                            <td class="small">
                                <div><?= e($proveedor['correo'] ?? '—') ?></div>
                                <div class="text-muted"><?= e($proveedor['telefono'] ?? '—') ?></div>
                            </td>
                            <td class="small">
                                <div><strong><?= (int) $proveedor['total_cuentas'] ?></strong> cuenta(s)</div>
                                <?php if ($proveedor['numero_principal'] !== null): ?>
                                    <div class="text-muted">
                                        Principal: <?= e(enmascararCuenta((string) $proveedor['numero_principal'])) ?>
                                        <?php if ((int) $proveedor['principal_verificada'] !== 1): ?>
                                            <span class="text-warning">· sin verificar</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-warning">Sin cuenta principal</div>
                                <?php endif; ?>
                            </td>
                            <td><?= badgeEstado((string) $proveedor['estado']) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(BASE_URL) ?>/modules/proveedores/detalle.php?id=<?= (int) $proveedor['id_proveedor'] ?>">
                                    Ver
                                </a>
                                <?php if ($puedeEditar): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= e(BASE_URL) ?>/modules/proveedores/form.php?id=<?= (int) $proveedor['id_proveedor'] ?>">
                                        Editar
                                    </a>
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
                <?php if ($total === 0): ?>
                    Sin resultados
                <?php else: ?>
                    Mostrando <?= (int) ($offset + 1) ?>–<?= (int) min($offset + $porPagina, $total) ?> de <?= $total ?>
                <?php endif; ?>
            </span>
            <?php if ($paginas > 1): ?>
                <nav aria-label="Paginación de proveedores">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query(['q' => $buscar, 'estado' => $estadoFiltro, 'pagina' => max(1, $pagina - 1)])) ?>">Anterior</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
                        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query(['q' => $buscar, 'estado' => $estadoFiltro, 'pagina' => min($paginas, $pagina + 1)])) ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
