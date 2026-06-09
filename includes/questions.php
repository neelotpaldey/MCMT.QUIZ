<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/db.php';

// ── Pull questions from stored bank ───────────────────────────────
function getBankQuestions($db, $examId, $gkCount, $englishCount, $logicalCount) {
    $questions = [];
    $categories = [
        'gk'      => (int)$gkCount,
        'english'  => (int)$englishCount,
        'logical'  => (int)$logicalCount,
    ];

    foreach ($categories as $cat => $count) {
        if ($count <= 0) continue;
        // prefer exam-specific first, fall back to global bank
        $sql = "SELECT * FROM question_bank
                WHERE category = '$cat'
                ORDER BY RAND() LIMIT $count";
        $res = $db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $questions[] = $row;
        }
    }
    shuffle($questions);
    return $questions;
}

// ── Call Gemini API ───────────────────────────────────────────────
function generateGeminiQuestions($apiKey, $gkCount, $englishCount, $logicalCount, $model = 'gemini-2.5-flash') {
    $total = $gkCount + $englishCount + $logicalCount;
    $prompt = buildQuestionPrompt($gkCount, $englishCount, $logicalCount);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => 8192],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => $err];

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = parseGeneratedQuestions($text);
    if (isset($parsed['error'])) {
        return $parsed;
    }
    return deduplicateQuestions($parsed)['questions'];
}

function generateGroqCategoryQuestions(string $apiKey, string $category, int $count, string $model = 'llama-3.1-8b-instant'): array
{
    return generateCategoryQuestions('groq', $apiKey, $category, $count, $model);
}

function normalizeAiProvider(string $provider): string
{
    $provider = strtolower(trim($provider));
    if (in_array($provider, ['chatgpt', 'gpt', 'openai'], true)) {
        return 'openai';
    }
    if ($provider === 'gemini') {
        return 'gemini';
    }
    return 'groq';
}

function getProviderBatchSize(string $provider): int
{
    return match (normalizeAiProvider($provider)) {
        'groq'   => 2,
        'gemini' => 5,
        'openai' => 4,
        default  => 2,
    };
}

function getProviderBatchDelay(string $provider): int
{
    return match (normalizeAiProvider($provider)) {
        'groq' => 12,
        default => 2,
    };
}

function isAiQuotaError(string $message): bool
{
    $m = strtolower($message);
    return str_contains($m, 'insufficient_quota')
        || str_contains($m, 'exceeded your current quota')
        || str_contains($m, 'exceeded your current')
        || str_contains($m, 'limit: 0')
        || str_contains($m, 'billing hard limit')
        || (str_contains($m, 'quota') && str_contains($m, 'billing'));
}

function isAiAuthError(string $message): bool
{
    $m = strtolower($message);
    return str_contains($m, 'invalid api key')
        || str_contains($m, 'incorrect api key')
        || str_contains($m, 'invalid_api_key')
        || str_contains($m, 'authentication')
        || str_contains($m, 'unauthorized');
}

function isAiModelError(string $message): bool
{
    $m = strtolower($message);
    return str_contains($m, 'model_not_found')
        || str_contains($m, 'does not exist')
        || (str_contains($m, 'model') && str_contains($m, 'not found'))
        || str_contains($m, 'not available')
        || (str_contains($m, 'free tier') && str_contains($m, 'not supported'));
}

function isAiRateLimitError(string $message): bool
{
    $m = strtolower($message);
    return str_contains($m, 'rate limit')
        || str_contains($m, 'tpm')
        || str_contains($m, 'too large')
        || str_contains($m, 'please retry');
}

