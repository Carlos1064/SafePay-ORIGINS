<?php
declare(strict_types=1);

/**
 * Autenticación, sesiones, permisos y auditoría (Fase 4).
 *
 * Reglas:
 * - La sesión se inicia antes de cualquier salida HTML.
 * - El ID de sesión se regenera al iniciar sesión y periódicamente.
 * - La sesión expira por inactividad y por tiempo absoluto.
 * - Los permisos se comprueban siempre en el servidor.
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);

    $seguro = esConexionSegura();

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL . '/',
        'domain'   => '',
        'secure'   => $seguro,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $seguro ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_TIMEOUT);
    session_cache_limiter('nocache');

    session_start();
}

aplicarCabecerasSeguridad();

/** Devuelve el usuario autenticado o null. */
function usuarioActual(): ?array
{
    $usuario = $_SESSION['usuario'] ?? null;

    return is_array($usuario) ? $usuario : null;
}

/** Indica si hay una sesión autenticada. */
function estaAutenticado(): bool
{
    return usuarioActual() !== null;
}

/** Rol del usuario autenticado, o null. */
function rolActual(): ?string
{
    return usuarioActual()['rol'] ?? null;
}

/** Comprueba si el usuario actual tiene uno de los roles indicados. */
function tieneRol(array|string $roles): bool
{
    $rol = rolActual();
    if ($rol === null) {
        return false;
    }

    $permitidos = is_array($roles) ? $roles : [$roles];

    return in_array($rol, $permitidos, true);
}

/** Genera (o reutiliza) el token CSRF de la sesión. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Campo oculto listo para insertar en un formulario. */
function csrfCampo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Valida el token CSRF enviado por POST. */
function csrfValido(mixed $token): bool
{
    $guardado = $_SESSION['csrf_token'] ?? null;

    return is_string($token)
        && is_string($guardado)
        && $guardado !== ''
        && hash_equals($guardado, $token);
}

/** Ruta interna actual, útil para volver después del login. */
function rutaActualRelativa(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with($uri, BASE_URL)) {
        $uri = substr($uri, strlen(BASE_URL));
    }

    if ($uri === '' || !str_starts_with($uri, '/')) {
        return '/dashboard.php';
    }

    return $uri;
}

/** Valida un destino interno para evitar redirecciones abiertas. */
function destinoSeguro(mixed $destino): string
{
    if (!is_string($destino)) {
        return '/dashboard.php';
    }

    $destino = trim($destino);

    if ($destino === ''
        || !str_starts_with($destino, '/')
        || str_starts_with($destino, '//')
        || str_contains($destino, '://')
        || str_contains($destino, '\\')
        || str_contains($destino, "\r")
        || str_contains($destino, "\n")
        || str_starts_with($destino, '/auth/')
    ) {
        return '/dashboard.php';
    }

    return $destino;
}

