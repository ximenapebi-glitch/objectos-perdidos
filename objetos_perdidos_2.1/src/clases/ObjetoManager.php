<?php
class ObjetoManager {
    private $file = __DIR__ . '/../../data/objetos.json';
    private $carpetaFotos = __DIR__ . '/../../data/fotos/';

    const MAX_FOTO_BYTES = 5 * 1024 * 1024; // 5 MB
    const TIPOS_FOTO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    // Tiempo límite antes de marcar una publicación como no disponible
    const DIAS_LIMITE = 14; // 2 semanas

    private function leerObjetos() {
        if (!file_exists($this->file)) {
            return [];
        }
        $json = file_get_contents($this->file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function guardarObjetos($objetos) {
        return file_put_contents($this->file, json_encode(array_values($objetos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // Valida y guarda la foto del objeto. Devuelve ['success'=>true,'foto'=>nombre|null] o error.
    // Si no se subió archivo, foto es null (no es un error).
    private function guardarFoto($archivo) {
        if (empty($archivo) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'foto' => null];
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No se pudo subir la foto (puede ser demasiado grande)'];
        }
        if ($archivo['size'] > self::MAX_FOTO_BYTES) {
            return ['success' => false, 'message' => 'La foto no puede pesar más de 5 MB'];
        }
        // El tipo se detecta por el contenido real del archivo, no por su nombre
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if (!isset(self::TIPOS_FOTO[$mime]) || @getimagesize($archivo['tmp_name']) === false) {
            return ['success' => false, 'message' => 'El archivo debe ser una imagen JPG, PNG, WEBP o GIF'];
        }
        if (!is_dir($this->carpetaFotos)) {
            mkdir($this->carpetaFotos, 0755, true);
        }
        $nombre = uniqid('foto_', true) . '.' . self::TIPOS_FOTO[$mime];
        if (!move_uploaded_file($archivo['tmp_name'], $this->carpetaFotos . $nombre)) {
            return ['success' => false, 'message' => 'No se pudo guardar la foto'];
        }
        return ['success' => true, 'foto' => $nombre];
    }

    private function borrarFoto($nombre) {
        if (!empty($nombre)) {
            $ruta = $this->carpetaFotos . basename($nombre);
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
    }

    // Buscar un objeto por id (null si no existe)
    public function obtenerObjeto($id) {
        foreach ($this->leerObjetos() as $objeto) {
            if ($objeto['id'] == $id) {
                return $objeto;
            }
        }
        return null;
    }

    // ¿Se puede escribir sobre este objeto? No si fue entregado o ya venció.
    public function estaDisponible($objeto) {
        return ($objeto['estado'] ?? '') !== 'entregado' && ($objeto['disponible'] ?? true) !== false;
    }

    // Revisa la fecha de publicación de cada objeto: si ya pasaron 2 semanas
    // y el publicador no lo eliminó, se marca la misma publicación como "no disponible"
    // en vez de borrarla.
    private function actualizarVencidos($objetos) {
        $hoy = new DateTime('today');
        $cambios = false;

        foreach ($objetos as &$objeto) {
            $fechaPub = $objeto['fecha_publicacion'] ?? $objeto['fecha'];
            $disponible = $objeto['disponible'] ?? true;

            if ($disponible) {
                $fecha = new DateTime($fechaPub);
                $dias = (int) $hoy->diff($fecha)->format('%a');
                if ($dias >= self::DIAS_LIMITE) {
                    $objeto['disponible'] = false;
                    $cambios = true;
                }
            }
        }
        unset($objeto);

        if ($cambios) {
            $this->guardarObjetos($objetos);
        }

        return $objetos;
    }

    // Listar todos los objetos (más recientes primero)
    public function listarObjetos() {
        $objetos = $this->leerObjetos();
        $objetos = $this->actualizarVencidos($objetos);
        usort($objetos, function ($a, $b) {
            return strcmp($b['fecha'], $a['fecha']);
        });
        return $objetos;
    }

    // Agregar un objeto (reporte de perdido o encontrado)
    public function agregarObjeto($data, $archivoFoto = null) {
        $foto = $this->guardarFoto($archivoFoto);
        if (!$foto['success']) {
            return $foto;
        }

        $objetos = $this->leerObjetos();
        $id = count($objetos) > 0 ? max(array_column($objetos, 'id')) : 0;

        $nuevoObjeto = [
            'id' => $id + 1,
            'titulo' => $data['titulo'],
            'estado' => $data['estado'],
            'lugar' => $data['lugar'],
            'fecha' => $data['fecha'],
            'descripcion' => $data['descripcion'] ?? '',
            'fecha_publicacion' => date('Y-m-d'), // usada para calcular las 2 semanas
            'disponible' => true,
            'usuario_id' => $data['usuario_id'] ?? null, // quién publicó el objeto
            'foto' => $foto['foto'] // nombre del archivo en data/fotos/ (o null)
        ];

        $objetos[] = $nuevoObjeto;

        if ($this->guardarObjetos($objetos)) {
            return ['success' => true, 'message' => 'Objeto reportado correctamente', 'id' => $nuevoObjeto['id']];
        } else {
            $this->borrarFoto($foto['foto']);
            return ['success' => false, 'message' => 'No se pudo guardar el objeto'];
        }
    }

    // Editar un objeto existente
    // $usuarioId: id del usuario que hace la petición (null si no hay sesión)
    // $esAdmin: true si la petición viene del panel de administrador
    public function editarObjeto($id, $data, $usuarioId = null, $esAdmin = false, $archivoFoto = null) {
        $objetos = $this->leerObjetos();
        $encontrado = false;

        foreach ($objetos as &$objeto) {
            if ($objeto['id'] == $id) {
                if (!$esAdmin && $objeto['usuario_id'] != $usuarioId) {
                    return ['success' => false, 'message' => 'No tienes permiso para editar esta publicación'];
                }
                // Foto nueva (opcional): reemplaza la anterior. Se valida después de comprobar el permiso.
                $foto = $this->guardarFoto($archivoFoto);
                if (!$foto['success']) {
                    return $foto;
                }
                if ($foto['foto'] !== null) {
                    $this->borrarFoto($objeto['foto'] ?? null);
                    $objeto['foto'] = $foto['foto'];
                }
                $objeto['titulo'] = $data['titulo'];
                $objeto['estado'] = $data['estado'];
                $objeto['lugar'] = $data['lugar'];
                $objeto['fecha'] = $data['fecha'];
                $objeto['descripcion'] = $data['descripcion'] ?? '';
                // Si el publicador edita su publicación, se reactiva y se reinicia el plazo de 2 semanas
                // (salvo que quede como "entregado": esa siempre queda no disponible)
                $objeto['disponible'] = $data['estado'] !== 'entregado';
                $objeto['fecha_publicacion'] = date('Y-m-d');
                $encontrado = true;
                break;
            }
        }
        unset($objeto);

        if (!$encontrado) {
            return ['success' => false, 'message' => 'No se encontró el objeto a editar'];
        }

        if ($this->guardarObjetos($objetos)) {
            return ['success' => true, 'message' => 'Objeto editado correctamente'];
        } else {
            return ['success' => false, 'message' => 'No se pudo editar el objeto'];
        }
    }

    // El publicador marca su objeto como entregado (ya lo recuperó su dueño)
    public function marcarEntregado($id, $usuarioId) {
        $objetos = $this->leerObjetos();

        foreach ($objetos as &$objeto) {
            if ($objeto['id'] == $id) {
                if ($objeto['usuario_id'] != $usuarioId) {
                    return ['success' => false, 'message' => 'No tienes permiso para modificar esta publicación'];
                }
                $objeto['estado'] = 'entregado';
                $objeto['disponible'] = false; // un objeto entregado ya no está disponible
                $titulo = $objeto['titulo'];
                unset($objeto);
                return $this->guardarObjetos($objetos)
                    ? ['success' => true, 'message' => 'Publicación marcada como entregada', 'titulo' => $titulo]
                    : ['success' => false, 'message' => 'No se pudo actualizar la publicación'];
            }
        }
        unset($objeto);
        return ['success' => false, 'message' => 'No se encontró la publicación'];
    }

    // Eliminar un objeto
    // $usuarioId: id del usuario que hace la petición (null si no hay sesión)
    // $esAdmin: true si la petición viene del panel de administrador
    public function eliminarObjeto($id, $usuarioId = null, $esAdmin = false) {
        $objetos = $this->leerObjetos();
        $encontrado = false;

        foreach ($objetos as $key => $objeto) {
            if ($objeto['id'] == $id) {
                if (!$esAdmin && $objeto['usuario_id'] != $usuarioId) {
                    return ['success' => false, 'message' => 'No tienes permiso para eliminar esta publicación'];
                }
                $this->borrarFoto($objeto['foto'] ?? null);
                unset($objetos[$key]);
                $encontrado = true;
                break;
            }
        }

        if (!$encontrado) {
            return ['success' => false, 'message' => 'No se encontró el objeto a eliminar'];
        }

        if ($this->guardarObjetos($objetos)) {
            return ['success' => true, 'message' => 'Objeto eliminado correctamente'];
        } else {
            return ['success' => false, 'message' => 'No se pudo eliminar el objeto'];
        }
    }
}