function formatAiError(string $message, ?string $provider = null): string
{
    $provider = $provider ? normalizeAiProvider($provider) : null;

    if (isAiAuthError($message)) {
        return 'Invalid API key. Create a new key at platform.openai.com/api-keys (must start with sk-), paste it in Settings, and save. '
            . 'OpenAI says: ' . $message;
    }

    if (isAiModelError($message)) {
        $hint = $provider === 'openai'
            ? 'Try GPT-4o Mini in Settings — GPT-5 models require a paid OpenAI tier.'
            : 'Pick another model in Settings.';
        return "Model not available on your account. {$hint} OpenAI says: {$message}";
    }

    if (isAiQuotaError($message)) {
        if ($provider === 'openai') {
            return 'OpenAI requires billing before any model works — creating an API key is not enough. '
                . 'Go to platform.openai.com/settings/billing, add a payment method, and add at least $5 credit. '
                . 'Until then, use Groq or Gemini (free tier). OpenAI says: ' . $message;
        }
        return 'API quota or billing limit reached on this provider. '
            . 'Add credits / enable billing, pick another model in Settings, or use Groq. '
            . 'Details: ' . $message;
    }

    if (isAiRateLimitError($message)) {
        return 'Rate limit reached — wait 30–60 seconds and try fewer questions at once. Details: ' . $message;
    }

    if (str_contains(strtolower($message), 'no json')) {
        return 'AI returned an invalid format on the last batch. Retried automatically; try 1–2 fewer questions if this persists.';
    }

    return $message;
}

function generateCategoryQuestions(string $provider, string $apiKey, string $category, int $count, string $model): array
{
    if (!in_array($category, ['gk', 'english', 'logical'], true)) {
        return ['error' => 'Invalid category'];
    }

    $provider    = normalizeAiProvider($provider);
    $count       = max(1, min(50, $count));
    $batchSize   = getProviderBatchSize($provider);
    $delay       = getProviderBatchDelay($provider);
    $all         = [];
    $seenKeys    = [];
    $attempts    = 0;
    $maxAttempts = max(30, $count * 5);
    $lastError   = '';

    while (count($all) < $count && $attempts < $maxAttempts) {
        $attempts++;
        $need       = $count - count($all);
        $batchCount = min($batchSize, $need);
        $batch      = generateCategoryQuestionsSingleBatchWithRetry(
            $provider,
            $apiKey,
            $category,
            $batchCount,
            $model,
            3
        );

        if (isset($batch['error'])) {
            $lastError = $batch['error'];

            if (isAiQuotaError($lastError)) {
                if (!empty($all)) {
                    return [
                        'error'   => formatAiError($lastError),
                        'partial' => $all,
                    ];
                }
                return ['error' => formatAiError($lastError)];
            }

            if ((isAiRateLimitError($lastError) || $batchCount > 1) && $batchSize > 1) {
                $batchSize = 1;
                sleep($delay);
                continue;
            }

            sleep($delay);
            continue;
        }

        $deduped  = deduplicateQuestions($batch, $seenKeys);
        $seenKeys = $deduped['seen'];
        $all      = array_merge($all, $deduped['questions']);

        if (count($all) < $count) {
            sleep($delay);
        }
    }

    if (empty($all)) {
        return ['error' => formatAiError($lastError ?: 'No questions were generated. Try fewer questions or another provider.')];
    }

    $all = array_slice($all, 0, $count);

    if (count($all) < $count) {
        return [
            'error'   => 'Generated ' . count($all) . " of {$count} questions. Run again to try filling the rest.",
            'partial' => $all,
        ];
    }

    return $all;
}

function generateCategoryQuestionsSingleBatchWithRetry(
    string $provider,
    string $apiKey,
    string $category,
    int $count,
    string $model,
    int $maxRetries = 3
): array {
    $last = ['error' => 'Unknown error'];

    for ($try = 1; $try <= $maxRetries; $try++) {
        $last = generateCategoryQuestionsSingleBatch($provider, $apiKey, $category, $count, $model);

        if (!isset($last['error'])) {
            return $last;
        }

        if (isAiQuotaError($last['error'])) {
            return $last;
        }

        if ($count > 1 && $try < $maxRetries) {
            $singles = [];
            for ($i = 0; $i < $count; $i++) {
                $one = generateCategoryQuestionsSingleBatch($provider, $apiKey, $category, 1, $model);
                if (!isset($one['error']) && !empty($one)) {
                    $singles = array_merge($singles, $one);
                }
                usleep(400000);
            }
            if (!empty($singles)) {
                return $singles;
            }
        }

        sleep(min(3, $try * 2));
    }

    return $last;
}

