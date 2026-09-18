<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Support & Tickets";
$success = '';
$error = '';

// Handle New Ticket Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ticket'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'General Inquiry');
        $priority = trim($_POST['priority'] ?? 'normal');
        $message = trim($_POST['message'] ?? '');

        if (!empty($subject) && !empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, category, priority, status) VALUES (?, ?, ?, ?, 'open')");
            $stmt->execute([$user['id'], $subject, $category, $priority]);
            $ticket_id = $pdo->lastInsertId();

            $msg_stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)");
            $msg_stmt->execute([$ticket_id, $user['id'], $message]);

            $success = 'Support ticket #' . $ticket_id . ' created successfully. An agent will respond shortly.';
        } else {
            $error = 'Please provide both a subject and message.';
        }
    }
}

// Handle User Reply to Existing Ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_reply'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $reply_msg = trim($_POST['reply_message'] ?? '');

        if ($ticket_id > 0 && !empty($reply_msg)) {
            // Verify ownership
            $chk = $pdo->prepare("SELECT id, status FROM tickets WHERE id = ? AND user_id = ?");
            $chk->execute([$ticket_id, $user['id']]);
            $t_row = $chk->fetch();

            if ($t_row) {
                $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)");
                $stmt->execute([$ticket_id, $user['id'], $reply_msg]);

                // Re-open if resolved
                if ($t_row['status'] === 'resolved') {
                    $pdo->prepare("UPDATE tickets SET status = 'open' WHERE id = ?")->execute([$ticket_id]);
                }

                $success = "Your reply was posted to Ticket #{$ticket_id}.";
            } else {
                $error = 'Invalid ticket or unauthorized access.';
            }
        } else {
            $error = 'Please enter a message before replying.';
        }
    }
}

