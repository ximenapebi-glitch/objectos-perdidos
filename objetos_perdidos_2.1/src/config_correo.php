<?php
// ============================================================
// CONFIGURACIÓN DE CORREO (PHPMailer)
// ------------------------------------------------------------
// Mientras 'activo' sea false, la página funciona normal pero
// NO envía correos. Para activarlo con Gmail:
//   1. Activa la verificación en dos pasos en tu cuenta de Google.
//   2. Crea una "contraseña de aplicación" (myaccount.google.com/apppasswords).
//   3. Pega aquí tu correo y esa contraseña de 16 letras (NO tu contraseña normal).
//   4. Cambia 'activo' a true.
// ============================================================

return [
    'activo'     => false,
    'host'       => 'smtp.gmail.com',
    'puerto'     => 587,              // 587 = STARTTLS
    'usuario'    => 'tu_correo@gmail.com',
    'password'   => 'contraseña de aplicación',
    'de_correo'  => 'tu_correo@gmail.com',
    'de_nombre'  => 'Objetos perdidos',
    // Dirección donde vive tu página (se usa para el enlace dentro de los correos)
    'url_sitio'  => 'http://localhost/objetos_perdidos/'
];