function generateCategoryQuestionsSingleBatch(string $provider, string $apiKey, string $category, int $count, string $model): array
{
    $count  = max(1, min(10, $count));
    $prompt = buildCategoryQuestionPrompt($category, $count);

    $text = match (normalizeAiProvider($provider)) {
        'gemini' => callGeminiText($apiKey, $model, $prompt, $count),
        'openai' => callOpenAIText($apiKey, $model, $prompt, $count),
        default  => callGroqText($apiKey, $model, $prompt, $count),
    };

    if (is_array($text) && isset($text['error'])) {
        return $text;
    }
    if (!is_string($text) || trim($text) === '') {
        return ['error' => 'Empty response from AI provider'];
    }

    $parsed = parseGeneratedQuestions($text, $category);
    if (isset($parsed['error'])) {
        return $parsed;
    }

    foreach ($parsed as &$q) {
        $q['category'] = $category;
    }
    unset($q);

    return $parsed;
}

function callGroqText(string $apiKey, string $model, string $prompt, int $questionCount): array|string
{
    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => 'Reply with valid JSON only. No markdown.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.7,
        'max_tokens'  => min(2800, 600 * $questionCount),
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $data = json_decode($response, true);
    if (isset($data['error']['message'])) {
        return ['error' => formatAiError($data['error']['message'])];
    }

    return $data['choices'][0]['message']['content'] ?? '';
}

function callGeminiText(string $apiKey, string $model, string $prompt, int $questionCount): array|string
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0.7,
            'maxOutputTokens'  => min(8192, 650 * $questionCount),
            'responseMimeType' => 'application/json',
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 90,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $data = json_decode($response, true);
    if (isset($data['error']['message'])) {
        return ['error' => formatAiError($data['error']['message'])];
    }

    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

