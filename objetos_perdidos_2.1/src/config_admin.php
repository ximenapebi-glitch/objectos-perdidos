<?php
// ============================================================
// CONFIGURACIÓN DEL ADMINISTRADOR
// ------------------------------------------------------------
// Este acceso es independiente del sistema de alumnos/maestros:
// es solo para ti, el dueño de la página.
//
// IMPORTANTE: cambia el usuario y la contraseña por los tuyos.
// Para generar el hash de una contraseña nueva, corre en una
// terminal con PHP:
//   php -r "echo password_hash('tu_password_nueva', PASSWORD_DEFAULT);"
// y pega el resultado en 'password_hash' de abajo.
// ============================================================

return [
    'usuario' => 'admin',
    // Contraseña actual: 123456789  (cámbiala generando un hash nuevo)
    'password_hash' => '$2y$10$eDkDxaFrJGNdF5FUMjtaaO2nKn0ApEWNRClgPv7cDI/YalqhWIyJq'
];