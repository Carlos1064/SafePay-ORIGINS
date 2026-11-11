-- =====================================================================
-- SafePay - Datos de demostración para InfinityFree
-- Importar DESPUÉS de schema-infinityfree.sql, sobre la misma base.
-- No incluye USE: phpMyAdmin importa en la base seleccionada.
-- =====================================================================

SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE auditoria;
TRUNCATE TABLE alertas;
TRUNCATE TABLE aprobaciones;
TRUNCATE TABLE pagos;
TRUNCATE TABLE facturas;
TRUNCATE TABLE cuentas_bancarias;
TRUNCATE TABLE proveedores;
TRUNCATE TABLE usuarios;
TRUNCATE TABLE roles;
SET FOREIGN_KEY_CHECKS = 1;

START TRANSACTION;

-- ---------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------
INSERT INTO roles (id_rol, nombre, descripcion) VALUES
(1, 'empleado',      'Registra facturas y solicita pagos'),
(2, 'financiero',    'Aprueba o rechaza pagos y consulta reportes'),
(3, 'administrador', 'Gestiona usuarios, proveedores, cuentas y alertas');

-- ---------------------------------------------------------------------
-- Usuarios (contraseñas con password_hash / bcrypt)
-- ---------------------------------------------------------------------
INSERT INTO usuarios
    (id_usuario, nombre, correo, password_hash, id_rol, activo, intentos_fallidos, bloqueado_hasta, creado_en)
VALUES
(1, 'Carlos Ramírez', 'empleado@safepay.local',
 '$2y$10$7xlYix44HQT5nFdPcYD2R.HTzF5444Zu7Vk.HlDGGhwxWaoyJ3zIq',
 1, 1, 0, NULL, DATE_SUB(NOW(), INTERVAL 200 DAY)),
(2, 'Laura Gómez', 'financiero@safepay.local',
 '$2y$10$CYCE24RcCWV/FuFvJTpFqeHyLBBcLBPEZCb4qT2CpLTJDiETfQlQW',
 2, 1, 0, NULL, DATE_SUB(NOW(), INTERVAL 190 DAY)),
(3, 'Andrés Torres', 'admin@safepay.local',
 '$2y$10$zx.LDCLy7jMboPh2RC7sweINoPFf3Wc5DSd2Ivt0MIsA8HH5p6yze',
 3, 1, 0, NULL, DATE_SUB(NOW(), INTERVAL 180 DAY));

-- ---------------------------------------------------------------------
-- Proveedores
-- ---------------------------------------------------------------------
INSERT INTO proveedores
    (id_proveedor, nit, razon_social, correo, telefono, estado, creado_en)
VALUES
(1, '900123456-7', 'Distribuciones ABC SAS', 'compras@distribucionesabc.com.co', '6015550101', 'activo',   DATE_SUB(NOW(), INTERVAL 400 DAY)),
(2, '900234567-8', 'Suministros del Norte Ltda', 'pagos@suministrosnorte.com', '6015550202', 'activo',   DATE_SUB(NOW(), INTERVAL 380 DAY)),
(3, '900345678-9', 'Tecnología Andina SAS', 'facturacion@tecandina.com', '6015550303', 'activo',   DATE_SUB(NOW(), INTERVAL 350 DAY)),
(4, '900456789-0', 'Papelería Central', 'cartera@papeleriacentral.com', '6015550404', 'activo',   DATE_SUB(NOW(), INTERVAL 320 DAY)),
(5, '900567890-1', 'Servicios Logísticos SAS', 'cuentas@servlogisticos.com', '6015550505', 'activo',   DATE_SUB(NOW(), INTERVAL 300 DAY)),
(6, '900678901-2', 'Ferretería El Tornillo', 'ventas@eltornillo.com', '6015550606', 'inactivo', DATE_SUB(NOW(), INTERVAL 280 DAY));

-- ---------------------------------------------------------------------
-- Cuentas bancarias
--   Suministros del Norte: la cuenta principal (id 4) se creó hace
--   12 días y no está verificada → dispara R4.
--   Papelería Central conserva una cuenta secundaria verificada (id 7)
--   para demostrar R3.
-- ---------------------------------------------------------------------
INSERT INTO cuentas_bancarias
    (id_cuenta, id_proveedor, banco, tipo_cuenta, numero_cuenta,
     es_principal, verificada, id_usuario_verifica, verificada_en, creada_en, modificada_en)
