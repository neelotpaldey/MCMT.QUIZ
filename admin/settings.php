<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Settings';
$pageKey   = 'settings';
$msg       = '';
$msgType   = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_smtp_email'])) {
    $testTo = trim($_POST['test_email_to'] ?? '');
    try {
        if (!isSmtpConfigured($db)) {
            throw new RuntimeException('SMTP is not fully configured. Save host, username, password, and From Email first.');
        }
        sendTestEmail($db, $testTo);
        $msg = 'Test email sent to ' . $testTo . '. Check inbox and spam folder.';
        $msgType = 'success';
    } catch (Throwable $e) {
        logDbError($e, 'test_smtp_email');
        $msg = $e->getMessage();
        $msgType = 'error';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_smtp'])) {
    $testHost = trim($_POST['smtp_host'] ?? getAppSetting($db, 'smtp_host'));
    $testPass = !empty($_POST['update_smtp_pass'])
        ? normalizeSmtpPassword($_POST['smtp_pass'] ?? '', $testHost)
        : '';
    try {
        if ($testPass !== '') {
            $passError = validateSmtpPassword($testPass, $testHost);
            if ($passError !== null) {
                throw new RuntimeException($passError);
            }
        }
        testSmtpConnection($db, $testPass !== '' ? $testPass : null);
        $host = getSmtpConfig($db)['host'];
        $tested = $testPass !== '' ? 'pasted password (' . strlen($testPass) . ' chars)' : 'saved password';
        $msg  = "SMTP test passed — {$host} accepted your login ({$tested}).";
        $msgType = 'success';
    } catch (Throwable $e) {
        logDbError($e, 'test_smtp');
        $msg  = $e->getMessage();
        $msgType = 'error';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $showResults = isset($_POST['show_student_results']) ? '1' : '0';

    try {
        setAppSetting($db, 'show_student_results', $showResults);
        setAppSetting($db, 'groq_api_key', trim($_POST['groq_api_key'] ?? ''));
        setAppSetting($db, 'groq_api_model', trim($_POST['groq_api_model'] ?? 'llama-3.1-8b-instant') ?: 'llama-3.1-8b-instant');
        setAppSetting($db, 'gemini_api_key', trim($_POST['gemini_api_key'] ?? ''));
        setAppSetting($db, 'gemini_api_model', trim($_POST['gemini_api_model'] ?? getDefaultGeminiModel()) ?: getDefaultGeminiModel());
        setAppSetting($db, 'openai_api_key', trim($_POST['openai_api_key'] ?? ''));
        setAppSetting($db, 'openai_api_model', trim($_POST['openai_api_model'] ?? getDefaultOpenAIModel()) ?: getDefaultOpenAIModel());
        $smtpHostInput = trim($_POST['smtp_host'] ?? '');
        setAppSetting($db, 'smtp_host', $smtpHostInput);
        setAppSetting($db, 'smtp_port', trim($_POST['smtp_port'] ?? '587') ?: '587');
        setAppSetting($db, 'smtp_user', trim($_POST['smtp_user'] ?? ''));
        $smtpPassInput = !empty($_POST['update_smtp_pass'])
            ? normalizeSmtpPassword($_POST['smtp_pass'] ?? '', $smtpHostInput)
            : '';
        $smtpPassUpdated = false;
        if (!empty($_POST['update_smtp_pass'])) {
            if ($smtpPassInput === '') {
                throw new RuntimeException(
                    '“Update SMTP Password” is checked but the field is empty. Paste your mailbox password.'
                );
            }
            $passError = validateSmtpPassword($smtpPassInput, $smtpHostInput);
            if ($passError !== null) {
                throw new RuntimeException($passError);
            }
            setAppSetting($db, 'smtp_pass', $smtpPassInput);
            $smtpPassUpdated = true;
        }
        setAppSetting($db, 'smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        setAppSetting($db, 'smtp_from_name', trim($_POST['smtp_from_name'] ?? 'Exam Portal') ?: 'Exam Portal');
        setAppSetting($db, 'smtp_encryption', in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true)
            ? $_POST['smtp_encryption'] : 'tls');

        $smtpPassStillMissing = trim($_POST['smtp_host'] ?? '') !== ''
            && getAppSetting($db, 'smtp_pass') === '';

        $msg = 'Settings saved successfully.';
        if ($smtpPassUpdated) {
            $msg .= ' SMTP password stored.';
            if (trim($_POST['smtp_host'] ?? '') !== '' && trim($_POST['smtp_from_email'] ?? '') !== '') {
                try {
                    testSmtpConnection($db);
                    $msg .= ' SMTP login test passed.';
                } catch (Throwable $e) {
                    logDbError($e, 'test_smtp_after_save');
                    $msg .= ' SMTP login failed: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
        if ($smtpPassStillMissing) {
            $msg .= ' Warning: SMTP password is still missing — check “Update SMTP Password”, paste it, and save again.';
            $msgType = 'error';
        } elseif ($msgType !== 'error') {
            $msgType = 'success';
        }
    } catch (RuntimeException $e) {
        $msg     = $e->getMessage();
        $msgType = 'error';
    } catch (mysqli_sql_exception $e) {
        logDbError($e, 'admin_settings');
        $msg     = 'Could not save settings. Please try again.';
        $msgType = 'error';
    }
}

$showResults  = getAppSetting($db, 'show_student_results', '1') === '1';
$groqKey      = getAppSetting($db, 'groq_api_key');
$groqModel    = getAppSetting($db, 'groq_api_model', 'llama-3.1-8b-instant');
$geminiKey    = getAppSetting($db, 'gemini_api_key');
$geminiModel  = getAppSetting($db, 'gemini_api_model', getDefaultGeminiModel());
$openaiKey    = getAppSetting($db, 'openai_api_key');
$openaiModel  = getAppSetting($db, 'openai_api_model', getDefaultOpenAIModel());
$smtpHost     = getAppSetting($db, 'smtp_host');
$smtpPort     = getAppSetting($db, 'smtp_port', '587');
$smtpUser     = getAppSetting($db, 'smtp_user');
$smtpPass     = getAppSetting($db, 'smtp_pass');
$smtpFrom     = getAppSetting($db, 'smtp_from_email');
$smtpFromName = getAppSetting($db, 'smtp_from_name', 'Exam Portal');
$smtpEnc      = getAppSetting($db, 'smtp_encryption', 'tls');
$smtpIsGmail  = isGmailSmtpHost($smtpHost);
$smtpReady    = isSmtpConfigured($db);
$smtpUserOk   = $smtpUser !== '' || $smtpFrom !== '';
$smtpEmailMatch = $smtpFrom !== '' && $smtpUser !== ''
    && strtolower($smtpUser) === strtolower($smtpFrom);
$smtpFreeProvider = isFreeEmailSmtpHost($smtpHost);
$adminEmail = $_SESSION['admin_email'] ?? $smtpFrom;

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">⚙️ System Settings</div>
  </div>
  <div class="page-body">
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $msgType === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="card" style="margin-bottom:1.2rem">
        <div class="card-header">
          <div class="card-title">Student Result Display</div>
        </div>
        <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;font-size:.92rem;line-height:1.6">
          <input type="checkbox" name="show_student_results" value="1" <?= $showResults ? 'checked' : '' ?>
                 style="width:18px;height:18px;margin-top:3px;accent-color:var(--sky)">
          <span>
            <strong>Show detailed results to students after submit</strong><br>
            <span style="color:var(--muted);font-size:.85rem">
              When disabled, students only see an “Exam Submitted” confirmation.
            </span>
          </span>
        </label>
      </div>

      <div class="card" style="margin-bottom:1.2rem">
        <div class="card-header">
          <div class="card-title">⚡ Groq AI</div>
        </div>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">
          Free tier has token limits — large batches are auto-split. Key from
          <a href="https://console.groq.com" target="_blank" rel="noopener" style="color:var(--sky)">console.groq.com</a>.
        </p>
        <div class="form-group" style="margin-bottom:1rem">
          <label>Groq API Key</label>
          <input class="form-control" type="password" name="groq_api_key" value="<?= htmlspecialchars($groqKey) ?>"
                 placeholder="gsk_..." autocomplete="off">
        </div>
        <div class="form-group">
          <label>Model</label>
          <select class="form-control" name="groq_api_model">
            <?php foreach ([
                'llama-3.1-8b-instant' => 'Llama 3.1 8B Instant',
                'llama-3.3-70b-versatile' => 'Llama 3.3 70B Versatile',
                'llama3-8b-8192' => 'Llama 3 8B',
            ] as $val => $label): ?>
            <option value="<?= $val ?>" <?= $groqModel === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="card" style="margin-bottom:1.2rem">
        <div class="card-header">
          <div class="card-title">✨ Google Gemini</div>
        </div>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">
          Key from <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" style="color:var(--sky)">Google AI Studio</a>.
          <strong>Gemini 2.5 Flash</strong> works best on the free tier. If 2.0 Flash shows quota errors, use 2.5 or 1.5 Flash.
        </p>
        <div class="form-group" style="margin-bottom:1rem">
          <label>Gemini API Key</label>
          <input class="form-control" type="password" name="gemini_api_key" value="<?= htmlspecialchars($geminiKey) ?>"
                 placeholder="AIza..." autocomplete="off">
        </div>
        <div class="form-group">
          <label>Model</label>
          <select class="form-control" name="gemini_api_model">
            <?php foreach (getGeminiModelOptions() as $val => $label): ?>
            <option value="<?= $val ?>" <?= $geminiModel === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="card" style="margin-bottom:1.2rem">
        <div class="card-header">
          <div class="card-title">💬 ChatGPT (OpenAI)</div>
        </div>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">
          Key from <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:var(--sky)">OpenAI Platform</a>.
          <strong>New accounts must add a payment method</strong> at
          <a href="https://platform.openai.com/settings/billing" target="_blank" rel="noopener" style="color:var(--sky)">Billing Settings</a>
          before the API works — a new key alone is not enough. Use <strong>GPT-4o Mini</strong> first; GPT-5 models need a paid tier.
        </p>
        <div class="form-group" style="margin-bottom:1rem">
          <label>OpenAI API Key</label>
          <input class="form-control" type="password" name="openai_api_key" value="<?= htmlspecialchars($openaiKey) ?>"
                 placeholder="sk-..." autocomplete="off">
        </div>
        <div class="form-group">
          <label>Model</label>
          <select class="form-control" name="openai_api_model">
            <?php foreach (getOpenAIModelOptions() as $val => $label): ?>
            <option value="<?= $val ?>" <?= $openaiModel === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="card" style="margin-bottom:1.2rem">
        <div class="card-header">
          <div class="card-title">📧 Email (SMTP)</div>
        </div>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">
          Required to send exam results to participants from Manage Exams.
        </p>
        <?php if ($smtpFreeProvider): ?>
        <div class="alert alert-warning" style="font-size:.82rem;margin-bottom:1rem;border-color:#ffb703">
          <strong>Emails going to spam?</strong> Free providers (Rediffmail, Yahoo, etc.) are often filtered by Gmail/Outlook.
          For reliable delivery, switch to <strong>Gmail SMTP</strong>:
          host <code>smtp.gmail.com</code>, port <code>587</code>, encryption <code>TLS</code>, and a
          <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" style="color:var(--sky)">16-character App Password</a>.
          Use your school Gmail as both Username and From Email, and set From Name to your institution name.
        </div>
        <?php endif; ?>
        <div class="alert alert-info" style="font-size:.82rem;margin-bottom:1rem">
          <strong>Rediffmail setup</strong>: Host <code>smtp.rediffmail.com</code> · Port <code>465</code> · Encryption <code>SSL</code><br>
          Username and <strong>From Email</strong> must be the same full address (e.g. <code>you@rediffmail.com</code>).<br>
          <span style="color:var(--muted)">Gmail: <code>smtp.gmail.com</code>, port <code>587</code>, TLS, App Password.</span>
        </div>
        <div class="form-row" style="display:grid;grid-template-columns:1fr 120px;gap:1rem;margin-bottom:1rem">
          <div class="form-group" style="margin:0">
            <label>SMTP Host</label>
            <input class="form-control" type="text" name="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>"
                   placeholder="smtp.rediffmail.com">
          </div>
          <div class="form-group" style="margin:0">
            <label>Port</label>
            <input class="form-control" type="number" name="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>" min="1" max="65535">
          </div>
        </div>
        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
          <div class="form-group" style="margin:0">
            <label>SMTP Username</label>
            <input class="form-control" type="text" name="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>"
                   placeholder="your@email.com" autocomplete="off">
          </div>
          <div class="form-group" style="margin:0">
            <label>SMTP Password</label>
            <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:.82rem;cursor:pointer">
              <input type="checkbox" name="update_smtp_pass" value="1" id="updateSmtpPass"
                     style="width:16px;height:16px;accent-color:var(--sky)">
              <span>Update SMTP Password (check this, then paste — prevents browser autofill from overwriting)</span>
            </label>
            <input class="form-control" type="password" name="smtp_pass" id="smtpPassInput" value=""
                   placeholder="<?= $smtpIsGmail ? '16-letter Gmail App Password' : 'Your Rediffmail login password' ?>"
                   autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" readonly
                   data-form-type="other">
            <?php if ($smtpIsGmail): ?>
            <div id="smtpPassCount" style="margin-top:6px;font-size:.8rem;color:var(--muted)">0 / 16 letters</div>
            <?php endif; ?>
            <?php if ($smtpPass !== ''): ?>
            <div style="margin-top:6px;font-size:.8rem;color:#81c784;display:flex;align-items:center;gap:6px">
              <span>✓</span>
              <span>Password saved. To replace: check the box above, paste your <?= $smtpIsGmail ? 'App Password' : 'Rediffmail password' ?>, then Save.</span>
            </div>
            <?php else: ?>
            <div style="margin-top:6px;font-size:.8rem;color:#ef9a9a">No password saved — check the box, paste your Rediffmail password, then Save.</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
          <div class="form-group" style="margin:0">
            <label>From Email *</label>
            <input class="form-control" type="email" name="smtp_from_email" value="<?= htmlspecialchars($smtpFrom) ?>"
                   placeholder="noreply@yourschool.com">
          </div>
          <div class="form-group" style="margin:0">
            <label>From Name</label>
            <input class="form-control" type="text" name="smtp_from_name" value="<?= htmlspecialchars($smtpFromName) ?>">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:1rem">
          <label>Encryption</label>
          <select class="form-control" name="smtp_encryption">
            <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS (recommended for port 587)</option>
            <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL (use with port 465)</option>
            <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>None</option>
          </select>
        </div>
        <div style="background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.82rem">
          <div style="font-weight:600;margin-bottom:.5rem">Configuration status</div>
          <div style="color:<?= $smtpHost ? '#81c784' : '#ef9a9a' ?>"><?= $smtpHost ? '✓' : '✗' ?> SMTP host set</div>
          <div style="color:<?= $smtpUserOk ? '#81c784' : '#ef9a9a' ?>"><?= $smtpUserOk ? '✓' : '✗' ?> SMTP username / from email</div>
          <div style="color:<?= $smtpPass !== '' ? '#81c784' : '#ef9a9a' ?>"><?= $smtpPass !== '' ? '✓' : '✗' ?> SMTP password saved</div>
          <div style="color:<?= ($smtpEmailMatch || ($smtpUser === '' && $smtpFrom !== '')) ? '#81c784' : '#ffb703' ?>">
            <?= ($smtpEmailMatch || ($smtpUser === '' && $smtpFrom !== '')) ? '✓' : '⚠' ?>
            Username matches From Email<?= !$smtpEmailMatch && $smtpUser && $smtpFrom ? ' — they should be the same address' : '' ?>
          </div>
        </div>
        <div class="form-row" style="display:grid;grid-template-columns:1fr auto;gap:.75rem;align-items:end;margin-bottom:.75rem">
          <div class="form-group" style="margin:0">
            <label>Send test email to</label>
            <input class="form-control" type="email" name="test_email_to" value="<?= htmlspecialchars($adminEmail) ?>"
                   placeholder="your@gmail.com" <?= $smtpReady ? '' : 'disabled' ?>>
          </div>
          <button type="submit" formaction="settings.php" class="btn btn-outline btn-sm" formmethod="POST"
                  name="test_smtp_email" value="1" <?= $smtpReady ? '' : 'disabled' ?>
                  onclick="return confirm('Send a test email with current saved SMTP settings?')">
            📨 Send Test Email
          </button>
        </div>
        <button type="submit" formaction="settings.php" class="btn btn-outline btn-sm" formmethod="POST" name="test_smtp" value="1"
                style="margin-right:.5rem" onclick="return confirm('Test SMTP login with current settings?')">
          🧪 Test SMTP Connection
        </button>
        <span style="font-size:.78rem;color:var(--muted)">Save settings first, then send a test email and check inbox vs spam.</span>
      </div>

      <button type="submit" class="btn btn-primary">💾 Save Settings</button>
    </form>
    <script>
    (function () {
      var box = document.getElementById('updateSmtpPass');
      var input = document.getElementById('smtpPassInput');
      var count = document.getElementById('smtpPassCount');
      if (!box || !input) return;

      function syncField() {
        var on = box.checked;
        input.readOnly = !on;
        if (!on) {
          input.value = '';
        } else {
          input.focus();
        }
        if (count) refreshCount();
      }

      function refreshCount() {
        if (!count) return;
        var len = input.value.replace(/\s+/g, '').replace(/[^a-z]/gi, '').length;
        count.textContent = len + ' / 16 letters';
        count.style.color = len === 16 ? '#81c784' : (len > 0 ? '#ffb703' : 'var(--muted)');
      }

      box.addEventListener('change', syncField);
      if (count) input.addEventListener('input', refreshCount);
      syncField();
    })();
    </script>
  </div>
</div>
</div>
</body></html>
