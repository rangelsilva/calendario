<?php
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token(): string {
    return generate_csrf_token();
}

function verify_csrf_token(string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * SPEC-23: Rotaciona o CSRF token, invalidando o anterior.
 * Deve ser chamado apÃ³s qualquer mutaÃ§Ã£o de estado POST bem-sucedida.
 * Isso garante que tokens capturados por XSS expirem apÃ³s o primeiro uso.
 */
function csrf_token_rotate(): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function require_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verify_csrf_token($token)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'SessÃ£o expirada ou requisiÃ§Ã£o invÃ¡lida (CSRF falhou).']));
        }
        // SPEC-23: Rotaciona o token apÃ³s validaÃ§Ã£o bem-sucedida.
        // Isso invalida o token anterior, forÃ§ando um novo em cada request POST.
        csrf_token_rotate();
    }
}

// 4. Authentication Helpers
function is_authenticated(): bool {
    return isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);
}

function requireLogin(): void {
    if (!is_authenticated()) {
        header('Location: login.php');
        exit();
    }

    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        if (DB_SCHEMA_MUTATIONS_ENABLED) {
            ensurePerfisHierarchyRemoved($conn);
            ensureUserPermissionsMaterialized($conn);
            ensureWorkingGroupsTables($conn);
        }
    }
}

// 5. RBAC & Permissions Logic
function authThrottleTableExists(mysqli $db): bool {
    static $checked = false;
    static $exists = false;
    if ($checked) return $exists;
    $checked = true;

    $res = $db->query("SHOW TABLES LIKE 'auth_throttle'");
    $exists = (bool)($res && $res->num_rows > 0);
    return $exists;
}

function authThrottleFallbackKey(string $scope, string $identifier): string {
    return 'throttle|' . authThrottleKey($scope, $identifier);
}

function authThrottleFallbackState(string $scope, string $identifier, int $maxAttempts = 3): array {
    $key = authThrottleFallbackKey($scope, $identifier);
    $st = $_SESSION[$key] ?? ['attempts' => 0, 'locked_until' => 0];
    $attempts = (int)($st['attempts'] ?? 0);
    $lockedUntil = (int)($st['locked_until'] ?? 0);

    $secondsLeft = max(0, $lockedUntil - time());
    if ($secondsLeft > 0) {
        return ['allowed' => false, 'attempts' => $attempts, 'remaining' => 0, 'locked_until' => $lockedUntil, 'seconds_left' => $secondsLeft];
    }

    return ['allowed' => true, 'attempts' => $attempts, 'remaining' => max(0, $maxAttempts - $attempts), 'locked_until' => null, 'seconds_left' => 0];
}

function authThrottleFallbackRegisterFailure(string $scope, string $identifier, int $maxAttempts = 3, int $lockMinutes = 5): array {
    $key = authThrottleFallbackKey($scope, $identifier);
    $state = authThrottleFallbackState($scope, $identifier, $maxAttempts);
    if (!$state['allowed']) return $state;

    $attempts = min($maxAttempts, ((int)$state['attempts']) + 1);
    $lockedUntil = 0;
    if ($attempts >= $maxAttempts) {
        $lockedUntil = time() + ($lockMinutes * 60);
    }

    $_SESSION[$key] = ['attempts' => $attempts, 'locked_until' => $lockedUntil];
    $secondsLeft = max(0, $lockedUntil - time());

    return [
        'allowed' => $attempts < $maxAttempts,
        'attempts' => $attempts,
        'remaining' => max(0, $maxAttempts - $attempts),
        'locked_until' => $lockedUntil > 0 ? $lockedUntil : null,
        'seconds_left' => $secondsLeft,
    ];
}

function authThrottleFallbackReset(string $scope, string $identifier): void {
    $key = authThrottleFallbackKey($scope, $identifier);
    unset($_SESSION[$key]);
}

function authThrottleIdentifier(string $value): string {
    $normalized = mb_strtolower(trim($value));
    return $normalized !== '' ? $normalized : 'unknown';
}

function authThrottleKey(string $scope, string $identifier): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    return authThrottleIdentifier($scope) . '|' . authThrottleIdentifier($identifier) . '|' . $ip;
}

function authThrottleSecondsLeft(?string $lockedUntil): int {
    if (empty($lockedUntil)) {
        return 0;
    }
    $ts = strtotime((string)$lockedUntil);
    if ($ts === false) {
        return 0;
    }
    $diff = $ts - time();
    return $diff > 0 ? $diff : 0;
}

