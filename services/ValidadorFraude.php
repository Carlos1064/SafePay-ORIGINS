<?php
declare(strict_types=1);

/**
 * Motor de reglas de fraude de SafePay (Fase 6).
 *
 * El validador es independiente de la interfaz: recibe una factura o un pago,
 * consulta la base de datos y devuelve los hallazgos. Cada hallazgo incluye la
 * regla, el tipo y nivel de alerta, la descripción y las entidades a las que
 * debe asociarse. La persistencia se hace con `guardarHallazgos()`.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

final class ValidadorFraude
{
    private PDO $pdo;

    /** @var array<string, mixed> */
    private array $parametros;

    /**
     * @param array<string, mixed>|null $parametros Permite sobrescribir los
     *        valores de config/parametros.php, por ejemplo en una prueba.
     */
    public function __construct(PDO $pdo, ?array $parametros = null)
    {
        $this->pdo = $pdo;
        $base = defined('PARAMETROS') && is_array(PARAMETROS) ? PARAMETROS : [];
        $this->parametros = array_merge($base, $parametros ?? []);
    }

    /**
     * R1 · Factura duplicada exacta por proveedor y número.
     *
     * Se evalúa antes de insertar. Devuelve el hallazgo con la factura
     * existente para no crear una segunda factura.
     *
     * @return array<string, mixed>|null
     */
    public function evaluarDuplicado(
        int $idProveedor,
        string $numeroFactura,
        ?int $excluirFactura = null
    ): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT f.id_factura, f.numero_factura, f.valor, f.fecha_emision,
                    pr.razon_social
             FROM facturas f
             INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
             WHERE f.id_proveedor = :id_proveedor
               AND f.numero_factura = :numero_factura
               AND f.id_factura <> :id_excluir
             LIMIT 1'
        );
        $stmt->execute([
            ':id_proveedor'   => $idProveedor,
            ':numero_factura' => $numeroFactura,
            ':id_excluir'     => $excluirFactura ?? 0,
        ]);
        $existente = $stmt->fetch();

        if (!$existente) {
            return null;
        }

        return $this->hallazgo(
            'R1',
            'factura_duplicada',
            'alto',
            'Intento de registrar de nuevo la factura ' . $numeroFactura
                . ' del proveedor ' . $existente['razon_social']
                . '. El registro existente conserva el valor de '
                . formatearMoneda($existente['valor']) . '.',
            [
                'id_factura'         => (int) $existente['id_factura'],
                'id_proveedor'       => $idProveedor,
                'id_factura_existente' => (int) $existente['id_factura'],
                'valor_existente'    => (string) $existente['valor'],
            ]
        );
    }

    /**
     * Evalúa las reglas de una factura ya persistida.
     *
     * Cubre R2 (duplicado por contenido), R5 (valor inusual), R6 (proveedor
     * inactivo) y R7 (fecha incoherente). R1 se evalúa antes de insertar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluarFactura(int $idFactura): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, pr.estado AS proveedor_estado, pr.razon_social
             FROM facturas f
             INNER JOIN proveedores pr ON pr.id_proveedor = f.id_proveedor
             WHERE f.id_factura = :id_factura
             LIMIT 1'
        );
        $stmt->execute([':id_factura' => $idFactura]);
        $factura = $stmt->fetch();

        if (!$factura) {
            return [];
        }

        $hallazgos = [];
        $evaluaciones = [
            $this->reglaDuplicadoContenido($factura),
            $this->reglaValorInusual($factura),
            $this->reglaProveedorInactivo($factura),
            $this->reglaFechaIncoherente($factura),
        ];

        foreach ($evaluaciones as $hallazgo) {
            if (is_array($hallazgo)) {
                $hallazgos[] = $hallazgo;
            }
        }

        return $hallazgos;
    }

    /**
     * Evalúa las reglas de cuenta destino de un pago ya persistido.
     *
     * Cubre R3 (cuenta distinta a la principal) y R4 (cuenta no verificada).
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluarPago(int $idPago): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id_pago, p.id_factura, p.id_cuenta_destino,
                    f.id_proveedor, f.numero_factura,
                    c.banco, c.numero_cuenta, c.es_principal, c.verificada,
                    c.creada_en, c.modificada_en
             FROM pagos p
             INNER JOIN facturas f ON f.id_factura = p.id_factura
             INNER JOIN cuentas_bancarias c ON c.id_cuenta = p.id_cuenta_destino
             WHERE p.id_pago = :id_pago
             LIMIT 1'
        );
        $stmt->execute([':id_pago' => $idPago]);
        $pago = $stmt->fetch();

        if (!$pago) {
            return [];
        }

        $hallazgos = [];
        $contextoPago = [
            'id_factura'   => (int) $pago['id_factura'],
            'id_pago'      => (int) $pago['id_pago'],
            'id_proveedor' => (int) $pago['id_proveedor'],
            'id_cuenta'    => (int) $pago['id_cuenta_destino'],
        ];
        $cuenta = $pago['banco'] . ' ' . $this->enmascararCuenta((string) $pago['numero_cuenta']);

        if ((int) $pago['es_principal'] !== 1) {
            $hallazgos[] = $this->hallazgo(
                'R3',
                'cuenta_diferente',
                'alto',
                'La cuenta destino (' . $cuenta . ') no es la cuenta principal del proveedor.',
                $contextoPago
            );
        }

        if ((int) $pago['verificada'] !== 1) {
            $dias = $this->diasDesdeCuenta($pago);
            $umbral = $this->entero('cuenta_reciente_dias', 30, 0);
            $hace = match (true) {
                $dias === 0 => 'hoy',
                $dias === 1 => 'hace 1 día',
                default     => 'hace ' . $dias . ' días',
            };

            $descripcion = 'La cuenta destino (' . $cuenta . ') no está verificada; '
                . 'fue creada o modificada ' . $hace . '.';
            if ($dias <= $umbral) {
                $descripcion .= ' Está dentro de la ventana de ' . $umbral . ' días.';
            } else {
                $descripcion .= ' Supera la ventana de ' . $umbral
                    . ' días, pero se mantiene el bloqueo por falta de verificación.';
            }

            $hallazgos[] = $this->hallazgo(
                'R4',
                'cuenta_modificada_reciente',
                'alto',
                $descripcion,
                $contextoPago
            );
        }

        return $hallazgos;
    }

    /**
     * R2 · Mismo proveedor, mismo valor y fecha de emisión cercana.
     *
     * @param array<string, mixed> $factura
     * @return array<string, mixed>|null
     */
    private function reglaDuplicadoContenido(array $factura): ?array
    {
        $dias = $this->entero('duplicado_valor_dias', 7, 0);

        $stmt = $this->pdo->prepare(
            'SELECT numero_factura, fecha_emision, valor
             FROM facturas
             WHERE id_proveedor = :id_proveedor
               AND id_factura <> :id_factura
               AND numero_factura <> :numero_factura
               AND valor = :valor
               AND estado <> \'rechazada\'
               AND ABS(DATEDIFF(fecha_emision, :fecha_base)) <= :dias
             LIMIT 1'
        );
        $stmt->execute([
            ':id_proveedor'   => (int) $factura['id_proveedor'],
            ':id_factura'     => (int) $factura['id_factura'],
            ':numero_factura' => (string) $factura['numero_factura'],
            ':valor'          => (string) $factura['valor'],
            ':fecha_base'     => (string) $factura['fecha_emision'],
            ':dias'           => $dias,
        ]);
        $similar = $stmt->fetch();

        if (!$similar) {
            return null;
        }

        return $this->hallazgo(
            'R2',
            'factura_duplicada',
            'medio',
            'Posible duplicado por contenido: la factura ' . $factura['numero_factura']
                . ' del proveedor ' . $factura['razon_social']
                . ' por ' . formatearMoneda($factura['valor'])
                . ' coincide en valor con la factura ' . $similar['numero_factura']
                . ' (emitida el ' . formatearFecha((string) $similar['fecha_emision'])
                . '), dentro de ±' . $dias . ' días.',
            [
                'id_factura'   => (int) $factura['id_factura'],
                'id_proveedor' => (int) $factura['id_proveedor'],
            ]
        );
    }

    /**
     * R5 · Valor superior al promedio histórico del proveedor.
     *
     * @param array<string, mixed> $factura
     * @return array<string, mixed>|null
     */
    private function reglaValorInusual(array $factura): ?array
    {
        $minimo = $this->entero('min_facturas_historial', 5);
        $factor = $this->decimal('factor_valor_inusual', 3.0);

        $stmt = $this->pdo->prepare(
            'SELECT resumen.total, resumen.promedio,
                    CASE
                        WHEN resumen.total >= :min_historial
                         AND resumen.promedio > 0
                         AND :valor_actual > (resumen.promedio * :factor)
                        THEN 1 ELSE 0
                    END AS supera
             FROM (
                 SELECT COUNT(*) AS total, AVG(valor) AS promedio
                 FROM facturas
                 WHERE id_proveedor = :id_proveedor
                   AND id_factura <> :id_factura
                   AND estado <> \'rechazada\'
             ) AS resumen'
        );
        $stmt->execute([
            ':min_historial' => $minimo,
            ':valor_actual'  => (string) $factura['valor'],
            ':factor'        => $factor,
            ':id_proveedor'  => (int) $factura['id_proveedor'],
            ':id_factura'    => (int) $factura['id_factura'],
        ]);
        $resumen = $stmt->fetch();

        if (!$resumen || (int) $resumen['supera'] !== 1) {
            return null;
        }

        return $this->hallazgo(
            'R5',
            'valor_inusual',
            'medio',
            'El valor de ' . formatearMoneda($factura['valor'])
                . ' supera ' . number_format($factor, 1, ',', '.') . '× el promedio histórico del proveedor ('
                . formatearMoneda($resumen['promedio']) . '), calculado sobre '
                . (int) $resumen['total'] . ' facturas no rechazadas (mínimo '
                . $minimo . ').',
            [
                'id_factura'   => (int) $factura['id_factura'],
                'id_proveedor' => (int) $factura['id_proveedor'],
            ]
        );
    }

    /**
     * R6 · Factura de un proveedor inactivo.
     *
     * La alerta se asocia al proveedor, no a una factura concreta, para no
     * confundirse con R7 (que también usa el tipo `otra`) y para bloquear
     * cualquier pago de ese proveedor mientras siga inactivo.
     *
     * @param array<string, mixed> $factura
     * @return array<string, mixed>|null
     */
    private function reglaProveedorInactivo(array $factura): ?array
    {
        if ((string) ($factura['proveedor_estado'] ?? '') !== 'inactivo') {
            return null;
        }

        return $this->hallazgo(
            'R6',
            'otra',
            'medio',
            'La factura ' . $factura['numero_factura'] . ' pertenece al proveedor inactivo '
                . $factura['razon_social'] . '.',
            [
                'id_proveedor' => (int) $factura['id_proveedor'],
            ]
        );
    }

    /**
     * R7 · Fecha de emisión futura o demasiado antigua.
     *
     * @param array<string, mixed> $factura
     * @return array<string, mixed>|null
     */
    private function reglaFechaIncoherente(array $factura): ?array
    {
        $textoFecha = (string) $factura['fecha_emision'];

        try {
            $fecha = new DateTimeImmutable($textoFecha);
            $hoy = new DateTimeImmutable('today');
        } catch (Throwable) {
            return null;
        }

        $dias = (int) $fecha->diff($hoy)->format('%r%a');
        $maximo = $this->entero('antiguedad_maxima_dias', 180, 0);
        $motivo = null;

        if ($dias < 0) {
            $motivo = 'La fecha de emisión (' . formatearFecha($textoFecha)
                . ') está en el futuro.';
        } elseif ($dias > $maximo) {
            $motivo = 'La fecha de emisión (' . formatearFecha($textoFecha)
                . ') tiene ' . $dias . ' días de antigüedad; el máximo configurado es '
                . $maximo . ' días.';
        }

        if ($motivo === null) {
            return null;
        }

        return $this->hallazgo(
            'R7',
            'otra',
            'bajo',
            $motivo,
            [
                'id_factura'   => (int) $factura['id_factura'],
                'id_proveedor' => (int) $factura['id_proveedor'],
            ]
        );
    }

    /**
     * Construye un hallazgo uniforme.
     *
     * @param array<string, mixed> $contexto
     * @return array<string, mixed>
     */
    private function hallazgo(
        string $regla,
        string $tipo,
        string $nivel,
        string $descripcion,
        array $contexto = []
    ): array {
        return array_merge([
            'regla'         => $regla,
            'tipo'          => $tipo,
            'nivel'         => $nivel,
            'descripcion'   => mb_substr(trim($descripcion), 0, 255),
            'id_factura'    => null,
            'id_pago'       => null,
            'id_proveedor'  => null,
            'id_cuenta'     => null,
        ], $contexto);
    }

    /** Días transcurridos desde la creación o última modificación de la cuenta. */
    private function diasDesdeCuenta(array $pago): int
    {
        $fecha = $pago['modificada_en'] ?: $pago['creada_en'];
        if ($fecha === null || $fecha === '') {
            return 0;
        }

        try {
            $origen = new DateTimeImmutable((string) $fecha);
            $ahora = new DateTimeImmutable('now');
            $dias = (int) $origen->diff($ahora)->format('%a');

            return max(0, $dias);
        } catch (Throwable) {
            return 0;
        }
    }

    /** Devuelve solo los últimos cuatro dígitos de una cuenta. */
    private function enmascararCuenta(string $numero): string
    {
        $digitos = preg_replace('/\D+/', '', $numero) ?? '';
        $ultimos = substr($digitos, -4);

        return '•••• •••• ' . ($ultimos !== '' ? $ultimos : '—');
    }

    private function entero(string $clave, int $porDefecto, int $minimo = 1): int
    {
        $valor = (int) ($this->parametros[$clave] ?? $porDefecto);

        return max($minimo, $valor);
    }

    private function decimal(string $clave, float $porDefecto): float
    {
        $valor = (float) ($this->parametros[$clave] ?? $porDefecto);

        return $valor > 0 ? $valor : $porDefecto;
    }
}