VALUES
(1, 1, 'Bancolombia', 'ahorros', '123456784582', 1, 1, 3, DATE_SUB(NOW(), INTERVAL 700 DAY), DATE_SUB(NOW(), INTERVAL 700 DAY), DATE_SUB(NOW(), INTERVAL 700 DAY)),
(2, 1, 'Davivienda', 'corriente', '9876543210', 0, 1, 3, DATE_SUB(NOW(), INTERVAL 400 DAY), DATE_SUB(NOW(), INTERVAL 400 DAY), DATE_SUB(NOW(), INTERVAL 400 DAY)),
(3, 2, 'Banco de Bogotá', 'corriente', '4567891234', 0, 1, 3, DATE_SUB(NOW(), INTERVAL 500 DAY), DATE_SUB(NOW(), INTERVAL 500 DAY), DATE_SUB(NOW(), INTERVAL 500 DAY)),
(4, 2, 'Banco de Bogotá', 'ahorros', '4567899999', 1, 0, NULL, NULL, DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY)),
(5, 3, 'BBVA', 'ahorros', '3012345678', 1, 1, 3, DATE_SUB(NOW(), INTERVAL 300 DAY), DATE_SUB(NOW(), INTERVAL 300 DAY), DATE_SUB(NOW(), INTERVAL 300 DAY)),
(6, 4, 'Banco Popular', 'ahorros', '2200113344', 1, 1, 3, DATE_SUB(NOW(), INTERVAL 600 DAY), DATE_SUB(NOW(), INTERVAL 600 DAY), DATE_SUB(NOW(), INTERVAL 600 DAY)),
(7, 4, 'Banco Popular', 'ahorros', '2200113355', 0, 1, 3, DATE_SUB(NOW(), INTERVAL 350 DAY), DATE_SUB(NOW(), INTERVAL 350 DAY), DATE_SUB(NOW(), INTERVAL 350 DAY)),
(8, 5, 'Bancolombia', 'corriente', '5556667778', 1, 1, 3, DATE_SUB(NOW(), INTERVAL 450 DAY), DATE_SUB(NOW(), INTERVAL 450 DAY), DATE_SUB(NOW(), INTERVAL 450 DAY)),
(9, 6, 'Banco Agrario', 'ahorros', '3344556677', 1, 1, 3, DATE_SUB(NOW(), INTERVAL 800 DAY), DATE_SUB(NOW(), INTERVAL 800 DAY), DATE_SUB(NOW(), INTERVAL 800 DAY));

-- ---------------------------------------------------------------------
-- Facturas (20)
--   Historial de 5–6 meses. Distribuciones ABC acumula 6 facturas
--   previas, suficiente para probar R5 (valor inusual).
--   F-10 y F-16 quedan observadas por alertas.
-- ---------------------------------------------------------------------
INSERT INTO facturas
    (id_factura, id_proveedor, numero_factura, fecha_emision, valor,
     archivo_pdf, estado, id_usuario_registra, creada_en)
