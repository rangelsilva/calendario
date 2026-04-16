<?php
function addNotification(mysqli $db, int $userId, string $message): bool {
    ensureNotificationsTable($db);
    $stmt = $db->prepare("INSERT INTO notificacoes (usuario_id, mensagem) VALUES (?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('is', $userId, $message);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

