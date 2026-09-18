<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$user = currentUser();
$page_title = "Support Tickets Management";
$success = '';
$error = '';

// Handle Status / Priority / Reply Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $ticket_id = intval($_POST['ticket_id'] ?? 0);

        if ($ticket_id > 0) {
            // Reply to ticket
            if ($action === 'reply_ticket') {
                $reply_msg = trim($_POST['reply_message'] ?? '');
                $new_status = trim($_POST['status'] ?? 'in_progress');

                if (!empty($reply_msg)) {
                    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
                    $stmt->execute([$ticket_id, $user['id'], $reply_msg]);

                    // Update ticket status
                    $stmt = $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $ticket_id]);

                    // Optionally send email notification to ticket owner
                    try {
                        $t_stmt = $pdo->prepare("SELECT t.*, u.name as user_name, u.email as user_email FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
                        $t_stmt->execute([$ticket_id]);
                        $ticket_info = $t_stmt->fetch();

                        if ($ticket_info && !empty($ticket_info['user_email'])) {
                            require_once __DIR__ . '/includes/mailer.php';
                            $email_subject = "Support Ticket Update: #" . $ticket_info['id'] . " - " . $ticket_info['subject'];
                            $email_preheader = "Our support team has updated your ticket #" . $ticket_info['id'];
                            $email_body = "<h2 style='margin: 0 0 16px 0; color: #ffffff !important; font-size: 20px; font-weight: 700;'>Support Ticket Update</h2><p style='margin: 0 0 16px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.6;'>Hello <strong style='color: #ffffff;'>" . htmlspecialchars($ticket_info['user_name']) . "</strong>,</p><p style='margin: 0 0 16px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.6;'>Our support team has responded to your ticket <strong style='color: #57ff52;'>#" . $ticket_info['id'] . " (" . htmlspecialchars($ticket_info['subject']) . ")</strong>:</p><div style='background-color: #05070d; border-left: 3px solid #57ff52; border-radius: 8px; padding: 16px 20px; margin: 20px 0; color: #ffffff; font-size: 14px; line-height: 1.6;'>" . nl2br(htmlspecialchars($reply_msg)) . "</div><p style='margin: 0 0 20px 0; color: #94a3b8 !important; font-size: 13.5px;'>Status: <strong style='color: #ffffff; text-transform: uppercase;'>" . htmlspecialchars($new_status) . "</strong></p><table role='presentation' border='0' cellpadding='0' cellspacing='0' width='100%' style='margin: 24px 0;'><tr><td align='center'><table role='presentation' border='0' cellpadding='0' cellspacing='0'><tr><td align='center' bgcolor='#57ff52' style='border-radius: 9999px; background-color: #57ff52;'><a href='https://portal.nexustune.com/support' target='_blank' style='display: inline-block; padding: 12px 30px; font-family: sans-serif; font-size: 14px; font-weight: 700; color: #000000 !important; text-decoration: none; border-radius: 9999px;'>View Ticket in Portal &rarr;</a></td></tr></table></td></tr></table>";
                            $html_out = renderNexusEmail($email_subject, $email_preheader, $email_body);
                            $mailer = new NexusMailer();
                            $mailer->send($ticket_info['user_email'], $ticket_info['user_name'], $email_subject, $html_out, $reply_msg);
                        }
                    } catch (Exception $e) {}

                    $success = "Reply added to Ticket #{$ticket_id} and status updated to {$new_status}.";
                } else {
                    $error = 'Please enter a reply message before sending.';
                }
            } elseif ($action === 'update_status') {
                $status = trim($_POST['status'] ?? 'open');
                $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$status, $ticket_id]);
                $success = "Ticket #{$ticket_id} status updated to {$status}.";
            } elseif ($action === 'update_priority') {
                $priority = trim($_POST['priority'] ?? 'normal');
                $pdo->prepare("UPDATE tickets SET priority = ? WHERE id = ?")->execute([$priority, $ticket_id]);
                $success = "Ticket #{$ticket_id} priority set to {$priority}.";
            } elseif ($action === 'delete_ticket') {
                $pdo->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticket_id]);
                $pdo->prepare("DELETE FROM ticket_messages WHERE ticket_id = ?")->execute([$ticket_id]);
                $success = "Ticket #{$ticket_id} and all related messages permanently deleted.";
            }
        }
    }
}

