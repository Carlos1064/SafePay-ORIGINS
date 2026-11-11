<?php
declare(strict_types=1);

/**
 * Proveedor · formulario (SC-04).
 * Solo administrador. El NIT se normaliza y no puede repetirse.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/modules.php';

requiereRol('administrador');

$pdo = conexionBd();
$id = max(0, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
$proveedor = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE id_proveedor = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $proveedor = $stmt->fetch();

    if (!$proveedor) {
        redirigirConFlash('/modules/proveedores/index.php', 'danger', 'El proveedor solicitado no existe.');
    }
}

$datos = [
    'razon_social' => (string) ($proveedor['razon_social'] ?? ''),
    'nit'          => (string) ($proveedor['nit'] ?? ''),
    'correo'       => (string) ($proveedor['correo'] ?? ''),
    'telefono'     => (string) ($proveedor['telefono'] ?? ''),
    'estado'       => (string) ($proveedor['estado'] ?? 'activo'),
];
$errores = [];
$errorGeneral = '';
$nitOriginal = $datos['nit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión del formulario expiró. Recargue la página e intente de nuevo.';
    }

    $datos['razon_social'] = trim((string) ($_POST['razon_social'] ?? ''));
    $datos['nit'] = normalizarNit((string) ($_POST['nit'] ?? ''));
    $datos['correo'] = trim((string) ($_POST['correo'] ?? ''));
    $datos['telefono'] = trim((string) ($_POST['telefono'] ?? ''));
    $datos['estado'] = (string) ($_POST['estado'] ?? 'activo');

    if (mb_strlen($datos['razon_social']) < 3 || mb_strlen($datos['razon_social']) > 150) {
        $errores[] = 'La razón social debe tener entre 3 y 150 caracteres.';
    }
    if ($datos['nit'] === '' || mb_strlen($datos['nit']) > 20) {
        $errores[] = 'Ingrese un NIT válido (por ejemplo 900.123.456-7).';
    }
    if ($datos['correo'] !== '' && filter_var($datos['correo'], FILTER_VALIDATE_EMAIL) === false) {
        $errores[] = 'El correo no tiene un formato válido.';
    }
    if ($datos['telefono'] !== '' && (mb_strlen($datos['telefono']) < 7 || mb_strlen($datos['telefono']) > 30)) {
        $errores[] = 'El teléfono debe tener entre 7 y 30 caracteres.';
    }
    if (!in_array($datos['estado'], ['activo', 'inactivo'], true)) {
        $datos['estado'] = 'activo';
    }

    if ($proveedor !== null && $nitOriginal !== '' && $datos['nit'] !== $nitOriginal
        && empty($_POST['confirmar_nit'])
    ) {
        $errores[] = 'Confirme el cambio de NIT del proveedor.';
    }

    if ($datos['nit'] !== '') {
        $stmt = $pdo->prepare(
            'SELECT id_proveedor FROM proveedores WHERE nit = :nit AND id_proveedor <> :id LIMIT 1'
        );
        $stmt->execute([':nit' => $datos['nit'], ':id' => $id]);
        if ($stmt->fetchColumn() !== false) {
            $errores[] = 'Ya existe un proveedor con ese NIT.';
        }
    }

    if ($errores === []) {
        try {
            if ($proveedor === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO proveedores (nit, razon_social, correo, telefono, estado)
                     VALUES (:nit, :razon, :correo, :telefono, :estado)'
                );
                $stmt->execute([
                    ':nit'      => $datos['nit'],
                    ':razon'    => $datos['razon_social'],
                    ':correo'   => $datos['correo'] !== '' ? $datos['correo'] : null,
                    ':telefono' => $datos['telefono'] !== '' ? $datos['telefono'] : null,
                    ':estado'   => $datos['estado'],
                ]);
                $idNuevo = (int) $pdo->lastInsertId();

                registrarAuditoria(
                    $pdo,
                    (int) usuarioActual()['id'],
                    'crear_proveedor',
                    'proveedores',
                    $idNuevo,
                    'Proveedor ' . $datos['razon_social'] . ' con NIT ' . $datos['nit'] . '.'
                );

                redirigirConFlash(
                    '/modules/proveedores/detalle.php?id=' . $idNuevo,
                    'success',
                    'Proveedor creado correctamente.'
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE proveedores
                 SET nit = :nit, razon_social = :razon, correo = :correo,
                     telefono = :telefono, estado = :estado
                 WHERE id_proveedor = :id'
            );
            $stmt->execute([
                ':nit'      => $datos['nit'],
                ':razon'    => $datos['razon_social'],
                ':correo'   => $datos['correo'] !== '' ? $datos['correo'] : null,
                ':telefono' => $datos['telefono'] !== '' ? $datos['telefono'] : null,
                ':estado'   => $datos['estado'],
                ':id'       => $id,
            ]);

            $cambios = [];
            if ($nitOriginal !== $datos['nit']) {
                $cambios[] = 'NIT: ' . $nitOriginal . ' → ' . $datos['nit'];
            }
            if ((string) ($proveedor['razon_social'] ?? '') !== $datos['razon_social']) {
                $cambios[] = 'Razón social actualizada.';
            }
            if ((string) ($proveedor['estado'] ?? '') !== $datos['estado']) {
                $cambios[] = 'Estado: ' . $proveedor['estado'] . ' → ' . $datos['estado'];
            }

            registrarAuditoria(
                $pdo,
                (int) usuarioActual()['id'],
                'editar_proveedor',
                'proveedores',
                $id,
                $cambios !== [] ? implode(' ', $cambios) : 'Datos de contacto actualizados.'
            );

            redirigirConFlash(
                '/modules/proveedores/detalle.php?id=' . $id,
                'success',
                'Proveedor actualizado correctamente.'
            );
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $errores[] = 'Ya existe un proveedor con ese NIT.';
            } else {
                error_log('SafePay proveedor: ' . $error->getMessage());
                $errorGeneral = 'No se pudo guardar el proveedor. Intente de nuevo.';
            }
        }
    }
}

$pageTitle = $proveedor === null ? 'Nuevo proveedor' : 'Editar proveedor';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-3">
        <a class="small text-decoration-none" href="<?= e(BASE_URL) ?>/modules/proveedores/index.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Proveedores
        </a>
    </div>

    <div class="mb-4">
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Los datos del proveedor y sus cuentas conservan el historial completo.</p>
    </div>

    <?php if ($errorGeneral !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e($errorGeneral) ?>
        </div>
    <?php endif; ?>

    <?php if ($errores !== []): ?>
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Revise el formulario:</div>
            <ul class="mb-0 ps-3">
                <?php foreach (array_unique($errores) as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(BASE_URL) ?>/modules/proveedores/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" novalidate>
        <?= csrfCampo() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="row g-4">
            <div class="col-xl-9">
                <div class="card">
                    <div class="card-header">Datos generales</div>
                    <div class="card-body">
                        <p class="small text-muted">Los campos con <span class="text-danger">*</span> son obligatorios.</p>

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="razon_social">Razón social *</label>
                                <input class="form-control" id="razon_social" name="razon_social" maxlength="150"
                                       value="<?= e($datos['razon_social']) ?>" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="nit">NIT *</label>
                                <input class="form-control" id="nit" name="nit" maxlength="20"
                                       value="<?= e($proveedor !== null ? formatearNit($datos['nit']) : $datos['nit']) ?>"
                                       placeholder="900.123.456-7" required>
                                <div class="form-text">Se guarda sin puntos para evitar duplicados.</div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="correo">Correo</label>
                                <input class="form-control" id="correo" name="correo" type="email" maxlength="120"
                                       value="<?= e($datos['correo']) ?>" placeholder="contacto@proveedor.com">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="telefono">Teléfono</label>
                                <input class="form-control" id="telefono" name="telefono" maxlength="30"
                                       value="<?= e($datos['telefono']) ?>" placeholder="601 555 0101">
                            </div>
                        </div>

                        <?php if ($proveedor !== null && $nitOriginal !== ''): ?>
                            <div class="alert alert-warning small mt-4 mb-0" role="alert">
                                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                Cambiar el NIT es una modificación sensible y queda en auditoría.
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="confirmar_nit" name="confirmar_nit" value="1"
                                        <?= !empty($_POST['confirmar_nit']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="confirmar_nit">Confirmo el cambio de NIT.</label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">Estado</div>
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4">
                                <label class="form-label" for="estado">Estado del proveedor</label>
                                <select class="form-select" id="estado" name="estado">
                                    <option value="activo" <?= $datos['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option>
                                    <option value="inactivo" <?= $datos['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <p class="small text-muted mb-0">
                                    Un proveedor inactivo conserva su historial. Sus facturas nuevas se registran,
                                    pero quedan sujetas a revisión por el motor de reglas.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar proveedor
                    </button>
                    <a class="btn btn-outline-secondary"
                       href="<?= e(BASE_URL) ?>/modules/proveedores/<?= $id > 0 ? 'detalle.php?id=' . $id : 'index.php' ?>">
                        Cancelar
                    </a>
                </div>
            </div>

            <div class="col-xl-3">
                <div class="card">
                    <div class="card-header">Buenas prácticas</div>
                    <div class="card-body small text-muted">
                        <ul class="mb-0 ps-3">
                            <li class="mb-2">Verifique el NIT contra el documento del proveedor.</li>
                            <li class="mb-2">Los cambios de cuenta bancaria se registran aparte, nunca sobrescriben la anterior.</li>
                            <li>Si inactiva el proveedor, sus facturas nuevas quedarán bajo revisión.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
