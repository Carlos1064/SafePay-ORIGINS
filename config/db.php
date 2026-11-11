<?php
declare(strict_types=1);

/**
 * Conexión a la base de datos de InfinityFree.
 *
 * Antes de subir el proyecto, reemplace los valores de ejemplo por los que
 * muestra el panel de InfinityFree en "MySQL Databases":
 *
 *   Host:     sqlXXX.infinityfree.com
 *   Puerto:   3306
 *   Base:     if0_XXXXXXXX_safepay
 *   Usuario:  if0_XXXXXXXX
 *   Clave:    la que definió al crear la base
 *
 * No suba este archivo con las credenciales locales de XAMPP.
 */

$host       = 'sql200.infinityfree.com';
$puerto     = 3306;
$basedatos  = 'if0_43377218_safepay';
$usuario    = 'if0_43377218';
$contrasena = 'LNcmYOycN898';

$dsn = "mysql:host={$host};port={$puerto};dbname={$basedatos};charset=utf8mb4";

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $usuario, $contrasena, $opciones);
