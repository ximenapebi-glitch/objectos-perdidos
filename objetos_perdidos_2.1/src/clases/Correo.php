<?php
require_once __DIR__ . '/../libs/PHPMailer/Exception.php';
require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

// Envío de correos con PHPMailer. Un fallo al enviar NUNCA rompe la acción
// principal (publicar, mensaje, aprobar...): simplemente devuelve false.
class Correo {
    private static function config() {
        $ruta = __DIR__ . '/../config_correo.php';
        return file_exists($ruta) ? require $ruta : ['activo' => false];
    }

    public static function enviar($paraCorreo, $paraNombre, $asunto, $titulo, $htmlCuerpo) {
        $cfg = self::config();
        if (empty($cfg['activo']) || empty($paraCorreo)) {
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = $cfg['puerto'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['usuario'];
            $mail->Password   = $cfg['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout    = 8; // segundos, para no dejar colgada la página
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($cfg['de_correo'], $cfg['de_nombre']);
            $mail->addAddress($paraCorreo, $paraNombre);
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = self::plantilla($titulo, $htmlCuerpo, $cfg['url_sitio'] ?? '');
            $mail->AltBody = trim(strip_tags($htmlCuerpo));

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Correo no enviado: ' . $e->getMessage());
            return false;
        }
    }

    // Texto seguro para meter dentro del HTML del correo
    public static function esc($texto) {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    private static function plantilla($titulo, $cuerpo, $url) {
        $boton = $url
            ? '<p style="margin:24px 0 0"><a href="' . self::esc($url) . '" style="background:#2B2A26;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-size:14px">Abrir la página</a></p>'
            : '';
        return '<div style="font-family:Arial,sans-serif;background:#F1EFE6;padding:24px">'
             . '<div style="max-width:480px;margin:0 auto;background:#FFFDF7;border:1px solid #DCD8C9;border-radius:10px;padding:24px;color:#2B2A26">'
             . '<h2 style="margin:0 0 12px;font-size:20px">' . self::esc($titulo) . '</h2>'
             . '<div style="font-size:14px;line-height:1.5">' . $cuerpo . '</div>'
             . $boton
             . '</div></div>';
    }
}
