<?php
declare(strict_types=1);

/**
 * Cierre de sesión (POST con CSRF).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!estaAutenticado()) {
    redirigir('/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido($_POST['csrf_token'] ?? null)) {
    redirigir('/dashboard.php');
}

$usuario = usuarioActual();

try {
    require_once __DIR__ . '/../config/db.php';
    registrarAuditoria(
        $pdo,
        (int) $usuario['id'],
        'logout',
        'usuarios',
        (int) $usuario['id'],
        'Cierre de sesión.'
    );
} catch (Throwable $error) {
    error_log('SafePay logout: ' . $error->getMessage());
}

cerrarSesion();
redirigir('/auth/login.php?salida=1');