VALUES
( 1, 1, 'F-2026-001', DATE_SUB(CURDATE(), INTERVAL 160 DAY), 4500000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL 160 DAY)),
( 2, 1, 'F-2026-002', DATE_SUB(CURDATE(), INTERVAL 130 DAY), 5200000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL 130 DAY)),
( 3, 1, 'F-2026-003', DATE_SUB(CURDATE(), INTERVAL 100 DAY), 4800000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL 100 DAY)),
( 4, 1, 'F-2026-004', DATE_SUB(CURDATE(), INTERVAL  70 DAY), 5000000.00, NULL, 'aprobada',   1, DATE_SUB(NOW(), INTERVAL  70 DAY)),
( 5, 1, 'F-2026-005', DATE_SUB(CURDATE(), INTERVAL  30 DAY), 4600000.00, NULL, 'registrada', 1, DATE_SUB(NOW(), INTERVAL  30 DAY)),
( 6, 1, 'F-2026-006', DATE_SUB(CURDATE(), INTERVAL   8 DAY), 5300000.00, NULL, 'registrada', 1, DATE_SUB(NOW(), INTERVAL   8 DAY)),
( 7, 2, 'SN-8801',    DATE_SUB(CURDATE(), INTERVAL  90 DAY), 3200000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL  90 DAY)),
( 8, 2, 'SN-8802',    DATE_SUB(CURDATE(), INTERVAL  60 DAY), 3500000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL  60 DAY)),
( 9, 2, 'SN-8803',    DATE_SUB(CURDATE(), INTERVAL  35 DAY), 3100000.00, NULL, 'aprobada',   1, DATE_SUB(NOW(), INTERVAL  35 DAY)),
(10, 2, 'SN-8804',    DATE_SUB(CURDATE(), INTERVAL   5 DAY), 3400000.00, NULL, 'observada',  1, DATE_SUB(NOW(), INTERVAL   5 DAY)),
(11, 3, 'TA-4410',    DATE_SUB(CURDATE(), INTERVAL 120 DAY), 8000000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL 120 DAY)),
(12, 3, 'TA-4411',    DATE_SUB(CURDATE(), INTERVAL  80 DAY), 8500000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL  80 DAY)),
(13, 3, 'TA-4412',    DATE_SUB(CURDATE(), INTERVAL  40 DAY), 7900000.00, NULL, 'aprobada',   1, DATE_SUB(NOW(), INTERVAL  40 DAY)),
(14, 3, 'TA-4413',    DATE_SUB(CURDATE(), INTERVAL  10 DAY), 8200000.00, NULL, 'registrada', 1, DATE_SUB(NOW(), INTERVAL  10 DAY)),
(15, 4, 'PC-2210',    DATE_SUB(CURDATE(), INTERVAL  70 DAY), 1200000.00, NULL, 'pagada',     1, DATE_SUB(NOW(), INTERVAL  70 DAY)),
(16, 4, 'PC-2211',    DATE_SUB(CURDATE(), INTERVAL  25 DAY), 1350000.00, NULL, 'observada',  1, DATE_SUB(NOW(), INTERVAL  25 DAY)),
(17, 4, 'PC-2212',    DATE_SUB(CURDATE(), INTERVAL  12 DAY), 1280000.00, NULL, 'registrada', 1, DATE_SUB(NOW(), INTERVAL  12 DAY)),
(18, 5, 'SL-3301',    DATE_SUB(CURDATE(), INTERVAL  50 DAY), 6000000.00, NULL, 'aprobada',   1, DATE_SUB(NOW(), INTERVAL  50 DAY)),
(19, 5, 'SL-3302',    DATE_SUB(CURDATE(), INTERVAL  20 DAY), 6400000.00, NULL, 'registrada', 1, DATE_SUB(NOW(), INTERVAL  20 DAY)),
(20, 6, 'FT-1101',    DATE_SUB(CURDATE(), INTERVAL  15 DAY),  900000.00, NULL, 'registrada', 1, DATE_SUB(NOW(), INTERVAL  15 DAY));

-- ---------------------------------------------------------------------
-- Pagos
--   Pago 7  → cuenta nueva sin verificar de Suministros (R4).
--   Pago 12 → cuenta secundaria de Papelería (R3).
--   Pago 4  → cuenta secundaria de ABC, con alerta resuelta.
-- ---------------------------------------------------------------------
INSERT INTO pagos
    (id_pago, id_factura, id_cuenta_destino, valor, estado, id_usuario_solicita, solicitado_en)
VALUES
( 1,  1, 1, 4500000.00, 'ejecutado', 1, DATE_SUB(NOW(), INTERVAL 158 DAY)),
( 2,  2, 1, 5200000.00, 'ejecutado', 1, DATE_SUB(NOW(), INTERVAL 128 DAY)),
( 3,  3, 1, 4800000.00, 'ejecutado', 1, DATE_SUB(NOW(), INTERVAL  98 DAY)),
( 4,  4, 2, 5000000.00, 'aprobado',  1, DATE_SUB(NOW(), INTERVAL  68 DAY)),
( 5,  5, 1, 4600000.00, 'pendiente', 1, DATE_SUB(NOW(), INTERVAL  28 DAY)),
( 6,  9, 3, 3100000.00, 'aprobado',  1, DATE_SUB(NOW(), INTERVAL  33 DAY)),
( 7, 10, 4, 3400000.00, 'bloqueado', 1, DATE_SUB(NOW(), INTERVAL   4 DAY)),
( 8, 11, 5, 8000000.00, 'ejecutado', 1, DATE_SUB(NOW(), INTERVAL 118 DAY)),
( 9, 12, 5, 8500000.00, 'ejecutado', 1, DATE_SUB(NOW(), INTERVAL  78 DAY)),
(10, 13, 5, 7900000.00, 'aprobado',  1, DATE_SUB(NOW(), INTERVAL  38 DAY)),
(11, 15, 6, 1200000.00, 'ejecutado', 1, DATE_SUB(NOW(), INTERVAL  68 DAY)),
(12, 16, 7, 1350000.00, 'bloqueado', 1, DATE_SUB(NOW(), INTERVAL  23 DAY)),
(13, 18, 8, 6000000.00, 'aprobado',  1, DATE_SUB(NOW(), INTERVAL  48 DAY)),
(14, 19, 8, 6400000.00, 'pendiente', 1, DATE_SUB(NOW(), INTERVAL  18 DAY));