// Fetch user's tickets
$stmt = $pdo->prepare("SELECT t.*, 
        (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as message_count,
        (SELECT MAX(created_at) FROM ticket_messages WHERE ticket_id = t.id) as last_activity
        FROM tickets t 
        WHERE t.user_id = ? 
        ORDER BY CASE t.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END, t.id DESC");
$stmt->execute([$user['id']]);
$tickets = $stmt->fetchAll();

// If viewing a specific ticket conversation
$active_ticket_id = intval($_GET['view'] ?? 0);
$active_ticket = null;
$active_messages = [];

if ($active_ticket_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND user_id = ?");
    $stmt->execute([$active_ticket_id, $user['id']]);
    $active_ticket = $stmt->fetch();

    if ($active_ticket) {
        $msg_stmt = $pdo->prepare("SELECT tm.*, u.name as sender_name, u.role as sender_role FROM ticket_messages tm JOIN users u ON tm.user_id = u.id WHERE tm.ticket_id = ? ORDER BY tm.id ASC");
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
            <h1 class="page-title"><i class="fa-solid fa-headset" style="color: var(--color-primary);"></i> Artist Support & Help Desk</h1>
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

        <div class="grid-2col">
            <!-- New Ticket -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-plus-circle" style="color: var(--color-primary);"></i> Open New Support Ticket</div>
                </div>

                <form method="POST" action="support">
                    <?= csrfInput() ?>
                    <input type="hidden" name="new_ticket" value="1">

                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="e.g. Need assistance with Spotify Artist profile mapping" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control">
                            <option value="Distribution / DSP Ingestion">Distribution / DSP Ingestion</option>
                            <option value="ISRC / Metadata Update">ISRC / Metadata Update</option>
                            <option value="Royalties & Payout">Royalties & Payout</option>
                            <option value="Copyright & DMCA Dispute">Copyright & DMCA Dispute</option>
                            <option value="General Inquiry">General Inquiry</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="normal" selected>Normal Priority</option>
                            <option value="high">High Priority</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Detailed Message</label>
                        <textarea name="message" class="form-control" placeholder="Explain your request in detail..." style="min-height: 110px;" required></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fa-solid fa-paper-plane"></i> Submit Ticket
                    </button>
                </form>
            </div>

            <!-- Existing Tickets -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-ticket" style="color: var(--color-secondary);"></i> My Tickets (<?= count($tickets) ?>)</div>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject & Category</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-dim); padding: 40px 20px;">No support tickets opened yet. If you need any assistance, fill the form on the left.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $t): ?>
                                    <tr style="<?= $t['status'] === 'open' ? 'background: rgba(87, 255, 82, 0.03);' : '' ?>">
                                        <td style="font-family: monospace; font-weight: 700; color: var(--color-primary);">
                                            #<?= $t['id'] ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: #fff; font-size: 13.5px; margin-bottom: 2px;">
                                                <?= htmlspecialchars($t['subject']) ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                <?= htmlspecialchars($t['category']) ?> &bull; <?= $t['message_count'] ?> messages
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($t['status'] === 'open'): ?>
                                                <span class="badge badge-pending">Open</span>
                                            <?php elseif ($t['status'] === 'in_progress'): ?>
                                                <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4);">In Progress</span>
                                            <?php else: ?>
                                                <span class="badge badge-live">Resolved</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="support?view=<?= $t['id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 11.5px; padding: 5px 12px;">
                                                <i class="fa-solid fa-comments"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- User Ticket Conversation Modal -->
        <?php if ($active_ticket): ?>
            <div class="custom-modal-backdrop" id="userTicketModal" style="display: flex; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; justify-content: center; align-items: center; padding: 16px;">
                <div class="custom-modal-card" style="max-width: 720px; width: 100%; max-height: 88vh; display: flex; flex-direction: column; padding: 0; overflow: hidden; background: #0e131d; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.8);">
                    
                    <!-- Header -->
                    <div style="padding: 18px 22px; background: #0c1018; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <span style="font-family: monospace; font-size: 12px; font-weight: 800; color: var(--color-primary); background: rgba(87, 255, 82, 0.1); padding: 2px 7px; border-radius: 4px;">#<?= $active_ticket['id'] ?></span>
                                <h2 style="font-size: 16px; font-weight: 700; color: #fff; margin: 0;"><?= htmlspecialchars($active_ticket['subject']) ?></h2>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap;">
                                <span><i class="fa-solid fa-tag"></i> <?= htmlspecialchars($active_ticket['category']) ?></span>
                                <span><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($active_ticket['created_at']) ?></span>
                                <span>Status: <strong style="color: <?= $active_ticket['status'] === 'resolved' ? '#57ff52' : '#fbbf24' ?>; text-transform: uppercase;"><?= htmlspecialchars($active_ticket['status']) ?></strong></span>
                            </div>
                        </div>
                        <a href="support" style="color: var(--text-muted); font-size: 22px; text-decoration: none; padding: 2px; line-height: 1;" title="Close Modal">&times;</a>
                    </div>

                    <!-- Conversation Thread -->
                    <div style="flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; max-height: 420px; background: #080b11;">
                        <?php foreach ($active_messages as $idx => $msg): ?>
                            <?php 
                            // A message is a staff reply if marked is_staff=1 OR created by an admin who is not the ticket owner
                            $isStaff = (!empty($msg['is_staff']) || ($idx > 0 && $msg['sender_role'] === 'admin' && intval($msg['user_id']) !== intval($active_ticket['user_id'])));
                            ?>
                            <div style="display: flex; flex-direction: column; align-items: <?= $isStaff ? 'flex-start' : 'flex-end' ?>;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 11px; color: var(--text-muted);">
                                    <?php if ($isStaff): ?>
                                        <span style="background: rgba(87, 255, 82, 0.15); color: #57ff52; padding: 2px 8px; border-radius: 999px; font-weight: 700; border: 1px solid rgba(87, 255, 82, 0.3);">
                                            <i class="fa-solid fa-shield-halved"></i> Nexus Support Staff
                                        </span>
                                    <?php else: ?>
                                        <span style="font-weight: 700; color: #fff;">
                                            <i class="fa-solid fa-user"></i> <?= htmlspecialchars($msg['sender_name'] ?: $user['name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($msg['created_at']) ?></span>
                                </div>
                                <div style="max-width: 85%; padding: 13px 16px; border-radius: 12px; font-size: 13.5px; line-height: 1.6; word-break: break-word; <?= $isStaff ? 'background: #0f1624; border: 1px solid rgba(87, 255, 82, 0.35); color: #ffffff;' : 'background: #141a26; border: 1px solid rgba(255, 255, 255, 0.08); color: #e5e7eb;' ?>">
                                    <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- User Reply Form -->
                    <div style="padding: 16px 20px; background: #0c1018; border-top: 1px solid rgba(255, 255, 255, 0.08);">
                        <form method="POST" action="support?view=<?= $active_ticket['id'] ?>">
                            <?= csrfInput() ?>
                            <input type="hidden" name="ticket_id" value="<?= $active_ticket['id'] ?>">
                            <input type="hidden" name="user_reply" value="1">

                            <div style="margin-bottom: 10px;">
                                <textarea name="reply_message" class="form-control" placeholder="Write a reply or follow-up question..." style="min-height: 70px; resize: vertical;" required></textarea>
                            </div>

                            <div style="display: flex; justify-content: flex-end;">
                                <button type="submit" class="btn btn-primary" style="padding: 8px 24px;">
                                    <i class="fa-solid fa-paper-plane"></i> Send Reply
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

