<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

$pageTitle = 'Dashboard';

function scalar(PDO $db, string $sql): int|float
{
    $value = $db->query($sql)->fetchColumn();
    return is_numeric($value) ? (float)$value : 0;
}

$totalProducts = (int)scalar($conn, "SELECT COUNT(*) FROM products WHERE status = 'Active'");
$totalCustomers = (int)scalar($conn, "SELECT COUNT(*) FROM customers WHERE status = 'Active'");
$lowStock = (int)scalar($conn, "SELECT COUNT(*) FROM products WHERE status = 'Active' AND stock_quantity <= reorder_level");
$monthSales = (float)scalar($conn, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_status = 'Completed' AND YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())");
$monthExpenses = (float)scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())");
$creditBalance = (float)scalar($conn, "SELECT COALESCE(SUM(balance),0) FROM customers WHERE status='Active' AND balance > 0");

$recentSales = $conn->query(
    "SELECT s.invoice_number, s.sale_date, s.total_amount, c.customer_name
     FROM sales s
     LEFT JOIN customers c ON c.id=s.customer_id
     WHERE s.sale_status='Completed'
     ORDER BY s.id DESC LIMIT 8"
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="content dashboard-page">
    <section class="dashboard-welcome">
        <div>
            <span class="dashboard-eyebrow">BOOKSHOP OPERATIONS</span>
            <h1>Good <?= e((int)date('G') < 12 ? 'morning' : ((int)date('G') < 17 ? 'afternoon' : 'evening')) ?>, <?= e(explode(' ', trim((string)($_SESSION['full_name'] ?? 'there')))[0] ?: 'there') ?>.</h1>
            <p>Here is your Dibonke Bookshop overview for today.</p>
        </div>
        <div class="dashboard-date"><i class="far fa-calendar-alt"></i><span><?= e(date('l, d F Y')) ?></span></div>
    </section>

    <section class="dashboard-kpis" aria-label="Business summary">
        <article class="dashboard-kpi kpi-sales">
            <div class="kpi-top"><span>Sales this month</span><span class="kpi-icon"><i class="fas fa-chart-line"></i></span></div>
            <strong>KES <?= number_format($monthSales, 2) ?></strong>
            <small>Completed transactions</small>
        </article>
        <article class="dashboard-kpi">
            <div class="kpi-top"><span>Active products</span><span class="kpi-icon"><i class="fas fa-book"></i></span></div>
            <strong><?= number_format($totalProducts) ?></strong>
            <small>Books, stationery &amp; retail items</small>
        </article>
        <article class="dashboard-kpi">
            <div class="kpi-top"><span>Active customers</span><span class="kpi-icon"><i class="fas fa-users"></i></span></div>
            <strong><?= number_format($totalCustomers) ?></strong>
            <small>Registered customers</small>
        </article>
        <article class="dashboard-kpi <?= $lowStock > 0 ? 'kpi-warning' : '' ?>">
            <div class="kpi-top"><span>Low stock items</span><span class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></span></div>
            <strong><?= number_format($lowStock) ?></strong>
            <small><?= $lowStock > 0 ? 'Review items for replenishment' : 'Stock levels look healthy' ?></small>
        </article>
    </section>

    <section class="dashboard-shortcuts">
        <div class="dashboard-section-heading">
            <div><h2>Quick actions</h2><p>Jump straight into everyday tasks.</p></div>
        </div>
        <div class="shortcut-grid">
            <?php if (canAccessModule('sales')): ?><a class="shortcut-card" href="<?= BASE_URL ?>/modules/sales/index.php"><span class="shortcut-icon"><i class="fas fa-cash-register"></i></span><span><strong>New sale</strong><small>Open point of sale</small></span><i class="fas fa-arrow-right shortcut-arrow"></i></a><?php endif; ?>
            <?php if (canAccessModule('products')): ?><a class="shortcut-card" href="<?= BASE_URL ?>/modules/products/index.php"><span class="shortcut-icon"><i class="fas fa-book-open"></i></span><span><strong>Products</strong><small>Manage bookshop catalogue</small></span><i class="fas fa-arrow-right shortcut-arrow"></i></a><?php endif; ?>
            <?php if (canAccessModule('purchases')): ?><a class="shortcut-card" href="<?= BASE_URL ?>/modules/purchases/index.php"><span class="shortcut-icon"><i class="fas fa-cart-plus"></i></span><span><strong>Record purchase</strong><small>Receive supplier stock</small></span><i class="fas fa-arrow-right shortcut-arrow"></i></a><?php endif; ?>
            <?php if (canAccessModule('customers')): ?><a class="shortcut-card" href="<?= BASE_URL ?>/modules/customers/index.php"><span class="shortcut-icon"><i class="fas fa-user-plus"></i></span><span><strong>Customers</strong><small>View customer accounts</small></span><i class="fas fa-arrow-right shortcut-arrow"></i></a><?php endif; ?>
        </div>
    </section>

    <section class="dashboard-lower">
        <article class="dashboard-panel recent-sales-panel">
            <div class="dashboard-section-heading">
                <div><h2>Recent sales</h2><p>Your latest completed transactions.</p></div>
                <?php if (canAccessModule('sales')): ?><a class="dashboard-view-link" href="<?= BASE_URL ?>/modules/sales/index.php">View sales <i class="fas fa-arrow-right"></i></a><?php endif; ?>
            </div>
            <?php if (!$recentSales): ?>
                <div class="dashboard-empty"><span><i class="fas fa-receipt"></i></span><strong>No sales recorded yet</strong><p>Completed sales will appear here when transactions are recorded.</p></div>
            <?php else: ?>
                <div class="dashboard-table-wrap">
                    <table class="dashboard-table">
                        <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th class="amount-cell">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td><strong><?= e($sale['invoice_number']) ?></strong></td>
                                <td><?= e($sale['customer_name'] ?: 'Walk-in Customer') ?></td>
                                <td><?= e(date('d M Y, H:i', strtotime($sale['sale_date']))) ?></td>
                                <td class="amount-cell">KES <?= number_format((float)$sale['total_amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <article class="dashboard-panel financial-panel">
            <div class="dashboard-section-heading"><div><h2>Financial snapshot</h2><p>This month's position.</p></div></div>
            <div class="financial-line"><span><i class="fas fa-wallet"></i> Expenses this month</span><strong>KES <?= number_format($monthExpenses, 2) ?></strong></div>
            <div class="financial-line"><span><i class="fas fa-file-invoice-dollar"></i> Customer credit outstanding</span><strong>KES <?= number_format($creditBalance, 2) ?></strong></div>
            <div class="financial-note"><i class="fas fa-info-circle"></i> Figures are based on transactions currently recorded in the system.</div>
            <?php if (canAccessModule('reports')): ?><a class="dashboard-report-link" href="<?= BASE_URL ?>/modules/reports/index.php">Open reports <i class="fas fa-arrow-right"></i></a><?php endif; ?>
        </article>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
