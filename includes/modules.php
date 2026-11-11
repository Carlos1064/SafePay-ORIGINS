<?php
declare(strict_types=1);

/**
 * Helpers compartidos por los módulos funcionales (Fases 5 y 6).
 *
 * Aquí viven las etiquetas de estado, el semáforo de riesgo, las alertas
 * relacionadas y los mensajes flash. Las consultas concretas permanecen en
 * cada módulo para que sean fáciles de revisar.
 */

require_once __DIR__ . '/auth.php';

/** Devuelve la conexión PDO compartida del proyecto. */
function conexionBd(): PDO
{
    static $conexion = null;

    if ($conexion instanceof PDO) {
        return $conexion;
    }

    require __DIR__ . '/../config/db.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('No se pudo inicializar la conexión a MySQL.');
    }

    return $conexion = $pdo;
}

/** Etiqueta visible de un estado de factura, pago, alerta o usuario. */
function etiquetaEstado(string $estado): string
{
    return match ($estado) {
        'registrada'  => 'Registrada',
        'observada'   => 'Observada',
        'aprobada'    => 'Aprobada',
        'rechazada'   => 'Rechazada',
        'pagada'      => 'Pagada',
        'pendiente'   => 'Pendiente',
        'bloqueado'   => 'Bloqueado',
        'aprobado'    => 'Aprobado',
        'rechazado'   => 'Rechazado',
        'ejecutado'   => 'Ejecutado',
        'abierta'     => 'Abierta',
        'resuelta'    => 'Resuelta',
        'descartada'  => 'Descartada',
        'activo'      => 'Activo',
        'inactivo'    => 'Inactivo',
        default       => ucfirst($estado),
    };
}

/** Clase Bootstrap para el badge de un estado. */
function claseEstado(string $estado): string
{
    return match ($estado) {
        'observada', 'pendiente' => 'text-bg-warning',
        'bloqueado', 'rechazada', 'rechazado', 'abierta' => 'text-bg-danger',
        'aprobada', 'aprobado' => 'text-bg-info',
        'pagada', 'ejecutado', 'resuelta', 'activo' => 'text-bg-success',
        'descartada', 'inactivo' => 'text-bg-secondary',
        default => 'text-bg-light border',
    };
}

/** Badge HTML de estado. */
function badgeEstado(string $estado): string
{
    return '<span class="badge ' . e(claseEstado($estado)) . '">' . e(etiquetaEstado($estado)) . '</span>';
}

/** Badge HTML del rol. */
function badgeRol(string $rol): string
{
    $clase = match ($rol) {
        'administrador' => 'text-bg-primary',
        'financiero'    => 'text-bg-info',
        default          => 'text-bg-light border',
    };

    return '<span class="badge ' . $clase . '">' . e(etiquetaRol($rol)) . '</span>';
}

/** Etiqueta visible del nivel de riesgo. */
function etiquetaNivel(string $nivel): string
{
    return match ($nivel) {
        'alto'  => 'Riesgo alto',
        'medio' => 'Riesgo medio',
        'bajo'  => 'Riesgo bajo',
        default => 'Sin alertas',
    };
}

/** Indicador de riesgo con color, punto e icono textual (no solo color). */
function badgeRiesgo(?string $nivel): string
{
    if ($nivel === null || $nivel === '') {
        return '<span class="badge-risk badge-risk--sin"><span class="dot"></span>Sin alertas</span>';
    }

    if (!in_array($nivel, ['alto', 'medio', 'bajo'], true)) {
        return '<span class="badge-risk badge-risk--sin"><span class="dot"></span>Sin alertas</span>';
    }

    return '<span class="badge-risk badge-risk--' . e($nivel) . '"><span class="dot"></span>'
        . e(etiquetaNivel($nivel)) . '</span>';
}

/** Devuelve el nivel más alto de una lista de niveles. */
function nivelMasAlto(array $niveles): ?string
{
    $orden = ['alto' => 3, 'medio' => 2, 'bajo' => 1];
    $mayor = null;
    $puntaje = 0;

    foreach ($niveles as $nivel) {
        $valor = $orden[$nivel] ?? 0;
        if ($valor > $puntaje) {
            $puntaje = $valor;
            $mayor = $nivel;
        }
    }

    return $mayor;
}

