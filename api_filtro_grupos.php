<?php
/**
 * ─────────────────────────────────────────────────
 * PASCOM — API: Salvar Filtro de Grupos (v1.0)
 * Salva as preferências de filtragem do calendário
 * ─────────────────────────────────────────────────
 */
require_once 'functions.php';
requireLogin();

header('Content-Type: application/json');

require_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'toggle') {
    $grupoId = (int)($_POST['grupo_id'] ?? -1);
    $ativo   = (int)($_POST['ativo'] ?? 1);

    if (!isset($_SESSION['filtro_grupos'])) {
        $_SESSION['filtro_grupos'] = null; // null = "todos ativos" (default)
    }

    // Ao fazer a primeira alteração, inicializamos com os IDs dos grupos do usuário
    if ($_SESSION['filtro_grupos'] === null) {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        $pid = (int)($_SESSION['paroquia_id'] ?? 0);
        $res = $conn->query("SELECT grupo_id FROM usuario_grupos ug 
                             INNER JOIN grupos_trabalho g ON g.id = ug.grupo_id
                             WHERE ug.usuario_id = $uid AND g.paroquia_id = $pid AND g.ativo = 1");
        $todos = [];
        while ($r = $res->fetch_assoc()) {
            $todos[] = (int)$r['grupo_id'];
        }
        $_SESSION['filtro_grupos'] = $todos;
    }

    $lista = &$_SESSION['filtro_grupos'];

    if ($grupoId === -1) {
        // Ação global: todos ou nenhum
        if ($ativo) {
            // Restore: null means "all active again"
            $_SESSION['filtro_grupos'] = null;
        } else {
            $_SESSION['filtro_grupos'] = []; // nenhum ativo
        }
    } else {
        if ($ativo) {
            if (!in_array($grupoId, $lista, true)) {
                $lista[] = $grupoId;
            }
        } else {
            $lista = array_values(array_filter($lista, fn($id) => $id !== $grupoId));
        }
    }

    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
}
