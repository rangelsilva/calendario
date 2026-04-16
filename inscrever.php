<?php
require_once 'functions.php';
requireLogin();

if (!ensureInscricoesTable($conn)) {
    json_response(false, 'Não foi possível preparar a estrutura de inscrições.');
}

if (!ensureEventActivitiesStructure($conn)) {
    json_response(false, 'Não foi possível preparar as atividades do evento.');
}

$aid = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$itemId = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
$action = strtolower(trim($_POST['action'] ?? $_GET['action'] ?? ''));
$userId = (int)($_SESSION['usuario_id'] ?? 0);
$pid = current_paroquia_id();

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        json_response(false, 'Apenas requisições POST seguras são permitidas.');
    }
    header('Location: index.php?error=invalid_request_method');
    exit();
}

require_csrf_token();

if ($aid <= 0 || !in_array($action, ['join', 'leave'], true)) {
    if ($isAjax) {
        json_response(false, 'Requisição inválida.');
    }
    header('Location: index.php?error=invalid_request');
    exit();
}

$stmt = $conn->prepare("
    SELECT a.id, a.nome, a.data_inicio, a.hora_inicio, a.paroquia_id, a.restrito, a.criador_id
    FROM atividades a
    WHERE a.id = ? AND a.paroquia_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $aid, $pid);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();

if (!$activity) {
    if ($isAjax) {
        json_response(false, 'Atividade não encontrada.');
    }
    header('Location: index.php?error=activity_not_found');
    exit();
}

if ($activity['restrito']) {
    if (!can('ver_restritos') && $activity['criador_id'] != $userId) {
        if ($isAjax) {
            json_response(false, 'Você não tem permissão para interagir com este evento restrito.');
        }
        header('Location: index.php?error=unauthorized_restricted');
        exit();
    }
}

$targetTable = 'inscricoes';
$targetRecordId = $aid;
$targetName = $activity['nome'];
$checkSql = "SELECT id FROM inscricoes WHERE atividade_id = ? AND usuario_id = ? LIMIT 1";
$insertSql = "
    INSERT INTO inscricoes (atividade_id, usuario_id, data_inscricao)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE data_inscricao = data_inscricao
";
$deleteSql = "DELETE FROM inscricoes WHERE atividade_id = ? AND usuario_id = ?";
$bindId = $aid;
$logAction = 'INSCREVER_ATIVIDADE';
$cancelLogAction = 'CANCELAR_INSCRICAO_ATIVIDADE';

if ($itemId > 0) {
    $itemStmt = $conn->prepare("
        SELECT ei.id, ac.nome
        FROM atividade_evento_itens ei
        INNER JOIN atividades_catalogo ac ON ac.id = ei.atividade_catalogo_id
        INNER JOIN atividades a ON a.id = ei.evento_id
        WHERE ei.id = ? AND ei.evento_id = ? AND a.paroquia_id = ?
        LIMIT 1
    ");
    $itemStmt->bind_param('iii', $itemId, $aid, $pid);
    $itemStmt->execute();
    $eventItem = $itemStmt->get_result()->fetch_assoc();

    if (!$eventItem) {
        if ($isAjax) {
            json_response(false, 'Atividade do evento não encontrada.');
        }
        header('Location: ver_atividade.php?id=' . $aid . '&error=' . urlencode('Atividade do evento não encontrada.'));
        exit();
    }

    $targetTable = 'atividade_evento_inscricoes';
    $targetRecordId = (int)$eventItem['id'];
    $targetName = $eventItem['nome'];
    $checkSql = "SELECT id FROM atividade_evento_inscricoes WHERE evento_item_id = ? AND usuario_id = ? LIMIT 1";
    $insertSql = "
        INSERT INTO atividade_evento_inscricoes (evento_item_id, usuario_id, data_inscricao)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE data_inscricao = data_inscricao
    ";
    $deleteSql = "DELETE FROM atividade_evento_inscricoes WHERE evento_item_id = ? AND usuario_id = ?";
    $bindId = (int)$eventItem['id'];
    $logAction = 'INSCREVER_ATIVIDADE_EVENTO';
    $cancelLogAction = 'CANCELAR_INSCRICAO_ATIVIDADE_EVENTO';
}

if ($action === 'join') {
    $insert = $conn->prepare($insertSql);
    $insert->bind_param('ii', $bindId, $userId);
    $ok = $insert->execute();

    if ($ok) {
        logAction($conn, $logAction, $targetTable, $targetRecordId, [
            'atividade_id' => $aid,
            'evento_item_id' => $itemId > 0 ? $bindId : null,
            'usuario_id' => $userId,
        ]);
        $message = $itemId > 0
            ? "Inscrição realizada em {$targetName}."
            : 'Inscrição realizada com sucesso.';
        if ($isAjax) {
            json_response(true, $message);
        }
        header('Location: ver_atividade.php?id=' . $aid . '&msg=' . urlencode($message));
        exit();
    }

    if ($isAjax) {
        json_response(false, 'Não foi possível concluir a inscrição.');
    }
    header('Location: ver_atividade.php?id=' . $aid . '&error=' . urlencode('Não foi possível concluir a inscrição.'));
    exit();
}

$check = $conn->prepare($checkSql);
$check->bind_param('ii', $bindId, $userId);
$check->execute();
$existing = $check->get_result()->fetch_assoc();

if (!$existing) {
    $message = $itemId > 0
        ? 'Você não está inscrito nesta atividade do evento.'
        : 'Você não está inscrito nesta atividade.';
    if ($isAjax) {
        json_response(false, $message);
    }
    header('Location: ver_atividade.php?id=' . $aid . '&error=' . urlencode($message));
    exit();
}

$deadlineTs = activityStartTimestamp($activity) - 86400;
if (time() > $deadlineTs && !canBypassEnrollmentDeadline()) {
    $message = 'Somente usuários de nível 3 ou superior podem desistir com menos de 24 horas de antecedência.';
    if ($isAjax) {
        json_response(false, $message);
    }
    header('Location: ver_atividade.php?id=' . $aid . '&error=' . urlencode($message));
    exit();
}

$delete = $conn->prepare($deleteSql);
$delete->bind_param('ii', $bindId, $userId);
$ok = $delete->execute();

if ($ok) {
    logAction($conn, $cancelLogAction, $targetTable, $targetRecordId, [
        'atividade_id' => $aid,
        'evento_item_id' => $itemId > 0 ? $bindId : null,
        'usuario_id' => $userId,
    ]);
    $message = $itemId > 0
        ? "Participação cancelada em {$targetName}."
        : 'Participação cancelada com sucesso.';
    if ($isAjax) {
        json_response(true, $message);
    }
    header('Location: ver_atividade.php?id=' . $aid . '&msg=' . urlencode($message));
    exit();
}

if ($isAjax) {
    json_response(false, 'Não foi possível cancelar a participação.');
}

header('Location: ver_atividade.php?id=' . $aid . '&error=' . urlencode('Não foi possível cancelar a participação.'));
