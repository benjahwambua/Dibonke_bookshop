<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
$userName = (string)($_SESSION['full_name'] ?? 'User');
$userInitial = strtoupper(substr(trim($userName), 0, 1)) ?: 'U';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle ?? APP_NAME) ?> | Dibonke Bookshop</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/module-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--app-topbar-height:72px;--app-sidebar-width:264px;--app-navy:#17324d;--app-navy-deep:#10263b;--app-gold:#d6a84f;--app-bg:#f5f7fa;--app-border:#e5eaf0}
        html,body{min-height:100%;margin:0}
        body{min-height:100vh;display:flex;flex-direction:column;background:var(--app-bg);color:#263648}
        .top-header{height:var(--app-topbar-height);min-height:var(--app-topbar-height);position:fixed;inset:0 0 auto 0;z-index:1100;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 28px;background:#fff;border-bottom:1px solid var(--app-border);box-shadow:0 2px 12px rgba(16,38,59,.06)}
        .header-left-group,.header-right,.header-brand,.header-user{display:flex;align-items:center}
        .header-left-group{gap:16px;min-width:0}.header-brand{gap:12px;min-width:0}
        .logo-container{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border-radius:12px;background:var(--app-navy);color:#fff;font-size:21px;box-shadow:inset 0 -3px 0 rgba(0,0,0,.12)}
        .header-title-wrapper{min-width:0}.header-title{margin:0;color:var(--app-navy);font-size:18px;line-height:1.2;font-weight:800;letter-spacing:-.3px}
        .subtitle{margin-top:4px;color:#7a8795;font-size:11px;letter-spacing:.25px}
        .sidebar-toggle{display:none;width:38px;height:38px;place-items:center;border:1px solid var(--app-border);border-radius:10px;background:#fff;color:var(--app-navy);font-size:16px;cursor:pointer;transition:.2s}
        .sidebar-toggle:hover{background:#f1f5f9;border-color:#cbd5e1}
        .header-right{gap:15px}.header-date{color:#7b8794;font-size:12px;white-space:nowrap}
        .header-user{gap:9px;padding:6px 11px 6px 6px;border:1px solid var(--app-border);border-radius:30px;background:#fff}
        .user-avatar-circle{width:31px;height:31px;display:grid;place-items:center;border-radius:50%;background:#f3e8cc;color:#73531c;font-size:12px;font-weight:800}
        .user-name{max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;font-size:12px;font-weight:700}
        .btn-logout{display:inline-flex;align-items:center;gap:8px;padding:10px 13px;border-radius:9px;background:#f1f5f9;color:#475569;text-decoration:none;font-size:12px;font-weight:700;transition:.2s}
        .btn-logout:hover{background:#fee2e2;color:#b91c1c}
        .layout{display:flex;flex:1;min-height:calc(100vh - var(--app-topbar-height));margin-top:var(--app-topbar-height)}
        .content{flex:1;min-width:0;margin-left:var(--app-sidebar-width);padding:30px 32px 36px;background:var(--app-bg)}
        .mobile-sidebar-backdrop{display:none}
        @media(max-width:900px){.top-header{padding:0 18px}.header-date{display:none}.content{padding:24px 20px}}
        @media(max-width:768px){.sidebar-toggle{display:grid}.header-title-wrapper{display:none}.top-header{padding:0 13px;gap:10px}.header-left-group{gap:10px}.header-right{gap:8px}.user-name{display:none}.header-user{padding:5px}.btn-logout{width:36px;height:36px;justify-content:center;padding:0;font-size:0}.btn-logout i{font-size:14px}.content{margin-left:0;padding:22px 15px 28px}.layout{min-height:calc(100vh - var(--app-topbar-height))}.mobile-sidebar-backdrop.is-visible{display:block;position:fixed;inset:var(--app-topbar-height) 0 0;background:rgba(15,23,42,.42);z-index:990}}
    </style>
</head>
<body>
<header class="top-header">
    <div class="header-left-group">
        <button class="sidebar-toggle" type="button" id="sidebarToggle" aria-label="Open navigation" aria-controls="appSidebar" aria-expanded="false"><i class="fas fa-bars"></i></button>
        <div class="header-brand">
            <div class="logo-container" aria-hidden="true"><i class="fas fa-book-open"></i></div>
            <div class="header-title-wrapper">
                <h1 class="header-title">Dibonke Bookshop</h1>
                <div class="subtitle">BOOKS · STATIONERY · GENERAL RETAIL</div>
            </div>
        </div>
    </div>
    <?php if (!empty($_SESSION['user_id'])): ?>
    <div class="header-right">
        <div class="header-date"><i class="far fa-calendar-alt"></i> <?= e(date('D, d M Y')) ?></div>
        <div class="header-user">
            <div class="user-avatar-circle"><?= e($userInitial) ?></div>
            <span class="user-name"><?= e($userName) ?></span>
        </div>
        <a class="btn-logout" href="<?= BASE_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt"></i><span>Sign out</span></a>
    </div>
    <?php endif; ?>
</header>
<div class="mobile-sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="layout">