/** Destruye la sesión y la cookie de sesión. */
function cerrarSesion(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Verifica expiración y renueva el ID de sesión si corresponde. */
function verificarSesion(): void
{
    if (!estaAutenticado()) {
        return;
    }

    $ahora = time();
    $ultimoMovimiento = (int) ($_SESSION['ultimo_movimiento'] ?? $ahora);
    $inicioSesion = (int) ($_SESSION['inicio_sesion'] ?? $ahora);
    $ultimaRegeneracion = (int) ($_SESSION['ultima_regeneracion'] ?? 0);

    if (($ahora - $ultimoMovimiento) > SESSION_IDLE_TIMEOUT
        || ($ahora - $inicioSesion) > SESSION_ABSOLUTE_TIMEOUT
    ) {
        cerrarSesion();
        redirigir('/auth/login.php?expirada=1');
    }

    $_SESSION['ultimo_movimiento'] = $ahora;

    if (($ahora - $ultimaRegeneracion) > SESSION_REGENERATE_EVERY) {
        session_regenerate_id(true);
        $_SESSION['ultima_regeneracion'] = $ahora;
    }
}

/** Exige una sesión autenticada. */
function requiereLogin(): void
{
    verificarSesion();

    if (!estaAutenticado()) {
        $destino = rutaActualRelativa();
        redirigir('/auth/login.php?next=' . rawurlencode($destino));
    }
}

/** Exige uno de los roles indicados. */
function requiereRol(array|string $roles): void
{
    requiereLogin();

    if (!tieneRol($roles)) {
        $destino = rutaActualRelativa();
        redirigir('/auth/403.php?from=' . rawurlencode($destino));
    }
}

/** Registra un evento en la tabla auditoria. */
function registrarAuditoria(
    PDO $pdo,
    ?int $idUsuario,
    string $accion,
    string $entidad,
    ?int $idEntidad = null,
    ?string $detalle = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO auditoria (id_usuario, accion, entidad, id_entidad, detalle, ip)
             VALUES (:id_usuario, :accion, :entidad, :id_entidad, :detalle, :ip)'
        );
        $stmt->execute([
            ':id_usuario' => $idUsuario,
            ':accion'     => mb_substr($accion, 0, 60),
            ':entidad'    => mb_substr($entidad, 0, 40),
            ':id_entidad' => $idEntidad,
            ':detalle'    => $detalle,
            ':ip'         => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (Throwable $error) {
        // La auditoría nunca debe romper la operación principal.
        error_log('SafePay auditoría: ' . $error->getMessage());
    }
}

/**
 * Autentica un correo y una contraseña.
 *
 * @return array{ok: bool, mensaje: string, usuario?: array}
 */
function autenticar(PDO $pdo, string $correo, string $clave): array
{
    $correo = mb_strtolower(trim($correo));

    if ($correo === '' || $clave === '') {
        return ['ok' => false, 'mensaje' => 'Ingrese su correo y contraseña.'];
    }

    $stmt = $pdo->prepare(
        'SELECT u.id_usuario, u.nombre, u.correo, u.password_hash, u.activo,
                u.intentos_fallidos, u.bloqueado_hasta, r.nombre AS rol
         FROM usuarios u
         INNER JOIN roles r ON r.id_rol = u.id_rol
         WHERE u.correo = :correo
         LIMIT 1'
    );
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch();

    // Se verifica siempre una contraseña, incluso si el correo no existe, para
    // reducir la diferencia de tiempo que permitiría enumerar cuentas.
    $hashParaVerificar = $usuario
        ? (string) $usuario['password_hash']
        : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $claveValida = password_verify($clave, $hashParaVerificar);

    if (!$usuario) {
        registrarAuditoria(
            $pdo,
            null,
            'login_fallido',
            'usuarios',
            null,
            'Intento fallido para un correo no registrado.'
        );

        return ['ok' => false, 'mensaje' => 'Correo o contraseña incorrectos.'];
    }

    $idUsuario = (int) $usuario['id_usuario'];

    if ((int) $usuario['activo'] !== 1) {
        registrarAuditoria($pdo, $idUsuario, 'login_fallido', 'usuarios', $idUsuario, 'Cuenta inactiva.');

        return ['ok' => false, 'mensaje' => 'La cuenta está inactiva. Contacte al administrador.'];
    }

    $bloqueadoHasta = $usuario['bloqueado_hasta'] !== null ? strtotime((string) $usuario['bloqueado_hasta']) : false;
    if ($bloqueadoHasta !== false && $bloqueadoHasta > time()) {
        registrarAuditoria($pdo, $idUsuario, 'login_fallido', 'usuarios', $idUsuario, 'Cuenta bloqueada temporalmente.');

        return [
            'ok' => false,
            'mensaje' => 'La cuenta está bloqueada temporalmente por demasiados intentos. Intente de nuevo en '
                . LOGIN_BLOQUEO_MINUTOS . ' minutos.',
        ];
    }

    if ($bloqueadoHasta !== false && $bloqueadoHasta <= time()) {
        $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?')
            ->execute([$idUsuario]);
        $usuario['intentos_fallidos'] = 0;
    }

    if (!$claveValida) {
        $intentos = (int) $usuario['intentos_fallidos'] + 1;

        if ($intentos >= LOGIN_MAX_INTENTOS) {
            $hasta = date('Y-m-d H:i:s', time() + (LOGIN_BLOQUEO_MINUTOS * 60));
            $pdo->prepare(
                'UPDATE usuarios SET intentos_fallidos = :intentos, bloqueado_hasta = :hasta WHERE id_usuario = :id'
            )->execute([
                ':intentos' => $intentos,
                ':hasta'    => $hasta,
                ':id'       => $idUsuario,
            ]);

            registrarAuditoria(
                $pdo,
                $idUsuario,
                'login_fallido',
                'usuarios',
                $idUsuario,
                'Cuenta bloqueada tras ' . $intentos . ' intentos fallidos.'
            );

            return [
                'ok' => false,
                'mensaje' => 'Demasiados intentos fallidos. La cuenta quedó bloqueada por '
                    . LOGIN_BLOQUEO_MINUTOS . ' minutos.',
            ];
        }

        $pdo->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id_usuario = ?')
            ->execute([$intentos, $idUsuario]);

        registrarAuditoria(
            $pdo,
            $idUsuario,
            'login_fallido',
            'usuarios',
            $idUsuario,
            'Contraseña incorrecta (' . $intentos . '/' . LOGIN_MAX_INTENTOS . ').'
        );

        return ['ok' => false, 'mensaje' => 'Correo o contraseña incorrectos.'];
    }

    if (password_needs_rehash((string) $usuario['password_hash'], PASSWORD_DEFAULT)) {
        $nuevoHash = password_hash($clave, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?')
            ->execute([$nuevoHash, $idUsuario]);
    }

    $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?')
        ->execute([$idUsuario]);

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'     => $idUsuario,
        'nombre' => $usuario['nombre'],
        'correo' => $usuario['correo'],
        'rol'    => $usuario['rol'],
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['inicio_sesion'] = time();
    $_SESSION['ultimo_movimiento'] = time();
    $_SESSION['ultima_regeneracion'] = time();

    registrarAuditoria($pdo, $idUsuario, 'login_exitoso', 'usuarios', $idUsuario, 'Inicio de sesión correcto.');

    return ['ok' => true, 'mensaje' => 'Sesión iniciada.', 'usuario' => $_SESSION['usuario']];
}
