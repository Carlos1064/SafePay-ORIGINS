<?php
declare(strict_types=1);

/**
 * Funciones comunes del proyecto.
 */

/** Escapa texto antes de imprimirlo en HTML (previene XSS). */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Detecta contenido activo habitual en PDF (JavaScript, lanzamiento de
 * aplicaciones, archivos embebidos o rich media). Es una defensa adicional:
 * no sustituye el análisis antivirus del servidor.
 */
function pdfTieneContenidoActivo(string $ruta): bool
{
    $contenido = @file_get_contents($ruta);

    if (!is_string($contenido)) {
        return true;
    }

    return preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b/i', $contenido) === 1;
}

/** Indica si la petición llegó por HTTPS (directo o detrás de un proxy). */
function esConexionSegura(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

    if ($https !== '' && $https !== 'off') {
        return true;
    }

    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Nonce por petición para los scripts inline.
 * Se genera una sola vez y debe coincidir con el de la cabecera CSP.
 */
function cspNonce(): string
{
    static $nonce = null;

    if (!is_string($nonce) || $nonce === '') {
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    return $nonce;
}

/**
 * Cabeceras de seguridad comunes a todas las respuestas dinámicas.
 * La sesión debe haberse iniciado antes para que session_start() no envíe
 * cabeceras de caché después de estas.
 */
function aplicarCabecerasSeguridad(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('X-Download-Options: noopen');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $nonce = cspNonce();
    header(
        "Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "script-src 'self' 'nonce-{$nonce}'; connect-src 'self';"
    );

    if (esConexionSegura()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Devuelve un parámetro configurado en config/parametros.php.
 *
 * @param mixed $porDefecto Valor usado si la clave no existe.
 * @return mixed
 */
function parametro(string $clave, mixed $porDefecto = null): mixed
{
    if (!defined('PARAMETROS') || !is_array(PARAMETROS)) {
        return $porDefecto;
    }

    return array_key_exists($clave, PARAMETROS) ? PARAMETROS[$clave] : $porDefecto;
}

/** Redirige a una ruta interna del proyecto y detiene la ejecución. */
function redirigir(string $ruta): never
{
    header('Location: ' . BASE_URL . $ruta);
    exit;
}

/**
 * Ítems del menú permitidos para un rol.
 * Sin sesión (null) devuelve una lista vacía: la navbar muestra "Ingresar".
 *
 * @return array<int, array{etiqueta: string, ruta: string, roles: array<int, string>}>
 */
function menuPorRol(?string $rol): array
{
    $items = [
        ['etiqueta' => 'Dashboard',   'ruta' => '/dashboard.php',                 'roles' => ['empleado', 'financiero', 'administrador']],
        ['etiqueta' => 'Proveedores', 'ruta' => '/modules/proveedores/index.php', 'roles' => ['financiero', 'administrador']],
        ['etiqueta' => 'Facturas',    'ruta' => '/modules/facturas/index.php',    'roles' => ['empleado', 'financiero', 'administrador']],
        ['etiqueta' => 'Pagos',       'ruta' => '/modules/pagos/index.php',       'roles' => ['empleado', 'financiero', 'administrador']],
        ['etiqueta' => 'Alertas',     'ruta' => '/modules/alertas/index.php',     'roles' => ['financiero', 'administrador']],
        ['etiqueta' => 'Auditoría',   'ruta' => '/modules/auditoria/index.php',   'roles' => ['financiero', 'administrador']],
        ['etiqueta' => 'Reportes',    'ruta' => '/modules/reportes/index.php',    'roles' => ['financiero', 'administrador']],
        ['etiqueta' => 'Usuarios',    'ruta' => '/modules/usuarios/index.php',    'roles' => ['administrador']],
    ];

    if ($rol === null) {
        return [];
    }

    return array_values(array_filter(
        $items,
        static fn (array $item): bool => in_array($rol, $item['roles'], true)
    ));
}

/** Indica si la ruta actual corresponde a un ítem del menú (incluye sus subpáginas). */
function esRutaActiva(string $ruta): bool
{
    $actual  = $_SERVER['SCRIPT_NAME'] ?? '';
    $destino = BASE_URL . $ruta;

    if ($ruta === '/index.php') {
        return $actual === $destino || $actual === BASE_URL . '/';
    }

    $base = rtrim(str_replace('/index.php', '', $destino), '/');

    return $actual === $destino || str_starts_with($actual, $base . '/');
}

/** Nombre legible de un rol. */
function etiquetaRol(?string $rol): string
{
    return match ($rol) {
        'empleado'      => 'Empleado',
        'financiero'    => 'Responsable financiero',
        'administrador' => 'Administrador',
        default         => 'Invitado',
    };
}

/** Iniciales para el avatar (máximo dos palabras). */
function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $texto  = '';

    foreach (array_slice($partes, 0, 2) as $parte) {
        $texto .= mb_strtoupper(mb_substr($parte, 0, 1));
    }

    return $texto !== '' ? $texto : '?';
}

/** Formatea un monto en pesos colombianos para la interfaz. */
function formatearMoneda(float|int|string|null $valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }

    return '$ ' . number_format((float) $valor, 0, ',', '.');
}

/** Formatea una fecha de base de datos como dd/mm/aaaa (y hora opcional). */
function formatearFecha(?string $fecha, bool $conHora = false): string
{
    if ($fecha === null || $fecha === '') {
        return '—';
    }

    try {
        $objeto = new DateTimeImmutable($fecha);
    } catch (Exception) {
        return '—';
    }

    return $objeto->format($conHora ? 'd/m/Y H:i' : 'd/m/Y');
}
