<?php
declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$usuarioActual = usuarioActual();
$pageTitle     = $pageTitle ?? APP_NAME;
$rol           = $usuarioActual['rol'] ?? null;
$nombreUsuario = $usuarioActual['nombre'] ?? null;
$itemsMenu     = menuPorRol($rol);
$urlInicio     = $usuarioActual !== null ? BASE_URL . '/dashboard.php' : BASE_URL . '/index.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(BASE_URL) ?>/assets/img/logo.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>/assets/css/style.css" rel="stylesheet">
    <?php if ($pageTitle === 'Iniciar sesión'): ?>
    <link href="<?= e(BASE_URL) ?>/assets/css/login-prototipo.css?v=1" rel="stylesheet">
    <?php endif; ?>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<a class="visually-hidden-focusable p-2 bg-white" href="#contenido">Saltar al contenido</a>

<nav class="navbar navbar-expand-lg navbar-dark bg-safepay sticky-top shadow-sm" aria-label="Navegación principal">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($urlInicio) ?>">
            <img src="<?= e(BASE_URL) ?>/assets/img/logo.svg" alt="" width="28" height="28">
            <span class="fw-semibold"><?= e(APP_NAME) ?></span>
        </a>

        <?php if ($itemsMenu !== []): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuPrincipal"
                    aria-controls="menuPrincipal" aria-expanded="false" aria-label="Abrir menú">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="menuPrincipal">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">
                    <?php foreach ($itemsMenu as $item): ?>
                        <?php $activo = esRutaActiva($item['ruta']); ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $activo ? ' active' : '' ?>"
                               href="<?= e(BASE_URL . $item['ruta']) ?>"
                               <?= $activo ? 'aria-current="page"' : '' ?>>
                                <?= e($item['etiqueta']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light d-flex align-items-center gap-2 dropdown-toggle"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar-iniciales" aria-hidden="true"><?= e(iniciales($nombreUsuario ?? 'Usuario')) ?></span>
                        <span class="d-none d-lg-inline"><?= e($nombreUsuario ?? 'Usuario') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><span class="dropdown-item-text small text-muted"><?= e(etiquetaRol($rol)) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="<?= e(BASE_URL) ?>/auth/logout.php" method="post" class="m-0">
                                <?= csrfCampo() ?>
                                <button class="dropdown-item" type="submit">
                                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Cerrar sesión
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        <?php else: ?>
            <a class="btn btn-sm btn-outline-light" href="<?= e(BASE_URL) ?>/auth/login.php">
                <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Ingresar
            </a>
        <?php endif; ?>
    </div>
</nav>

<main id="contenido" class="flex-grow-1">
