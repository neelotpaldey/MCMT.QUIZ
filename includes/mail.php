<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/settings.php';

function isGmailSmtpHost(string $host): bool
{
    return stripos($host, 'gmail.com') !== false;
}

function normalizeSmtpPassword(string $password, ?string $smtpHost = null): string
{
    $password = trim($password);
    if ($smtpHost !== null && isGmailSmtpHost($smtpHost)) {
        $password = preg_replace('/\s+/u', '', $password);
        $password = preg_replace('/[^a-z]/i', '', $password);

        return strtolower($password);
    }

    return $password;
}

function isValidGmailAppPassword(string $password): bool
{
    return preg_match('/^[a-z]{16}$/', $password) === 1;
}

function validateSmtpPassword(string $password, string $host): ?string
{
    if ($password === '') {
        return 'SMTP password is empty.';
    }
    if (isGmailSmtpHost($host) && !isValidGmailAppPassword($password)) {
        return 'Gmail App Password must be exactly 16 letters (a–z). You entered ' . strlen($password)
            . ' after removing spaces.';
    }

    return null;
}

function getSmtpConfig(mysqli $db, ?string $passwordOverride = null): array
{
    $from = strtolower(trim(getAppSetting($db, 'smtp_from_email')));
    $user = strtolower(trim(getAppSetting($db, 'smtp_user')));
    if ($user === '' && $from !== '') {
        $user = $from;
    }

    $host = trim(getAppSetting($db, 'smtp_host'));
    $pass = $passwordOverride !== null
        ? normalizeSmtpPassword($passwordOverride, $host)
        : normalizeSmtpPassword(getAppSetting($db, 'smtp_pass'), $host);

    return [
        'host'       => $host,
        'port'       => (int) getAppSetting($db, 'smtp_port', '587'),
        'user'       => $user,
        'pass'       => $pass,
        'from_email' => $from,
        'from_name'  => getAppSetting($db, 'smtp_from_name', 'Exam Portal'),
        'encryption' => getAppSetting($db, 'smtp_encryption', 'tls'),
    ];
}

function isSmtpConfigured(mysqli $db): bool
{
    $cfg = getSmtpConfig($db);
    return $cfg['host'] !== ''
        && $cfg['from_email'] !== ''
        && $cfg['user'] !== ''
        && $cfg['pass'] !== '';
}

function smtpTlsCryptoMethod(): int
{
    $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    return $method;
}

function getStudentEmailPlaceholders(array $student): array
{
    return [
        '{name}'        => $student['full_name'] ?? '',
        '{email}'       => $student['email'] ?? '',
        '{mobile}'      => $student['mobile'] ?? '',
        '{roll_number}' => $student['roll_number'] ?? '',
        '{dob}'         => $student['dob'] ?? '',
    ];
}

