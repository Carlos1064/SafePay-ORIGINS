<?php
declare(strict_types=1);

/** Dashboard de SafePay conectado a MySQL (producción). */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/modules.php';

requiereLogin();
$pdo = conexionBd();
$usuario = usuarioActual();
$esEmpleado = tieneRol('empleado');
$idUsuario = (int) ($usuario['id'] ?? 0);
$flash = consumirFlash();

// Cada indicador se calcula sobre datos reales. Los empleados solo ven sus registros.
$whereFacturas = $esEmpleado ? ' WHERE f.id_usuario_registra = :uid' : '';
$paramsUsuario = $esEmpleado ? [':uid' => $idUsuario] : [];
$stmt = $pdo->prepare('SELECT COUNT(*) FROM facturas f' . $whereFacturas);
$stmt->execute($paramsUsuario);
$totalFacturas = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT f.estado, COUNT(*) AS total FROM facturas f{$whereFacturas} GROUP BY f.estado");
$stmt->execute($paramsUsuario);
$conteosEstados = ['registrada'=>0,'observada'=>0,'aprobada'=>0,'rechazada'=>0,'pagada'=>0];
foreach ($stmt->fetchAll() as $fila) {
    $estado = (string) $fila['estado'];
    if (array_key_exists($estado, $conteosEstados)) $conteosEstados[$estado] = (int) $fila['total'];
}

$wherePagos = $esEmpleado ? ' WHERE p.id_usuario_solicita = :uid' : '';
$stmt = $pdo->prepare("SELECT p.estado, COUNT(*) AS total, COALESCE(SUM(p.valor),0) AS monto FROM pagos p{$wherePagos} GROUP BY p.estado");
$stmt->execute($paramsUsuario);
$conteosPagos = ['pendiente'=>0,'bloqueado'=>0,'aprobado'=>0,'rechazado'=>0,'ejecutado'=>0];
$montoSolicitado = 0.0;
foreach ($stmt->fetchAll() as $fila) {
    $estado = (string) $fila['estado'];
    if (array_key_exists($estado, $conteosPagos)) $conteosPagos[$estado] = (int) $fila['total'];
    if (in_array($estado, ['pendiente','bloqueado','aprobado'], true)) $montoSolicitado += (float) $fila['monto'];
}

$alertasAbiertas = 0;
$alertasRecientes = [];
if (!$esEmpleado) {
    $alertasAbiertas = (int) $pdo->query("SELECT COUNT(*) FROM alertas WHERE estado = 'abierta'")->fetchColumn();
    $alertasRecientes = $pdo->query(
        "SELECT a.id_alerta, a.nivel, a.tipo, a.creada_en, pr.razon_social, f.numero_factura
         FROM alertas a
         LEFT JOIN proveedores pr ON pr.id_proveedor = a.id_proveedor
         LEFT JOIN facturas f ON f.id_factura = a.id_factura
         WHERE a.estado = 'abierta'
         ORDER BY FIELD(a.nivel,'alto','medio','bajo'), a.creada_en DESC
         LIMIT 5"
    )->fetchAll();
}

$sqlPagos = "SELECT p.id_pago, p.estado, p.valor, p.solicitado_en,
                    f.numero_factura, pr.razon_social
             FROM pagos p
             INNER JOIN facturas f ON f.id_factura = p.id_factura
             INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
             WHERE p.estado IN ('pendiente','bloqueado')";
if ($esEmpleado) $sqlPagos .= ' AND p.id_usuario_solicita = ' . $idUsuario;
$sqlPagos .= ' ORDER BY p.solicitado_en DESC, p.id_pago DESC LIMIT 5';
$pagosRecientes = $pdo->query($sqlPagos)->fetchAll();

$sqlAuditoria = "SELECT a.accion, a.entidad, a.detalle, a.fecha, u.nombre AS usuario
                 FROM auditoria a LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario";
if ($esEmpleado) $sqlAuditoria .= ' WHERE a.id_usuario = ' . $idUsuario;
$sqlAuditoria .= ' ORDER BY a.fecha DESC, a.id_auditoria DESC LIMIT 5';
$actividadReciente = $pdo->query($sqlAuditoria)->fetchAll();

