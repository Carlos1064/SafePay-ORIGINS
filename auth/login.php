<?php
declare(strict_types=1);

/**
 * Inicio de sesión (SC-01) — Fase 4.
 * Autenticación real contra la tabla usuarios, con CSRF, bloqueo por intentos y auditoría.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$destino = destinoSeguro($_GET['next'] ?? $_POST['next'] ?? '/dashboard.php');

if (estaAutenticado()) {
    redirigir($destino);
}

$correo = '';
$errores = [];
$mensaje = '';
$dbError = null;
$pdo = null;

try {
    require_once __DIR__ . '/../config/db.php';
} catch (Throwable $error) {
    $dbError = 'No se pudo conectar con la base de datos. Verifique que MySQL esté iniciado.';
    error_log('SafePay login DB: ' . $error->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');

    if ($dbError !== null || !($pdo instanceof PDO)) {
        $errores[] = $dbError ?? 'No hay conexión con la base de datos.';
    } elseif (!csrfValido($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión del formulario expiró. Recargue la página e intente de nuevo.';
    } else {
        try {
            $resultado = autenticar($pdo, $correo, $clave);

            if ($resultado['ok']) {
                redirigir($destino);
            }

            $errores[] = $resultado['mensaje'];
        } catch (Throwable $error) {
            error_log('SafePay login: ' . $error->getMessage());
            $errores[] = 'No se pudo procesar el inicio de sesión. Intente de nuevo.';
        }
    }
} else {
    if (isset($_GET['expirada'])) {
        $mensaje = 'Su sesión expiró por inactividad. Ingrese de nuevo.';
    } elseif (isset($_GET['salida'])) {
        $mensaje = 'La sesión se cerró correctamente.';
    }
}

$pageTitle = 'Iniciar sesión';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-4 py-lg-5">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="card shadow-sm overflow-hidden sp-auth-login-card">
                <div class="row g-0">
                    <div class="col-lg-6">
                        <div class="sp-auth-login-panel h-100 p-4 p-lg-5 d-flex flex-column justify-content-between">
                            <div>
                                <a class="d-flex align-items-center gap-2 mb-4 sp-auth-brand" href="<?= e(BASE_URL) ?>/index.php">
                                    <img src="<?= e(BASE_URL) ?>/assets/img/logo.svg" alt="" width="40" height="40">
                                    <span class="fs-4 fw-semibold">SafePay</span>
                                </a>
                                <h1 class="h2 fw-bold mb-3">Controle sus pagos antes de que salgan de su empresa.</h1>
                                <p class="sp-auth-lead mb-4">
                                    Facturas verificadas, alertas en tiempo real y auditoría completa en un solo lugar.
                                </p>
                                <div class="d-flex flex-column gap-2 sp-auth-checks">
                                    <div><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Detección de cuentas modificadas y pagos inusuales.</span></div>
                                    <div><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Aprobación con doble control y trazabilidad.</span></div>
                                    <div><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Historial de cada acción para mayor transparencia.</span></div>
                                </div>
                            </div>
                            <p class="small sp-auth-foot mb-0 mt-4">
                                Plataforma de control de pagos empresariales
                                <span><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Acceso seguro</span>
                            </p>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="p-4 p-lg-5 sp-auth-form-panel">
                            <div class="d-flex align-items-center gap-2 mb-4 sp-auth-mobile-brand">
                                <img src="<?= e(BASE_URL) ?>/assets/img/logo.svg" alt="" width="36" height="36">
                                <span class="fw-semibold">SafePay</span>
                            </div>
                            <div class="d-flex align-items-center gap-2 mb-2 sp-auth-form-kicker">
                                <i class="bi bi-shield-check" aria-hidden="true"></i>
                                <span>ACCESO A LA PLATAFORMA</span>
                            </div>
                            <h2 class="h2 mb-1">Bienvenido</h2>
                            <p class="text-muted mb-4">Ingrese con su cuenta corporativa para continuar.</p>

                            <?php if ($mensaje !== ''): ?>
                                <div class="alert alert-info small" role="status">
                                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e($mensaje) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($errores !== []): ?>
                                <div class="alert alert-danger small" role="alert">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                    <?= e($errores[0]) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="<?= e(BASE_URL) ?>/auth/login.php" novalidate>
                                <?= csrfCampo() ?>
                                <input type="hidden" name="next" value="<?= e($destino) ?>">

                                <div class="mb-3">
                                    <label for="correo" class="form-label fw-semibold">Correo corporativo</label>
                                    <div class="input-group sp-auth-input">
                                        <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                                        <input type="email" class="form-control" id="correo" name="correo"
                                               value="<?= e($correo) ?>" placeholder="usuario@empresa.com"
                                               autocomplete="username" required autofocus>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="clave" class="form-label fw-semibold">Contraseña</label>
                                    <div class="input-group sp-auth-input">
                                        <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                                        <input type="password" class="form-control" id="clave" name="clave"
                                               placeholder="Ingrese su contraseña" autocomplete="current-password" required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggleClave"
                                                aria-label="Mostrar contraseña" aria-pressed="false">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <button class="btn btn-primary w-100 sp-auth-submit" type="submit">
                                    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Ingresar
                                </button>
                            </form>

                            <div class="sp-auth-security mt-3">
                                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                                <div>
                                    <strong>Su información está protegida</strong>
                                    <p>Acceso restringido a usuarios autorizados. Si no recuerda sus credenciales, contacte al administrador.</p>
                                    <small>Tras <?= (int) LOGIN_MAX_INTENTOS ?> intentos fallidos la cuenta se bloquea
                                    <?= (int) LOGIN_BLOQUEO_MINUTOS ?> minutos.</small>
                                </div>
                            </div>
                            <a class="d-inline-block mt-3 text-decoration-none" href="<?= e(BASE_URL) ?>/index.php">
                                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al inicio
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const boton = document.getElementById('toggleClave');
    const campo = document.getElementById('clave');
    if (!boton || !campo) return;
    boton.addEventListener('click', () => {
        const mostrar = campo.type === 'password';
        campo.type = mostrar ? 'text' : 'password';
        boton.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        boton.setAttribute('aria-pressed', String(mostrar));
        boton.innerHTML = `<i class="bi ${mostrar ? 'bi-eye-slash' : 'bi-eye'}" aria-hidden="true"></i>`;
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