/** Nombre legible del tipo de alerta. */
function etiquetaTipoAlerta(string $tipo): string
{
    return match ($tipo) {
        'factura_duplicada'          => 'Factura duplicada',
        'cuenta_diferente'           => 'Cuenta diferente',
        'cuenta_modificada_reciente' => 'Cuenta modificada recientemente',
        'valor_inusual'              => 'Valor inusual',
        'otra'                       => 'Otra situación',
        default                      => ucfirst(str_replace('_', ' ', $tipo)),
    };
}

/** Muestra solo los últimos cuatro dígitos de una cuenta. */
function enmascararCuenta(string $numero): string
{
    $digitos = preg_replace('/\D+/', '', $numero) ?? '';
    $ultimos = substr($digitos, -4);

    return '•••• •••• ' . ($ultimos !== '' ? $ultimos : '—');
}

/** Normaliza un NIT para almacenarlo como 900123456-7. */
function normalizarNit(string $nit): string
{
    $limpio = preg_replace('/[.\s-]+/', '', trim($nit)) ?? '';

    if (!preg_match('/^\d{6,13}$/', $limpio)) {
        return '';
    }

    $cuerpo = substr($limpio, 0, -1);
    $verificador = substr($limpio, -1);

    return $cuerpo . '-' . $verificador;
}

/** Presenta el NIT con puntos cuando tiene el formato esperado. */
function formatearNit(string $nit): string
{
    if (preg_match('/^(\d{3})(\d{3})(\d{3})-(\d)$/', $nit, $partes) === 1) {
        return $partes[1] . '.' . $partes[2] . '.' . $partes[3] . '-' . $partes[4];
    }

    return $nit;
}

/** Guarda un mensaje flash para mostrarlo después de una redirección. */
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'] = [
        'tipo'    => in_array($tipo, ['success', 'danger', 'warning', 'info'], true) ? $tipo : 'info',
        'mensaje' => $mensaje,
    ];
}

/** Recupera y elimina el mensaje flash pendiente. */
function consumirFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

/** Redirige con un mensaje flash. */
function redirigirConFlash(string $ruta, string $tipo, string $mensaje): never
{
    flash($tipo, $mensaje);
    redirigir($ruta);
}

/** HTML de un mensaje flash. */
function htmlFlash(?array $flash): string
{
    if ($flash === null || empty($flash['mensaje'])) {
        return '';
    }

    $tipo = in_array($flash['tipo'] ?? '', ['success', 'danger', 'warning', 'info'], true)
        ? $flash['tipo']
        : 'info';

    return '<div class="alert alert-' . e($tipo) . ' alert-dismissible fade show" role="'
        . ($tipo === 'danger' ? 'alert' : 'status') . '">'
        . '<i class="bi ' . ($tipo === 'danger' ? 'bi-exclamation-triangle' : 'bi-check-circle')
        . ' me-1" aria-hidden="true"></i>' . e((string) $flash['mensaje'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>'
        . '</div>';
}

/** Redirige a la pantalla 403 y deja el intento en auditoría. */
function denegarAcceso(string $detalle = ''): never
{
    $destino = rutaActualRelativa();

    try {
        $pdo = conexionBd();
        $usuario = usuarioActual();
        registrarAuditoria(
            $pdo,
            $usuario !== null ? (int) $usuario['id'] : null,
            'acceso_denegado',
            'usuarios',
            $usuario !== null ? (int) $usuario['id'] : null,
            $detalle !== '' ? $detalle : 'Intento de acceso a ' . $destino . '.'
        );
    } catch (Throwable $error) {
        error_log('SafePay acceso denegado: ' . $error->getMessage());
    }

    redirigir('/auth/403.php?from=' . rawurlencode($destino));
}

/** Página actual de un listado. */
function paginaActual(): int
{
    return max(1, (int) ($_GET['pagina'] ?? 1));
}

/** Total de páginas de un listado. */
function totalPaginas(int $total, int $porPagina): int
{
    return max(1, (int) ceil($total / max(1, $porPagina)));
}

/** Valida una fecha en formato YYYY-MM-DD. */
function fechaIsoValida(string $fecha): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return false;
    }

    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

    return $objeto !== false && $objeto->format('Y-m-d') === $fecha;
}

/**
 * Alertas abiertas relacionadas con una factura, proveedor, pago y cuenta.
 *
 * @return array<int, array<string, mixed>>
 */