function callOpenAIText(string $apiKey, string $model, string $prompt, int $questionCount): array|string
{
    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => 'Reply with valid JSON only. No markdown.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.7,
        'max_tokens'  => min(4096, 600 * $questionCount),
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $data = json_decode($response, true);
    if (isset($data['error']['message'])) {
        return ['error' => formatAiError($data['error']['message'], 'openai')];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    if (trim($content) === '') {
        return ['error' => 'Empty response from OpenAI. Check your API key and billing at platform.openai.com/settings/billing.'];
    }

    return $content;
}

function normalizeQuestionText(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

    return $text;
}

function getQuestionFingerprint(array $question): string
{
    return normalizeQuestionText($question['question_text'] ?? '');
}

function deduplicateQuestions(array $questions, array $seenKeys = []): array
{
    $unique  = [];
    $removed = 0;

    foreach ($questions as $question) {
        $key = getQuestionFingerprint($question);
        if ($key === '' || isset($seenKeys[$key])) {
            $removed++;
            continue;
        }
        $seenKeys[$key] = true;
        $unique[]       = $question;
    }

    return [
        'questions' => $unique,
        'removed'   => $removed,
        'seen'      => $seenKeys,
    ];
}

function loadBankQuestionFingerprints(mysqli $db, array $categories): array
{
    $categories = array_values(array_unique(array_filter(
        $categories,
        fn ($cat) => in_array($cat, ['gk', 'english', 'logical'], true)
    )));

    if (empty($categories)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $types        = str_repeat('s', count($categories));
    $stmt         = $db->prepare("SELECT question_text FROM question_bank WHERE category IN ($placeholders)");
    $stmt->bind_param($types, ...$categories);
    $stmt->execute();
    $res = $stmt->get_result();

    $seen = [];
    while ($row = $res->fetch_assoc()) {
        $key = normalizeQuestionText($row['question_text'] ?? '');
        if ($key !== '') {
            $seen[$key] = true;
        }
    }
    $stmt->close();

    return $seen;
}

function importQuestionsToBank(mysqli $db, array $questions): array
{
    $added       = 0;
    $errors      = 0;
    $duplicates  = 0;
    $categories  = [];
    foreach ($questions as $qdata) {
        $cat = $qdata['category'] ?? 'gk';
        if (in_array($cat, ['gk', 'english', 'logical'], true)) {
            $categories[] = $cat;
        }
    }
    $seenKeys = loadBankQuestionFingerprints($db, $categories);

    $stmt = $db->prepare(
        'INSERT INTO question_bank (category, question_text, option_a, option_b, option_c, option_d, correct_answer, explanation, difficulty)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($questions as $qdata) {
        $cat  = in_array($qdata['category'] ?? '', ['gk', 'english', 'logical'], true) ? $qdata['category'] : 'gk';
        $q    = trim($qdata['question_text'] ?? '');
        $a    = trim($qdata['option_a'] ?? '');
        $b    = trim($qdata['option_b'] ?? '');
        $c    = trim($qdata['option_c'] ?? '');
        $d    = trim($qdata['option_d'] ?? '');
        $ans  = strtoupper(trim($qdata['correct_answer'] ?? 'A'));
        $exp  = trim($qdata['explanation'] ?? '');
        $diff = in_array($qdata['difficulty'] ?? '', ['easy', 'medium', 'hard'], true) ? $qdata['difficulty'] : 'medium';

        if (!$q || !$a || !$b || !$c || !$d || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            $errors++;
            continue;
        }

        $fingerprint = normalizeQuestionText($q);
        if ($fingerprint === '' || isset($seenKeys[$fingerprint])) {
            $duplicates++;
            continue;
        }
        $seenKeys[$fingerprint] = true;

        $stmt->bind_param('sssssssss', $cat, $q, $a, $b, $c, $d, $ans, $exp, $diff);
        if ($stmt->execute()) {
            $added++;
        } else {
            $errors++;
        }
    }

    $stmt->close();

    return ['added' => $added, 'errors' => $errors, 'duplicates' => $duplicates];
}

// ── Call Groq API ─────────────────────────────────────────────────
function generateGroqQuestions($apiKey, $gkCount, $englishCount, $logicalCount, $model = 'llama3-8b-8192') {
    $prompt = buildQuestionPrompt($gkCount, $englishCount, $logicalCount);

    $payload = json_encode([
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.9,
        'max_tokens'  => 8192,
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => $err];

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    $parsed = parseGeneratedQuestions($text);
    if (isset($parsed['error'])) {
        return $parsed;
    }
    return deduplicateQuestions($parsed)['questions'];
}

// ── Prompt builder ────────────────────────────────────────────────
function buildCategoryQuestionPrompt(string $category, int $count): string
{
    $labels = [
        'gk'      => 'General Knowledge',
        'english' => 'Basic English',
        'logical' => 'Logical Reasoning',
    ];
    $label = $labels[$category] ?? 'General Knowledge';

    return "Generate exactly {$count} {$label} MCQs for Indian 10+2 students. "
        . "Return ONLY a JSON array (no markdown). Each item: "
        . "{\"category\":\"{$category}\",\"question_text\":\"...\",\"option_a\":\"...\","
        . "\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_answer\":\"A\"}. "
        . "Rules: 4 options, correct_answer is A/B/C/D only, all questions unique.";
}

function buildQuestionPrompt($gkCount, $englishCount, $logicalCount) {
    return <<<PROMPT
Generate exactly {$gkCount} General Knowledge, {$englishCount} Basic English, and {$logicalCount} Logical Reasoning multiple-choice questions suitable for 10+2 / Intermediate level students (ages 17-19 in India).

Return ONLY a valid JSON array with NO extra text or markdown. Format:
[
  {
    "category": "gk",
    "question_text": "...",
    "option_a": "...",
    "option_b": "...",
    "option_c": "...",
    "option_d": "...",
    "correct_answer": "A"
  },
  ...
]

Rules:
- Each question must have exactly 4 options (A, B, C, D)
- correct_answer must be exactly one of: A, B, C, D
- Questions must be clear, unambiguous, and educational
- GK: cover Indian history, geography, science, current affairs basics
- English: grammar, vocabulary, comprehension at 10+2 level
- Logical: series, analogies, reasoning, basic math puzzles
- All questions must be different from each other
- Return exactly {$gkCount} GK + {$englishCount} English + {$logicalCount} Logical questions
PROMPT;
}

// ── Parse AI response into question array ─────────────────────────
function normalizeQuestionRecord(array $q, ?string $forceCategory = null): ?array
{
    if (
        empty($q['question_text']) ||
        empty($q['option_a']) || empty($q['option_b']) ||
        empty($q['option_c']) || empty($q['option_d']) ||
        !in_array(strtoupper($q['correct_answer'] ?? ''), ['A', 'B', 'C', 'D'], true)
    ) {
        return null;
    }

    $cat = $forceCategory;
    if (!$cat) {
        $cat = $q['category'] ?? '';
        if (!in_array($cat, ['gk', 'english', 'logical'], true)) {
            return null;
        }
    }

    return [
        'category'       => $cat,
        'question_text'  => trim($q['question_text']),
        'option_a'       => trim($q['option_a']),
        'option_b'       => trim($q['option_b']),
        'option_c'       => trim($q['option_c']),
        'option_d'       => trim($q['option_d']),
        'correct_answer' => strtoupper($q['correct_answer']),
    ];
}

function extractQuestionObjectsFromText(string $text, ?string $forceCategory = null): array
{
    $valid  = [];
    $offset = 0;
    $len    = strlen($text);

    while ($offset < $len) {
        $start = strpos($text, '{', $offset);
        if ($start === false) {
            break;
        }

        $depth   = 0;
        $found   = false;
        $inString = false;
        $escape  = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($text, $start, $i - $start + 1);
                    $obj  = json_decode($json, true);
                    if (is_array($obj)) {
                        $norm = normalizeQuestionRecord($obj, $forceCategory);
                        if ($norm) {
                            $valid[] = $norm;
                        }
                    }
                    $offset = $i + 1;
                    $found  = true;
                    break;
                }
            }
        }

        if (!$found) {
            break;
        }
    }

    return $valid;
}

