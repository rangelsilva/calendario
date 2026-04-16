<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Database Connection Layer (v2.0)
 * Modern Error Handling · Charset Enforcement
 * ═══════════════════════════════════════════════════════ */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable for production, log instead

$db_config = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    'name' => getenv('DB_NAME') ?: 'u596929139_calen'
];

try {
    $conn = new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['name']);

    if ($conn->connect_error) {
        throw new Exception("Falha na conexão: " . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    // In a real production environment, log this to a file
    error_log($e->getMessage());
    die(json_encode([
        'success' => false,
        'message' => 'Erro crítico de banco de dados. Por favor, tente novamente mais tarde.'
    ]));
}

/**
 * Utility to close connection gracefully
 */
register_shutdown_function(function() use ($conn) {
    if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
        $conn->close();
    }
});
