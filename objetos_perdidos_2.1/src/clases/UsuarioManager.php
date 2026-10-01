<?php
class UsuarioManager {
    const MIN_PASSWORD = 8;

    private $file = __DIR__ . '/../../data/usuarios.json';
    private $carpetaCredenciales = __DIR__ . '/../../data/credenciales/';

    private function leerUsuarios() {
        if (!file_exists($this->file)) {
            return [];
        }
        $json = file_get_contents($this->file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function guardarUsuarios($usuarios) {
        return file_put_contents($this->file, json_encode(array_values($usuarios), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // Registrar un nuevo usuario (alumno o maestro) con foto de credencial universitaria
    public function registrar($data, $archivoCredencial) {
        if (empty($data['nombre']) || empty($data['correo']) || empty($data['password']) || empty($data['tipo_usuario'])) {
            return ['success' => false, 'message' => 'Nombre, correo, contraseña y tipo de usuario son obligatorios'];
        }

        $data['nombre'] = trim($data['nombre']);
        $data['correo'] = trim($data['correo']);

        if (mb_strlen($data['nombre']) < 3 || !preg_match('/^[\p{L}][\p{L} .\'-]*$/u', $data['nombre'])) {
            return ['success' => false, 'message' => 'Escribe tu nombre completo (solo letras, mínimo 3 caracteres)'];
        }

        if (strpos($data['correo'], '@') === false || !filter_var($data['correo'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'El correo debe ser válido y llevar @ (ej. nombre@universidad.edu.mx)'];
        }

        if (mb_strlen($data['password']) < self::MIN_PASSWORD) {
            return ['success' => false, 'message' => 'La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres'];
        }

        if (!in_array($data['tipo_usuario'], ['alumno', 'maestro'])) {
            return ['success' => false, 'message' => 'El tipo de usuario debe ser alumno o maestro'];
        }

        if (empty($archivoCredencial) || $archivoCredencial['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Debes subir una foto de tu credencial universitaria'];
        }

        if (@getimagesize($archivoCredencial['tmp_name']) === false) {
            return ['success' => false, 'message' => 'La credencial debe ser una imagen (JPG, PNG, WEBP...)'];
        }

        $usuarios = $this->leerUsuarios();

        foreach ($usuarios as $u) {
            if (strtolower($u['correo']) === strtolower($data['correo'])) {
                return ['success' => false, 'message' => 'Ya existe una cuenta con ese correo'];
            }
        }

        // Guardar la imagen de la credencial
        if (!is_dir($this->carpetaCredenciales)) {
            mkdir($this->carpetaCredenciales, 0755, true);
        }
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($archivoCredencial['name'], PATHINFO_EXTENSION))) ?: 'jpg';
        $nombreArchivo = uniqid('credencial_') . '.' . $extension;
        $rutaDestino = $this->carpetaCredenciales . $nombreArchivo;

        if (!move_uploaded_file($archivoCredencial['tmp_name'], $rutaDestino)) {
            return ['success' => false, 'message' => 'No se pudo guardar la foto de la credencial'];
        }

        $id = count($usuarios) > 0 ? max(array_column($usuarios, 'id')) : 0;

        $nuevoUsuario = [
            'id' => $id + 1,
            'nombre' => $data['nombre'],
            'correo' => $data['correo'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'tipo_usuario' => $data['tipo_usuario'], // 'alumno' o 'maestro'
            'credencial_foto' => $nombreArchivo,
            'verificado' => false // un administrador revisa la credencial antes de verificar la cuenta
        ];

        $usuarios[] = $nuevoUsuario;

        if ($this->guardarUsuarios($usuarios)) {
            return ['success' => true, 'message' => 'Cuenta creada correctamente. Tu credencial será revisada.', 'id' => $nuevoUsuario['id']];
        } else {
            return ['success' => false, 'message' => 'No se pudo crear la cuenta'];
        }
    }

    // Listar todos los usuarios (sin la contraseña) — uso del administrador
    public function listarUsuarios() {
        $usuarios = $this->leerUsuarios();
        foreach ($usuarios as &$usuario) {
            unset($usuario['password']);
        }
        unset($usuario);
        return $usuarios;
    }

    // Aprobar la credencial de un usuario y activar su cuenta — uso del administrador
    public function verificarUsuario($id) {
        $usuarios = $this->leerUsuarios();
        $encontrado = false;

        foreach ($usuarios as &$usuario) {
            if ($usuario['id'] == $id) {
                $usuario['verificado'] = true;
                $encontrado = true;
                break;
            }
        }
        unset($usuario);

        if (!$encontrado) {
            return ['success' => false, 'message' => 'No se encontró el usuario'];
        }

        if ($this->guardarUsuarios($usuarios)) {
            return ['success' => true, 'message' => 'Usuario verificado correctamente'];
        } else {
            return ['success' => false, 'message' => 'No se pudo verificar el usuario'];
        }
    }

    // Eliminar una cuenta (rechazo de credencial o baja de cuenta) — uso del administrador
    public function eliminarUsuario($id) {
        $usuarios = $this->leerUsuarios();
        $encontrado = false;

        foreach ($usuarios as $key => $usuario) {
            if ($usuario['id'] == $id) {
                // Borrar también la foto de la credencial guardada en disco
                if (!empty($usuario['credencial_foto'])) {
                    $rutaFoto = $this->carpetaCredenciales . $usuario['credencial_foto'];
                    if (file_exists($rutaFoto)) {
                        unlink($rutaFoto);
                    }
                }
                unset($usuarios[$key]);
                $encontrado = true;
                break;
            }
        }

        if (!$encontrado) {
            return ['success' => false, 'message' => 'No se encontró el usuario'];
        }

        if ($this->guardarUsuarios($usuarios)) {
            return ['success' => true, 'message' => 'Usuario eliminado correctamente'];
        } else {
            return ['success' => false, 'message' => 'No se pudo eliminar el usuario'];
        }
    }

    // Datos básicos de un usuario (sin contraseña ni credencial). null si no existe.
    // Devuelve también el correo: úsalo solo en el servidor, nunca lo envíes tal cual al navegador.
    public function obtenerUsuario($id) {
        foreach ($this->leerUsuarios() as $u) {
            if ($u['id'] == $id) {
                return [
                    'id' => $u['id'],
                    'nombre' => $u['nombre'],
                    'correo' => $u['correo'], // solo para uso interno (envío de avisos); no se manda al navegador
                    'tipo_usuario' => $u['tipo_usuario'],
                    'verificado' => !empty($u['verificado'])
                ];
            }
        }
        return null;
    }

    // Iniciar sesión
    public function iniciarSesion($correo, $password) {
        $usuarios = $this->leerUsuarios();

        foreach ($usuarios as $usuario) {
            if (strtolower($usuario['correo']) === strtolower($correo)) {
                if (password_verify($password, $usuario['password'])) {
                    unset($usuario['password']); // nunca devolver la contraseña al cliente
                    return ['success' => true, 'usuario' => $usuario];
                }
                return ['success' => false, 'message' => 'Contraseña incorrecta'];
            }
        }

        return ['success' => false, 'message' => 'No existe una cuenta con ese correo'];
    }
}
