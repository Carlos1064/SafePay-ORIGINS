<?php
declare(strict_types=1);

/**
 * Parámetros configurables del motor de reglas y de la operación (Fase 6).
 *
 * Cambiar un valor aquí no requiere tocar el código de los módulos ni del
 * validador. El archivo se versiona porque no contiene credenciales.
 */

return [
    // R2 · Posible duplicado por contenido: ventana en días alrededor de la fecha.
    'duplicado_valor_dias' => 7,

    // R4 · Ventana para resaltar una cuenta recién creada o modificada.
    'cuenta_reciente_dias' => 30,

    // R5 · Valor inusual: múltiplo del promedio y mínimo de facturas históricas.
    'factor_valor_inusual' => 3.0,
    'min_facturas_historial' => 5,

    // R7 · Antigüedad máxima de una factura antes de generar alerta.
    'antiguedad_maxima_dias' => 180,

    // Validaciones de archivo y sesión.
    'pdf_max_mb' => 5,
    'sesion_inactividad_min' => 30,
    'login_intentos_max' => 5,
];
