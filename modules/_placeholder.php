<?php
declare(strict_types=1);

/**
 * Vista base para módulos nuevos.
 * Los módulos funcionales ya tienen sus propias pantallas y no incluyen este archivo.
 */

if (!isset($moduloTitulo)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
requiereLogin();

$pageTitle = $moduloTitulo;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body p-4 p-md-5 text-center">
                    <div class="display-5 text-primary mb-2" aria-hidden="true">
                        <i class="bi <?= e($moduloIcono ?? 'bi-box') ?>"></i>
                    </div>
                    <h1 class="h3"><?= e($moduloTitulo) ?></h1>
                    <p class="text-muted mb-4"><?= e($moduloDescripcion ?? '') ?></p>
                    <div class="alert alert-info small text-start" role="status">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Esta es una vista base de apoyo. Los módulos funcionales ya están
                        implementados y el acceso a cada uno se valida por rol en el servidor.
                    </div>
                    <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/dashboard.php">
                        <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Volver al dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
