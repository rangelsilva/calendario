<?php

// Define constantes que normalmente existiriam no ambiente real
define('ENVIRONMENT', 'test');

// Garante que a sessão está ativa para funções que dependam dela
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Carrega o autoloader do Composer quando disponível
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // Fallback: carrega helpers manualmente se o composer não rodou ainda
    require_once __DIR__ . '/../includes/helpers.php';
}
