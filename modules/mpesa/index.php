<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mpesa.php';

requireModuleAccess('mpesa');
$pageTitle = 'M-PESA Business Settings';

function mpesaBusinessSave(PDO $conn, string $key, string $value): void
{
    $stmt = $conn->prepare(
        'INSERT INTO settings(setting_key, setting_value) VALUES(?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    requireModulePermission('mpesa', 'edit');

    try {
        $businessType = strtolower(trim((string)($_POST['mpesa_business_type'] ?? 'paybill')));
        $shortcode = preg_replace('/\D+/', '', (string)($_POST['mpesa_shortcode'] ?? '')) ?? '';
        $accountReference = trim((string)($_POST['mpesa_account_reference'] ?? ''));
        $businessName = trim((string)($_POST['mpesa_business_name'] ?? ''));

        if (!in_array($businessType, ['paybill', 'till'], true)) {
            throw new RuntimeException('Choose either PayBill or Buy Goods Till.');
        }
        if (!preg_match('/^\d{5,10}$/', $shortcode)) {
            throw new RuntimeException('Enter a valid business PayBill or Till number.');
        }
        if ($businessType === 'paybill' && $accountReference === '') {
            throw new RuntimeException('Enter the PayBill account number/reference customers should use.');
        }
        if ($accountReference !== '' && !preg_match('/^[A-Za-z0-9._-]{1,20}$/', $accountReference)) {
            throw new RuntimeException('The account number/reference can contain letters, numbers, dots, underscores and hyphens only (maximum 20 characters).');
        }
        if ($businessName === '' || strlen($businessName) > 100) {
            throw new RuntimeException('Enter the business name (maximum 100 characters).');
        }

        mpesaBusinessSave($conn, 'mpesa_business_type', $businessType);
        mpesaBusinessSave($conn, 'mpesa_shortcode', $shortcode);
        mpesaBusinessSave($conn, 'mpesa_account_reference', $accountReference);
        mpesaBusinessSave($conn, 'mpesa_business_name', $businessName);

        $_SESSION['mpesa_flash'] = 'M-PESA business payment details saved successfully.';
    } catch (Throwable $e) {
        $_SESSION['mpesa_flash'] = 'Business settings were not saved: ' . $e->getMessage();
    }

    header('Location: index.php');
    exit;
}

$businessType = mpesaSetting('mpesa_business_type', 'paybill');
$shortcode = mpesaSetting('mpesa_shortcode');
$accountReference = mpesaSetting('mpesa_account_reference');
$businessName = mpesaSetting('mpesa_business_name', 'Dibonke Bookshop');
$flash = $_SESSION['mpesa_flash'] ?? null;
unset($_SESSION['mpesa_flash']);

$transactions = $conn->query('SELECT * FROM mpesa_transactions ORDER BY id DESC LIMIT 30')->fetchAll();
$unallocatedC2B = (int)$conn->query("SELECT COUNT(*) FROM mpesa_transactions WHERE transaction_type='C2B' AND result_status='Success' AND reference_type IS NULL")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
    <div class="mpesa-business-wrap">
        <div class="page-heading">
            <div>
                <h1>M-PESA Business Settings</h1>
                <p>Enter the PayBill or Till details customers use to pay your business. No Daraja developer credentials or sandbox testing are needed here.</p>
            </div>
            <?php if (isSuperUser()): ?>
                <a class="btn secondary" href="developer.php">Developer Settings</a>
            <?php endif; ?>
        </div>

        <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

        <section class="card">
            <h2>Business Payment Details</h2>
            <p class="muted">These details identify where the bookshop receives M-PESA payments.</p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-grid">
                    <label>Payment method
                        <select name="mpesa_business_type" id="mpesaBusinessType">
                            <option value="paybill" <?= $businessType === 'paybill' ? 'selected' : '' ?>>PayBill</option>
                            <option value="till" <?= $businessType === 'till' ? 'selected' : '' ?>>Buy Goods Till</option>
                        </select>
                    </label>

                    <label id="shortcodeLabel">PayBill / Till number
                        <input name="mpesa_shortcode" value="<?= e($shortcode) ?>" inputmode="numeric" required placeholder="Enter business shortcode">
                    </label>

                    <label id="accountReferenceLabel">PayBill account number
                        <input name="mpesa_account_reference" value="<?= e($accountReference) ?>" maxlength="20" placeholder="e.g. 274784">
                        <small>For manual PayBill payments. STK Push requests use the sale or receipt reference to help match payments.</small>
                    </label>

                    <label>Business name
                        <input name="mpesa_business_name" value="<?= e($businessName) ?>" maxlength="100" required placeholder="e.g. Dibonke Bookshop">
                    </label>
                </div>

                <button class="btn primary" type="submit">Save Business Payment Details</button>
            </form>
        </section>

        <section class="card c2b-summary">
            <div>
                <h2>Direct PayBill Payments</h2>
                <p class="muted">Successful direct PayBill payments received and awaiting allocation.</p>
            </div>
            <div class="c2b-count">
                <strong><?= number_format($unallocatedC2B) ?></strong>
                <span>Awaiting allocation</span>
            </div>
        </section>

        <section class="card">
            <h2>Recent M-PESA Transactions</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th><th>Type</th><th>Phone</th><th>Amount</th><th>Receipt / Tx ID</th><th>Invoice reference</th><th>Allocation</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?= e($transaction['transaction_date'] ?: $transaction['created_at']) ?></td>
                            <td><?= e($transaction['transaction_type']) ?></td>
                            <td><?= e($transaction['phone_number'] ?: '—') ?></td>
                            <td>KES <?= number_format((float)$transaction['amount'], 2) ?></td>
                            <td><?= e($transaction['transaction_reference'] ?: $transaction['checkout_request_id'] ?: '—') ?></td>
                            <td><?= e($transaction['account_reference'] ?: '—') ?></td>
                            <td><?= !empty($transaction['reference_id']) ? 'Allocated to sale #' . (int)$transaction['reference_id'] : ($transaction['transaction_type'] === 'C2B' ? 'Unallocated' : '—') ?></td>
                            <td><?= e($transaction['result_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="8" class="empty">No M-PESA transactions have been recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<script>
(function () {
    const type = document.getElementById('mpesaBusinessType');
    const label = document.getElementById('accountReferenceLabel');
    const input = document.querySelector('[name="mpesa_account_reference"]');
    function updateAccountReference() {
        const isPaybill = type.value === 'paybill';
        label.style.display = isPaybill ? '' : 'none';
        input.required = isPaybill;
    }
    type.addEventListener('change', updateAccountReference);
    updateAccountReference();
})();
</script>

<style>
.mpesa-business-wrap{max-width:1200px;margin:0 auto}.page-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}.page-heading h1{margin:0 0 6px}.page-heading p,.muted{color:#64748b}.card{background:#fff;border:1px solid #e8edf3;border-radius:14px;padding:22px;box-shadow:0 1px 5px #0001;margin-bottom:18px}.card h2{margin:0 0 7px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:20px 0}.form-grid label{display:block;font-size:12px;font-weight:700;color:#334155}.form-grid input,.form-grid select{display:block;box-sizing:border-box;width:100%;margin-top:6px;padding:12px;border:1px solid #dbe3ec;border-radius:8px;background:#fff}.form-grid small{display:block;color:#64748b;font-weight:400;margin-top:5px;line-height:1.4}.btn{display:inline-block;padding:11px 16px;border:0;border-radius:8px;text-decoration:none;font-weight:700;cursor:pointer}.primary{background:#004a99;color:#fff}.secondary{background:#eef2f7;color:#1e293b}.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:18px}.c2b-summary{display:flex;align-items:center;justify-content:space-between;gap:16px}.c2b-summary h2{margin:0 0 5px}.c2b-summary p{margin:0}.c2b-count{display:flex;align-items:center;gap:9px;padding:9px 13px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;white-space:nowrap}.c2b-count strong{font-size:23px;color:#0f172a}.c2b-count span{font-size:11px;color:#64748b}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:12px;white-space:nowrap}th{background:#f8fafc;color:#334155}.empty{text-align:center;color:#64748b;padding:24px}@media(max-width:800px){.form-grid{grid-template-columns:1fr}.page-heading{align-items:flex-start;flex-direction:column}.c2b-summary{align-items:flex-start;flex-direction:column}}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
