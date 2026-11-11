<?php
declare(strict_types=1);

/**
 * Acceso denegado por rol (HTTP 403).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requiereLogin();

http_response_code(403);

$desde = isset($_GET['from']) ? destinoSeguro($_GET['from']) : null;

if ($desde !== null) {
    try {
        require_once __DIR__ . '/../config/db.php';
        $usuario = usuarioActual();
        registrarAuditoria(
            $pdo,
            (int) $usuario['id'],
            'acceso_denegado',
            'usuarios',
            (int) $usuario['id'],
            'Intento de acceso a ' . $desde . ' con rol ' . ($usuario['rol'] ?? 'desconocido') . '.'
        );
    } catch (Throwable $error) {
        error_log('SafePay 403: ' . $error->getMessage());
    }
}

$pageTitle = 'Acceso denegado';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm text-center">
                <div class="card-body p-4 p-md-5">
                    <div class="display-5 text-danger mb-2" aria-hidden="true">403</div>
                    <h1 class="h3">Acceso denegado</h1>
                    <p class="text-muted mb-4">
                        Su rol actual no tiene permiso para abrir esta sección. Si cree que es un error,
                        contacte al administrador del sistema.
                    </p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/dashboard.php">
                            <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Volver al dashboard
                        </a>
                        <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/index.php">
                            <i class="bi bi-house me-1" aria-hidden="true"></i>Inicio
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
