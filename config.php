<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Core Configuration & Security (v2.0)
 * Session Management · Global Includes
 * ═══════════════════════════════════════════════════════ */

// 1. Session & Environment Security
date_default_timezone_set('America/Sao_Paulo');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
// SameSite ajuda contra CSRF basico; Secure so quando estiver em HTTPS
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
ini_set('session.cookie_samesite', 'Lax');
if ($is_https) {
    ini_set('session.cookie_secure', 1);
}
session_start();

// 2. Load Database Connection
require_once __DIR__ . '/conexao.php';

// 2.1. Schema mutations guard
// Nao executar ALTER/CREATE/DROP automaticamente em runtime.
// Ajustes de schema devem ser feitos manualmente no banco.
if (!defined('DB_SCHEMA_MUTATIONS_ENABLED')) {
    define('DB_SCHEMA_MUTATIONS_ENABLED', false);
}

// 3. Global Security Headers via Middlewares 
// Os HTTP Security headers pesados (X-Frame, XSS, HSTS) agora foram designados estritamente ao Apache (vhost.conf) Edge Layer para alta performance.
// Apenas politicas dependentes da session ou dinamicas ficam aqui:
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https:;");

// 4. Módulos da Aplicação (Extratos do antigo functions.php / config.php)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/auth_context.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/groups.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/activities.php';
