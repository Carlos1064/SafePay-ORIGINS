# SafePay (Versión InfinityFree)

Sistema web de gestión financiera, control de pagos, facturas y administración de proveedores, diseñado de forma modular y optimizado para su despliegue en servidores de hosting compartido (como InfinityFree).

---

## 🚀 Características Principales

- **Módulo de Facturación:** Creación, consulta y exportación de facturas (soporte para PDF).
- **Control de Pagos:** Registro y seguimiento detallado de transacciones y pagos a proveedores.
- **Gestión de Proveedores:** Administración de datos de contacto y estados de cuenta.
- **Seguridad y Auditoría:** 
  - Sistema de autenticación de usuarios (`auth/`).
  - Control de accesos por roles y perfiles.
  - Registro de auditoría de actividades del sistema (`modules/auditoria/`).
  - Validación de operaciones y prevención de fraude (`services/ValidadorFraude.php`).
- **Interfaz Moderna:** Construido con **Bootstrap 5.3.3** y **Bootstrap Icons**.

---

## 🛠️ Requisitos del Sistema

- **PHP:** Versión 8.0 o superior recomendada.
- **Base de Datos:** MySQL / MariaDB.
- **Servidor Web:** Apache (con soporte para archivos `.htaccess` habilitado).

---

## 📂 Estructura del Proyecto

```text
SafePay-InfinityFree/
│
├── instalacion/              # Scripts SQL de estructura y datos iniciales
│   ├── schema-infinityfree.sql
│   └── seed-infinityfree.sql
│
└── SafePay-ORIGINS/          # Código fuente principal de la aplicación
    ├── assets/               # CSS, JS, imágenes y librerías externas (Bootstrap)
    ├── auth/                 # Control de sesión (login, logout, 403)
    ├── config/               # Archivos de configuración y conexión a BD
    ├── includes/             # Componentes reutilizables (header, footer, funciones)
    ├── modules/              # Módulos del sistema (alertas, facturas, pagos, etc.)
    ├── services/             # Lógica de negocio y validaciones
    ├── uploads/              # Directorio para archivos cargados
    ├── dashboard.php         # Panel principal de control
    └── index.php             # Página de entrada