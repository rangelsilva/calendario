<?php
/**
 * Retorna o ID da parÃ³quia atual da sessÃ£o
 */
function current_paroquia_id(): int {
    return (int)($_SESSION['paroquia_id'] ?? 0);
}

function current_user_id(): int {
    return (int)($_SESSION['usuario_id'] ?? 0);
}

function current_user_perfil_id(mysqli $db): int {
    $sid = (int)($_SESSION['usuario_perfil_id'] ?? 0);
    if ($sid > 0) return $sid;

    $uid = current_user_id();
    if ($uid <= 0) return 0;

    $stmt = $db->prepare('SELECT perfil_id FROM usuarios WHERE id = ? LIMIT 1');
    if (!$stmt) return 0;
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $pid = (int)($row['perfil_id'] ?? 0);
    $_SESSION['usuario_perfil_id'] = $pid;
    return $pid;
}

function userCanSwitchParish(): bool {
    return (bool)(
        ($_SESSION['usuario_id'] ?? 0) === 1 ||
        can('admin_sistema') ||
        (int)($_SESSION['usuario_nivel'] ?? -1) === 0
    );
}

function canInteractWithActivity(): bool {
    return is_authenticated() && can('ver_calendario');
}

function canBypassEnrollmentDeadline(): bool {
    // nivel_acesso usa escala invertida: 0 = Master, 7 = Visitante.
    // Apenas nÃ­veis privilegiados (<= 3: Master, Admin, Coordenador, Supervisor)
    // podem cancelar inscriÃ§Ã£o com menos de 24h de antecedÃªncia (RN09).
    return (bool)(
        can('admin_sistema') ||
        ($_SESSION['usuario_id'] ?? 0) === 1 ||
        (int)($_SESSION['usuario_nivel'] ?? 99) <= 3
    );
}

