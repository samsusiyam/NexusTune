<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$user = currentUser();
$page_title = "Admin KPI & Overview";

// Metrics
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_releases = $pdo->query("SELECT COUNT(*) FROM releases")->fetchColumn();
$pending_releases = $pdo->query("SELECT COUNT(*) FROM releases WHERE status = 'pending'")->fetchColumn();
$open_tickets = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
$total_tickets = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$total_contacts = $pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$unread_contacts = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn();
$pending_payouts = $pdo->query("SELECT COUNT(*) FROM payouts WHERE status = 'pending'")->fetchColumn();

// Recent pending releases
$stmt = $pdo->query("SELECT r.*, u.name as user_name FROM releases r JOIN users u ON r.user_id = u.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 5");
$pending_list = $stmt->fetchAll();

// Recent support tickets
$stmt = $pdo->query("SELECT t.*, u.name as user_name FROM tickets t JOIN users u ON t.user_id = u.id ORDER BY CASE t.status WHEN 'open' THEN 1 ELSE 2 END, t.id DESC LIMIT 5");
$recent_tickets = $stmt->fetchAll();

// Recent contacts
$stmt = $pdo->query("SELECT * FROM contacts ORDER BY id DESC LIMIT 5");
$recent_contacts = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title"><i class="fa-solid fa-shield-halved" style="color: var(--color-primary);"></i> Super Admin Dashboard</h1>
        </div>
        <div class="header-right">
            <a href="admin-releases" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-list-check"></i> Moderation Queue (<?= $pending_releases ?>)
            </a>
        </div>
    </header>

    <main class="page-body">
        <div class="grid-metrics" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div class="metric-card">
                <div class="metric-icon-box icon-cyan"><i class="fa-solid fa-users"></i></div>
                <div class="metric-label">Total Artists</div>
                <div class="metric-value"><?= $total_users ?></div>
                <div class="metric-sub"><a href="admin-users" style="color: var(--color-secondary);">Manage Artists &rarr;</a></div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-amber"><i class="fa-solid fa-clock"></i></div>
                <div class="metric-label">Pending Releases</div>
                <div class="metric-value" style="color: <?= $pending_releases > 0 ? '#fbbf24' : '#fff' ?>;"><?= $pending_releases ?></div>
                <div class="metric-sub"><a href="admin-releases" style="color: #fbbf24;">Review Queue &rarr;</a></div>
            </div>

            <div class="metric-card" style="border-color: <?= $open_tickets > 0 ? 'rgba(239, 68, 68, 0.4)' : 'rgba(255,255,255,0.08)' ?>;">
                <div class="metric-icon-box" style="background: rgba(239, 68, 68, 0.15); color: #f87171;"><i class="fa-solid fa-headset"></i></div>
                <div class="metric-label">Open Tickets</div>
                <div class="metric-value" style="color: <?= $open_tickets > 0 ? '#f87171' : '#fff' ?>;"><?= $open_tickets ?></div>
                <div class="metric-sub"><a href="admin-tickets?status=open" style="color: #f87171;">Reply to Artists &rarr;</a></div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-green"><i class="fa-solid fa-compact-disc"></i></div>
                <div class="metric-label">Catalog Titles</div>
                <div class="metric-value"><?= $total_releases ?></div>
                <div class="metric-sub">Distributed Globally</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-cyan"><i class="fa-solid fa-envelope-open-text"></i></div>
                <div class="metric-label">Website Inquiries</div>
                <div class="metric-value"><?= $total_contacts ?></div>
                <div class="metric-sub"><a href="admin-contacts" style="color: var(--color-secondary);"><?= $unread_contacts ?> New Messages &rarr;</a></div>
            </div>
        </div>

        <div class="grid-2col">
            <!-- Pending Moderation -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-compact-disc" style="color: #fbbf24;"></i> Releases Awaiting Approval</div>
                    <a href="admin-releases" class="btn btn-secondary btn-sm">View All</a>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Release</th>
                                <th>Artist</th>
                                <th>Genre</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_list)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-dim); padding: 30px;">Moderation queue is empty. All releases processed!</td></tr>
                            <?php else: ?>
                                <?php foreach ($pending_list as $rel): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($rel['title']) ?></strong></td>
                                        <td><?= htmlspecialchars($rel['user_name']) ?></td>
                                        <td><?= htmlspecialchars($rel['genre']) ?></td>
                                        <td>
                                            <a href="admin-releases" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Review</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Support Tickets -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-headset" style="color: var(--color-primary);"></i> Artist Support Tickets</div>
                    <a href="admin-tickets" class="btn btn-secondary btn-sm">All Tickets</a>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Artist</th>
                                <th>Subject</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_tickets)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-dim); padding: 30px;">No support tickets opened yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_tickets as $tk): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-weight: 700; color: var(--color-primary);">#<?= $tk['id'] ?></td>
                                        <td><?= htmlspecialchars($tk['user_name']) ?></td>
                                        <td><div style="font-size: 12.5px; max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; color: #fff;"><?= htmlspecialchars($tk['subject']) ?></div></td>
                                        <td>
                                            <?php if ($tk['status'] === 'open'): ?>
                                                <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #ef4444; font-weight: 700;">Open</span>
                                            <?php elseif ($tk['status'] === 'in_progress'): ?>
                                                <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">In Progress</span>
                                            <?php else: ?>
                                                <span class="badge badge-live">Resolved</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Website Inquiries (Full Width Card) -->
        <div class="glass-card" style="margin-top: 24px;">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-envelope-open-text" style="color: var(--color-secondary);"></i> Recent Website Inquiries</div>
                <a href="admin-contacts" class="btn btn-secondary btn-sm">All Inquiries</a>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Sender</th>
                            <th>Message</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_contacts)): ?>
                            <tr><td colspan="3" style="text-align: center; color: var(--text-dim); padding: 30px;">No messages received from website contact forms yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_contacts as $c): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($c['name']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
                                    </td>
                                    <td><div style="font-size: 13px; max-width: 500px; line-height: 1.5; color: #e5e7eb;"><?= htmlspecialchars($c['message']) ?></div></td>
                                    <td>
                                        <?php if ($c['status'] === 'new'): ?>
                                            <span class="badge badge-pending">New Message</span>
                                        <?php else: ?>
                                            <span class="badge badge-live">Reviewed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
