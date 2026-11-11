-- =====================================================================
-- SafePay - Esquema para InfinityFree
-- Importar desde phpMyAdmin DESPUÉS de crear la base en el panel.
-- No incluye CREATE DATABASE, USE, triggers ni claves foráneas.
-- Motor: InnoDB (si la cuenta no lo permite, el import fallará).
-- =====================================================================

SET NAMES utf8mb4;

-- Se desactivan las FK para poder borrar las tablas en cualquier orden.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS auditoria;
DROP TABLE IF EXISTS alertas;
DROP TABLE IF EXISTS aprobaciones;
DROP TABLE IF EXISTS pagos;
DROP TABLE IF EXISTS facturas;
DROP TABLE IF EXISTS cuentas_bancarias;
DROP TABLE IF EXISTS proveedores;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. roles
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id_rol       TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(30)  NOT NULL,
    descripcion  VARCHAR(150) NULL,
    CONSTRAINT uq_roles_nombre UNIQUE (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles del sistema: empleado, financiero, administrador';

-- ---------------------------------------------------------------------
-- 2. usuarios
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id_usuario        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre            VARCHAR(100) NOT NULL,
    correo            VARCHAR(120) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    id_rol            TINYINT UNSIGNED NOT NULL,
    activo            TINYINT(1)   NOT NULL DEFAULT 1,
    intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta   DATETIME     NULL,
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_usuarios_correo UNIQUE (correo),
    KEY idx_usuarios_rol (id_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuarios y credenciales (hash), con control de intentos fallidos';

-- ---------------------------------------------------------------------
-- 3. proveedores
-- ---------------------------------------------------------------------
CREATE TABLE proveedores (
    id_proveedor  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nit           VARCHAR(20)  NOT NULL,
    razon_social  VARCHAR(150) NOT NULL,
    correo        VARCHAR(120) NULL,
    telefono      VARCHAR(30)  NULL,
    estado        ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_proveedores_nit UNIQUE (nit),
    KEY idx_proveedores_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Proveedores; se inactivan, nunca se eliminan';

-- ---------------------------------------------------------------------
-- 4. cuentas_bancarias
--    La columna generada principal_de + UNIQUE garantiza, a nivel de
--    base de datos, que cada proveedor tenga como máximo UNA principal.
-- ---------------------------------------------------------------------
CREATE TABLE cuentas_bancarias (
    id_cuenta           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_proveedor        INT UNSIGNED NOT NULL,
    banco               VARCHAR(80)  NOT NULL,
    tipo_cuenta         ENUM('ahorros','corriente') NOT NULL,
    numero_cuenta       VARCHAR(30)  NOT NULL,
    es_principal        TINYINT(1)   NOT NULL DEFAULT 0,
    verificada          TINYINT(1)   NOT NULL DEFAULT 0,
    id_usuario_verifica INT UNSIGNED NULL,
    verificada_en       DATETIME     NULL,
    creada_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modificada_en       DATETIME     NULL,
    principal_de        INT UNSIGNED GENERATED ALWAYS AS (
                            IF(es_principal = 1, id_proveedor, NULL)
                        ) STORED,
    CONSTRAINT uq_cuentas_proveedor_numero UNIQUE (id_proveedor, numero_cuenta),
    CONSTRAINT uq_cuentas_principal UNIQUE (principal_de),
    KEY idx_cuentas_usuario_verifica (id_usuario_verifica)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial de cuentas; una sola principal por proveedor';

-- ---------------------------------------------------------------------
-- 5. facturas
-- ---------------------------------------------------------------------
CREATE TABLE facturas (
    id_factura          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_proveedor        INT UNSIGNED NOT NULL,
    numero_factura      VARCHAR(40)  NOT NULL,
    fecha_emision       DATE         NOT NULL,
    valor               DECIMAL(14,2) NOT NULL,
    archivo_pdf         VARCHAR(255) NULL,
    estado              ENUM('registrada','observada','aprobada','rechazada','pagada')
                        NOT NULL DEFAULT 'registrada',
    id_usuario_registra INT UNSIGNED NOT NULL,
    creada_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_facturas_proveedor_numero UNIQUE (id_proveedor, numero_factura),
    CONSTRAINT chk_facturas_valor CHECK (valor > 0),
    KEY idx_facturas_estado (estado),
    KEY idx_facturas_fecha (fecha_emision),
    KEY idx_facturas_usuario (id_usuario_registra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Facturas; el UNIQUE evita duplicados exactos por proveedor';

-- ---------------------------------------------------------------------
-- 6. pagos
--    UNIQUE (id_factura): en el MVP se permite un pago por factura.
-- ---------------------------------------------------------------------
CREATE TABLE pagos (
    id_pago            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_factura         INT UNSIGNED NOT NULL,
    id_cuenta_destino  INT UNSIGNED NOT NULL,
    valor              DECIMAL(14,2) NOT NULL,
    estado             ENUM('pendiente','bloqueado','aprobado','rechazado','ejecutado')
                       NOT NULL DEFAULT 'pendiente',
    id_usuario_solicita INT UNSIGNED NOT NULL,
    solicitado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_pagos_factura UNIQUE (id_factura),
    CONSTRAINT chk_pagos_valor CHECK (valor > 0),
    KEY idx_pagos_estado (estado),
    KEY idx_pagos_cuenta (id_cuenta_destino),
    KEY idx_pagos_usuario (id_usuario_solicita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solicitudes de pago y su estado; no ejecuta transferencias';

-- ---------------------------------------------------------------------
-- 7. aprobaciones
--    UNIQUE (id_pago): una sola decisión final por pago en el MVP.
-- ---------------------------------------------------------------------
CREATE TABLE aprobaciones (
    id_aprobacion  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_pago        INT UNSIGNED NOT NULL,
    id_usuario     INT UNSIGNED NOT NULL,
    decision       ENUM('aprobado','rechazado') NOT NULL,
    comentario     VARCHAR(255) NULL,
    decidido_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_aprobaciones_pago UNIQUE (id_pago),
    KEY idx_aprobaciones_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Decisión final de aprobación o rechazo, con trazabilidad';

-- ---------------------------------------------------------------------
-- 8. alertas
--    Puede apuntar a factura, pago, proveedor y/o cuenta.
--    id_cuenta se agrega para R3/R4 (cuenta distinta o reciente).
-- ---------------------------------------------------------------------
CREATE TABLE alertas (
    id_alerta            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_factura           INT UNSIGNED NULL,
    id_pago              INT UNSIGNED NULL,
    id_proveedor         INT UNSIGNED NULL,
    id_cuenta            INT UNSIGNED NULL,
    tipo                 ENUM('factura_duplicada','cuenta_diferente',
                              'cuenta_modificada_reciente','valor_inusual','otra') NOT NULL,
    nivel                ENUM('bajo','medio','alto') NOT NULL,
    descripcion          VARCHAR(255) NOT NULL,
    estado               ENUM('abierta','resuelta','descartada') NOT NULL DEFAULT 'abierta',
    creada_en            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario_resuelve  INT UNSIGNED NULL,
    resuelta_en          DATETIME NULL,
    justificacion        VARCHAR(255) NULL,
    CONSTRAINT chk_alertas_referencia CHECK (
        id_factura IS NOT NULL OR id_pago IS NOT NULL
        OR id_proveedor IS NOT NULL OR id_cuenta IS NOT NULL
    ),
    KEY idx_alertas_estado_nivel (estado, nivel),
    KEY idx_alertas_factura (id_factura),
    KEY idx_alertas_pago (id_pago),
    KEY idx_alertas_proveedor (id_proveedor),
    KEY idx_alertas_cuenta (id_cuenta),
    KEY idx_alertas_usuario_resuelve (id_usuario_resuelve)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hallazgos del motor de reglas; solo se cierran con justificación';

-- ---------------------------------------------------------------------
-- 9. auditoria
--    Solo INSERT desde la aplicación. No se actualiza ni se borra.
-- ---------------------------------------------------------------------
CREATE TABLE auditoria (
    id_auditoria BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_usuario   INT UNSIGNED NULL,
    accion       VARCHAR(60) NOT NULL,
    entidad      VARCHAR(40) NOT NULL,
    id_entidad   INT UNSIGNED NULL,
    detalle      TEXT NULL,
    ip           VARCHAR(45) NULL,
    fecha        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_auditoria_fecha (fecha),
    KEY idx_auditoria_usuario (id_usuario),
    KEY idx_auditoria_entidad (entidad, id_entidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro inmutable de acciones relevantes';

