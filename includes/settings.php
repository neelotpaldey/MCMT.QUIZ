<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/db.php';

function ensureAppSettingsTable(mysqli $db): void
{
    $db->query(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    $defaults = [
        'show_student_results' => '1',
        'groq_api_key'         => '',
        'groq_api_model'       => 'llama-3.1-8b-instant',
        'gemini_api_key'       => '',
        'gemini_api_model'     => 'gemini-2.5-flash',
        'openai_api_key'       => '',
        'openai_api_model'     => 'gpt-4o-mini',
        'smtp_host'            => '',
        'smtp_port'            => '587',
        'smtp_user'            => '',
        'smtp_pass'            => '',
        'smtp_from_email'      => '',
        'smtp_from_name'       => 'Exam Portal',
        'smtp_encryption'      => 'tls',
    ];

    foreach ($defaults as $key => $value) {
        $stmt = $db->prepare('INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

function getAppSetting(mysqli $db, string $key, string $default = ''): string
{
    ensureAppSettingsTable($db);
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) $row['setting_value'] : $default;
}

function setAppSetting(mysqli $db, string $key, string $value): void
{
    ensureAppSettingsTable($db);
    $stmt = $db->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function showStudentResults(mysqli $db): bool
{
    return getAppSetting($db, 'show_student_results', '1') === '1';
}

function getAiProviderCredentials(mysqli $db, string $provider): array
{
    require_once __DIR__ . '/questions.php';

    $provider = normalizeAiProvider($provider);

    $map = [
        'groq'   => ['groq_api_key',   'groq_api_model',   'llama-3.1-8b-instant'],
        'gemini' => ['gemini_api_key', 'gemini_api_model', 'gemini-2.5-flash'],
        'openai' => ['openai_api_key', 'openai_api_model', 'gpt-4o-mini'],
    ];

    [$keySetting, $modelSetting, $defaultModel] = $map[$provider];

    return [
        'provider' => $provider,
        'api_key'  => trim(getAppSetting($db, $keySetting)),
        'model'    => getAppSetting($db, $modelSetting, $defaultModel) ?: $defaultModel,
    ];
}

function getDefaultGeminiModel(): string
{
    return 'gemini-2.5-flash';
}

function getGeminiModelOptions(): array
{
    return [
        'gemini-2.5-flash'      => 'Gemini 2.5 Flash (recommended)',
        'gemini-1.5-flash'      => 'Gemini 1.5 Flash',
        'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
        'gemini-2.0-flash'      => 'Gemini 2.0 Flash (quota limited)',
        'gemini-1.5-pro'        => 'Gemini 1.5 Pro',
    ];
}

function getDefaultOpenAIModel(): string
{
    return 'gpt-4o-mini';
}

function getOpenAIModelOptions(): array
{
    return [
        'gpt-4o-mini'   => 'GPT-4o Mini (recommended — works on new accounts with billing)',
        'gpt-4o'        => 'GPT-4o',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        'gpt-5.4-mini'  => 'GPT-5.4 Mini (paid tier only)',
        'gpt-5.4'       => 'GPT-5.4 (paid tier only)',
        'gpt-5.5'       => 'GPT-5.5 (paid tier only)',
    ];
}