function replaceEmailPlaceholders(string $template, array $data): string
{
    $replacements = array_merge([
        '{name}'         => '',
        '{email}'        => '',
        '{exam_title}'   => '',
        '{marks}'        => '',
        '{total_marks}'  => '',
        '{percentage}'   => '',
        '{result}'       => '',
        '{correct}'      => '',
        '{wrong}'        => '',
        '{skipped}'      => '',
        '{attempted}'    => '',
        '{roll_number}'  => '',
        '{mobile}'       => '',
        '{dob}'          => '',
    ], $data);

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

function replaceResultPlaceholders(string $template, array $data): string
{
    return replaceEmailPlaceholders($template, $data);
}

function buildTransactionalEmailBody(string $fromName, string $greeting, string $contentHtml): string
{
    $sender = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $sender . '</title></head><body'
        . ' style="font-family:Georgia,\'Times New Roman\',serif;line-height:1.6;color:#222;max-width:640px;margin:0;padding:16px">'
        . '<p>' . $greeting . '</p>'
        . $contentHtml
        . '<p style="margin-top:24px">Regards,<br>' . $sender . '</p>'
        . '</body></html>';
}

function buildCustomStudentEmailBody(string $customMessage, array $student, string $fromName = 'Exam Portal'): string
{
    $data = getStudentEmailPlaceholders($student);
    $message = replaceEmailPlaceholders($customMessage, $data);
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $message = nl2br($message);
    $name = htmlspecialchars($student['full_name'] ?? 'Student', ENT_QUOTES, 'UTF-8');

    return buildTransactionalEmailBody(
        $fromName,
        'Dear ' . $name . ',',
        '<div>' . $message . '</div>'
    );
}

function sendCustomStudentEmail(mysqli $db, array $student, string $customMessage, string $subject): void
{
    $email = trim($student['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Student has no valid email address.');
    }

    $cfg = getSmtpConfig($db);
    $body = buildCustomStudentEmailBody($customMessage, $student, $cfg['from_name'] ?: 'Exam Portal');
    sendSmtpMail($db, $email, $student['full_name'] ?? '', $subject, $body);
}

function throttleBulkEmail(): void
{
    usleep(750000);
}

function buildResultEmailBody(string $customMessage, array $result, array $student, array $exam, string $fromName = 'Exam Portal'): string
{
    $data = [
        'name'        => $student['full_name'] ?? '',
        'exam_title'  => $exam['title'] ?? '',
        'marks'       => (string) ($result['marks_obtained'] ?? ''),
        'total_marks' => (string) ($exam['total_marks'] ?? ''),
        'percentage'  => (string) ($result['percentage'] ?? ''),
        'result'      => !empty($result['is_passed']) ? 'Passed' : 'Not passed',
        'correct'     => (string) ($result['correct'] ?? ''),
        'wrong'       => (string) ($result['wrong'] ?? ''),
        'skipped'     => (string) ($result['skipped'] ?? ''),
        'attempted'   => (string) ($result['attempted'] ?? ''),
        'roll_number' => $student['roll_number'] ?? '',
        'mobile'      => $student['mobile'] ?? '',
    ];

    $message = replaceResultPlaceholders($customMessage, $data);
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $message = nl2br($message);

    $title       = htmlspecialchars($exam['title'] ?? 'Exam', ENT_QUOTES, 'UTF-8');
    $name        = htmlspecialchars($student['full_name'] ?? 'Student', ENT_QUOTES, 'UTF-8');
    $marks       = htmlspecialchars($data['marks'], ENT_QUOTES, 'UTF-8');
    $totalMarks  = htmlspecialchars($data['total_marks'], ENT_QUOTES, 'UTF-8');
    $percentage  = htmlspecialchars($data['percentage'], ENT_QUOTES, 'UTF-8');
    $result  = htmlspecialchars($data['result'], ENT_QUOTES, 'UTF-8');
    $correct = htmlspecialchars($data['correct'], ENT_QUOTES, 'UTF-8');
    $wrong   = htmlspecialchars($data['wrong'], ENT_QUOTES, 'UTF-8');
    $skipped = htmlspecialchars($data['skipped'], ENT_QUOTES, 'UTF-8');

    $summary = '<div>' . $message . '</div>'
        . '<p><strong>Exam:</strong> ' . $title . '<br>'
        . '<strong>Marks:</strong> ' . $marks . ' / ' . $totalMarks . '<br>'
        . '<strong>Percentage:</strong> ' . $percentage . '%<br>'
        . '<strong>Result:</strong> ' . $result . '<br>'
        . '<strong>Correct / Wrong / Skipped:</strong> ' . $correct . ' / ' . $wrong . ' / ' . $skipped . '</p>';

    return buildTransactionalEmailBody($fromName, 'Dear ' . $name . ',', $summary);
}

function smtpRead($socket): string
{
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtpCommand($socket, string $command, array $expectedCodes = [250]): string
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }
    $response = smtpRead($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException(formatSmtpError($response));
    }
    return $response;
}

