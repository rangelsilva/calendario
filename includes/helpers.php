<?php
/**
 * Formata uma data para o padrÃ£o brasileiro (d/m/Y)
 */
function formatDate(string $date): string {
    if (empty($date)) return 'â€”';
    try {
        $dt = new DateTime($date);
        return $dt->format('d/m/Y');
    } catch (Exception $e) {
        return h($date);
    }
}

/**
 * Formata um horÃ¡rio para o padrÃ£o (H:i)
 */
function formatTime(?string $time): string {
    if (empty($time)) return '00:00';
    return substr((string)$time, 0, 5);
}

/**
 * Retorna o label de nÃ­vel de acesso.
 * Escala invertida: 0 = maior privilÃ©gio (Master), 7 = menor (Visitante).
 */
function getAccessLabel(int $level): string {
    static $labels = [
        0 => 'Master',
        1 => 'Administrador',
        2 => 'Gerente',
        3 => 'Supervisor',
        4 => 'Encarregado',
        5 => 'Trabalhador',
        6 => 'Consultor',
        7 => 'Visitante',
    ];
    return $labels[$level] ?? ('NÃ­vel ' . $level);
}

/** @deprecated Use getAccessLabel() */
function getAccessLabelV2(int $level): string {
    return getAccessLabel($level);
}


/**
 * Sanitiza inputs de formulÃ¡rio em massa
 */
function sanitize_post(array $data): array {
    $sanitized = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitize_post($value);
        } else {
            $sanitized[$key] = trim($value);
        }
    }
    return $sanitized;
}

/**
 * Gera um alerta HTML estilizado para o sistema
 */
function alert(string $type, string $message): string {
    $colors = [
        'success' => 'rgba(34, 197, 94, 0.1), #22c55e',
        'error'   => 'rgba(239, 68, 68, 0.1), #ef4444',
        'info'    => 'rgba(59, 130, 246, 0.1), #3b82f6'
    ];
    $style = $colors[$type] ?? $colors['info'];
    list($bg, $border) = explode(', ', $style);
    
    return "<div style='background:{$bg}; border:1px solid {$border}; color:{$border}; padding:1rem; border-radius:var(--r-md); margin-bottom:1.5rem; font-size:0.85rem; font-weight:700; text-align:center;'>{$message}</div>";
}

/**
 * Retorna todos os grupos de trabalho de uma parÃ³quia
 */
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function json_response(bool $success, string $message = '', array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit();
}

/**
 * Centralized logging function
 */
function logAction(mysqli $db, string $acao, string $tabela = '', int $regId = 0, mixed $detalhes = ''): void {
    $uid = $_SESSION['usuario_id'] ?? null;
    $parish_id = $_SESSION['paroquia_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    
    // Auto-serialize arrays for detailed state logs
    if (is_array($detalhes) || is_object($detalhes)) {
        $detalhes = json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    $sql = "INSERT INTO log_alteracoes (usuario_id, acao, tabela_afetada, registro_id, detalhes_alteracao, paroquia_id, ip_origem) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('issisis', $uid, $acao, $tabela, $regId, $detalhes, $parish_id, $ip);
        $stmt->execute();
    }
    
    // Cloud-Native Observability: Pipe security events para stdout/stderr em formato JSON
    // para captura pelo Docker Daemon, Datadog ou Elastic Stack.
    $observability_event = [
        'timestamp' => date('c'),
        'level' => 'INFO',
        'event_name' => $acao,
        'user_id' => $uid,
        'table' => $tabela,
        'record_id' => $regId,
        'client_ip' => $ip,
        'parish_id' => $parish_id,
        'event_details' => is_string($detalhes) ? json_decode($detalhes, true) ?? $detalhes : $detalhes,
    ];
    error_log(json_encode($observability_event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