function alertasAbiertasRelacionadas(
    PDO $pdo,
    int $idFactura,
    ?int $idPago,
    int $idProveedor,
    ?int $idCuenta
): array {
    $sql = "SELECT a.*, u.nombre AS responsable
            FROM alertas a
            LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario_resuelve
            WHERE a.estado = 'abierta'
              AND (a.id_factura = :id_factura
                   OR a.id_pago <=> :id_pago
                   OR a.id_proveedor = :id_proveedor";
    $params = [
        ':id_factura'   => $idFactura,
        ':id_pago'      => $idPago,
        ':id_proveedor' => $idProveedor,
    ];

    if ($idCuenta !== null) {
        $sql .= ' OR a.id_cuenta = :id_cuenta';
        $params[':id_cuenta'] = $idCuenta;
    }

    $sql .= ") ORDER BY FIELD(a.nivel, 'alto', 'medio', 'bajo'), a.creada_en DESC, a.id_alerta DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Crea una alerta o actualiza la existente si ya hay una abierta equivalente.
 * R4 se deduplica por cuenta; el resto, por tipo y entidades asociadas.
 * Debe llamarse dentro de la transacción de la operación que la origina.
 */
function crearAlerta(
    PDO $pdo,
    ?int $idFactura,
    ?int $idPago,
    ?int $idProveedor,
    ?int $idCuenta,
    string $tipo,
    string $nivel,
    string $descripcion
): ?int {
    $tipos = ['factura_duplicada', 'cuenta_diferente', 'cuenta_modificada_reciente', 'valor_inusual', 'otra'];
    $niveles = ['bajo', 'medio', 'alto'];

    if (!in_array($tipo, $tipos, true) || !in_array($nivel, $niveles, true)) {
        return null;
    }

    if ($idFactura === null && $idPago === null && $idProveedor === null && $idCuenta === null) {
        return null;
    }

    $descripcion = mb_substr(trim($descripcion), 0, 255);

    if ($tipo === 'cuenta_modificada_reciente' && $idCuenta !== null) {
        // R4 identifica el riesgo por cuenta: la alerta creada al registrar una
        // cuenta nueva y la generada al solicitar el pago no deben duplicarse.
        $buscar = $pdo->prepare(
            "SELECT id_alerta, descripcion, nivel
             FROM alertas
             WHERE estado = 'abierta'
               AND tipo = :tipo
               AND id_cuenta = :id_cuenta
             LIMIT 1"
        );
        $buscar->execute([
            ':tipo'      => $tipo,
            ':id_cuenta' => $idCuenta,
        ]);
    } else {
        $buscar = $pdo->prepare(
            "SELECT id_alerta, descripcion, nivel
             FROM alertas
             WHERE estado = 'abierta'
               AND tipo = :tipo
               AND id_factura <=> :id_factura
               AND id_pago <=> :id_pago
               AND id_proveedor <=> :id_proveedor
               AND id_cuenta <=> :id_cuenta
             LIMIT 1"
        );
        $buscar->execute([
            ':tipo'         => $tipo,
            ':id_factura'   => $idFactura,
            ':id_pago'      => $idPago,
            ':id_proveedor' => $idProveedor,
            ':id_cuenta'    => $idCuenta,
        ]);
    }

    $existente = $buscar->fetch();

    if ($existente) {
        // Idempotencia: si la regla se vuelve a evaluar, se actualiza el texto
        // y el nivel sin abrir una alerta nueva.
        if ((string) $existente['descripcion'] !== $descripcion
            || (string) $existente['nivel'] !== $nivel) {
            $pdo->prepare(
                "UPDATE alertas
                 SET descripcion = :descripcion, nivel = :nivel
                 WHERE id_alerta = :id_alerta AND estado = 'abierta'"
            )->execute([
                ':descripcion' => $descripcion,
                ':nivel'       => $nivel,
                ':id_alerta'   => (int) $existente['id_alerta'],
            ]);
        }

        return (int) $existente['id_alerta'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO alertas
            (id_factura, id_pago, id_proveedor, id_cuenta, tipo, nivel, descripcion, estado)
         VALUES
            (:id_factura, :id_pago, :id_proveedor, :id_cuenta, :tipo, :nivel, :descripcion, \'abierta\')'
    );
    $stmt->execute([
        ':id_factura'   => $idFactura,
        ':id_pago'      => $idPago,
        ':id_proveedor' => $idProveedor,
        ':id_cuenta'    => $idCuenta,
        ':tipo'         => $tipo,
        ':nivel'        => $nivel,
        ':descripcion'  => $descripcion,
    ]);

    $idAlerta = (int) $pdo->lastInsertId();

    registrarAuditoria(
        $pdo,
        null,
        'alerta_generada',
        'alertas',
        $idAlerta,
        'Alerta ' . $tipo . ' (' . $nivel . '): ' . mb_substr($descripcion, 0, 140)
    );

    return $idAlerta;
}

/**
 * Persiste los hallazgos devueltos por ValidadorFraude.
 *
 * Cada hallazgo indica a qué entidades asociar la alerta. La función debe
 * ejecutarse dentro de la misma transacción de la operación validada.
 *
 * @param array<int, array<string, mixed>> $hallazgos
 * @return int Cantidad de hallazgos procesados.
 */
function guardarHallazgos(PDO $pdo, array $hallazgos): int
{
    $procesados = 0;

    foreach ($hallazgos as $hallazgo) {
        if (!is_array($hallazgo)
            || !isset($hallazgo['tipo'], $hallazgo['nivel'], $hallazgo['descripcion'])) {
            continue;
        }

        $idFactura = isset($hallazgo['id_factura']) && $hallazgo['id_factura'] !== null
            ? (int) $hallazgo['id_factura']
            : null;
        $idPago = isset($hallazgo['id_pago']) && $hallazgo['id_pago'] !== null
            ? (int) $hallazgo['id_pago']
            : null;
        $idProveedor = isset($hallazgo['id_proveedor']) && $hallazgo['id_proveedor'] !== null
            ? (int) $hallazgo['id_proveedor']
            : null;
        $idCuenta = isset($hallazgo['id_cuenta']) && $hallazgo['id_cuenta'] !== null
            ? (int) $hallazgo['id_cuenta']
            : null;

        crearAlerta(
            $pdo,
            $idFactura,
            $idPago,
            $idProveedor,
            $idCuenta,
            (string) $hallazgo['tipo'],
            (string) $hallazgo['nivel'],
            (string) $hallazgo['descripcion']
        );
        $procesados++;
    }

    return $procesados;
}

/**
 * Recalcula el estado de un pago y de su factura según las alertas abiertas.
 * No toca pagos ya aprobados, rechazados o ejecutados.
 */
function sincronizarEstados(PDO $pdo, int $idPago): void
{
    $stmt = $pdo->prepare(
        'SELECT p.id_pago, p.estado, p.id_factura, p.id_cuenta_destino,
                f.id_proveedor, f.estado AS estado_factura
         FROM pagos p
         INNER JOIN facturas f ON f.id_factura = p.id_factura
         WHERE p.id_pago = :id_pago
         LIMIT 1'
    );
    $stmt->execute([':id_pago' => $idPago]);
    $pago = $stmt->fetch();

    if (!$pago) {
        return;
    }

    $alertas = alertasAbiertasRelacionadas(
        $pdo,
        (int) $pago['id_factura'],
        (int) $pago['id_pago'],
        (int) $pago['id_proveedor'],
        (int) $pago['id_cuenta_destino']
    );

    $estadoPago = (string) $pago['estado'];
    if (in_array($estadoPago, ['pendiente', 'bloqueado'], true)) {
        $nuevoEstado = $alertas !== [] ? 'bloqueado' : 'pendiente';

        if ($nuevoEstado !== $estadoPago) {
            $pdo->prepare('UPDATE pagos SET estado = :estado WHERE id_pago = :id_pago')
                ->execute([':estado' => $nuevoEstado, ':id_pago' => $idPago]);
        }
    }

    $estadoFactura = (string) $pago['estado_factura'];
    if (!in_array($estadoFactura, ['registrada', 'observada'], true)) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT a.nivel
         FROM alertas a
         WHERE a.estado = 'abierta'
           AND (a.id_factura = :id_factura
                OR a.id_pago IN (SELECT p2.id_pago FROM pagos p2 WHERE p2.id_factura = :id_factura_pago))
         ORDER BY FIELD(a.nivel, 'alto', 'medio', 'bajo')"
    );
    $stmt->execute([
        ':id_factura'      => (int) $pago['id_factura'],
        ':id_factura_pago' => (int) $pago['id_factura'],
    ]);
    $nivelesFactura = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $nivelFactura = nivelMasAlto($nivelesFactura);

    $nuevoEstadoFactura = $estadoFactura;
    if ($nivelesFactura === [] && $estadoFactura === 'observada') {
        $nuevoEstadoFactura = 'registrada';
    } elseif ($nivelFactura === 'alto' && $estadoFactura === 'registrada') {
        $nuevoEstadoFactura = 'observada';
    }

    if ($nuevoEstadoFactura !== $estadoFactura) {
        $pdo->prepare('UPDATE facturas SET estado = :estado WHERE id_factura = :id_factura')
            ->execute([':estado' => $nuevoEstadoFactura, ':id_factura' => (int) $pago['id_factura']]);
    }
}

/**
 * Recalcula los pagos pendientes o bloqueados de un proveedor.
 *
 * Se usa cuando una alerta nueva afecta al proveedor (R1, R4 o R6) para que
 * los pagos que ya estaban en la bandeja reflejen el bloqueo de inmediato.
 */
function sincronizarPagosDeProveedor(PDO $pdo, int $idProveedor): void
{
    $stmt = $pdo->prepare(
        "SELECT p.id_pago
         FROM pagos p
         INNER JOIN facturas f ON f.id_factura = p.id_factura
         WHERE f.id_proveedor = :id_proveedor
           AND p.estado IN ('pendiente', 'bloqueado')
         ORDER BY p.id_pago"
    );
    $stmt->execute([':id_proveedor' => $idProveedor]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $idPago) {
        sincronizarEstados($pdo, (int) $idPago);
    }
}

/** Recalcula el estado de una factura según sus alertas abiertas. */
function sincronizarFactura(PDO $pdo, int $idFactura): void
{
    $stmt = $pdo->prepare('SELECT estado FROM facturas WHERE id_factura = :id LIMIT 1');
    $stmt->execute([':id' => $idFactura]);
    $estado = $stmt->fetchColumn();

    if ($estado === false || !in_array((string) $estado, ['registrada', 'observada'], true)) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT a.nivel
         FROM alertas a
         WHERE a.estado = 'abierta'
           AND (a.id_factura = :id_factura
                OR a.id_pago IN (SELECT p.id_pago FROM pagos p WHERE p.id_factura = :id_factura_pago))
         ORDER BY FIELD(a.nivel, 'alto', 'medio', 'bajo')"
    );
    $stmt->execute([
        ':id_factura'      => $idFactura,
        ':id_factura_pago' => $idFactura,
    ]);
    $niveles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $nivel = nivelMasAlto($niveles);
    $nuevoEstado = (string) $estado;

    if ($niveles === [] && $estado === 'observada') {
        $nuevoEstado = 'registrada';
    } elseif ($nivel === 'alto' && $estado === 'registrada') {
        $nuevoEstado = 'observada';
    }

    if ($nuevoEstado !== $estado) {
        $pdo->prepare('UPDATE facturas SET estado = :estado WHERE id_factura = :id')
            ->execute([':estado' => $nuevoEstado, ':id' => $idFactura]);
    }
}

/** Alertas asociadas a una factura (directas, de su pago, de su cuenta o del proveedor). */
function alertasDeFactura(PDO $pdo, int $idFactura): array
{
    $stmt = $pdo->prepare(
        "SELECT a.*, u.nombre AS responsable
         FROM alertas a
         LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario_resuelve
         WHERE a.id_factura = :id_factura
            OR a.id_pago IN (SELECT p.id_pago FROM pagos p WHERE p.id_factura = :id_factura_pago)
            OR EXISTS (
                SELECT 1
                FROM pagos p2
                WHERE p2.id_factura = :id_factura_cuenta
                  AND p2.id_cuenta_destino = a.id_cuenta
            )
            OR (
                a.id_factura IS NULL
                AND a.id_pago IS NULL
                AND a.id_cuenta IS NULL
                AND a.id_proveedor = (
                    SELECT f.id_proveedor FROM facturas f WHERE f.id_factura = :id_factura_proveedor
                )
            )
         ORDER BY FIELD(a.estado, 'abierta', 'resuelta', 'descartada'),
                  FIELD(a.nivel, 'alto', 'medio', 'bajo'),
                  a.creada_en DESC"
    );
    $stmt->execute([
        ':id_factura'           => $idFactura,
        ':id_factura_pago'      => $idFactura,
        ':id_factura_cuenta'    => $idFactura,
        ':id_factura_proveedor' => $idFactura,
    ]);

    return $stmt->fetchAll();
}

/** Alertas asociadas a un pago (pago, factura, proveedor y cuenta). */
function alertasDePago(
    PDO $pdo,
    int $idPago,
    int $idFactura,
    int $idProveedor,
    ?int $idCuenta
): array {
    $sql = "SELECT a.*, u.nombre AS responsable
            FROM alertas a
            LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario_resuelve
            WHERE a.id_pago = :id_pago
               OR a.id_factura = :id_factura
               OR a.id_proveedor = :id_proveedor";
    $params = [
        ':id_pago'      => $idPago,
        ':id_factura'   => $idFactura,
        ':id_proveedor' => $idProveedor,
    ];

    if ($idCuenta !== null) {
        $sql .= ' OR a.id_cuenta = :id_cuenta';
        $params[':id_cuenta'] = $idCuenta;
    }

    $sql .= " ORDER BY FIELD(a.estado, 'abierta', 'resuelta', 'descartada'),
                     FIELD(a.nivel, 'alto', 'medio', 'bajo'),
                     a.creada_en DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** Verifica que una cuenta pertenezca al proveedor. */
function cuentaDelProveedor(PDO $pdo, int $idCuenta, int $idProveedor): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM cuentas_bancarias WHERE id_cuenta = :id_cuenta AND id_proveedor = :id_proveedor LIMIT 1'
    );
    $stmt->execute([':id_cuenta' => $idCuenta, ':id_proveedor' => $idProveedor]);
    $cuenta = $stmt->fetch();

    return $cuenta ?: null;
}

/** Etiqueta legible de una acción de auditoría. */
function etiquetaAccion(string $accion): string
{
    return match ($accion) {
        'login_exitoso', 'login' => 'Inicio de sesión',
        'login_fallido'          => 'Intento fallido',
        'logout'                 => 'Cierre de sesión',
        'acceso_denegado'        => 'Acceso denegado',
        'crear_proveedor'        => 'Crear proveedor',
        'editar_proveedor'       => 'Editar proveedor',
        'cambiar_cuenta'         => 'Cambiar cuenta',
        'crear_cuenta'           => 'Registrar cuenta',
        'verificar_cuenta'       => 'Verificar cuenta',
        'crear_factura'          => 'Registrar factura',
        'factura_duplicada_intento' => 'Intento de factura duplicada',
        'solicitar_pago'         => 'Solicitar pago',
        'aprobar_pago'           => 'Aprobar pago',
        'aprobacion_bloqueada'   => 'Aprobación bloqueada por alertas',
        'rechazar_pago'          => 'Rechazar pago',
        'ejecutar_pago'          => 'Marcar pago ejecutado',
        'autoaprobacion'         => 'Intento de autoaprobación',
        'resolver_alerta'        => 'Resolver alerta',
        'descartar_alerta'       => 'Descartar alerta',
        'alerta_generada'        => 'Alerta generada',
        'crear_usuario'          => 'Crear usuario',
        'editar_usuario'         => 'Editar usuario',
        'cambiar_estado_usuario' => 'Activar / inactivar usuario',
        'restablecer_password'   => 'Restablecer contraseña',
        'exportar_reporte'       => 'Exportar reporte',
        'consultar_auditoria'    => 'Consultar auditoría',
        'ver_pdf'                => 'Consultar PDF',
        'pago_desbloqueado'      => 'Pago desbloqueado',
        default                  => ucfirst(str_replace('_', ' ', $accion)),
    };
}

/** Cuenta regresiva compacta de días para mostrar en la interfaz. */
function diasDesde(?string $fecha): ?int
{
    if ($fecha === null || $fecha === '') {
        return null;
    }

    try {
        $origen = new DateTimeImmutable($fecha);
        $hoy = new DateTimeImmutable('today');
        $dias = (int) $origen->diff($hoy)->format('%r%a');

        return max(0, $dias);
    } catch (Throwable) {
        return null;
    }
}