function formatSmtpError(string $response): string
{
    $raw = trim(preg_replace('/\s+/', ' ', $response));

    if (preg_match('/535|BadCredentials|Username and Password not accepted|authentication failed/i', $raw)) {
        return 'SMTP login rejected — username or password is wrong. '
            . 'For Rediffmail use your normal mailbox password (not a Gmail App Password). '
            . 'Check “Update SMTP Password”, paste the password, and Save Settings. '
            . 'Username and From Email must be the full address (e.g. mcmt.quiz@rediffmail.com).';
    }

    if (strlen($raw) > 180) {
        $raw = substr($raw, 0, 177) . '...';
    }

    return 'SMTP error: ' . $raw;
}

function isSmtpAuthError(Throwable $e): bool
{
    $msg = $e->getMessage();
    return str_contains($msg, '535')
        || str_contains($msg, 'BadCredentials')
        || str_contains($msg, 'App Password')
        || str_contains($msg, 'Username and Password not accepted');
}

function smtpEhloHost(string $fromEmail): string
{
    $parts = explode('@', $fromEmail, 2);
    if (isset($parts[1]) && $parts[1] !== '') {
        return $parts[1];
    }

    $hostname = gethostname();
    if (is_string($hostname) && $hostname !== '' && str_contains($hostname, '.')) {
        return $hostname;
    }

    return 'localhost';
}

function htmlToPlainText(string $html): string
{
    $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n\n", $text);
    $text = preg_replace('/<\/tr>/i', "\n", $text);
    $text = preg_replace('/<\/td>/i', "\t", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

function encodeMimePart(string $text): string
{
    $encoded = quoted_printable_encode($text);
    $encoded = preg_replace('/\r\n|\r|\n/', "\r\n", $encoded) ?? $encoded;

    return rtrim($encoded) . "\r\n";
}

function buildMimeEmailPayload(string $htmlBody, string $fromEmail): array
{
    $boundary = 'exam_' . bin2hex(random_bytes(12));
    $plainText = htmlToPlainText($htmlBody);
    if ($plainText === '') {
        $plainText = 'Please view this message in an HTML-capable email client.';
    }
    $domain = explode('@', $fromEmail, 2)[1] ?? 'localhost';
    $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';

    $parts = [
        "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . encodeMimePart($plainText),
        "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . encodeMimePart($htmlBody),
        "--{$boundary}--",
    ];

    return [
        'message_id'   => $messageId,
        'body'         => implode("\r\n", $parts),
        'content_type' => "multipart/alternative; boundary=\"{$boundary}\"",
    ];
}

function smtpDotStuff(string $data): string
{
    return preg_replace('/^\./m', '..', $data) ?? $data;
}

function openSmtpSocket(array $cfg)
{
    $host = $cfg['host'];
    $port = $cfg['port'] ?: 587;
    $enc  = strtolower($cfg['encryption']);

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host;
    $socket = @stream_socket_client("{$remote}:{$port}", $errno, $errstr, 30);
    if (!$socket) {
        throw new RuntimeException("Could not connect to SMTP server ({$host}:{$port}): {$errstr}");
    }

    stream_set_timeout($socket, 30);
    smtpRead($socket);
    $localHost = smtpEhloHost($cfg['from_email'] ?? '');
    $ehloCmd   = $enc === 'none' ? "HELO {$localHost}" : "EHLO {$localHost}";
    smtpCommand($socket, $ehloCmd, [250]);

    if ($enc === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, smtpTlsCryptoMethod())) {
            throw new RuntimeException('Could not enable TLS on SMTP connection.');
        }
        smtpCommand($socket, "EHLO {$localHost}", [250]);
    }

    return $socket;
}

function smtpAuthenticate($socket, string $user, string $pass): void
{
    if ($pass === '') {
        throw new RuntimeException(
            'No SMTP password is saved. Go to Settings → Email, check “Update SMTP Password”, '
            . 'paste your mailbox password, and click Save Settings.'
        );
    }
    if ($user === '') {
        throw new RuntimeException('SMTP Username is empty. Enter your full email address as SMTP Username.');
    }

    smtpCommand($socket, 'AUTH LOGIN', [334]);
    smtpCommand($socket, base64_encode($user), [334]);
    smtpCommand($socket, base64_encode($pass), [235]);
}

