<?php
// Mensajes entre usuarios, siempre ligados a una publicación (objeto).
// Cada mensaje: id, objeto_id, de_id, para_id, texto, fecha, leido
class MensajeManager {
    private $file = __DIR__ . '/../../data/mensajes.json';

    const MAX_CARACTERES = 1000;

    private function leerMensajes() {
        if (!file_exists($this->file)) {
            return [];
        }
        $data = json_decode(file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    private function guardarMensajes($mensajes) {
        return file_put_contents(
            $this->file,
            json_encode(array_values($mensajes), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function enviar($objetoId, $deId, $paraId, $texto) {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return ['success' => false, 'message' => 'Escribe un mensaje antes de enviar'];
        }
        if (mb_strlen($texto) > self::MAX_CARACTERES) {
            return ['success' => false, 'message' => 'El mensaje no puede pasar de ' . self::MAX_CARACTERES . ' caracteres'];
        }

        $mensajes = $this->leerMensajes();
        $id = count($mensajes) > 0 ? max(array_column($mensajes, 'id')) : 0;

        $mensajes[] = [
            'id' => $id + 1,
            'objeto_id' => (int) $objetoId,
            'de_id' => (int) $deId,
            'para_id' => (int) $paraId,
            'texto' => $texto,
            'fecha' => date('Y-m-d H:i:s'),
            'leido' => false
        ];

        return $this->guardarMensajes($mensajes)
            ? ['success' => true, 'message' => 'Mensaje enviado']
            : ['success' => false, 'message' => 'No se pudo enviar el mensaje'];
    }

    // Mensajes sin leer de $deId hacia $paraId en este objeto (para no llenar el correo de avisos)
    public function contarNoLeidosHilo($objetoId, $deId, $paraId) {
        $total = 0;
        foreach ($this->leerMensajes() as $m) {
            if ($m['objeto_id'] == $objetoId && $m['de_id'] == $deId && $m['para_id'] == $paraId && empty($m['leido'])) {
                $total++;
            }
        }
        return $total;
    }

    // Ids de las personas que le escribieron al publicador sobre este objeto
    public function usuariosQueEscribieron($objetoId, $duenoId) {
        $ids = [];
        foreach ($this->leerMensajes() as $m) {
            if ($m['objeto_id'] == $objetoId && $m['para_id'] == $duenoId) {
                $ids[$m['de_id']] = true;
            }
        }
        return array_keys($ids);
    }

    // ¿$deId ya le escribió a $paraId sobre este objeto? (para que el dueño pueda responder)
    public function existeMensaje($objetoId, $deId, $paraId) {
        foreach ($this->leerMensajes() as $m) {
            if ($m['objeto_id'] == $objetoId && $m['de_id'] == $deId && $m['para_id'] == $paraId) {
                return true;
            }
        }
        return false;
    }

    // Una conversación = un objeto + la otra persona. Devuelve la lista más reciente primero.
    public function listarConversaciones($usuarioId) {
        $conversaciones = [];

        foreach ($this->leerMensajes() as $m) {
            if ($m['de_id'] != $usuarioId && $m['para_id'] != $usuarioId) {
                continue;
            }
            $otroId = $m['de_id'] == $usuarioId ? $m['para_id'] : $m['de_id'];
            $clave = $m['objeto_id'] . '-' . $otroId;

            if (!isset($conversaciones[$clave])) {
                $conversaciones[$clave] = ['objeto_id' => $m['objeto_id'], 'otro_id' => $otroId, 'no_leidos' => 0];
            }
            $conversaciones[$clave]['ultimo_texto'] = $m['texto'];
            $conversaciones[$clave]['ultima_fecha'] = $m['fecha'];
            $conversaciones[$clave]['ultimo_propio'] = $m['de_id'] == $usuarioId;
            if ($m['para_id'] == $usuarioId && empty($m['leido'])) {
                $conversaciones[$clave]['no_leidos']++;
            }
        }

        $lista = array_values($conversaciones);
        usort($lista, function ($a, $b) {
            return strcmp($b['ultima_fecha'], $a['ultima_fecha']);
        });
        return $lista;
    }

    // Mensajes entre $usuarioId y $otroId sobre un objeto; marca como leídos los recibidos.
    public function listarHilo($usuarioId, $objetoId, $otroId) {
        $mensajes = $this->leerMensajes();
        $hilo = [];
        $cambios = false;

        foreach ($mensajes as &$m) {
            if ($m['objeto_id'] != $objetoId) continue;
            $entre = ($m['de_id'] == $usuarioId && $m['para_id'] == $otroId)
                  || ($m['de_id'] == $otroId && $m['para_id'] == $usuarioId);
            if (!$entre) continue;

            if ($m['para_id'] == $usuarioId && empty($m['leido'])) {
                $m['leido'] = true;
                $cambios = true;
            }
            $hilo[] = [
                'texto' => $m['texto'],
                'fecha' => $m['fecha'],
                'propio' => $m['de_id'] == $usuarioId
            ];
        }
        unset($m);

        if ($cambios) {
            $this->guardarMensajes($mensajes);
        }
        return $hilo;
    }

    public function contarNoLeidos($usuarioId) {
        $total = 0;
        foreach ($this->leerMensajes() as $m) {
            if ($m['para_id'] == $usuarioId && empty($m['leido'])) {
                $total++;
            }
        }
        return $total;
    }
}
