<?php

require_once __DIR__ . '/../vendor/autoload.php';

use MGI\CMIS\CMISConfig;
use MGI\CMIS\CMISService;
use MGI\CMIS\CMISRestAPI;

// Carregar .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!empty($name)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}

try {
    // Carregar configuração e criar serviço
    $config = CMISConfig::fromEnvironment();
    $service = new CMISService($config);

    // Criar API REST
    $api = new CMISRestAPI($service);

    // Processar requisição
    $api->handleRequest();

} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
