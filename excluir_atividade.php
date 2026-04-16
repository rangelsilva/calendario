<?php
/**
 * PASCOM - Activity Deletion (v2.1)
 * Post-only · CSRF Protected · Auth Guard · Integrated Logging
 */

require_once 'functions.php';
requirePerm('excluir_eventos');

// SPEC-20: Mutações de estado exigem POST. GET retorna 405.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Location: index.php?error=' . urlencode('Método não permitido.'));
    exit();
}

require_csrf_token();

$pid = current_paroquia_id();
$id  = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de atividade inválido.'));
    exit();
}

$stmt = $conn->prepare("SELECT nome, data_inicio, restrito, criador_id FROM atividades WHERE id = ? AND paroquia_id = ? LIMIT 1");
$stmt->bind_param('ii', $id, $pid);
$stmt->execute();
$act = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$act) {
    header('Location: index.php?error=' . urlencode('Atividade não encontrada.'));
    exit();
}

if ($act['restrito']) {
    $userId = (int)($_SESSION['usuario_id'] ?? 0);
    if (!can('ver_restritos') && (int)$act['criador_id'] !== $userId) {
        header('Location: index.php?error=' . urlencode('Sem permissão para excluir este evento restrito.'));
        exit();
    }
}

$del = $conn->prepare("DELETE FROM atividades WHERE id = ? AND paroquia_id = ?");
$del->bind_param('ii', $id, $pid);

if ($del->execute()) {
    logAction($conn, 'EXCLUIR_ATIVIDADE', 'atividades', $id, $act['nome']);
    $eventMonth = (int)date('n', strtotime((string)$act['data_inicio']));
    $eventYear  = (int)date('Y', strtotime((string)$act['data_inicio']));
    header('Location: index.php?m=' . $eventMonth . '&y=' . $eventYear . '&msg=' . urlencode('Atividade excluída com sucesso!') . '&refresh=1');
} else {
    header('Location: index.php?error=' . urlencode('Não foi possível excluir a atividade.'));
}

exit();
