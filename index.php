<?php
declare(strict_types=1);

/**
 * Página de inicio pública de SafePay.
 * Presenta la plataforma y dirige al inicio de sesión o al dashboard.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$autenticado = estaAutenticado();
$pageTitle = 'Control de pagos empresariales';

require_once __DIR__ . '/includes/header.php';
?>
<div class="sp-landing">

    <section class="sp-hero">
        <span class="sp-hero-shape sp-hero-shape--one" aria-hidden="true"></span>
        <span class="sp-hero-shape sp-hero-shape--two" aria-hidden="true"></span>
        <span class="sp-hero-grid" aria-hidden="true"></span>

        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="sp-eyebrow">
                        <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                        Control y prevención de fraudes
                    </span>

                    <h1 class="sp-hero-title">
                        Pagos empresariales bajo control, sin sorpresas.
                    </h1>

                    <p class="sp-hero-text">
                        SafePay reúne facturas, proveedores y pagos en un solo lugar. Cada operación
                        pasa por validación de riesgo, aprobación y registro antes de ejecutarse.
                    </p>

                    <div class="d-flex flex-wrap gap-3 mt-4">
                        <?php if ($autenticado): ?>
                            <a class="btn btn-light btn-lg sp-btn-hero" href="<?= e(BASE_URL) ?>/dashboard.php">
                                <i class="bi bi-speedometer2 me-2" aria-hidden="true"></i>Ir al dashboard
                            </a>
                        <?php else: ?>
                            <a class="btn btn-light btn-lg sp-btn-hero" href="<?= e(BASE_URL) ?>/auth/login.php">
                                <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Iniciar sesión
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-outline-light btn-lg sp-btn-hero" href="#plataforma">
                            Conocer la plataforma
                        </a>
                    </div>

                    <ul class="sp-hero-points">
                        <li><i class="bi bi-check2-circle" aria-hidden="true"></i>Alertas antes de pagar</li>
                        <li><i class="bi bi-check2-circle" aria-hidden="true"></i>Doble aprobación</li>
                        <li><i class="bi bi-check2-circle" aria-hidden="true"></i>Historial completo</li>
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="sp-panel" aria-hidden="true">
                        <div class="sp-panel-top">
                            <span class="sp-panel-brand">
                                <img src="<?= e(BASE_URL) ?>/assets/img/logo.svg" alt="" width="24" height="24">
                                SafePay
                            </span>
                            <span class="sp-panel-dots"><span></span><span></span><span></span></span>
                        </div>

                        <div class="sp-panel-body">
                            <div class="sp-panel-heading">
                                <div>
                                    <span class="sp-panel-label">Panel de control</span>
                                    <strong class="sp-panel-title">Resumen de pagos</strong>
                                </div>
                                <span class="sp-panel-badge">En revisión</span>
                            </div>

                            <div class="sp-panel-kpis">
                                <div class="sp-panel-kpi">
                                    <span>Pagos pendientes</span>
                                    <strong>02</strong>
                                    <em class="sp-trend sp-trend--warning">Requieren decisión</em>
                                </div>
                                <div class="sp-panel-kpi">
                                    <span>Alertas abiertas</span>
                                    <strong>03</strong>
                                    <em class="sp-trend sp-trend--danger">Riesgo detectado</em>
                                </div>
                                <div class="sp-panel-kpi">
                                    <span>Aprobados</span>
                                    <strong>08</strong>
                                    <em class="sp-trend sp-trend--success">Listos para pagar</em>
                                </div>
                            </div>

                            <div class="sp-panel-list">
                                <div class="sp-panel-item">
                                    <span class="sp-panel-avatar">SN</span>
                                    <div class="sp-panel-info">
                                        <strong>Suministros del Norte</strong>
                                        <span>Factura SN-8804</span>
                                    </div>
                                    <span class="sp-pill sp-pill--danger">Bloqueado</span>
                                </div>
                                <div class="sp-panel-item">
                                    <span class="sp-panel-avatar sp-panel-avatar--teal">SL</span>
                                    <div class="sp-panel-info">
                                        <strong>Servicios Logísticos</strong>
                                        <span>Factura SL-3302</span>
                                    </div>
                                    <span class="sp-pill sp-pill--warning">Pendiente</span>
                                </div>
                                <div class="sp-panel-item">
                                    <span class="sp-panel-avatar sp-panel-avatar--blue">PC</span>
                                    <div class="sp-panel-info">
                                        <strong>Papelería Central</strong>
                                        <span>Factura PC-2211</span>
                                    </div>
                                    <span class="sp-pill sp-pill--success">Aprobado</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="sp-strip">
        <div class="container">
            <div class="row g-4 text-center text-lg-start">
                <div class="col-6 col-lg-3">
                    <div class="sp-strip-item">
                        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                        <div><strong>Validación de riesgo</strong><span>Antes de cada pago</span></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="sp-strip-item">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        <div><strong>Acceso por rol</strong><span>Empleado, financiero, admin</span></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="sp-strip-item">
                        <i class="bi bi-journal-check" aria-hidden="true"></i>
                        <div><strong>Auditoría</strong><span>Cada acción registrada</span></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="sp-strip-item">
                        <i class="bi bi-diagram-3" aria-hidden="true"></i>
                        <div><strong>Doble control</strong><span>Solicitud y aprobación</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="sp-section" id="plataforma">
        <div class="container">
            <div class="sp-section-head">
                <span class="sp-kicker">La plataforma</span>
                <h2>Todo el ciclo del pago, en un solo flujo</h2>
                <p>
                    Desde el registro de la factura hasta la ejecución del pago, SafePay conserva
                    el contexto de cada operación y detiene lo que no cumple las reglas.
                </p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-xl-3">
                    <article class="sp-feature">
                        <span class="sp-feature-icon"><i class="bi bi-shield-exclamation" aria-hidden="true"></i></span>
                        <h3>Prevención de fraude</h3>
                        <p>Detecta cuentas modificadas, facturas duplicadas y valores fuera de lo habitual.</p>
                    </article>
                </div>
                <div class="col-md-6 col-xl-3">
                    <article class="sp-feature">
                        <span class="sp-feature-icon"><i class="bi bi-building-check" aria-hidden="true"></i></span>
                        <h3>Proveedores verificados</h3>
                        <p>Centraliza datos, cuentas bancarias e historial de cambios de cada proveedor.</p>
                    </article>
                </div>
                <div class="col-md-6 col-xl-3">
                    <article class="sp-feature">
                        <span class="sp-feature-icon"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
                        <h3>Aprobación en dos pasos</h3>
                        <p>La solicitud, la aprobación y la ejecución quedan separadas y bajo control.</p>
                    </article>
                </div>
                <div class="col-md-6 col-xl-3">
                    <article class="sp-feature">
                        <span class="sp-feature-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
                        <h3>Auditoría completa</h3>
                        <p>Consulta quién hizo qué, cuándo y sobre qué operación, sin perder trazabilidad.</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="sp-section sp-section--soft">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-5">
                    <span class="sp-kicker">Cómo funciona</span>
                    <h2 class="sp-title-sm">Tres pasos para un pago controlado</h2>
                    <p class="text-muted mb-4">
                        El sistema aplica las reglas del negocio en cada etapa. Si algo no cuadra,
                        el pago se detiene antes de salir.
                    </p>
                    <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/auth/login.php">
                        <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Entrar al sistema
                    </a>
                </div>
                <div class="col-lg-7">
                    <ol class="sp-steps">
                        <li>
                            <span class="sp-step-num">01</span>
                            <div>
                                <h3>Registre la factura</h3>
                                <p>Adjunte el PDF, seleccione el proveedor y confirme el valor a pagar.</p>
                            </div>
                        </li>
                        <li>
                            <span class="sp-step-num">02</span>
                            <div>
                                <h3>Validación automática</h3>
                                <p>El sistema revisa la cuenta bancaria, el historial y las reglas de riesgo.</p>
                            </div>
                        </li>
                        <li>
                            <span class="sp-step-num">03</span>
                            <div>
                                <h3>Aprobación y registro</h3>
                                <p>Un responsable autoriza el pago y la operación queda auditada.</p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="sp-contact">
        <div class="container">
            <div class="sp-contact-card">
                <span class="sp-contact-shape" aria-hidden="true"></span>
                <div class="row align-items-center g-4 position-relative">
                    <div class="col-lg-7">
                        <span class="sp-kicker sp-kicker--light">Hablemos</span>
                        <h2>¿Listo para controlar sus pagos?</h2>
                        <p>
                            Escríbanos para conocer cómo SafePay se adapta a la operación de su empresa
                            y a sus reglas de aprobación.
                        </p>
                    </div>
                    <div class="col-lg-5">
                        <div class="sp-contact-links">
                            <a href="mailto:contacto@safepay.local">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <span><strong>Correo</strong>contacto@safepay.local</span>
                            </a>
                            <div>
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span><strong>Ubicación</strong>Colombia</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
