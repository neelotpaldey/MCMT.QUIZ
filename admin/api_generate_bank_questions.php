<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/questions.php';
requireAdminLogin();

header('Content-Type: application/json');

try {
    set_time_limit(600);

    $data     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $db       = getDB();
    $provider = normalizeAiProvider($data['provider'] ?? 'groq');
    $category = $data['category'] ?? 'gk';
    $count    = max(1, min(50, (int) ($data['count'] ?? 5)));
    $creds    = getAiProviderCredentials($db, $provider);

    if (!$creds['api_key']) {
        jsonResponse(['error' => 'API key not configured for this provider. Add it in Admin → Settings.'], 400);
    }

    $result = generateCategoryQuestions($provider, $creds['api_key'], $category, $count, $creds['model']);

    if (isset($result['error'])) {
        $partial = $result['partial'] ?? null;
        if (is_array($partial) && !empty($partial)) {
            $import = importQuestionsToBank($db, $partial);
            jsonResponse([
                'status'  => 'partial',
                'error'   => $result['error'],
                'added'   => $import['added'],
                'message' => "Saved {$import['added']} question(s) before error.",
            ], 422);
        }
        jsonResponse(['error' => $result['error']], 422);
    }

    $import = importQuestionsToBank($db, $result);

    $dupMsg = ($import['duplicates'] ?? 0) > 0 ? ', ' . $import['duplicates'] . ' duplicates skipped' : '';
    jsonResponse([
        'status'      => 'ok',
        'generated'   => count($result),
        'added'       => $import['added'],
        'errors'      => $import['errors'],
        'duplicates'  => $import['duplicates'] ?? 0,
        'message'     => "Generated {$import['added']} question(s) and saved to bank{$dupMsg}.",
    ]);
} catch (mysqli_sql_exception $e) {
    handleDbException($e, [
        'context' => 'api_generate_bank',
        'json'    => true,
        'message' => 'Database error while saving generated questions.',
    ]);
}
