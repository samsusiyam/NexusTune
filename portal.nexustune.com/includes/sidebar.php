<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
        <a href="dashboard" style="display: flex; align-items: center; gap: 10px; text-decoration: none;">
            <img src="assets/images/logo.png" alt="Nexus Tune" style="height: 36px; max-width: 170px; object-fit: contain;">
        </a>
        <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" title="Close Menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="sidebar-nav">
        <div class="nav-section-title">Artist Workspace</div>
        
        <a href="dashboard" class="nav-item <?= $current_page === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span>
            <span>Dashboard</span>
        </a>

        <a href="create-release" class="nav-item <?= $current_page === 'create-release' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
            <span>Upload Release</span>
        </a>

        <a href="releases" class="nav-item <?= in_array($current_page, ['releases', 'release-view']) ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-compact-disc"></i></span>
            <span>Music Catalog</span>
        </a>

        <a href="royalties" class="nav-item <?= $current_page === 'royalties' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-wallet"></i></span>
            <span>Royalties & Payouts</span>
        </a>

        <a href="tools" class="nav-item <?= $current_page === 'tools' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-barcode"></i></span>
            <span>ISRC & Smart Tools</span>
        </a>

        <a href="support" class="nav-item <?= $current_page === 'support' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-headset"></i></span>
            <span>Support & Tickets</span>
        </a>

        <a href="settings" class="nav-item <?= $current_page === 'settings' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="fa-solid fa-sliders"></i></span>
            <span>Account Settings</span>
        </a>

        <?php if (isAdmin()): ?>
            <?php
            if (!isset($open_tickets_count)) {
                try {
                    $open_tickets_count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
                } catch (Exception $e) { $open_tickets_count = 0; }
            }
            if (!isset($contacts_count)) {
                try {
                    $contacts_count = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn();
                } catch (Exception $e) { $contacts_count = 0; }
            }
            ?>
            <div class="nav-section-title" style="margin-top: 15px;">Admin Management</div>
            
            <a href="admin" class="nav-item <?= $current_page === 'admin' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-shield-halved"></i></span>
                <span>Admin KPI</span>
            </a>

            <a href="admin-releases" class="nav-item <?= $current_page === 'admin-releases' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-list-check"></i></span>
                <span>Moderation</span>
                <?php if (isset($pending_count) && $pending_count > 0): ?>
                    <span class="nav-badge danger"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>

            <a href="admin-tickets" class="nav-item <?= $current_page === 'admin-tickets' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-headset"></i></span>
                <span>Support Tickets</span>
                <?php if ($open_tickets_count > 0): ?>
                    <span class="nav-badge danger"><?= $open_tickets_count ?></span>
                <?php endif; ?>
            </a>

            <a href="admin-users" class="nav-item <?= $current_page === 'admin-users' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-users"></i></span>
                <span>Artists & Labels</span>
            </a>

            <a href="admin-contacts" class="nav-item <?= $current_page === 'admin-contacts' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-envelope-open-text"></i></span>
                <span>Inquiries</span>
                <?php if ($contacts_count > 0): ?>
                    <span class="nav-badge"><?= $contacts_count ?></span>
                <?php endif; ?>
            </a>

            <a href="admin-payouts" class="nav-item <?= $current_page === 'admin-payouts' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-money-bill-transfer"></i></span>
                <span>Payout Requests</span>
            </a>

            <a href="admin-smtp" class="nav-item <?= $current_page === 'admin-smtp' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fa-solid fa-envelope-circle-check"></i></span>
                <span>SMTP Mail Server</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-snippet">
            <div class="user-avatar">
                <i class="fa-solid fa-user"></i>
            </div>
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($user['name'] ?? 'Artist') ?></div>
                <div class="user-role-badge"><?= htmlspecialchars($user['role'] ?? 'Artist') ?></div>
            </div>
            <a href="logout" title="Logout" style="color: var(--text-dim); padding: 5px; font-size: 14px;">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