function closeSmtpSocket($socket): void
{
    try {
        smtpCommand($socket, 'QUIT', [221]);
    } catch (Throwable $e) {
        // ignore quit errors
    }
    fclose($socket);
}

function testSmtpConnection(mysqli $db, ?string $passwordOverride = null): void
{
    $cfg = getSmtpConfig($db, $passwordOverride);
    if (!$cfg['host'] || !$cfg['from_email']) {
        throw new RuntimeException('SMTP host and From Email are required.');
    }

    $socket = openSmtpSocket($cfg);
    try {
        smtpAuthenticate($socket, $cfg['user'], $cfg['pass']);
    } finally {
        closeSmtpSocket($socket);
    }
}

function sendSmtpMail(mysqli $db, string $toEmail, string $toName, string $subject, string $htmlBody): void
{
    $cfg = getSmtpConfig($db);
    if (!$cfg['host'] || !$cfg['from_email']) {
        throw new RuntimeException('SMTP is not configured. Set mail settings in Admin → Settings.');
    }

    $socket = openSmtpSocket($cfg);
    smtpAuthenticate($socket, $cfg['user'], $cfg['pass']);

    $from = $cfg['from_email'];
    $fromName = $cfg['from_name'] ?: 'Exam Portal';

    smtpCommand($socket, "MAIL FROM:<{$from}>", [250]);
    smtpCommand($socket, "RCPT TO:<{$toEmail}>", [250, 251]);
    smtpCommand($socket, 'DATA', [354]);

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $toHeader = $toName !== '' ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $toEmail . '>' : $toEmail;
    $mime = buildMimeEmailPayload($htmlBody, $from);

    $headers = [
        "From: {$encodedFromName} <{$from}>",
        "Reply-To: {$encodedFromName} <{$from}>",
        "To: {$toHeader}",
        "Subject: {$encodedSubject}",
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: ' . $mime['message_id'],
        'MIME-Version: 1.0',
        'Content-Type: ' . $mime['content_type'],
        'Content-Language: en',
        'Organization: ' . $fromName,
    ];

    $payload = smtpDotStuff(implode("\r\n", $headers) . "\r\n\r\n" . $mime['body']);
    fwrite($socket, $payload . "\r\n.\r\n");
    smtpCommand($socket, '', [250]);
    closeSmtpSocket($socket);
}

function sendExamResultEmail(mysqli $db, array $result, array $student, array $exam, string $customMessage, string $subject): void
{
    $email = trim($student['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Student has no valid email address.');
    }

    $cfg = getSmtpConfig($db);
    $body = buildResultEmailBody($customMessage, $result, $student, $exam, $cfg['from_name'] ?: 'Exam Portal');
    sendSmtpMail($db, $email, $student['full_name'] ?? '', $subject, $body);
}

function sendTestEmail(mysqli $db, string $toEmail): void
{
    $toEmail = strtolower(trim($toEmail));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid test email address.');
    }

    $cfg = getSmtpConfig($db);
    $fromName = $cfg['from_name'] ?: 'Exam Portal';
    $sentAt = gmdate('Y-m-d H:i:s') . ' UTC';
    $body = buildTransactionalEmailBody(
        $fromName,
        'Hello,',
        '<p>This is a delivery test from your exam portal.</p>'
        . '<p><strong>From:</strong> ' . htmlspecialchars($cfg['from_email'], ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Sent at:</strong> ' . htmlspecialchars($sentAt, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>If this message is in spam, use Gmail SMTP (smtp.gmail.com, port 587, TLS, App Password) '
        . 'or your institution domain with SPF and DKIM configured. Rediffmail often lands in Gmail spam.</p>'
    );

    sendSmtpMail($db, $toEmail, '', $fromName . ' — delivery test', $body);
}

function isFreeEmailSmtpHost(string $host): bool
{
    $host = strtolower($host);
    foreach (['rediffmail.com', 'gmail.com', 'yahoo.', 'hotmail.', 'outlook.'] as $needle) {
        if (str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}
