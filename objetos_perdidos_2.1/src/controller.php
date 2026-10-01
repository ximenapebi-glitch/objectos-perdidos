<?php
session_start();

// Incluir e importar las clases externas
require_once __DIR__ . '/clases/ObjetoManager.php';
require_once __DIR__ . '/clases/UsuarioManager.php';
require_once __DIR__ . '/clases/MensajeManager.php';
require_once __DIR__ . '/clases/Correo.php';

// Configurar el encabezado para devolver JSON
header('Content-Type: application/json');

// Instanciar los manejadores
$objetoManager = new ObjetoManager();
$usuarioManager = new UsuarioManager();
$mensajeManager = new MensajeManager();

// true = solo las cuentas ya aprobadas por el administrador pueden enviar mensajes
const MENSAJES_SOLO_VERIFICADOS = true;

// Solo 'adminVerCredencial' se acepta por GET (la usa <img src> en el panel de administración)
$accion = $_POST['action'] ?? (($_GET['action'] ?? '') === 'adminVerCredencial' ? 'adminVerCredencial' : '');

switch ($accion) {

    // --- Registro de usuario (alumno o maestro) con foto de credencial ---
    case 'registrar':
        try {
            $archivoCredencial = $_FILES['credencial'] ?? null;
            $response = $usuarioManager->registrar($_POST, $archivoCredencial);
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo completar el registro.']);
        }
        break;

    // --- Inicio de sesión ---
    case 'iniciarSesion':
        try {
            if (empty($_POST['correo']) || empty($_POST['password'])) {
                echo json_encode(['success' => false, 'message' => 'Correo y contraseña son obligatorios']);
                exit;
            }
            $response = $usuarioManager->iniciarSesion($_POST['correo'], $_POST['password']);
            if ($response['success']) {
                $_SESSION['usuario'] = $response['usuario'];
            }
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo iniciar sesión.']);
        }
        break;

    // --- Cerrar sesión ---
    case 'cerrarSesion':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    // --- Consultar si hay una sesión activa ---
    case 'sesionActual':
        if (!empty($_SESSION['usuario'])) {
            echo json_encode(['success' => true, 'usuario' => $_SESSION['usuario']]);
        } else {
            echo json_encode(['success' => false]);
        }
        break;

    case 'listar':
        try {
            $objetos = $objetoManager->listarObjetos();
            echo json_encode($objetos);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudieron listar los objetos.']);
        }
        break;

    case 'agregar':
        try {
            if (empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión para reportar un objeto']);
                exit;
            }
            if (empty($_POST['titulo']) || empty($_POST['estado']) || empty($_POST['lugar']) || empty($_POST['fecha'])) {
                echo json_encode(['success' => false, 'message' => 'Título, estado, lugar y fecha son obligatorios']);
                exit;
            }
            $_POST['usuario_id'] = $_SESSION['usuario']['id'];
            $response = $objetoManager->agregarObjeto($_POST, $_FILES['foto'] ?? null);
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo reportar el objeto.']);
        }
        break;

    case 'editar':
        try {
            if (empty($_POST['id']) || empty($_POST['titulo']) || empty($_POST['estado']) || empty($_POST['lugar']) || empty($_POST['fecha'])) {
                echo json_encode(['success' => false, 'message' => 'ID, título, estado, lugar y fecha son obligatorios']);
                exit;
            }
            $esAdmin = !empty($_SESSION['admin']);
            if (!$esAdmin && empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión para editar una publicación']);
                exit;
            }
            $usuarioId = $_SESSION['usuario']['id'] ?? null;
            $response = $objetoManager->editarObjeto($_POST['id'], $_POST, $usuarioId, $esAdmin, $_FILES['foto'] ?? null);
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo editar el objeto.']);
        }
        break;

    case 'eliminar':
        try {
            if (empty($_POST['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID es obligatorio']);
                exit;
            }
            $esAdmin = !empty($_SESSION['admin']);
            if (!$esAdmin && empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión para eliminar una publicación']);
                exit;
            }
            $usuarioId = $_SESSION['usuario']['id'] ?? null;
            $response = $objetoManager->eliminarObjeto($_POST['id'], $usuarioId, $esAdmin);
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el objeto.']);
        }
        break;

    // El publicador marca su objeto como entregado y se avisa por correo a quienes le escribieron
    case 'marcarEntregado':
        try {
            if (empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión']);
                exit;
            }
            if (empty($_POST['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID es obligatorio']);
                exit;
            }
            $miId = $_SESSION['usuario']['id'];
            $response = $objetoManager->marcarEntregado($_POST['id'], $miId);
            if ($response['success']) {
                foreach ($mensajeManager->usuariosQueEscribieron($_POST['id'], $miId) as $otroId) {
                    $otro = $usuarioManager->obtenerUsuario($otroId);
                    if ($otro) {
                        Correo::enviar(
                            $otro['correo'], $otro['nombre'],
                            'La publicación "' . $response['titulo'] . '" fue marcada como entregada',
                            'Objeto entregado',
                            '<p>Hola ' . Correo::esc($otro['nombre']) . ',</p><p>La publicación <strong>'
                            . Correo::esc($response['titulo']) . '</strong>, sobre la que escribiste, fue marcada como entregada.</p>'
                        );
                    }
                }
                unset($response['titulo']);
            }
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la publicación.']);
        }
        break;

    // ==========================================================
    // MENSAJES ENTRE USUARIOS (requieren sesión iniciada)
    // ==========================================================

    case 'enviarMensaje':
        try {
            if (empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión para enviar mensajes']);
                exit;
            }
            $miId = $_SESSION['usuario']['id'];
            $yo = $usuarioManager->obtenerUsuario($miId);
            if (!$yo) {
                echo json_encode(['success' => false, 'message' => 'Tu cuenta ya no existe']);
                exit;
            }
            if (MENSAJES_SOLO_VERIFICADOS && !$yo['verificado']) {
                echo json_encode(['success' => false, 'message' => 'Tu credencial aún está en revisión. Podrás enviar mensajes cuando sea aprobada.']);
                exit;
            }

            $objeto = $objetoManager->obtenerObjeto($_POST['objeto_id'] ?? 0);
            if (!$objeto || empty($objeto['usuario_id'])) {
                echo json_encode(['success' => false, 'message' => 'No se puede enviar mensaje sobre esta publicación']);
                exit;
            }
            // Objeto entregado o vencido: ya no se pueden enviar mensajes
            if (!$objetoManager->estaDisponible($objeto)) {
                echo json_encode(['success' => false, 'message' => 'Esta publicación ya no está disponible, por lo que ya no se pueden enviar mensajes.']);
                exit;
            }

            if ($objeto['usuario_id'] != $miId) {
                // Alguien más escribe al publicador
                $paraId = $objeto['usuario_id'];
            } else {
                // El publicador solo puede responderle a quien ya le escribió sobre este objeto
                $paraId = $_POST['para_id'] ?? 0;
                if (!$mensajeManager->existeMensaje($objeto['id'], $paraId, $miId)) {
                    echo json_encode(['success' => false, 'message' => 'Solo puedes responder a quien ya te escribió']);
                    exit;
                }
            }
            if (!$usuarioManager->obtenerUsuario($paraId)) {
                echo json_encode(['success' => false, 'message' => 'El destinatario ya no existe']);
                exit;
            }

            // ¿Es el primer mensaje sin leer de esta conversación? Solo entonces se avisa por correo
            $primerAviso = $mensajeManager->contarNoLeidosHilo($objeto['id'], $miId, $paraId) === 0;

            $response = $mensajeManager->enviar($objeto['id'], $miId, $paraId, $_POST['texto'] ?? '');

            if ($response['success'] && $primerAviso) {
                $destino = $usuarioManager->obtenerUsuario($paraId);
                Correo::enviar(
                    $destino['correo'], $destino['nombre'],
                    'Tienes un mensaje nuevo sobre "' . $objeto['titulo'] . '"',
                    'Tienes un mensaje nuevo',
                    '<p>Hola ' . Correo::esc($destino['nombre']) . ',</p><p><strong>' . Correo::esc($yo['nombre'])
                    . '</strong> te escribió sobre <strong>' . Correo::esc($objeto['titulo']) . '</strong>:</p>'
                    . '<p style="background:#F1EFE6;padding:10px 14px;border-radius:8px">' . nl2br(Correo::esc(trim($_POST['texto'] ?? ''))) . '</p>'
                    . '<p>Entra a la página, abre <em>Mensajes</em> y responde.</p>'
                );
            }
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo enviar el mensaje.']);
        }
        break;

    case 'listarConversaciones':
        try {
            if (empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión']);
                exit;
            }
            $conversaciones = $mensajeManager->listarConversaciones($_SESSION['usuario']['id']);
            foreach ($conversaciones as &$c) {
                $otro = $usuarioManager->obtenerUsuario($c['otro_id']);
                $obj = $objetoManager->obtenerObjeto($c['objeto_id']);
                $c['otro_nombre'] = $otro ? $otro['nombre'] : 'Usuario eliminado';
                $c['objeto_titulo'] = $obj ? $obj['titulo'] : 'Publicación eliminada';
                $c['objeto_disponible'] = $obj ? $objetoManager->estaDisponible($obj) : false;
            }
            unset($c);
            echo json_encode(['success' => true, 'conversaciones' => $conversaciones]);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudieron cargar los mensajes.']);
        }
        break;

    case 'verHilo':
        try {
            if (empty($_SESSION['usuario'])) {
                echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión']);
                exit;
            }
            $hilo = $mensajeManager->listarHilo($_SESSION['usuario']['id'], $_POST['objeto_id'] ?? 0, $_POST['otro_id'] ?? 0);
            echo json_encode(['success' => true, 'mensajes' => $hilo]);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo abrir la conversación.']);
        }
        break;

    case 'contarNoLeidos':
        if (empty($_SESSION['usuario'])) {
            echo json_encode(['success' => true, 'total' => 0]);
        } else {
            echo json_encode(['success' => true, 'total' => $mensajeManager->contarNoLeidos($_SESSION['usuario']['id'])]);
        }
        break;

    // ==========================================================
    // ACCIONES DE ADMINISTRADOR
    // (usuario/contraseña fijos definidos en config_admin.php,
    // separados por completo del sistema de alumnos/maestros)
    // ==========================================================

    case 'adminLogin':
        try {
            $adminConfig = require __DIR__ . '/config_admin.php';
            $usuario = $_POST['usuario'] ?? '';
            $password = $_POST['password'] ?? '';
            if ($usuario === $adminConfig['usuario'] && password_verify($password, $adminConfig['password_hash'])) {
                $_SESSION['admin'] = true;
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Usuario o contraseña incorrectos']);
            }
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo iniciar sesión de administrador.']);
        }
        break;

    case 'adminLogout':
        unset($_SESSION['admin']);
        echo json_encode(['success' => true]);
        break;

    case 'adminSesionActual':
        echo json_encode(['success' => !empty($_SESSION['admin'])]);
        break;

    case 'adminListarUsuarios':
        try {
            if (empty($_SESSION['admin'])) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            $usuarios = $usuarioManager->listarUsuarios();
            echo json_encode(['success' => true, 'usuarios' => $usuarios]);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudieron listar los usuarios.']);
        }
        break;

    case 'adminVerificarUsuario':
        try {
            if (empty($_SESSION['admin'])) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            if (empty($_POST['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID es obligatorio']);
                exit;
            }
            $response = $usuarioManager->verificarUsuario($_POST['id']);
            if ($response['success'] && ($u = $usuarioManager->obtenerUsuario($_POST['id']))) {
                Correo::enviar(
                    $u['correo'], $u['nombre'], 'Tu cuenta fue aprobada', 'Cuenta aprobada',
                    '<p>Hola ' . Correo::esc($u['nombre']) . ',</p><p>Revisamos tu credencial y tu cuenta ya está activa. Ya puedes enviar y recibir mensajes.</p>'
                );
            }
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo verificar el usuario.']);
        }
        break;

    case 'adminEliminarUsuario':
        try {
            if (empty($_SESSION['admin'])) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            if (empty($_POST['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID es obligatorio']);
                exit;
            }
            $u = $usuarioManager->obtenerUsuario($_POST['id']); // se consulta antes de borrarlo
            $response = $usuarioManager->eliminarUsuario($_POST['id']);
            if ($response['success'] && $u && !$u['verificado']) {
                // Si aún no estaba verificado, eliminarlo equivale a rechazar su credencial
                Correo::enviar(
                    $u['correo'], $u['nombre'], 'No pudimos verificar tu credencial', 'Credencial rechazada',
                    '<p>Hola ' . Correo::esc($u['nombre']) . ',</p><p>No pudimos validar la foto de tu credencial y tu cuenta fue eliminada. Puedes crear una cuenta nueva con una foto clara de tu credencial universitaria.</p>'
                );
            }
            echo json_encode($response);
        } catch (\Throwable $th) {
            echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el usuario.']);
        }
        break;

    // Sirve la foto de una credencial solo al administrador (la carpeta data/ está bloqueada al público)
    case 'adminVerCredencial':
        if (empty($_SESSION['admin'])) {
            http_response_code(403);
            exit;
        }
        $ruta = __DIR__ . '/../data/credenciales/' . basename($_GET['archivo'] ?? '');
        if (!is_file($ruta)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
