<?php
declare(strict_types=1);

/**
 * Constantes globales del proyecto SafePay.
 * Este archivo SÍ se versiona: no contiene credenciales.
 */

// En producción no se muestran errores al usuario; se registran en el log.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

$parametros = require __DIR__ . '/parametros.php';
if (!is_array($parametros)) {
    $parametros = [];
}
define('PARAMETROS', $parametros);
unset($parametros);

define('APP_NAME', 'SafePay');
define('APP_VERSION', '0.2.0');

// InfinityFree publica el proyecto en la raíz del dominio.
// Si se instala en una subcarpeta, cambiar por '/nombre-de-la-carpeta'.
define('BASE_URL', '');

// Rutas físicas
define('ROOT_PATH', dirname(__DIR__));
define('UPLOADS_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads');

// Zona horaria del proyecto (Colombia)
date_default_timezone_set('America/Bogota');

// Sesiones y autenticación (Fase 4, umbrales en Fase 6)
define('SESSION_NAME', 'safepay_session');
define('SESSION_IDLE_TIMEOUT', max(1, (int) (PARAMETROS['sesion_inactividad_min'] ?? 30)) * 60);
define('SESSION_ABSOLUTE_TIMEOUT', 28800);  // 8 horas desde el inicio de sesión
define('SESSION_REGENERATE_EVERY', 300);    // Renovar el ID cada 5 minutos
define('LOGIN_MAX_INTENTOS', max(1, (int) (PARAMETROS['login_intentos_max'] ?? 5)));
define('LOGIN_BLOQUEO_MINUTOS', 15);

// Límite de los PDF de facturas (Fase 6)
define('PDF_MAX_MB', max(1, (int) (PARAMETROS['pdf_max_mb'] ?? 5)));