// Filter and Search
$filter_status = $_GET['status'] ?? 'all';
$search_query = trim($_GET['q'] ?? '');

$sql = "SELECT t.*, u.name as user_name, u.email as user_email, u.avatar as user_avatar, u.account_type,
        (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as message_count,
        (SELECT MAX(created_at) FROM ticket_messages WHERE ticket_id = t.id) as last_activity
        FROM tickets t 
        JOIN users u ON t.user_id = u.id 
        WHERE 1=1";
$params = [];

if ($filter_status !== 'all') {
    $sql .= " AND t.status = ?";
    $params[] = $filter_status;
}

if (!empty($search_query)) {
    $sql .= " AND (t.id = ? OR t.subject LIKE ? OR t.category LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $params[] = intval($search_query);
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$sql .= " ORDER BY CASE t.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END, t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Counts for KPI tabs
$count_all = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$count_open = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
$count_inprogress = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'in_progress'")->fetchColumn();
$count_resolved = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'resolved'")->fetchColumn();

// If active ticket view requested
$active_ticket_id = intval($_GET['view'] ?? 0);
$active_ticket = null;
$active_messages = [];

if ($active_ticket_id > 0) {
    $stmt = $pdo->prepare("SELECT t.*, u.name as user_name, u.email as user_email, u.avatar as user_avatar, u.account_type, u.country, u.spotify_id FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
    $stmt->execute([$active_ticket_id]);
    $active_ticket = $stmt->fetch();

    if ($active_ticket) {
        $msg_stmt = $pdo->prepare("SELECT tm.*, u.name as sender_name, u.role as sender_role, u.avatar as sender_avatar FROM ticket_messages tm JOIN users u ON tm.user_id = u.id WHERE tm.ticket_id = ? ORDER BY tm.id ASC");
        $msg_stmt->execute([$active_ticket_id]);
        $active_messages = $msg_stmt->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title"><i class="fa-solid fa-headset" style="color: var(--color-primary);"></i> Artist Support Tickets</h1>
        </div>
        <div class="header-right">
            <a href="admin" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>
    </header>

    <main class="page-body">
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- KPI Metrics -->
        <div class="grid-metrics" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
            <div class="metric-card">
                <div class="metric-icon-box icon-cyan"><i class="fa-solid fa-ticket"></i></div>
                <div class="metric-label">Total Tickets</div>
                <div class="metric-value"><?= $count_all ?></div>
                <div class="metric-sub"><a href="admin-tickets?status=all" style="color: var(--color-secondary);">All Inquiries</a></div>
            </div>

            <div class="metric-card" style="border-color: <?= $count_open > 0 ? 'rgba(239, 68, 68, 0.4)' : 'rgba(255,255,255,0.08)' ?>;">
                <div class="metric-icon-box icon-amber" style="background: rgba(239, 68, 68, 0.15); color: #f87171;"><i class="fa-solid fa-envelope-open"></i></div>
                <div class="metric-label">Open / Unanswered</div>
                <div class="metric-value" style="color: <?= $count_open > 0 ? '#f87171' : '#fff' ?>;"><?= $count_open ?></div>
                <div class="metric-sub"><a href="admin-tickets?status=open" style="color: #f87171;">Action Required &rarr;</a></div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-amber"><i class="fa-solid fa-spinner"></i></div>
                <div class="metric-label">In Progress</div>
                <div class="metric-value" style="color: #fbbf24;"><?= $count_inprogress ?></div>
                <div class="metric-sub"><a href="admin-tickets?status=in_progress" style="color: #fbbf24;">Under Review</a></div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-green"><i class="fa-solid fa-circle-check"></i></div>
                <div class="metric-label">Resolved</div>
                <div class="metric-value" style="color: #57ff52;"><?= $count_resolved ?></div>
                <div class="metric-sub"><a href="admin-tickets?status=resolved" style="color: #57ff52;">Completed</a></div>
            </div>
        </div>

        <!-- Filter and Search Header -->
        <div class="glass-card" style="margin-bottom: 24px; padding: 18px 24px;">
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;">
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="admin-tickets?status=all" class="btn btn-sm <?= $filter_status === 'all' ? 'btn-primary' : 'btn-secondary' ?>">
                        All (<?= $count_all ?>)
                    </a>
                    <a href="admin-tickets?status=open" class="btn btn-sm <?= $filter_status === 'open' ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $filter_status === 'open' ? 'background: #ef4444; border-color: #ef4444;' : '' ?>">
                        Open (<?= $count_open ?>)
                    </a>
                    <a href="admin-tickets?status=in_progress" class="btn btn-sm <?= $filter_status === 'in_progress' ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $filter_status === 'in_progress' ? 'background: #f59e0b; border-color: #f59e0b;' : '' ?>">
                        In Progress (<?= $count_inprogress ?>)
                    </a>
                    <a href="admin-tickets?status=resolved" class="btn btn-sm <?= $filter_status === 'resolved' ? 'btn-primary' : 'btn-secondary' ?>">
                        Resolved (<?= $count_resolved ?>)
                    </a>
                </div>

                <form method="GET" action="admin-tickets" style="display: flex; gap: 8px; max-width: 320px; width: 100%;">
                    <?php if ($filter_status !== 'all'): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    <?php endif; ?>
                    <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" class="form-control" placeholder="Search ticket, artist, email..." style="font-size: 13px; height: 38px;">
                    <button type="submit" class="btn btn-secondary btn-sm" style="padding: 0 16px;"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>
            </div>
        </div>

        <!-- Ticket List Table -->
        <div class="glass-card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Artist / Sender</th>
                            <th>Subject & Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Last Activity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-dim); padding: 50px 20px;">
                                    <i class="fa-solid fa-headset" style="font-size: 38px; color: var(--text-muted); margin-bottom: 14px; display: block;"></i>
                                    No support tickets found matching current filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $t): ?>
                                <tr style="<?= $t['status'] === 'open' ? 'background: rgba(239, 68, 68, 0.04);' : '' ?>">
                                    <td style="font-weight: 800; font-family: monospace; color: var(--color-primary); font-size: 13.5px;">
                                        #<?= $t['id'] ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px;">
                                            <span><?= htmlspecialchars($t['user_name']) ?></span>
                                            <span style="font-size: 10px; background: rgba(255,255,255,0.08); padding: 2px 6px; border-radius: 4px; color: var(--text-muted);"><?= htmlspecialchars($t['account_type']) ?></span>
                                        </div>
                                        <a href="mailto:<?= htmlspecialchars($t['user_email']) ?>" style="font-size: 12px; color: var(--text-muted); text-decoration: none;">
                                            <?= htmlspecialchars($t['user_email']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #e5e7eb; font-size: 14px; margin-bottom: 2px;">
                                            <?= htmlspecialchars($t['subject']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--color-secondary);">
                                            <i class="fa-solid fa-tag"></i> <?= htmlspecialchars($t['category']) ?> &bull; <?= $t['message_count'] ?> messages
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($t['priority'] === 'urgent'): ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.4);">Urgent</span>
                                        <?php elseif ($t['priority'] === 'high'): ?>
                                            <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4);">High</span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(255,255,255,0.06); color: #9ca3af;">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['status'] === 'open'): ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.4); font-weight: 700;">Open</span>
                                        <?php elseif ($t['status'] === 'in_progress'): ?>
                                            <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4);">In Progress</span>
                                        <?php else: ?>
                                            <span class="badge badge-live">Resolved</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 12px; color: var(--text-dim); white-space: nowrap;">
                                        <?= htmlspecialchars($t['last_activity'] ?? $t['created_at']) ?>
                                    </td>
                                    <td>
                                        <a href="admin-tickets?view=<?= $t['id'] ?><?= $filter_status !== 'all' ? '&status=' . urlencode($filter_status) : '' ?>" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 6px 14px;">
                                            <i class="fa-solid fa-comments"></i> Open Thread
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ticket Conversation Modal View -->
        <?php if ($active_ticket): ?>
            <div class="custom-modal-backdrop" id="ticketModalBackdrop" style="display: flex; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; justify-content: center; align-items: center; padding: 16px;">
                <div class="custom-modal-card" style="max-width: 800px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; padding: 0; overflow: hidden; background: #0e131d; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.8);">
                    
                    <!-- Modal Header -->
                    <div style="padding: 20px 24px; background: #0c1018; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                                <span style="font-family: monospace; font-size: 13px; font-weight: 800; color: var(--color-primary); background: rgba(87, 255, 82, 0.1); padding: 3px 8px; border-radius: 4px;">#<?= $active_ticket['id'] ?></span>
                                <h2 style="font-size: 18px; font-weight: 700; color: #fff; margin: 0;"><?= htmlspecialchars($active_ticket['subject']) ?></h2>
                            </div>
                            <div style="font-size: 12.5px; color: var(--text-muted); display: flex; gap: 14px; flex-wrap: wrap;">
                                <span><i class="fa-solid fa-user" style="color: var(--color-primary);"></i> <?= htmlspecialchars($active_ticket['user_name']) ?> (<?= htmlspecialchars($active_ticket['user_email']) ?>)</span>
                                <span><i class="fa-solid fa-tag"></i> <?= htmlspecialchars($active_ticket['category']) ?></span>
                                <span><i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($active_ticket['created_at']) ?></span>
                            </div>
                        </div>
                        <a href="admin-tickets<?= $filter_status !== 'all' ? '?status=' . urlencode($filter_status) : '' ?>" style="color: var(--text-muted); font-size: 24px; text-decoration: none; padding: 4px; line-height: 1;" title="Close Modal">&times;</a>
                    </div>

                    <!-- Status Bar & Quick Actions -->
                    <div style="padding: 12px 24px; background: rgba(255, 255, 255, 0.02); border-bottom: 1px solid rgba(255, 255, 255, 0.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <form method="POST" action="admin-tickets?view=<?= $active_ticket['id'] ?>" style="display: inline-flex; align-items: center; gap: 8px;">
                                <?= csrfInput() ?>
                                <input type="hidden" name="ticket_id" value="<?= $active_ticket['id'] ?>">
                                <input type="hidden" name="action" value="update_status">
                                <label style="font-size: 12px; color: var(--text-muted);">Status:</label>
                                <select name="status" onchange="this.form.submit()" class="form-control" style="font-size: 12px; height: 32px; padding: 4px 10px; width: auto; background: #07090e;">
                                    <option value="open" <?= $active_ticket['status'] === 'open' ? 'selected' : '' ?>>🔴 Open</option>
                                    <option value="in_progress" <?= $active_ticket['status'] === 'in_progress' ? 'selected' : '' ?>>🟡 In Progress</option>
                                    <option value="resolved" <?= $active_ticket['status'] === 'resolved' ? 'selected' : '' ?>>🟢 Resolved</option>
                                </select>
                            </form>

                            <form method="POST" action="admin-tickets?view=<?= $active_ticket['id'] ?>" style="display: inline-flex; align-items: center; gap: 8px;">
                                <?= csrfInput() ?>
                                <input type="hidden" name="ticket_id" value="<?= $active_ticket['id'] ?>">
                                <input type="hidden" name="action" value="update_priority">
                                <label style="font-size: 12px; color: var(--text-muted);">Priority:</label>
                                <select name="priority" onchange="this.form.submit()" class="form-control" style="font-size: 12px; height: 32px; padding: 4px 10px; width: auto; background: #07090e;">
                                    <option value="normal" <?= $active_ticket['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                                    <option value="high" <?= $active_ticket['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="urgent" <?= $active_ticket['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                                </select>
                            </form>
                        </div>

                        <form method="POST" action="admin-tickets" onsubmit="return confirm('Are you sure you want to permanently delete this ticket and all its messages?');">
                            <?= csrfInput() ?>
                            <input type="hidden" name="ticket_id" value="<?= $active_ticket['id'] ?>">
                            <input type="hidden" name="action" value="delete_ticket">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color: #ef4444; font-size: 11px; padding: 4px 10px;">
                                <i class="fa-solid fa-trash"></i> Delete Ticket
                            </button>
                        </form>
                    </div>

                    <!-- Scrollable Messages Container -->
                    <div style="flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 18px; max-height: 400px; background: #080b11;">
                        <?php foreach ($active_messages as $msg): ?>
                            <?php $isAdmin = ($msg['sender_role'] === 'admin'); ?>
                            <div style="display: flex; flex-direction: column; align-items: <?= $isAdmin ? 'flex-end' : 'flex-start' ?>;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 11.5px; color: var(--text-muted);">
                                    <?php if ($isAdmin): ?>
                                        <span style="background: rgba(87, 255, 82, 0.15); color: #57ff52; padding: 2px 8px; border-radius: 999px; font-weight: 700; border: 1px solid rgba(87, 255, 82, 0.3);">
                                            <i class="fa-solid fa-shield-halved"></i> Nexus Support Staff (<?= htmlspecialchars($msg['sender_name']) ?>)
                                        </span>
                                    <?php else: ?>
                                        <span style="font-weight: 700; color: #fff;">
                                            <i class="fa-solid fa-user"></i> <?= htmlspecialchars($msg['sender_name']) ?> (Artist)
                                        </span>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($msg['created_at']) ?></span>
                                </div>
                                <div style="max-width: 85%; padding: 14px 18px; border-radius: 14px; font-size: 14px; line-height: 1.6; word-break: break-word; <?= $isAdmin ? 'background: #0f1624; border: 1px solid rgba(87, 255, 82, 0.3); color: #ffffff;' : 'background: #141a26; border: 1px solid rgba(255, 255, 255, 0.08); color: #e5e7eb;' ?>">
                                    <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Admin Reply Box -->
                    <div style="padding: 20px 24px; background: #0c1018; border-top: 1px solid rgba(255, 255, 255, 0.08);">
                        <!-- Canned Response Chips -->
                        <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px;">
                            <span style="font-size: 11px; color: var(--text-muted); align-self: center;">Quick Snippets:</span>
                            <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 2px 8px;" onclick="setSnippet('Hello! Your release metadata has been verified and delivered to all 250+ DSP stores.')">DSP Delivery</button>
                            <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 2px 8px;" onclick="setSnippet('Your Spotify Artist profile mapping has been updated successfully. Please allow 24-48 hours for global propagation.')">Spotify OAC</button>
                            <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 2px 8px;" onclick="setSnippet('Your payout request has been verified and forwarded to accounts for processing.')">Payout Update</button>
                            <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 2px 8px;" onclick="setSnippet('Thank you for contacting Nexus Tune Support. We have resolved your inquiry. Please let us know if you have further questions!')">Resolved</button>
                        </div>

                        <form method="POST" action="admin-tickets?view=<?= $active_ticket['id'] ?>">
                            <?= csrfInput() ?>
                            <input type="hidden" name="ticket_id" value="<?= $active_ticket['id'] ?>">
                            <input type="hidden" name="action" value="reply_ticket">

                            <div style="margin-bottom: 12px;">
                                <textarea name="reply_message" id="adminReplyText" class="form-control" placeholder="Write official staff response..." style="min-height: 80px; resize: vertical;" required></textarea>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label style="font-size: 12.5px; color: var(--text-muted); margin: 0;">Update Status to:</label>
                                    <select name="status" class="form-control" style="font-size: 12px; height: 34px; width: auto; padding: 4px 10px; background: #07090e;">
                                        <option value="in_progress" selected>In Progress</option>
                                        <option value="resolved">Resolved</option>
                                        <option value="open">Keep Open</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary" style="padding: 8px 24px;">
                                    <i class="fa-solid fa-paper-plane"></i> Send Official Reply
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

            <script>
            function setSnippet(text) {
                const el = document.getElementById('adminReplyText');
                if (el) {
                    el.value = text;
                    el.focus();
                }
            }
            </script>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
