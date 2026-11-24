<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mpesa.php';
if (!isSuperUser()) { http_response_code(403); exit('Access denied.'); }
$pageTitle='Developer Console';
function developerConsoleSetting(string $key,string $default=''):string { global $conn; $q=$conn->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1'); $q->execute([$key]); $v=$q->fetchColumn(); return $v===false?$default:(string)$v; }
$env=mpesaEnvironment();
$mpesaReady=developerConsoleSetting('mpesa_'.$env.'_consumer_key')!=='' && developerConsoleSetting('mpesa_'.$env.'_consumer_secret')!=='' && developerConsoleSetting('mpesa_'.$env.'_passkey')!=='';
if($env==='sandbox') { $mpesaReady=$mpesaReady || (developerConsoleSetting('mpesa_consumer_key')!=='' && developerConsoleSetting('mpesa_consumer_secret')!=='' && developerConsoleSetting('mpesa_passkey')!==''); }
$etimsPin=developerConsoleSetting('etims_taxpayer_pin');
$etimsStatus=developerConsoleSetting('etims_registration_status','Not Started');
$etimsEnv=developerConsoleSetting('etims_environment','sandbox');
$hasQueue=(bool)$conn->query("SHOW TABLES LIKE 'etims_documents'")->fetchColumn();
$queue=$hasQueue?(int)$conn->query('SELECT COUNT(*) FROM etims_documents')->fetchColumn():0;
$queued=$hasQueue?(int)$conn->query("SELECT COUNT(*) FROM etims_documents WHERE status='Queued'")->fetchColumn():0;
require_once __DIR__ . '/../../includes/header.php'; require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content"><div class="dev-console">
<div class="page-heading"><div><h1>Developer Console</h1><p>Technical configuration, integration readiness and troubleshooting.</p></div><span>System superuser</span></div>
<section class="dev-card"><h2>M-PESA Daraja</h2><p>Environment: <strong><?=e(strtoupper($env))?></strong> · Active credentials: <strong><?= $mpesaReady?'Configured':'Incomplete' ?></strong></p><p class="muted">Callback delivery must be tested separately; saved settings do not prove Safaricom can reach the URL.</p><a class="dev-button" href="<?=BASE_URL?>/modules/mpesa/developer.php">Open M-PESA diagnostics</a></section>
<section class="dev-card"><h2>KRA eTIMS</h2><p>Taxpayer PIN: <strong><?= $etimsPin!==''?'Recorded':'Not recorded' ?></strong> · Onboarding: <strong><?=e($etimsStatus)?></strong></p><p>Profile environment: <?=e(strtoupper($etimsEnv))?> · Prepared documents: <?=number_format($queue)?> · Queued: <?=number_format($queued)?></p><p class="notice">KRA transmission is disabled. Profile details and prepared invoice snapshots do not mean Dibonke is connected, certified or approved by KRA.</p><a class="dev-button" href="<?=BASE_URL?>/modules/etims/index.php">Open eTIMS setup</a></section>
<section class="dev-card"><h2>Application environment</h2><ul><li>PHP: <?=e(PHP_VERSION)?></li><li>cURL: <?=extension_loaded('curl')?'Available':'Missing'?></li><li>OpenSSL: <?=extension_loaded('openssl')?'Available':'Missing'?></li><li>PDO MySQL: <?=extension_loaded('pdo_mysql')?'Available':'Missing'?></li><li>Database connection: Connected</li></ul><p class="muted">These are local runtime checks only, not external API or callback tests.</p></section>
<section class="dev-card"><h2>Integration workspace</h2><p>Future integrations can be added here with configuration, connection tests, callback monitoring and redacted diagnostic logs.</p><p>Email and SMS · Additional payment gateways · External APIs · Scheduled tasks</p></section>
</div></main>
<style>.dev-console{max-width:1100px;margin:auto}.page-heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px}.page-heading p,.muted{color:#64748b}.dev-card{background:#fff;border:1px solid #e2e8f0;border-radius:13px;padding:20px;margin:0 0 16px}.dev-card h2{margin-top:0}.dev-card p,.dev-card li{font-size:13px;line-height:1.6}.dev-button{display:inline-block;padding:10px 14px;background:#174a7e;color:white;text-decoration:none;border-radius:8px;font-weight:700;font-size:12px}.notice{padding:10px;background:#fff8e8;color:#805b0b;border-radius:8px}.dev-card ul{display:flex;gap:24px;flex-wrap:wrap;padding-left:20px}@media(max-width:700px){.page-heading{display:block}.dev-card ul{display:block}}</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>