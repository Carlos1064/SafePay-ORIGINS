<?php
declare(strict_types=1);

/**
 * Usuarios · listado y activación (SC-20).
 * Solo administrador. No permite inactivar la propia cuenta ni el último administrador activo.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol('administrador');

$pdo = conexionBd();
$usuarioActual = usuarioActual();
$flash = consumirFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        redirigirConFlash('/modules/usuarios/index.php', 'danger', 'La sesión del formulario expiró. Intente de nuevo.');
    }

    $accion = (string) ($_POST['accion'] ?? '');
    $idUsuario = max(0, (int) ($_POST['id_usuario'] ?? 0));

    if ($accion !== 'cambiar_estado' || $idUsuario <= 0) {
        redirigirConFlash('/modules/usuarios/index.php', 'danger', 'Solicitud no válida.');
    }

    if ($idUsuario === (int) $usuarioActual['id']) {
        redirigirConFlash('/modules/usuarios/index.php', 'danger', 'No puede inactivar su propia cuenta.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id_usuario = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $idUsuario]);
        $usuarioObjetivo = $stmt->fetch();

        if (!$usuarioObjetivo) {
            throw new DomainException('El usuario no existe.');
        }

        $nuevoEstado = (int) $usuarioObjetivo['activo'] === 1 ? 0 : 1;

        if ((int) $usuarioObjetivo['id_rol'] === 3 && (int) $usuarioObjetivo['activo'] === 1 && $nuevoEstado === 0) {
            $stmt = $pdo->query('SELECT COUNT(*) FROM usuarios WHERE id_rol = 3 AND activo = 1 FOR UPDATE');
            if ((int) $stmt->fetchColumn() <= 1) {
                throw new DomainException('No puede inactivar al último administrador activo.');
            }
        }

        $pdo->prepare('UPDATE usuarios SET activo = :activo WHERE id_usuario = :id')
            ->execute([':activo' => $nuevoEstado, ':id' => $idUsuario]);

        registrarAuditoria(
            $pdo,
            (int) $usuarioActual['id'],
            'cambiar_estado_usuario',
            'usuarios',
            $idUsuario,
            'Usuario ' . $usuarioObjetivo['correo'] . ' ' . ($nuevoEstado === 1 ? 'activado' : 'inactivado') . '.'
        );

        $pdo->commit();
        redirigirConFlash(
            '/modules/usuarios/index.php',
            'success',
            'Usuario ' . ($nuevoEstado === 1 ? 'activado' : 'inactivado') . ' correctamente.'
        );
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirigirConFlash('/modules/usuarios/index.php', 'danger', $error->getMessage());
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SafePay estado usuario: ' . $error->getMessage());
        redirigirConFlash('/modules/usuarios/index.php', 'danger', 'No se pudo cambiar el estado del usuario.');
    }
}

$buscar = trim((string) ($_GET['q'] ?? ''));
$rolFiltro = max(0, (int) ($_GET['rol'] ?? 0));
$estadoFiltro = (string) ($_GET['estado'] ?? 'todos');
if (!in_array($estadoFiltro, ['todos', 'activo', 'inactivo'], true)) {
    $estadoFiltro = 'todos';
}

$where = [];
$params = [];
if ($buscar !== '') {
    $where[] = '(u.nombre LIKE :buscar_nombre OR u.correo LIKE :buscar_correo)';
    $params[':buscar_nombre'] = '%' . $buscar . '%';
    $params[':buscar_correo'] = '%' . $buscar . '%';
}
if ($rolFiltro > 0) {
    $where[] = 'u.id_rol = :id_rol';
    $params[':id_rol'] = $rolFiltro;
}
if ($estadoFiltro !== 'todos') {
    $where[] = 'u.activo = :activo';
    $params[':activo'] = $estadoFiltro === 'activo' ? 1 : 0;
}

$sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$pagina = paginaActual();
$porPagina = 20;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios u' . $sqlWhere);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = totalPaginas($total, $porPagina);
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$stmt = $pdo->prepare(
    'SELECT u.*, r.nombre AS rol
     FROM usuarios u
     INNER JOIN roles r ON r.id_rol = u.id_rol'
    . $sqlWhere
    . ' ORDER BY u.activo DESC, u.nombre ASC LIMIT ' . $porPagina . ' OFFSET ' . $offset
);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

$roles = $pdo->query('SELECT * FROM roles ORDER BY id_rol')->fetchAll();

$pageTitle = 'Usuarios';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Usuarios</h1>
            <p class="text-muted mb-0">Gestione el acceso y el rol de cada persona.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/usuarios/form.php">
            <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Nuevo usuario
        </a>
    </div>

    <?= htmlFlash($flash) ?>

    <div class="alert alert-info small" role="status">
        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
        Las contraseñas se almacenan con <code>password_hash()</code>. Un usuario nunca puede inactivar su propia cuenta.
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(BASE_URL) ?>/modules/usuarios/index.php" class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label" for="q">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control" id="q" name="q" type="search" value="<?= e($buscar) ?>"
                               placeholder="Nombre o correo">
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="rol">Rol</label>
                    <select class="form-select" id="rol" name="rol">
                        <option value="0">Todos</option>
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= (int) $rol['id_rol'] ?>" <?= $rolFiltro === (int) $rol['id_rol'] ? 'selected' : '' ?>>
                                <?= e(etiquetaRol((string) $rol['nombre'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado">
                        <option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="activo" <?= $estadoFiltro === 'activo' ? 'selected' : '' ?>>Activos</option>
                        <option value="inactivo" <?= $estadoFiltro === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                    </select>
                </div>
                <div class="col-12 col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">Filtrar</button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/usuarios/index.php">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Último acceso / bloqueo</th>
                        <th>Creado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($usuarios === []): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-people d-block fs-2 mb-2" aria-hidden="true"></i>
                                <p class="mb-0">No hay usuarios con esos filtros.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usuarios as $usuarioListado): ?>
                        <?php
                        $bloqueado = $usuarioListado['bloqueado_hasta'] !== null
                            && strtotime((string) $usuarioListado['bloqueado_hasta']) > time();
                        $esPropio = (int) $usuarioListado['id_usuario'] === (int) $usuarioActual['id'];
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="sp-avatar-sm"><?= e(iniciales((string) $usuarioListado['nombre'])) ?></span>
                                    <div>
                                        <div class="fw-semibold">
                                            <?= e($usuarioListado['nombre']) ?>
                                            <?php if ($esPropio): ?><span class="badge text-bg-light border ms-1">Usted</span><?php endif; ?>
                                        </div>
                                        <div class="small text-muted"><?= e($usuarioListado['correo']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= badgeRol((string) $usuarioListado['rol']) ?></td>
                            <td>
                                <?= badgeEstado((int) $usuarioListado['activo'] === 1 ? 'activo' : 'inactivo') ?>
                                <?php if ($bloqueado): ?>
                                    <div class="small text-danger mt-1">Bloqueado hasta <?= e(formatearFecha((string) $usuarioListado['bloqueado_hasta'], true)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= $bloqueado ? 'Bloqueo temporal por intentos' : 'Sin bloqueo activo' ?>
                                <?php if ((int) $usuarioListado['intentos_fallidos'] > 0): ?>
                                    <div><?= (int) $usuarioListado['intentos_fallidos'] ?> intento(s) fallido(s)</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= e(formatearFecha((string) $usuarioListado['creado_en'])) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/usuarios/form.php?id=<?= (int) $usuarioListado['id_usuario'] ?>">Editar</a>
                                <?php if (!$esPropio): ?>
                                    <form method="post" action="<?= e(BASE_URL) ?>/modules/usuarios/index.php" class="d-inline"
                                          data-confirm="¿Confirma <?= (int) $usuarioListado['activo'] === 1 ? 'inactivar' : 'activar' ?> a este usuario?">
                                        <?= csrfCampo() ?>
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id_usuario" value="<?= (int) $usuarioListado['id_usuario'] ?>">
                                        <button class="btn btn-sm <?= (int) $usuarioListado['activo'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                            <?= (int) $usuarioListado['activo'] === 1 ? 'Inactivar' : 'Activar' ?>
                                        </button>
                                    </form>
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
                <?= $total === 0 ? 'Sin resultados' : 'Mostrando ' . ($offset + 1) . '–' . min($offset + $porPagina, $total) . ' de ' . $total ?>
            </span>
            <?php if ($paginas > 1): ?>
                <nav aria-label="Paginación de usuarios">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query(['q' => $buscar, 'rol' => $rolFiltro, 'estado' => $estadoFiltro, 'pagina' => max(1, $pagina - 1)])) ?>">Anterior</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
                        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= e(http_build_query(['q' => $buscar, 'rol' => $rolFiltro, 'estado' => $estadoFiltro, 'pagina' => min($paginas, $pagina + 1)])) ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