function parseGeneratedQuestions($text, ?string $forceCategory = null)
{
    $text = preg_replace('/```json\s*/i', '', $text);
    $text = preg_replace('/```\s*/i', '', $text);
    $text = trim($text);

    $start = strpos($text, '[');
    $end   = strrpos($text, ']');

    if ($start !== false && $end !== false && $end > $start) {
        $json      = substr($text, $start, $end - $start + 1);
        $questions = json_decode($json, true);

        if (is_array($questions)) {
            $valid = [];
            foreach ($questions as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $norm = normalizeQuestionRecord($q, $forceCategory);
                if ($norm) {
                    $valid[] = $norm;
                }
            }
            if (!empty($valid)) {
                return deduplicateQuestions($valid)['questions'];
            }
        }
    }

    $salvaged = extractQuestionObjectsFromText($text, $forceCategory);
    if (!empty($salvaged)) {
        return deduplicateQuestions($salvaged)['questions'];
    }

    if ($start !== false) {
        $chunk    = substr($text, $start);
        $salvaged = extractQuestionObjectsFromText($chunk, $forceCategory);
        if (!empty($salvaged)) {
            return deduplicateQuestions($salvaged)['questions'];
        }
    }

    return ['error' => 'No JSON array found in AI response', 'raw' => substr($text, 0, 500)];
}

// ── Save AI questions to DB and return IDs ────────────────────────
function saveAIQuestionsForSession($db, $sessionId, $questions) {
    $ids = [];
    $stmt = $db->prepare(
        "INSERT INTO ai_generated_questions
         (session_id, category, question_text, option_a, option_b, option_c, option_d, correct_answer)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    foreach ($questions as $q) {
        $stmt->bind_param('isssssss',
            $sessionId,
            $q['category'], $q['question_text'],
            $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'],
            $q['correct_answer']
        );
        $stmt->execute();
        $ids[] = $db->insert_id;
    }
    $stmt->close();
    return $ids;
}

// ── Get questions for a session (unified, works for all sources) ──
function getSessionQuestions($db, $session) {
    $questionOrder = json_decode($session['question_order'], true);
    if (empty($questionOrder)) return [];

    $source = $session['question_source'] ?? 'bank';
    $ids    = implode(',', array_map('intval', $questionOrder));

    if ($source === 'bank') {
        $res = $db->query("SELECT * FROM question_bank WHERE id IN ($ids)");
    } else {
        $res = $db->query("SELECT *, id as qid FROM ai_generated_questions WHERE id IN ($ids)");
    }

    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[$row['id']] = $row;
    }

    // Return in shuffled order (per question_order)
    $ordered = [];
    foreach ($questionOrder as $qid) {
        if (isset($map[$qid])) $ordered[] = $map[$qid];
    }
    return $ordered;
}
