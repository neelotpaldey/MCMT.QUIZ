<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/questions.php';
requireAdminLogin();

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

$provider   = $data['provider'] ?? 'gemini';
$apiKey     = $data['api_key'] ?? '';
$gkQ        = max(1, (int)($data['gk_questions'] ?? 2));
$enQ        = max(1, (int)($data['english_questions'] ?? 2));
$logQ       = max(1, (int)($data['logical_questions'] ?? 1));
$model      = $data['api_model'] ?? '';

if (!$apiKey) {
    echo json_encode(['error' => 'API key is required']);
    exit;
}

if ($provider === 'gemini') {
    $result = generateGeminiQuestions($apiKey, $gkQ, $enQ, $logQ, $model ?: getDefaultGeminiModel());
} else {
    $result = generateGroqQuestions($apiKey, $gkQ, $enQ, $logQ, $model ?: 'llama3-8b-8192');
}

echo json_encode($result);