function authThrottleState(mysqli $db, string $scope, string $identifier, int $maxAttempts = 3): array {
    ensureAuthThrottleTable($db);

    $scope = authThrottleIdentifier($scope);
    $identifier = authThrottleIdentifier($identifier);

    if (!authThrottleTableExists($db)) {
        return authThrottleFallbackState($scope, $identifier, $maxAttempts);
    }

    $stmt = $db->prepare('SELECT attempts, locked_until FROM auth_throttle WHERE scope = ? AND identifier = ? LIMIT 1');
    if (!$stmt) {
        return authThrottleFallbackState($scope, $identifier, $maxAttempts);
    }

    $stmt->bind_param('ss', $scope, $identifier);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return ['allowed' => true, 'attempts' => 0, 'remaining' => $maxAttempts, 'locked_until' => null, 'seconds_left' => 0];
    }

    $attempts = (int)($row['attempts'] ?? 0);
    $lockedUntil = trim((string)($row['locked_until'] ?? ''));
    $secondsLeft = authThrottleSecondsLeft($lockedUntil ?: null);

    if ($secondsLeft > 0) {
        return [
            'allowed' => false,
            'attempts' => $attempts,
            'remaining' => 0,
            'locked_until' => $lockedUntil,
            'seconds_left' => $secondsLeft,
        ];
    }

    return [
        'allowed' => true,
        'attempts' => $attempts,
        'remaining' => max(0, $maxAttempts - $attempts),
        'locked_until' => null,
        'seconds_left' => 0,
    ];
}

function authThrottleRegisterFailure(mysqli $db, string $scope, string $identifier, int $maxAttempts = 3, int $lockMinutes = 5): array {
    ensureAuthThrottleTable($db);

    $scope = authThrottleIdentifier($scope);
    $identifier = authThrottleIdentifier($identifier);

    if (!authThrottleTableExists($db)) {
        return authThrottleFallbackRegisterFailure($scope, $identifier, $maxAttempts, $lockMinutes);
    }

    $state = authThrottleState($db, $scope, $identifier, $maxAttempts);

    if (!$state['allowed']) {
        return $state;
    }

    $attempts = min($maxAttempts, ((int)$state['attempts']) + 1);
    $lockedUntil = null;
    if ($attempts >= $maxAttempts) {
        $lockedUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
    }

    $stmt = $db->prepare('SELECT id FROM auth_throttle WHERE scope = ? AND identifier = ? LIMIT 1');
    if (!$stmt) {
        return authThrottleFallbackRegisterFailure($scope, $identifier, $maxAttempts, $lockMinutes);
    }

    $stmt->bind_param('ss', $scope, $identifier);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $id = (int)$row['id'];
        $stmtUpd = $db->prepare('UPDATE auth_throttle SET attempts = ?, locked_until = ?, last_attempt_at = NOW() WHERE id = ?');
        if ($stmtUpd) {
            $stmtUpd->bind_param('isi', $attempts, $lockedUntil, $id);
            $stmtUpd->execute();
            $stmtUpd->close();
        }
    } else {
        $stmtIns = $db->prepare('INSERT INTO auth_throttle (scope, identifier, attempts, locked_until, last_attempt_at) VALUES (?, ?, ?, ?, NOW())');
        if ($stmtIns) {
            $stmtIns->bind_param('ssis', $scope, $identifier, $attempts, $lockedUntil);
            $stmtIns->execute();
            $stmtIns->close();
        }
    }

    return [
        'allowed' => $attempts < $maxAttempts,
        'attempts' => $attempts,
        'remaining' => max(0, $maxAttempts - $attempts),
        'locked_until' => $lockedUntil,
        'seconds_left' => authThrottleSecondsLeft($lockedUntil),
    ];
}

function authThrottleReset(mysqli $db, string $scope, string $identifier): void {
    ensureAuthThrottleTable($db);

    $scope = authThrottleIdentifier($scope);
    $identifier = authThrottleIdentifier($identifier);

    if (!authThrottleTableExists($db)) {
        authThrottleFallbackReset($scope, $identifier);
        return;
    }

    $stmt = $db->prepare('DELETE FROM auth_throttle WHERE scope = ? AND identifier = ?');
    if ($stmt) {
        $stmt->bind_param('ss', $scope, $identifier);
        $stmt->execute();
    }
}
