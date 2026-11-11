<?php
declare(strict_types=1);

/**
 * Usuario · formulario (SC-21).
 * Solo administrador. Protege la propia cuenta y el último administrador activo.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol('administrador');

$pdo = conexionBd();
$usuarioActual = usuarioActual();
$id = max(0, (int) ($_GET['id'] ?? $_POST['id_usuario'] ?? 0));
$usuarioEditado = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id_usuario = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $usuarioEditado = $stmt->fetch();

    if (!$usuarioEditado) {
        redirigirConFlash('/modules/usuarios/index.php', 'danger', 'El usuario solicitado no existe.');
    }
}

$roles = $pdo->query('SELECT * FROM roles ORDER BY id_rol')->fetchAll();
$rolesValidos = array_map(static fn (array $rol): int => (int) $rol['id_rol'], $roles);

$datos = [
    'nombre' => (string) ($usuarioEditado['nombre'] ?? ''),
    'correo' => (string) ($usuarioEditado['correo'] ?? ''),
    'id_rol' => (int) ($usuarioEditado['id_rol'] ?? 1),
    'activo' => (int) ($usuarioEditado['activo'] ?? 1),
];
$errores = [];
$errorGeneral = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión del formulario expiró. Recargue la página e intente de nuevo.';
    }

    $datos['nombre'] = trim((string) ($_POST['nombre'] ?? ''));
    $datos['correo'] = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
    $datos['id_rol'] = (int) ($_POST['id_rol'] ?? 0);
    $datos['activo'] = !empty($_POST['activo']) ? 1 : 0;
    $clave = (string) ($_POST['clave'] ?? '');
    $claveConfirmacion = (string) ($_POST['clave_confirmacion'] ?? '');

    if (mb_strlen($datos['nombre']) < 3 || mb_strlen($datos['nombre']) > 100) {
        $errores[] = 'El nombre debe tener entre 3 y 100 caracteres.';
    }
    if (filter_var($datos['correo'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($datos['correo']) > 120) {
        $errores[] = 'El correo no tiene un formato válido.';
    }
    if (!in_array($datos['id_rol'], $rolesValidos, true)) {
        $errores[] = 'Seleccione un rol válido.';
    }

    if ($usuarioEditado !== null && $id === (int) $usuarioActual['id']) {
        if ($datos['id_rol'] !== (int) $usuarioEditado['id_rol']) {
            $errores[] = 'No puede cambiar su propio rol.';
        }
        if ($datos['activo'] !== 1) {
            $errores[] = 'No puede inactivar su propia cuenta.';
        }
    }

    $textoClave = trim($clave);
    $debeValidarClave = $usuarioEditado === null || $textoClave !== '';
    if ($debeValidarClave) {
        if (mb_strlen($textoClave) < 8 || mb_strlen($textoClave) > 72) {
            $errores[] = 'La contraseña debe tener entre 8 y 72 caracteres.';
        } elseif (!preg_match('/[A-Z]/', $textoClave) || !preg_match('/[a-z]/', $textoClave) || !preg_match('/\d/', $textoClave)) {
            $errores[] = 'La contraseña debe incluir mayúscula, minúscula y número.';
        }
        if ($textoClave !== $claveConfirmacion) {
            $errores[] = 'La confirmación de la contraseña no coincide.';
        }
    }

    if ($datos['correo'] !== '') {
        $stmt = $pdo->prepare(
            'SELECT id_usuario FROM usuarios WHERE correo = :correo AND id_usuario <> :id LIMIT 1'
        );
        $stmt->execute([':correo' => $datos['correo'], ':id' => $id]);
        if ($stmt->fetchColumn() !== false) {
            $errores[] = 'Ya existe un usuario con ese correo.';
        }
    }

    if ($usuarioEditado !== null
        && (int) $usuarioEditado['id_rol'] === 3
        && (int) $usuarioEditado['activo'] === 1
        && ($datos['id_rol'] !== 3 || $datos['activo'] !== 1)
    ) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE id_rol = 3 AND activo = 1');
        $stmt->execute();
        if ((int) $stmt->fetchColumn() <= 1) {
            $errores[] = 'No puede desactivar ni cambiar el rol del último administrador activo.';
        }
    }

    if ($errores === []) {
        try {
            $pdo->beginTransaction();

            if ($usuarioEditado !== null
                && (int) $usuarioEditado['id_rol'] === 3
                && (int) $usuarioEditado['activo'] === 1
                && ($datos['id_rol'] !== 3 || $datos['activo'] !== 1)
            ) {
                $stmt = $pdo->query('SELECT id_usuario FROM usuarios WHERE id_rol = 3 AND activo = 1 FOR UPDATE');
                if (count($stmt->fetchAll()) <= 1) {
                    throw new DomainException('No puede desactivar ni cambiar el rol del último administrador activo.');
                }
            }

            if ($usuarioEditado === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO usuarios (nombre, correo, password_hash, id_rol, activo)
                     VALUES (:nombre, :correo, :hash, :id_rol, :activo)'
                );
                $stmt->execute([
                    ':nombre' => $datos['nombre'],
                    ':correo' => $datos['correo'],
                    ':hash'   => password_hash($textoClave, PASSWORD_DEFAULT),
                    ':id_rol' => $datos['id_rol'],
                    ':activo' => $datos['activo'],
                ]);
                $idNuevo = (int) $pdo->lastInsertId();

                registrarAuditoria(
                    $pdo,
                    (int) $usuarioActual['id'],
                    'crear_usuario',
                    'usuarios',
                    $idNuevo,
                    'Usuario creado: ' . $datos['correo'] . ', rol ' . $datos['id_rol']
                        . ', estado ' . ($datos['activo'] === 1 ? 'activo' : 'inactivo') . '.'
                );

                $pdo->commit();
                redirigirConFlash('/modules/usuarios/index.php', 'success', 'Usuario creado correctamente.');
            }

            $asignarClave = $textoClave !== '';
            $sql = 'UPDATE usuarios
                    SET nombre = :nombre, correo = :correo, id_rol = :id_rol, activo = :activo';
            $params = [
                ':nombre' => $datos['nombre'],
                ':correo' => $datos['correo'],
                ':id_rol' => $datos['id_rol'],
                ':activo' => $datos['activo'],
                ':id'     => $id,
            ];
            if ($asignarClave) {
                $sql .= ', password_hash = :hash, intentos_fallidos = 0, bloqueado_hasta = NULL';
                $params[':hash'] = password_hash($textoClave, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id_usuario = :id';

            $pdo->prepare($sql)->execute($params);

            $cambios = [];
            if ((string) $usuarioEditado['nombre'] !== $datos['nombre']) {
                $cambios[] = 'Nombre actualizado.';
            }
            if ((string) $usuarioEditado['correo'] !== $datos['correo']) {
                $cambios[] = 'Correo: ' . $usuarioEditado['correo'] . ' → ' . $datos['correo'] . '.';
            }
            if ((int) $usuarioEditado['id_rol'] !== $datos['id_rol']) {
                $cambios[] = 'Rol anterior: ' . $usuarioEditado['id_rol'] . ', nuevo rol: ' . $datos['id_rol'] . '.';
            }
            if ((int) $usuarioEditado['activo'] !== $datos['activo']) {
                $cambios[] = 'Estado: ' . ((int) $usuarioEditado['activo'] === 1 ? 'activo' : 'inactivo')
                    . ' → ' . ($datos['activo'] === 1 ? 'activo' : 'inactivo') . '.';
            }

            registrarAuditoria(
                $pdo,
                (int) $usuarioActual['id'],
                'editar_usuario',
                'usuarios',
                $id,
                'Usuario ' . $datos['correo'] . '. '
                    . ($cambios !== [] ? implode(' ', $cambios) : 'Sin cambios de datos generales.')
            );

            if ($asignarClave) {
                registrarAuditoria(
                    $pdo,
                    (int) $usuarioActual['id'],
                    'restablecer_password',
                    'usuarios',
                    $id,
                    'Contraseña restablecida para ' . $datos['correo'] . '. Se desbloqueó la cuenta.'
                );
            }

            $pdo->commit();
            redirigirConFlash('/modules/usuarios/index.php', 'success', 'Usuario actualizado correctamente.');
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errores[] = $error->getMessage();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $error->getCode() === '23000') {
                $errores[] = 'Ya existe un usuario con ese correo.';
            } else {
                error_log('SafePay usuario: ' . $error->getMessage());
                $errorGeneral = 'No se pudo guardar el usuario. Intente de nuevo.';
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SafePay usuario: ' . $error->getMessage());
            $errorGeneral = 'No se pudo guardar el usuario. Intente de nuevo.';
        }
    }
}

$pageTitle = $usuarioEditado === null ? 'Nuevo usuario' : 'Editar usuario';
$esPropio = $usuarioEditado !== null && $id === (int) $usuarioActual['id'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/usuarios/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Usuarios
        </a>
    </div>

    <div class="mb-4">
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">La contraseña se almacena con hash seguro y nunca se muestra.</p>
    </div>

    <?php if ($errorGeneral !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e($errorGeneral) ?>
        </div>
    <?php endif; ?>

    <?php if ($errores !== []): ?>
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-1">Revise el formulario:</div>
            <ul class="mb-0 ps-3">
                <?php foreach (array_unique($errores) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($esPropio): ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-person-lock me-1" aria-hidden="true"></i>
            Está editando su propia cuenta. No puede cambiar su rol ni inactivarla.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(BASE_URL) ?>/modules/usuarios/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" novalidate>
        <?= csrfCampo() ?>
        <input type="hidden" name="id_usuario" value="<?= $id ?>">

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header">Datos de la cuenta</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="nombre">Nombre completo *</label>
                                <input class="form-control" id="nombre" name="nombre" maxlength="100"
                                       value="<?= e($datos['nombre']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="correo">Correo *</label>
                                <input class="form-control" id="correo" name="correo" type="email" maxlength="120"
                                       value="<?= e($datos['correo']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="id_rol">Rol *</label>
                                <select class="form-select" id="id_rol" name="id_rol" required>
                                    <?php foreach ($roles as $rol): ?>
                                        <option value="<?= (int) $rol['id_rol'] ?>" <?= $datos['id_rol'] === (int) $rol['id_rol'] ? 'selected' : '' ?>>
                                            <?= e(etiquetaRol((string) $rol['nombre'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <?php
                                    $descripcionRol = '';
                                    foreach ($roles as $rol) {
                                        if ((int) $rol['id_rol'] === $datos['id_rol']) {
                                            $descripcionRol = (string) $rol['descripcion'];
                                            break;
                                        }
                                    }
                                    echo e($descripcionRol);
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-6 d-flex align-items-center">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1"
                                        <?= $datos['activo'] === 1 ? 'checked' : '' ?>
                                        <?= $esPropio ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="activo">Cuenta activa</label>
                                    <?php if ($esPropio): ?>
                                        <input type="hidden" name="activo" value="1">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <?= $usuarioEditado === null ? 'Contraseña inicial' : 'Restablecer contraseña (opcional)' ?>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="clave">
                                    Contraseña <?= $usuarioEditado === null ? '*' : '' ?>
                                </label>
                                <input class="form-control" id="clave" name="clave" type="password" maxlength="72"
                                       autocomplete="new-password" <?= $usuarioEditado === null ? 'required' : '' ?>>
                                <div class="form-text">Mínimo 8 caracteres, con mayúscula, minúscula y número.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="clave_confirmacion">Confirmar contraseña <?= $usuarioEditado === null ? '*' : '' ?></label>
                                <input class="form-control" id="clave_confirmacion" name="clave_confirmacion" type="password" maxlength="72"
                                       autocomplete="new-password" <?= $usuarioEditado === null ? 'required' : '' ?>>
                            </div>
                        </div>
                        <?php if ($usuarioEditado !== null): ?>
                            <div class="alert alert-info small mt-3 mb-0" role="status">
                                Si deja ambos campos vacíos, la contraseña no cambia. Si asigna una nueva, se desbloquea la cuenta.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar usuario
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/modules/usuarios/index.php">Cancelar</a>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">Roles y permisos</div>
                    <div class="card-body small">
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($roles as $rol): ?>
                                <li class="mb-3">
                                    <div class="mb-1"><?= badgeRol((string) $rol['nombre']) ?></div>
                                    <div class="text-muted"><?= e($rol['descripcion']) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="alert alert-info small mt-3 mb-0" role="status">
                    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                    El sistema impide eliminar o desactivar al último administrador activo.
                </div>
            </div>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
