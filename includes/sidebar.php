<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
function navActive(string $needle): string
{
    global $currentPath;
    return strpos($currentPath, $needle) !== false ? 'active' : '';
}
$fullName = (string)($_SESSION['full_name'] ?? 'User');
$role = (string)($_SESSION['role'] ?? 'Staff');
$initial = strtoupper(substr(trim($fullName), 0, 1)) ?: 'U';
?>
<style>
.sidebar{position:fixed;left:0;top:var(--app-topbar-height,72px);bottom:0;width:var(--app-sidebar-width,264px);height:auto;display:flex;flex-direction:column;gap:0;padding:0;background:linear-gradient(180deg,#17324d 0%,#10263b 100%);color:#dce6ef;z-index:1000;overflow-y:auto;overflow-x:hidden;box-shadow:4px 0 18px rgba(16,38,59,.10);transition:transform .25s ease}
#appSidebar.sidebar{display:flex!important;visibility:visible!important;opacity:1!important;box-sizing:border-box;float:none;flex:0 0 var(--app-sidebar-width,264px);}
#appSidebar.sidebar nav{display:block!important;visibility:visible!important;opacity:1!important;width:100%;box-sizing:border-box;}
#appSidebar.sidebar nav a{display:flex!important;visibility:visible!important;opacity:1!important;}
#appSidebar.sidebar .sidebar-brand,#appSidebar.sidebar .user-profile{display:flex!important;visibility:visible!important;}
.sidebar::-webkit-scrollbar{width:5px}.sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);border-radius:10px}
.sidebar-brand{display:flex;align-items:center;gap:12px;padding:23px 22px 20px;border-bottom:1px solid rgba(255,255,255,.1)}
.sidebar-brand-mark{width:38px;height:38px;display:grid;place-items:center;flex:0 0 38px;border-radius:11px;background:rgba(214,168,79,.16);color:#e9c477;font-size:18px}
.sidebar-brand-copy strong{display:block;color:#fff;font-size:14px;letter-spacing:.3px}.sidebar-brand-copy span{display:block;margin-top:3px;color:#9fb1c2;font-size:10px;letter-spacing:1.1px;text-transform:uppercase}
.user-profile{display:flex;align-items:center;gap:11px;margin:16px 14px 10px;padding:12px;border:1px solid rgba(255,255,255,.09);border-radius:12px;background:rgba(255,255,255,.055)}
.user-avatar{width:36px;height:36px;display:grid;place-items:center;flex:0 0 36px;border-radius:50%;background:#e9c477;color:#17324d;font-size:13px;font-weight:800}
.user-info{min-width:0}.user-info .name{display:block;overflow:hidden;color:#fff;font-size:12px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.user-info .role{display:block;margin-top:3px;color:#a9bac9;font-size:10px}
.sidebar nav{display:block;flex:1;min-width:0;overflow:visible;padding:10px 12px 22px}.sidebar nav a:before{display:none}.menu-title{padding:17px 12px 7px;color:#d6b66f;font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase}
.sidebar nav button.menu-title{display:flex;align-items:center;justify-content:space-between;width:100%;margin:0;border:0;background:transparent;text-align:left;font-family:inherit;font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer}
.sidebar nav button.menu-title .nav-group-chevron{width:7px;height:7px;flex:0 0 7px;margin:0 3px 3px 10px;border-right:1.5px solid currentColor;border-bottom:1.5px solid currentColor;transform:rotate(45deg);transition:transform .18s ease}
.sidebar nav button.menu-title[aria-expanded="true"] .nav-group-chevron{transform:rotate(225deg);margin-bottom:-3px}
#appSidebar.sidebar nav [hidden]{display:none!important}
#appSidebar.sidebar nav a[hidden]{display:none!important}
.sidebar nav a{display:flex;align-items:center;gap:12px;margin:3px 0;padding:10px 12px;border-radius:9px;color:#c7d4df;text-decoration:none;font-size:12px;font-weight:600;transition:background .18s,color .18s}
.sidebar nav a .icon-main{width:17px;flex:0 0 17px;color:#9fb4c7;text-align:center;font-size:13px}
.sidebar nav a:hover{background:rgba(255,255,255,.09);color:#fff}.sidebar nav a:hover .icon-main{color:#e9c477}
.sidebar nav a.active{background:#fff;color:#17324d;box-shadow:0 3px 12px rgba(0,0,0,.12)}.sidebar nav a.active .icon-main{color:#a87924}
.sidebar-access-note{display:flex;gap:8px;align-items:flex-start;margin:13px 5px;padding:11px;border:1px solid rgba(233,196,119,.22);border-radius:9px;background:rgba(233,196,119,.08);color:#e8d6ae;font-size:10px;line-height:1.5}.sidebar-access-note i{margin-top:2px;color:#e9c477}
.sidebar nav a.logout-link{margin-top:18px;border:1px solid rgba(248,113,113,.18);background:rgba(248,113,113,.08);color:#fecaca}
.sidebar nav a.logout-link .icon-main{color:#fca5a5}
@media(max-width:768px){#appSidebar.sidebar{position:fixed;display:flex!important;left:0;top:var(--app-topbar-height,72px);right:auto;bottom:0;width:min(290px,86vw);height:auto;display:flex;flex-direction:column;gap:0;padding:0;border:0;transform:translateX(-105%);box-shadow:12px 0 30px rgba(0,0,0,.2)}#appSidebar.sidebar.is-open{transform:translateX(0);visibility:visible!important}.sidebar nav{display:block;flex-direction:column;overflow-y:auto;overflow-x:hidden}.sidebar nav a{width:auto;white-space:normal;flex-shrink:initial}}

/* Desktop compact sidebar mode */
@media(min-width:769px){
 html.sidebar-collapsed{--app-sidebar-width:76px}
 html.sidebar-collapsed #appSidebar.sidebar{width:76px;flex-basis:76px}
 html.sidebar-collapsed #appSidebar .sidebar-brand{justify-content:center;padding:18px 8px}
 html.sidebar-collapsed #appSidebar .sidebar-brand-copy,
 html.sidebar-collapsed #appSidebar .user-profile,
 html.sidebar-collapsed #appSidebar .menu-title,
 html.sidebar-collapsed #appSidebar .sidebar-access-note{display:none!important}
 html.sidebar-collapsed #appSidebar nav{padding:12px 8px}
 html.sidebar-collapsed #appSidebar nav a{justify-content:center;gap:0;padding:12px 6px;font-size:0;white-space:nowrap}
 html.sidebar-collapsed #appSidebar nav a .icon-main{width:22px;flex:0 0 22px;font-size:16px}
 html.sidebar-collapsed #appSidebar nav a.logout-link{margin-top:18px}
}
</style>

<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-mark"><i class="fas fa-book-open"></i></div>
        <div class="sidebar-brand-copy"><strong>Dibonke Bookshop</strong><span>Retail Management</span></div>
    </div>
    <div class="user-profile">
        <div class="user-avatar"><?= e($initial) ?></div>
        <div class="user-info"><span class="name"><?= e($fullName) ?></span><span class="role"><?= e($role) ?></span></div>
    </div>
    <nav aria-label="Main navigation">
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Workspace</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <a href="<?= BASE_URL ?>/index.php" class="<?= $currentPath === BASE_URL . '/index.php' ? 'active' : '' ?>"><i class="fas fa-th-large icon-main"></i>Dashboard</a>

        <?php if (canAccessModule('products') || canAccessModule('categories') || canAccessModule('brands') || canAccessModule('price_lists') || canAccessModule('stock_movements')): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Catalogue &amp; Inventory</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <?php if (canAccessModule('products')): ?><a href="<?= BASE_URL ?>/modules/products/index.php" class="<?= navActive('/products/') ?>"><i class="fas fa-book icon-main"></i>Products &amp; Books</a><?php endif; ?>
        <?php if (canAccessModule('categories')): ?><a href="<?= BASE_URL ?>/modules/categories/index.php" class="<?= navActive('/categories/') ?>"><i class="fas fa-layer-group icon-main"></i>Categories</a><?php endif; ?>
        <?php if (canAccessModule('brands')): ?><a href="<?= BASE_URL ?>/modules/brands/index.php" class="<?= navActive('/brands/') ?>"><i class="fas fa-bookmark icon-main"></i>Brands / Publishers</a><?php endif; ?>
        <?php if (canAccessModule('price_lists')): ?><a href="<?= BASE_URL ?>/modules/price_lists/index.php" class="<?= navActive('/price_lists/') ?>"><i class="fas fa-tags icon-main"></i>Price Lists</a><?php endif; ?>
        <?php if (canAccessModule('stock_movements')): ?><a href="<?= BASE_URL ?>/modules/stock_movements/index.php" class="<?= navActive('/stock_movements/') ?>"><i class="fas fa-exchange-alt icon-main"></i>Stock Movements</a><?php endif; ?>
        <?php endif; ?>

        <?php if (canAccessModule('suppliers') || canAccessModule('purchases') || canAccessModule('supply_chain')): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Procurement</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <?php if (canAccessModule('suppliers')): ?><a href="<?= BASE_URL ?>/modules/suppliers/index.php" class="<?= navActive('/suppliers/') ?>"><i class="fas fa-truck icon-main"></i>Suppliers</a><?php endif; ?>
        <?php if (canAccessModule('purchases')): ?><a href="<?= BASE_URL ?>/modules/purchases/index.php" class="<?= navActive('/purchases/') ?>"><i class="fas fa-cart-plus icon-main"></i>Purchase Register</a><?php endif; ?>
        <?php if (canAccessModule('supply_chain')): ?><a href="<?= BASE_URL ?>/modules/supply_chain/index.php" class="<?= navActive('/supply_chain/') ?>"><i class="fas fa-diagram-project icon-main"></i>Purchase Orders &amp; Receiving</a><?php endif; ?>
        <?php endif; ?>

        <?php if (canAccessModule('customers') || canAccessModule('sales') || canAccessModule('customer_payments') || canAccessModule('deliveries')): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Sales &amp; Customer Service</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <?php if (canAccessModule('customers')): ?><a href="<?= BASE_URL ?>/modules/customers/index.php" class="<?= navActive('/customers/') ?>"><i class="fas fa-users icon-main"></i>Customers</a><?php endif; ?>
        <?php if (canAccessModule('sales')): ?><a href="<?= BASE_URL ?>/modules/sales/index.php" class="<?= navActive('/sales/') ?>"><i class="fas fa-cash-register icon-main"></i>Sales &amp; POS</a><?php endif; ?>
        <?php if (hasModulePermission('cash_management','view') || hasModulePermission('cash_management','create')): ?><a href="<?= BASE_URL ?>/modules/sales/my_shift.php" class="<?= navActive('/sales/my_shift.php') ?>"><i class="fas fa-cash-register icon-main"></i>My Cashier Shift</a><?php endif; ?>
        <?php if (canAccessModule('customer_payments')): ?><a href="<?= BASE_URL ?>/modules/customer_payments/index.php" class="<?= navActive('/customer_payments/') ?>"><i class="fas fa-money-check-alt icon-main"></i>Customer Payments</a><?php endif; ?>
        <?php if (canAccessModule('deliveries')): ?><a href="<?= BASE_URL ?>/modules/deliveries/index.php" class="<?= $currentPath === BASE_URL . '/modules/deliveries/index.php' ? 'active' : '' ?>"><i class="fas fa-shipping-fast icon-main"></i>Deliveries</a><?php endif; ?>
        <?php if (canAccessModule('fleet')): ?><a href="<?= BASE_URL ?>/modules/deliveries/fleet.php" class="<?= navActive('/deliveries/fleet.php') ?>"><i class="fas fa-truck icon-main"></i>Fleet Management</a><?php endif; ?>
        <?php endif; ?>

        <?php if (canAccessModule('finance') || canAccessModule('billing') || canAccessModule('expenses') || canAccessModule('supplier_payments') || canAccessModule('mpesa') || canAccessModule('chart_of_accounts') || canAccessModule('journals') || canAccessModule('general_ledger') || canAccessModule('trial_balance') || canAccessModule('aged_receivables') || canAccessModule('accounting_mappings')): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Finance &amp; Billing</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <?php if (canAccessModule('accounting_mappings')): ?><a href="<?= BASE_URL ?>/modules/finance/posting_mappings.php" class="<?= navActive('/finance/posting_mappings.php') ?>"><i class="fas fa-random icon-main"></i>Posting Mappings</a><?php endif; ?>
        <?php if (canAccessModule('chart_of_accounts')): ?><a href="<?= BASE_URL ?>/modules/finance/chart_of_accounts.php" class="<?= navActive('/finance/chart_of_accounts.php') ?>"><i class="fas fa-list-ol icon-main"></i>Chart of Accounts</a><?php endif; ?>
        <?php if (canAccessModule('journals')): ?><a href="<?= BASE_URL ?>/modules/finance/journals.php" class="<?= navActive('/finance/journals.php') ?>"><i class="fas fa-book-open icon-main"></i>Journal Entries</a><?php endif; ?>
        <?php if (canAccessModule('general_ledger')): ?><a href="<?= BASE_URL ?>/modules/finance/general_ledger.php" class="<?= navActive('/finance/general_ledger.php') ?>"><i class="fas fa-book icon-main"></i>General Ledger</a><?php endif; ?>
        <?php if (canAccessModule('trial_balance')): ?><a href="<?= BASE_URL ?>/modules/finance/trial_balance.php" class="<?= navActive('/finance/trial_balance.php') ?>"><i class="fas fa-balance-scale icon-main"></i>Trial Balance</a><?php endif; ?>
        <?php if (canAccessModule('aged_receivables')): ?><a href="<?= BASE_URL ?>/modules/finance/aged_receivables.php" class="<?= navActive('/finance/aged_receivables.php') ?>"><i class="fas fa-hourglass-half icon-main"></i>Aged Receivables</a><?php endif; ?>
        <?php if (canAccessModule('finance')): ?>
        <a href="<?= BASE_URL ?>/modules/finance/index.php" class="<?= navActive('/finance/') ?>"><i class="fas fa-chart-pie icon-main"></i>Finance Overview</a>
        <a href="<?= BASE_URL ?>/modules/finance/ledger.php" class="<?= navActive('/finance/ledger.php') ?>"><i class="fas fa-book icon-main"></i>Ledger</a>
        <a href="<?= BASE_URL ?>/modules/finance/control.php" class="<?= navActive('/finance/control.php') ?>"><i class="fas fa-shield-alt icon-main"></i>Financial Control</a>
        <?php endif; ?>
        <?php if (canAccessModule('billing')): ?><a href="<?= BASE_URL ?>/modules/billing/index.php" class="<?= navActive('/billing/') ?>"><i class="fas fa-file-invoice icon-main"></i>Billing &amp; Invoices</a><?php endif; ?>
        <?php if (canAccessModule('expenses')): ?><a href="<?= BASE_URL ?>/modules/expenses/index.php" class="<?= navActive('/expenses/') ?>"><i class="fas fa-wallet icon-main"></i>Expenses</a><?php endif; ?>
        <?php if (canAccessModule('supplier_payments')): ?><a href="<?= BASE_URL ?>/modules/supplier_payments/index.php" class="<?= navActive('/supplier_payments/') ?>"><i class="fas fa-file-invoice-dollar icon-main"></i>Supplier Payments</a><?php endif; ?>
        <?php if (canAccessModule('mpesa')): ?><a href="<?= BASE_URL ?>/modules/mpesa/index.php" class="<?= navActive('/mpesa/') ?>"><i class="fas fa-mobile-alt icon-main"></i>M-PESA</a><?php endif; ?>
        <?php endif; ?>

        <?php if (canAccessModule('launch_controls')): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Control &amp; Compliance</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <a href="<?= BASE_URL ?>/modules/launch_controls/index.php" class="<?= navActive('/launch_controls/') ?>"><i class="fas fa-clipboard-check icon-main"></i>Launch Operations</a>
        <?php if (canAccessModule('etims')): ?><a href="<?= BASE_URL ?>/modules/etims/index.php" class="<?= navActive('/etims/') ?>"><i class="fas fa-file-invoice-dollar icon-main"></i>eTIMS Setup</a><?php endif; ?>
        <?php endif; ?>

        <?php if (canAccessModule('reports') || canAccessModule('analytics')): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Insights</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <?php if (canAccessModule('reports')): ?><a href="<?= BASE_URL ?>/modules/reports/index.php" class="<?= navActive('/reports/') ?>"><i class="fas fa-chart-bar icon-main"></i>Reports</a><?php endif; ?>
        <?php if (canAccessModule('analytics')): ?><a href="<?= BASE_URL ?>/modules/analytics/index.php" class="<?= navActive('/analytics/') ?>"><i class="fas fa-chart-line icon-main"></i>Analytics</a><?php endif; ?>
        <?php endif; ?>

        <?php if (canAccessModule('staff') || canAccessModule('hr_self_service') || isAdministrator()): ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Staff &amp; Access</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <?php if (canAccessModule('hr_self_service')): ?><a href="<?= BASE_URL ?>/modules/hr/my.php" class="<?= navActive('/hr/my.php') ?>"><i class="fas fa-clock icon-main"></i>My HR Self-Service</a><?php endif; ?>
        <?php if (canAccessModule('hr')): ?><a href="<?= BASE_URL ?>/modules/hr/index.php" class="<?= navActive('/hr/index.php') ?>"><i class="fas fa-user-tie icon-main"></i>Human Resources</a><?php endif; ?>
        <?php if (canAccessModule('staff')): ?><a href="<?= BASE_URL ?>/modules/staff/index.php" class="<?= navActive('/staff/') ?>"><i class="fas fa-id-badge icon-main"></i>Staff Directory</a><?php endif; ?>
        <?php if (isAdministrator()): ?>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="<?= navActive('/users/') ?>"><i class="fas fa-user-shield icon-main"></i>Users</a>
        <a href="<?= BASE_URL ?>/modules/roles/index.php" class="<?= navActive('/roles/') ?>"><i class="fas fa-user-lock icon-main"></i>Roles &amp; Permissions</a>
        <a href="<?= BASE_URL ?>/modules/settings/index.php" class="<?= navActive('/settings/') ?>"><i class="fas fa-sliders-h icon-main"></i>Settings</a>
        <?php if (isSuperUser()): ?><a href="<?= BASE_URL ?>/modules/developer/index.php" class="<?= navActive('/developer/') ?>"><i class="fas fa-terminal icon-main"></i>Developer Console</a><?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="menu-title nav-group-toggle" aria-expanded="false"><span>Help &amp; Support</span><span class="nav-group-chevron" aria-hidden="true"></span></button>
        <a href="<?= BASE_URL ?>/modules/help/manual.php" class="manual-nav"><i class="fas fa-book-reader icon-main"></i>User Manual</a>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="logout-link"><i class="fas fa-sign-out-alt icon-main"></i>Sign out</a>
    </nav>
</aside>

<script>
(function(){
    const nav=document.querySelector('#appSidebar nav');
    if(!nav)return;
    const groups=Array.from(nav.querySelectorAll(':scope > .nav-group-toggle'));
    groups.forEach((heading,index)=>{
        const members=[];
        let next=heading.nextElementSibling;
        while(next&&!next.classList.contains('nav-group-toggle')){
            members.push(next);
            next=next.nextElementSibling;
        }
        const hasActive=members.some(node=>node.matches('a.active'));
        const expanded=hasActive;
        heading.setAttribute('aria-expanded',String(expanded));
        members.forEach(node=>{node.hidden=!expanded;});
        heading.addEventListener('click',()=>{
            const open=heading.getAttribute('aria-expanded')!=='true';
            heading.setAttribute('aria-expanded',String(open));
            members.forEach(node=>{node.hidden=!open;});
        });
    });
})();
</script>
