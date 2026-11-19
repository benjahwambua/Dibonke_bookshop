<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mpesa.php';

if (!isSuperUser()) {
    http_response_code(403);
    exit('Access denied. Developer M-PESA settings are restricted to the system superuser.');
}

$pageTitle = 'M-PESA Developer Settings';

function mpesaDeveloperValue(string $key, string $legacyKey = ''): string
{
    $value = mpesaSetting($key);
    if ($value === '' && $legacyKey !== '') {
        $value = mpesaSetting($legacyKey);
    }
    return $value;
}

function mpesaDeveloperSave(PDO $conn, string $key, string $value): void
{
    $stmt = $conn->prepare(
        'INSERT INTO settings(setting_key, setting_value) VALUES(?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'save') {
        try {
            $environment = (string)($_POST['mpesa_environment'] ?? 'sandbox');
            if (!in_array($environment, ['sandbox', 'live'], true)) {
                throw new RuntimeException('Select a valid Daraja environment.');
            }

            $sandboxShortcode = preg_replace('/\D+/', '', (string)($_POST['mpesa_sandbox_shortcode'] ?? '174379')) ?? '';
            if ($sandboxShortcode === '') {
                throw new RuntimeException('Enter the Daraja sandbox shortcode.');
            }

            $sandboxCallback = trim((string)($_POST['mpesa_sandbox_callback_url'] ?? ''));
            $liveCallback = trim((string)($_POST['mpesa_live_callback_url'] ?? ''));
            $confirmation = trim((string)($_POST['mpesa_c2b_confirmation_url'] ?? ''));
            $validation = trim((string)($_POST['mpesa_c2b_validation_url'] ?? ''));

            foreach ([
                'Sandbox STK callback URL' => $sandboxCallback,
                'Production STK callback URL' => $liveCallback,
                'C2B confirmation URL' => $confirmation,
                'C2B validation URL' => $validation,
            ] as $label => $url) {
                if ($url === '') {
                    continue;
                }
                if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new RuntimeException($label . ' must be a valid public HTTPS URL.');
                }
            }

            $credentialFields = [
                'mpesa_sandbox_consumer_key',
                'mpesa_sandbox_consumer_secret',
                'mpesa_sandbox_passkey',
                'mpesa_live_consumer_key',
                'mpesa_live_consumer_secret',
                'mpesa_live_passkey',
            ];

            if ($environment === 'live') {
                foreach (['mpesa_live_consumer_key', 'mpesa_live_consumer_secret', 'mpesa_live_passkey'] as $key) {
                    $submitted = trim((string)($_POST[$key] ?? ''));
                    if ($submitted === '' && mpesaSetting($key) === '') {
                        throw new RuntimeException('Production mode was not activated: enter all three production Daraja credentials first.');
                    }
                }
                if (mpesaSetting('mpesa_shortcode') === '') {
                    throw new RuntimeException('Production mode requires the business PayBill or Till shortcode in Business Settings.');
                }
                if ($liveCallback === '') {
                    throw new RuntimeException('Production mode requires a permanent HTTPS STK callback URL.');
                }
            }

            // Validate the requested environment before changing the active mode.
            // This prevents an incomplete production setup from switching the live
            // payment code away from the already-working sandbox configuration.
            mpesaDeveloperSave($conn, 'mpesa_sandbox_shortcode', $sandboxShortcode);
            mpesaDeveloperSave($conn, 'mpesa_sandbox_callback_url', $sandboxCallback);
            mpesaDeveloperSave($conn, 'mpesa_live_callback_url', $liveCallback);
            mpesaDeveloperSave($conn, 'mpesa_c2b_confirmation_url', $confirmation);
            mpesaDeveloperSave($conn, 'mpesa_c2b_validation_url', $validation);

            foreach ($credentialFields as $key) {
                $value = trim((string)($_POST[$key] ?? ''));
                if ($value !== '') {
                    mpesaDeveloperSave($conn, $key, $value);
                }
            }
            mpesaDeveloperSave($conn, 'mpesa_environment', $environment);

            $_SESSION['mpesa_dev_flash'] = 'Developer M-PESA settings saved.';
        } catch (Throwable $e) {
            $_SESSION['mpesa_dev_flash'] = 'Settings not saved: ' . $e->getMessage();
        }

        header('Location: developer.php');
        exit;
    }

    if ($action === 'test_connection') {
        try {
            if (mpesaSetting('mpesa_environment', 'sandbox') !== 'sandbox') {
                throw new RuntimeException('Switch to Sandbox before testing sandbox credentials.');
            }
            mpesaAccessToken();
            $_SESSION['mpesa_dev_flash'] = 'Sandbox connection successful. Daraja returned an access token.';
        } catch (Throwable $e) {
            $_SESSION['mpesa_dev_flash'] = 'Sandbox connection failed: ' . $e->getMessage();
        }
        header('Location: developer.php');
        exit;
    }

    if ($action === 'test_stk') {
        try {
            if (mpesaSetting('mpesa_environment', 'sandbox') !== 'sandbox') {
                throw new RuntimeException('Sandbox STK testing is disabled while Production is selected.');
            }

            $phone = trim((string)($_POST['test_phone'] ?? ''));
            $amount = (float)($_POST['test_amount'] ?? 1);
            if ($amount < 1 || $amount > 10) {
                throw new RuntimeException('Use a sandbox test amount between KES 1 and KES 10.');
            }

            $callback = mpesaDeveloperValue('mpesa_sandbox_callback_url', 'mpesa_callback_url');
            if (!filter_var($callback, FILTER_VALIDATE_URL) || parse_url($callback, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('Set a publicly reachable HTTPS sandbox callback URL before sending a test prompt.');
            }

            $host = strtolower((string)parse_url($callback, PHP_URL_HOST));
            if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('The sandbox callback must use a public hostname, not localhost.');
            }

            $testCallback = preg_replace('~/callback\.php(?:\?.*)?$~i', '/sandbox_test_callback.php', $callback);
            if (!$testCallback || $testCallback === $callback) {
                throw new RuntimeException('The sandbox callback URL must end in /callback.php.');
            }

            $result = mpesaStkPush($amount, $phone, 'DBKTEST', 'Dibonke sandbox test', $testCallback);
            $requestId = (string)($result['CheckoutRequestID'] ?? '');
            $customerMessage = (string)($result['CustomerMessage'] ?? $result['ResponseDescription'] ?? 'Safaricom accepted the STK request.');
            if (($result['ResponseCode'] ?? null) !== '0') {
                throw new RuntimeException((string)($result['ResponseDescription'] ?? 'Safaricom did not accept the STK request.'));
            }

            $_SESSION['mpesa_dev_flash'] = 'Sandbox STK request accepted. ' . $customerMessage
                . ($requestId !== '' ? ' Checkout ID: ' . $requestId : '')
                . ' This isolated test does not create a Dibonke sale or invoice.';
        } catch (Throwable $e) {
            $_SESSION['mpesa_dev_flash'] = 'Sandbox STK test failed: ' . $e->getMessage();
        }
        header('Location: developer.php');
        exit;
    }

    if ($action === 'register_c2b') {
        try {
            if (mpesaSetting('mpesa_business_type', 'paybill') !== 'paybill') {
                throw new RuntimeException('C2B URL registration is for PayBill accounts. It is not available for a Buy Goods Till.');
            }

            $shortcode = mpesaSetting('mpesa_environment', 'sandbox') === 'sandbox'
                ? mpesaSetting('mpesa_sandbox_shortcode', '174379')
                : mpesaSetting('mpesa_shortcode');
            $confirmation = mpesaSetting('mpesa_c2b_confirmation_url');
            $validation = mpesaSetting('mpesa_c2b_validation_url');

            if ($shortcode === '' || $confirmation === '' || $validation === '') {
                throw new RuntimeException('Enter the shortcode and both public HTTPS C2B callback URLs first.');
            }

            foreach ([$confirmation, $validation] as $url) {
                if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new RuntimeException('C2B callback URLs must be valid public HTTPS URLs.');
                }
            }

            $token = mpesaAccessToken();
            $payload = [
                'ShortCode' => $shortcode,
                'ResponseType' => 'Completed',
                'ConfirmationURL' => $confirmation,
                'ValidationURL' => $validation,
            ];

            $ch = curl_init(mpesaBaseUrl() . '/mpesa/c2b/v2/registerurl');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $code >= 400) {
                throw new RuntimeException('C2B URL registration failed: ' . ($error ?: (string)$body));
            }

            mpesaDeveloperSave($conn, 'mpesa_c2b_registration_response', (string)json_encode(json_decode((string)$body, true)));
            $_SESSION['mpesa_dev_flash'] = 'C2B callback registration request submitted. Confirm the Daraja response and test the callbacks before relying on automatic reconciliation.';
        } catch (Throwable $e) {
            $_SESSION['mpesa_dev_flash'] = 'C2B registration failed: ' . $e->getMessage();
        }
        header('Location: developer.php');
        exit;
    }

    $_SESSION['mpesa_dev_flash'] = 'Unknown developer settings action.';
    header('Location: developer.php');
    exit;
}

