<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Support & Tickets";
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ticket'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $message = trim($_POST['message'] ?? '');

        if (!empty($subject) && !empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, category, status) VALUES (?, ?, ?, 'open')");
            $stmt->execute([$user['id'], $subject, $category]);
            $ticket_id = $pdo->lastInsertId();

            $msg_stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
            $msg_stmt->execute([$ticket_id, $user['id'], $message]);

            $success = 'Support ticket #' . $ticket_id . ' created successfully. An agent will respond shortly.';
        } else {
            $error = 'Please provide both a subject and message.';
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$user['id']]);
$tickets = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Artist Support & Help Desk</h1>
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
                        <label class="form-label">Detailed Message</label>
                        <textarea name="message" class="form-control" placeholder="Explain your request in detail..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fa-solid fa-paper-plane"></i> Submit Ticket
                    </button>
                </form>
            </div>

            <!-- Existing Tickets -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-ticket" style="color: var(--color-secondary);"></i> My Tickets</div>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-dim); padding: 30px;">No support tickets opened.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $t): ?>
                                    <tr>
                                        <td>#<?= $t['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($t['subject']) ?></strong></td>
                                        <td><span style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($t['category']) ?></span></td>
                                        <td>
                                            <?php if ($t['status'] === 'open'): ?>
                                                <span class="badge badge-pending">Open</span>
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
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