$coloresEstado = ['registrada'=>'#6C757D','observada'=>'#B58100','aprobada'=>'#055160','rechazada'=>'#B02A37','pagada'=>'#198754'];
$denominadorFacturas = max(1, $totalFacturas);
$pageTitle = 'Dashboard';
$extraStyles = ['/assets/css/dashboard.css'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="container py-4 sp-dashboard">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <span class="text-primary fw-semibold small text-uppercase">Panel de control</span>
            <h1 class="h3 mb-1">Hola, <?= e((string) ($usuario['nombre'] ?? 'usuario')) ?></h1>
            <p class="text-muted mb-0"><?= $esEmpleado ? 'Resumen de tus facturas y solicitudes de pago.' : 'Resumen general de operaciones, riesgos y actividad.' ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/modules/facturas/form.php"><i class="bi bi-plus-lg me-1"></i>Nueva factura</a>
            <a class="btn btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/pagos/index.php"><i class="bi bi-cash-stack me-1"></i>Ver pagos</a>
        </div>
    </div>
    <?= htmlFlash($flash) ?>

    <div class="row g-3 mb-4">
        <?php if (!$esEmpleado): ?>
        <div class="col-sm-6 col-xl-3"><a class="card sp-kpi h-100 text-decoration-none text-reset" href="<?= e(BASE_URL) ?>/modules/alertas/index.php"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small">Alertas abiertas</div><div class="display-6 fw-bold <?= $alertasAbiertas ? 'text-danger' : '' ?>"><?= $alertasAbiertas ?></div></div><span class="sp-kpi-icon text-danger"><i class="bi bi-shield-exclamation"></i></span></div></a></div>
        <?php endif; ?>
        <div class="col-sm-6 col-xl-3"><a class="card sp-kpi h-100 text-decoration-none text-reset" href="<?= e(BASE_URL) ?>/modules/pagos/index.php?estado=pendiente"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small">Pagos pendientes</div><div class="display-6 fw-bold"><?= $conteosPagos['pendiente'] ?></div></div><span class="sp-kpi-icon"><i class="bi bi-hourglass-split"></i></span></div></a></div>
        <div class="col-sm-6 col-xl-3"><a class="card sp-kpi h-100 text-decoration-none text-reset" href="<?= e(BASE_URL) ?>/modules/pagos/index.php?estado=bloqueado"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small">Pagos bloqueados</div><div class="display-6 fw-bold text-danger"><?= $conteosPagos['bloqueado'] ?></div></div><span class="sp-kpi-icon text-danger"><i class="bi bi-lock"></i></span></div></a></div>
        <div class="col-sm-6 col-xl-3"><a class="card sp-kpi h-100 text-decoration-none text-reset" href="<?= e(BASE_URL) ?>/modules/facturas/index.php"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small"><?= $esEmpleado ? 'Mis facturas' : 'Total de facturas' ?></div><div class="display-6 fw-bold"><?= $totalFacturas ?></div></div><span class="sp-kpi-icon"><i class="bi bi-receipt"></i></span></div></a></div>
        <?php if ($esEmpleado): ?><div class="col-sm-6 col-xl-3"><div class="card sp-kpi h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small">Monto en proceso</div><div class="h4 fw-bold mb-0"><?= e(formatearMoneda($montoSolicitado)) ?></div></div><span class="sp-kpi-icon"><i class="bi bi-cash-stack"></i></span></div></div></div><?php endif; ?>
    </div>

    <div class="row g-4 mb-4">
        <?php if (!$esEmpleado): ?>
        <div class="col-xl-7"><section class="card h-100"><div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>Alertas recientes</strong><a class="small" href="<?= e(BASE_URL) ?>/modules/alertas/index.php">Ver todas</a></div>
            <?php if (!$alertasRecientes): ?><div class="sp-empty"><i class="bi bi-shield-check"></i><p class="mb-0">No hay alertas abiertas.</p></div><?php else: ?><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Nivel</th><th>Tipo</th><th>Proveedor</th><th>Fecha</th><th></th></tr></thead><tbody>
            <?php foreach ($alertasRecientes as $a): ?><tr><td><span class="badge <?= $a['nivel']==='alto'?'text-bg-danger':($a['nivel']==='medio'?'text-bg-warning':'text-bg-secondary') ?>"><?= e(ucfirst((string)$a['nivel'])) ?></span></td><td><?= e(ucfirst(str_replace('_',' ',(string)$a['tipo']))) ?></td><td><?= e((string)($a['razon_social'] ?? '—')) ?></td><td class="text-muted small"><?= e(formatearFecha((string)$a['creada_en'], true)) ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/modules/alertas/detalle.php?id=<?= (int)$a['id_alerta'] ?>">Ver</a></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?></section></div>
        <?php endif; ?>
        <div class="<?= $esEmpleado ? 'col-12' : 'col-xl-5' ?>"><section class="card h-100"><div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>Pagos pendientes</strong><a class="small" href="<?= e(BASE_URL) ?>/modules/pagos/index.php">Ver todos</a></div>
            <?php if (!$pagosRecientes): ?><div class="sp-empty"><i class="bi bi-check-circle"></i><p class="mb-0">No hay pagos pendientes o bloqueados.</p></div><?php else: ?><div class="list-group list-group-flush">
            <?php foreach ($pagosRecientes as $p): ?><a class="list-group-item list-group-item-action py-3" href="<?= e(BASE_URL) ?>/modules/pagos/detalle.php?id=<?= (int)$p['id_pago'] ?>"><div class="d-flex justify-content-between gap-3"><div><strong><?= e((string)$p['numero_factura']) ?></strong><div class="small text-muted"><?= e((string)$p['razon_social']) ?></div><span class="badge <?= $p['estado']==='bloqueado'?'text-bg-danger':'text-bg-warning' ?> mt-1"><?= e(ucfirst((string)$p['estado'])) ?></span></div><strong class="text-nowrap"><?= e(formatearMoneda($p['valor'])) ?></strong></div></a><?php endforeach; ?>
            </div><?php endif; ?></section></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-5"><section class="card h-100"><div class="card-header bg-white"><strong>Facturas por estado</strong></div><div class="card-body">
            <div class="sp-distribucion mb-3" role="img" aria-label="Distribución de facturas por estado"><?php foreach ($coloresEstado as $estado=>$color): $cantidad=$conteosEstados[$estado]; if($cantidad>0): ?><span style="width:<?= number_format(($cantidad/$denominadorFacturas)*100,2,'.','') ?>%;background:<?= e($color) ?>" title="<?= e(ucfirst($estado)) ?>: <?= $cantidad ?>"></span><?php endif; endforeach; ?></div>
            <div class="d-flex flex-wrap gap-3"><?php foreach($coloresEstado as $estado=>$color): ?><span class="small d-flex align-items-center gap-2"><i class="sp-dot" style="background:<?= e($color) ?>"></i><?= e(ucfirst($estado)) ?>: <strong><?= $conteosEstados[$estado] ?></strong></span><?php endforeach; ?></div>
        </div></section></div>
        <div class="col-xl-7"><section class="card h-100"><div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>Actividad reciente</strong><?php if(!$esEmpleado): ?><a class="small" href="<?= e(BASE_URL) ?>/modules/auditoria/index.php">Ver auditoría</a><?php endif; ?></div>
            <?php if (!$actividadReciente): ?><div class="sp-empty"><i class="bi bi-clock-history"></i><p class="mb-0">Todavía no hay actividad registrada.</p></div><?php else: ?><ul class="sp-timeline list-unstyled mb-0"><?php foreach($actividadReciente as $ev): ?><li><div class="d-flex flex-column flex-sm-row justify-content-between gap-1"><strong class="small"><?= e((string)($ev['usuario'] ?? 'Sistema')) ?> <span class="text-muted fw-normal">· <?= e(ucfirst(str_replace('_',' ',(string)$ev['accion']))) ?></span></strong><span class="small text-muted"><?= e(formatearFecha((string)$ev['fecha'],true)) ?></span></div><div class="small text-muted"><?= e((string)($ev['detalle'] ?? ($ev['entidad'] ?? ''))) ?></div></li><?php endforeach; ?></ul><?php endif; ?>
        </section></div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