$flash = $_SESSION['mpesa_dev_flash'] ?? null;
unset($_SESSION['mpesa_dev_flash']);

$environment = mpesaSetting('mpesa_environment', 'sandbox');
$businessType = mpesaSetting('mpesa_business_type', 'paybill');
$businessShortcode = mpesaSetting('mpesa_shortcode');
$sandboxShortcode = mpesaSetting('mpesa_sandbox_shortcode', '174379');
$sandboxCallback = mpesaDeveloperValue('mpesa_sandbox_callback_url', 'mpesa_callback_url');
$liveCallback = mpesaSetting('mpesa_live_callback_url');

$credentialStatus = [
    'Sandbox Consumer Key' => mpesaDeveloperValue('mpesa_sandbox_consumer_key', 'mpesa_consumer_key') !== '',
    'Sandbox Consumer Secret' => mpesaDeveloperValue('mpesa_sandbox_consumer_secret', 'mpesa_consumer_secret') !== '',
    'Sandbox Passkey' => mpesaDeveloperValue('mpesa_sandbox_passkey', 'mpesa_passkey') !== '',
    'Production Consumer Key' => mpesaSetting('mpesa_live_consumer_key') !== '',
    'Production Consumer Secret' => mpesaSetting('mpesa_live_consumer_secret') !== '',
    'Production Passkey' => mpesaSetting('mpesa_live_passkey') !== '',
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
    <div class="mpesa-dev-wrap">
        <div class="page-heading">
            <div>
                <h1>M-PESA Developer Settings</h1>
                <p>Daraja credentials, callbacks, environment controls and sandbox testing. This page is restricted to the system superuser.</p>
            </div>
            <div><a class="btn secondary" href="<?= BASE_URL ?>/modules/developer/index.php">Developer Console</a> <a class="btn secondary" href="index.php">Business Settings</a></div>
        </div>

        <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

        <section class="card">
            <h2>Daraja Environment &amp; Credentials</h2>
            <p class="muted">Keep sandbox and production credentials separate. Production credentials are not required for sandbox testing.</p>

            <div class="status-grid">
                <?php foreach ($credentialStatus as $label => $configured): ?>
                    <div class="status-item">
                        <span><?= e($label) ?></span>
                        <strong class="<?= $configured ? 'configured' : 'missing' ?>"><?= $configured ? 'Configured' : 'Not configured' ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save">

                <div class="form-grid">
                    <label>Active environment
                        <select name="mpesa_environment">
                            <option value="sandbox" <?= $environment === 'sandbox' ? 'selected' : '' ?>>Sandbox — testing</option>
                            <option value="live" <?= $environment === 'live' ? 'selected' : '' ?>>Production — real payments</option>
                        </select>
                    </label>
                    <label>Sandbox shortcode
                        <input name="mpesa_sandbox_shortcode" value="<?= e($sandboxShortcode) ?>" required>
                    </label>
                    <label>Production business shortcode
                        <input value="<?= e($businessShortcode) ?>" readonly>
                        <small>Managed on the M-PESA Business Settings page (<?= e(strtoupper($businessType)) ?>).</small>
                    </label>

                    <div class="section-label">Sandbox credentials</div>
                    <label>Sandbox Consumer Key
                        <input type="password" name="mpesa_sandbox_consumer_key" autocomplete="new-password" placeholder="<?= $credentialStatus['Sandbox Consumer Key'] ? 'Saved — leave blank to keep current value' : 'Enter sandbox consumer key' ?>">
                    </label>
                    <label>Sandbox Consumer Secret
                        <input type="password" name="mpesa_sandbox_consumer_secret" autocomplete="new-password" placeholder="<?= $credentialStatus['Sandbox Consumer Secret'] ? 'Saved — leave blank to keep current value' : 'Enter sandbox consumer secret' ?>">
                    </label>
                    <label>Sandbox Lipa na M-PESA Passkey
                        <input type="password" name="mpesa_sandbox_passkey" autocomplete="new-password" placeholder="<?= $credentialStatus['Sandbox Passkey'] ? 'Saved — leave blank to keep current value' : 'Enter sandbox passkey' ?>">
                    </label>
                    <label class="full">Sandbox STK callback URL
                        <input type="url" name="mpesa_sandbox_callback_url" value="<?= e($sandboxCallback) ?>" placeholder="https://public-hostname/modules/mpesa/callback.php">
                    </label>

                    <div class="section-label">Production credentials</div>
                    <label>Production Consumer Key
                        <input type="password" name="mpesa_live_consumer_key" autocomplete="new-password" placeholder="<?= $credentialStatus['Production Consumer Key'] ? 'Saved — leave blank to keep current value' : 'Enter production consumer key' ?>">
                    </label>
                    <label>Production Consumer Secret
                        <input type="password" name="mpesa_live_consumer_secret" autocomplete="new-password" placeholder="<?= $credentialStatus['Production Consumer Secret'] ? 'Saved — leave blank to keep current value' : 'Enter production consumer secret' ?>">
                    </label>
                    <label>Production Lipa na M-PESA Passkey
                        <input type="password" name="mpesa_live_passkey" autocomplete="new-password" placeholder="<?= $credentialStatus['Production Passkey'] ? 'Saved — leave blank to keep current value' : 'Enter production passkey' ?>">
                    </label>
                    <label class="full">Production STK callback URL
                        <input type="url" name="mpesa_live_callback_url" value="<?= e($liveCallback) ?>" placeholder="https://your-permanent-hostname/modules/mpesa/callback.php">
                    </label>

                    <div class="section-label">Direct PayBill C2B callbacks</div>
                    <label class="full">C2B Confirmation URL
                        <input type="url" name="mpesa_c2b_confirmation_url" value="<?= e(mpesaSetting('mpesa_c2b_confirmation_url')) ?>" placeholder="https://your-hostname/callbacks/c2b_confirmation.php">
                    </label>
                    <label class="full">C2B Validation URL
                        <input type="url" name="mpesa_c2b_validation_url" value="<?= e(mpesaSetting('mpesa_c2b_validation_url')) ?>" placeholder="https://your-hostname/callbacks/c2b_validation.php">
                    </label>
                </div>

                <button class="btn primary" type="submit">Save Developer Settings</button>
            </form>
        </section>

        <section class="card">
            <h2>Sandbox Tests</h2>
            <p class="muted">These tools are for developer verification only. Sandbox tests use the sandbox shortcode and credentials, and the isolated callback does not create sales or payment records.</p>

            <form method="post" class="action-row">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn secondary" name="action" value="test_connection" type="submit">Test Sandbox Connection</button>
            </form>

            <form method="post" class="action-row">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Sandbox test phone
                    <input name="test_phone" inputmode="numeric" placeholder="Safaricom sandbox test MSISDN" required>
                </label>
                <label>Amount (KES)
                    <input type="number" name="test_amount" min="1" max="10" step="1" value="1" required>
                </label>
                <button class="btn primary" name="action" value="test_stk" type="submit">Send Sandbox STK Test</button>
            </form>
            <p class="muted small">The sandbox callback must be a publicly reachable HTTPS URL ending in /callback.php. Do not use a customer's real phone number.</p>
        </section>

        <section class="card">
            <h2>Register Direct PayBill C2B Callbacks</h2>
            <p class="muted">Only use this for a PayBill with valid C2B callback endpoints. It is not required for the ordinary STK Push checkout flow.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn secondary" name="action" value="register_c2b" type="submit">Register C2B Callback URLs</button>
            </form>
        </section>
    </div>
</main>

<style>
.mpesa-dev-wrap{max-width:1180px;margin:0 auto}.page-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}.page-heading h1{margin:0 0 6px}.page-heading p,.muted{color:#64748b}.card{background:#fff;border:1px solid #e8edf3;border-radius:14px;padding:22px;box-shadow:0 1px 5px #0001;margin-bottom:18px}.card h2{margin:0 0 7px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;margin:20px 0}.form-grid label,.action-row label{display:block;font-size:12px;font-weight:700;color:#334155}.form-grid input,.form-grid select,.action-row input{display:block;box-sizing:border-box;width:100%;margin-top:6px;padding:11px;border:1px solid #dbe3ec;border-radius:8px;background:#fff}.form-grid small{display:block;color:#64748b;font-weight:400;margin-top:5px}.full,.section-label{grid-column:1/-1}.section-label{font-size:13px;font-weight:800;color:#174a7e;padding-top:10px;border-bottom:1px solid #e8edf3;padding-bottom:8px}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:16px 0}.status-item{display:flex;flex-direction:column;gap:5px;padding:10px;border:1px solid #e8edf3;border-radius:8px;font-size:11px}.configured{color:#15803d}.missing{color:#b45309}.btn{display:inline-block;padding:11px 16px;border:0;border-radius:8px;text-decoration:none;font-weight:700;cursor:pointer}.primary{background:#004a99;color:#fff}.secondary{background:#eef2f7;color:#1e293b}.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:18px}.action-row{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:14px 0}.action-row label{min-width:220px}.small{font-size:11px}@media(max-width:800px){.form-grid,.status-grid{grid-template-columns:1fr}.full,.section-label{grid-column:auto}.page-heading{align-items:flex-start;flex-direction:column}}

/* Compact typography for M-PESA developer settings */
.mpesa-dev-wrap h1{font-size:1.25rem!important;line-height:1.3;margin:0 0 5px}
.mpesa-dev-wrap h2{font-size:1rem!important;line-height:1.35}
.mpesa-dev-wrap p{font-size:12px;line-height:1.5}
.mpesa-dev-wrap .form-grid label,.mpesa-dev-wrap .action-row label{font-size:12px}
.mpesa-dev-wrap .btn{font-size:12px}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