-- ---------------------------------------------------------------------
-- Aprobaciones (una decisión por pago)
-- ---------------------------------------------------------------------
INSERT INTO aprobaciones
    (id_aprobacion, id_pago, id_usuario, decision, comentario, decidido_en)
VALUES
( 1,  1, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL 156 DAY)),
( 2,  2, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL 126 DAY)),
( 3,  3, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL  96 DAY)),
( 4,  4, 3, 'aprobado', 'Cuenta secundaria confirmada por llamada al proveedor.', DATE_SUB(NOW(), INTERVAL 68 DAY)),
( 5,  6, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL  33 DAY)),
( 6,  8, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL 116 DAY)),
( 7,  9, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL  76 DAY)),
( 8, 10, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL  38 DAY)),
( 9, 11, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL  66 DAY)),
(10, 13, 2, 'aprobado', 'Pago conforme a la factura.', DATE_SUB(NOW(), INTERVAL  48 DAY));

-- ---------------------------------------------------------------------
-- Alertas
-- ---------------------------------------------------------------------
INSERT INTO alertas
    (id_alerta, id_factura, id_pago, id_proveedor, id_cuenta, tipo, nivel,
     descripcion, estado, creada_en, id_usuario_resuelve, resuelta_en, justificacion)
VALUES
(1, 10,  7, 2, 4, 'cuenta_modificada_reciente', 'alto',
 'La cuenta destino fue creada hace 12 días y no está verificada.',
 'abierta', DATE_SUB(NOW(), INTERVAL 4 DAY), NULL, NULL, NULL),
(2, 16, 12, 4, 7, 'cuenta_diferente', 'alto',
 'La cuenta destino no es la cuenta principal del proveedor.',
 'abierta', DATE_SUB(NOW(), INTERVAL 23 DAY), NULL, NULL, NULL),
(3, 20, NULL, 6, 9, 'otra', 'medio',
 'Factura registrada sobre un proveedor inactivo.',
 'abierta', DATE_SUB(NOW(), INTERVAL 15 DAY), NULL, NULL, NULL),
(4, 4, 4, 1, 2, 'cuenta_diferente', 'alto',
 'Pago a cuenta secundaria del proveedor.',
 'resuelta', DATE_SUB(NOW(), INTERVAL 68 DAY), 3,
 DATE_SUB(NOW(), INTERVAL 68 DAY),
 'Se confirmó por llamada al proveedor y autorización de gerencia.');

-- ---------------------------------------------------------------------
-- Auditoría (solo inserción)
-- ---------------------------------------------------------------------
INSERT INTO auditoria
    (id_usuario, accion, entidad, id_entidad, detalle, ip, fecha)
VALUES
(1, 'login', 'usuarios', 1, 'Inicio de sesión correcto.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 160 DAY)),
(1, 'crear_factura', 'facturas', 1, 'Factura F-2026-001 por 4.500.000 COP.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 160 DAY)),
(2, 'login', 'usuarios', 2, 'Inicio de sesión correcto.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 156 DAY)),
(2, 'aprobar_pago', 'pagos', 1, 'Pago aprobado.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 156 DAY)),
(3, 'login', 'usuarios', 3, 'Inicio de sesión correcto.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 68 DAY)),
(3, 'cambiar_cuenta', 'cuentas_bancarias', 4, 'Cuenta principal anterior: 4567891234. Nueva: 4567899999 (sin verificar).', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(1, 'crear_factura', 'facturas', 10, 'Factura SN-8804 por 3.400.000 COP.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(NULL, 'alerta_generada', 'alertas', 1, 'R4 cuenta_modificada_reciente (nivel alto).', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3, 'resolver_alerta', 'alertas', 4, 'Alerta resuelta: cuenta secundaria confirmada.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 68 DAY)),
(NULL, 'login_fallido', 'usuarios', NULL, 'Intento fallido para admin@safepay.local.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 'aprobar_pago', 'pagos', 4, 'Pago aprobado a cuenta secundaria verificada.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 68 DAY)),
(1, 'solicitar_pago', 'pagos', 14, 'Solicitud de pago de SL-3302.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 18 DAY));

COMMIT;
