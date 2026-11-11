<?php
declare(strict_types=1);

/**
 * Consultas compartidas por la vista y la exportación de reportes.
 */

require_once __DIR__ . '/../../includes/modules.php';

/** Lee y valida los filtros del reporte. */
function filtrosReporte(array $entrada): array
{
    $desde = trim((string) ($entrada['desde'] ?? ''));
    $hasta = trim((string) ($entrada['hasta'] ?? ''));

    if (!fechaIsoValida($desde)) {
        $desde = date('Y-m-01');
    }
    if (!fechaIsoValida($hasta)) {
        $hasta = date('Y-m-d');
    }
    if ($desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    $tipo = (string) ($entrada['tipo'] ?? 'alertas');
    if (!in_array($tipo, ['alertas', 'pagos_bloqueados'], true)) {
        $tipo = 'alertas';
    }

    $nivel = (string) ($entrada['nivel'] ?? '');
    if (!in_array($nivel, ['', 'alto', 'medio', 'bajo'], true)) {
        $nivel = '';
    }

    return [
        'desde'     => $desde,
        'hasta'     => $hasta,
        'tipo'      => $tipo,
        'nivel'     => $nivel,
        'proveedor' => max(0, (int) ($entrada['proveedor'] ?? 0)),
    ];
}

/**
 * Devuelve las filas del reporte seleccionado.
 *
 * @return array<int, array<string, mixed>>
 */
function consultarReporte(PDO $pdo, array $filtros, ?int $limite = null): array
{
    $params = [
        ':desde' => $filtros['desde'],
        ':hasta' => $filtros['hasta'],
    ];
    $where = ['DATE(a.creada_en) BETWEEN :desde AND :hasta'];
    $joins = '';

    if ($filtros['nivel'] !== '') {
        $where[] = 'a.nivel = :nivel';
        $params[':nivel'] = $filtros['nivel'];
    }
    if ($filtros['proveedor'] > 0) {
        $where[] = 'COALESCE(a.id_proveedor, f.id_proveedor, fp.id_proveedor) = :id_proveedor';
        $params[':id_proveedor'] = $filtros['proveedor'];
    }

    if ($filtros['tipo'] === 'alertas') {
        $sql = "SELECT a.id_alerta, a.creada_en AS fecha, a.tipo, a.nivel, a.estado,
                       a.descripcion, a.justificacion,
                       COALESCE(a.id_factura, p.id_factura) AS id_factura,
                       COALESCE(f.numero_factura, fp.numero_factura) AS numero_factura,
                       COALESCE(a.id_pago, p.id_pago) AS id_pago,
                       COALESCE(a.id_proveedor, f.id_proveedor, fp.id_proveedor) AS id_proveedor,
                       COALESCE(pr.razon_social, prf.razon_social, prp.razon_social) AS razon_social,
                       COALESCE(f.valor, p.valor, 0) AS valor,
                       u.nombre AS responsable
                FROM alertas a
                LEFT JOIN facturas f ON f.id_factura = a.id_factura
                LEFT JOIN proveedores pr ON pr.id_proveedor = a.id_proveedor
                LEFT JOIN proveedores prf ON prf.id_proveedor = f.id_proveedor
                LEFT JOIN pagos p ON p.id_pago = a.id_pago
                LEFT JOIN facturas fp ON fp.id_factura = p.id_factura
                LEFT JOIN proveedores prp ON prp.id_proveedor = fp.id_proveedor
                LEFT JOIN usuarios u ON u.id_usuario = a.id_usuario_resuelve
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.creada_en DESC, a.id_alerta DESC";

        if ($limite !== null) {
            $sql .= ' LIMIT ' . max(1, $limite);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $filas = $stmt->fetchAll();

        return array_map(static function (array $fila): array {
            $fila['origen'] = 'Alerta';
            $fila['tipo_legible'] = etiquetaTipoAlerta((string) $fila['tipo']);
            $fila['url'] = BASE_URL . '/modules/alertas/detalle.php?id=' . (int) $fila['id_alerta'];
            return $fila;
        }, $filas);
    }

    $params = [
        ':desde' => $filtros['desde'],
        ':hasta' => $filtros['hasta'],
    ];
    $where = [
        "p.estado = 'bloqueado'",
        'DATE(p.solicitado_en) BETWEEN :desde AND :hasta',
    ];
    if ($filtros['proveedor'] > 0) {
        $where[] = 'f.id_proveedor = :id_proveedor';
        $params[':id_proveedor'] = $filtros['proveedor'];
    }

    $sql = "SELECT p.id_pago, p.solicitado_en AS fecha, p.valor, p.estado,
                   f.id_factura, f.numero_factura, f.id_proveedor,
                   pr.razon_social,
                   us.nombre AS solicitante,
                   COALESCE((
                       SELECT MAX(CASE a.nivel WHEN 'alto' THEN 3 WHEN 'medio' THEN 2 ELSE 1 END)
                       FROM alertas a
                       WHERE a.estado = 'abierta'
                         AND (a.id_pago = p.id_pago
                              OR a.id_factura = p.id_factura
                              OR a.id_proveedor = f.id_proveedor
                              OR a.id_cuenta = p.id_cuenta_destino)
                   ), 0) AS riesgo_score
            FROM pagos p
            INNER JOIN facturas f ON f.id_factura = p.id_factura
            INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
            INNER JOIN usuarios us ON us.id_usuario = p.id_usuario_solicita
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.solicitado_en DESC, p.id_pago DESC";

    if ($limite !== null) {
        $sql .= ' LIMIT ' . max(1, $limite);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll();

    $niveles = [3 => 'alto', 2 => 'medio', 1 => 'bajo'];
    $resultado = [];
    foreach ($filas as $fila) {
        $nivel = $niveles[(int) $fila['riesgo_score']] ?? null;
        if ($filtros['nivel'] !== '' && $nivel !== $filtros['nivel']) {
            continue;
        }
        $fila['origen'] = 'Pago bloqueado';
        $fila['tipo'] = null;
        $fila['tipo_legible'] = 'Pago bloqueado';
        $fila['nivel'] = $nivel;
        $fila['descripcion'] = '';
        $fila['justificacion'] = null;
        $fila['responsable'] = null;
        $fila['url'] = BASE_URL . '/modules/pagos/detalle.php?id=' . (int) $fila['id_pago'];
        $resultado[] = $fila;
    }

    return $resultado;
}

/**
 * Resumen del período y los filtros seleccionados.
 *
 * @return array{alertas: int, proveedores: int, monto_alertas: float, pagos_bloqueados: int, monto_bloqueado: float}
 */
function resumenReporte(PDO $pdo, array $filtros): array
{
    $where = ['DATE(a.creada_en) BETWEEN :desde AND :hasta'];
    $params = [
        ':desde' => $filtros['desde'],
        ':hasta' => $filtros['hasta'],
    ];

    if ($filtros['nivel'] !== '') {
        $where[] = 'a.nivel = :nivel';
        $params[':nivel'] = $filtros['nivel'];
    }
    if ($filtros['proveedor'] > 0) {
        $where[] = 'COALESCE(a.id_proveedor, f.id_proveedor, fp.id_proveedor) = :id_proveedor';
        $params[':id_proveedor'] = $filtros['proveedor'];
    }

    $joins = 'LEFT JOIN facturas f ON f.id_factura = a.id_factura
              LEFT JOIN pagos p ON p.id_pago = a.id_pago
              LEFT JOIN facturas fp ON fp.id_factura = p.id_factura';
    $sqlWhere = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM alertas a $joins WHERE $sqlWhere");
    $stmt->execute($params);
    $totalAlertas = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(t.valor), 0)
         FROM (
             SELECT DISTINCT COALESCE(a.id_factura, p.id_factura) AS id_factura,
                    COALESCE(f.valor, p.valor, 0) AS valor
             FROM alertas a
             $joins
             WHERE $sqlWhere
         ) t"
    );
    $stmt->execute($params);
    $montoAlertas = (float) $stmt->fetchColumn();

    $paramsBloqueados = [
        ':desde' => $filtros['desde'],
        ':hasta' => $filtros['hasta'],
    ];
    $whereBloqueados = "p.estado = 'bloqueado' AND DATE(p.solicitado_en) BETWEEN :desde AND :hasta";
    if ($filtros['proveedor'] > 0) {
        $whereBloqueados .= ' AND f.id_proveedor = :id_proveedor';
        $paramsBloqueados[':id_proveedor'] = $filtros['proveedor'];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*), COALESCE(SUM(p.valor), 0)
         FROM pagos p
         INNER JOIN facturas f ON f.id_factura = p.id_factura
         WHERE $whereBloqueados"
    );
    $stmt->execute($paramsBloqueados);
    [$totalBloqueados, $montoBloqueado] = $stmt->fetch(PDO::FETCH_NUM);

    $stmt = $pdo->prepare(
        "SELECT DISTINCT COALESCE(a.id_proveedor, f.id_proveedor, fp.id_proveedor)
         FROM alertas a
         $joins
         WHERE $sqlWhere
           AND COALESCE(a.id_proveedor, f.id_proveedor, fp.id_proveedor) IS NOT NULL"
    );
    $stmt->execute($params);
    $proveedoresAlerta = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare(
        "SELECT DISTINCT f.id_proveedor
         FROM pagos p
         INNER JOIN facturas f ON f.id_factura = p.id_factura
         WHERE $whereBloqueados"
    );
    $stmt->execute($paramsBloqueados);
    $proveedoresBloqueados = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $proveedores = count(array_unique(array_merge($proveedoresAlerta, $proveedoresBloqueados)));

    return [
        'alertas'           => $totalAlertas,
        'proveedores'       => $proveedores,
        'monto_alertas'     => $montoAlertas,
        'pagos_bloqueados'  => (int) $totalBloqueados,
        'monto_bloqueado'   => (float) $montoBloqueado,
    ];
}
