<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$user = currentUser();
$page_title = "Contact Form Inquiries";
$success = '';
$error = '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $contact_id = intval($_POST['contact_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        if ($contact_id > 0) {
            if ($action === 'mark_read') {
                $pdo->prepare("UPDATE contacts SET status = 'read' WHERE id = ?")->execute([$contact_id]);
                $success = 'Message marked as read.';
            } elseif ($action === 'delete') {
                $pdo->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contact_id]);
                $success = 'Message deleted.';
            }
        }
    }
}

$stmt = $pdo->query("SELECT * FROM contacts ORDER BY id DESC");
$contacts = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Website Inquiries (<?= count($contacts) ?>)</h1>
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

        <div class="glass-card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Sender</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contacts)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-dim); padding: 40px;">No messages received from website forms yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($contacts as $c): ?>
                                <tr>
                                    <td style="font-size: 12px; color: var(--text-dim); white-space: nowrap;">
                                        <?= htmlspecialchars($c['created_at']) ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #fff;"><?= htmlspecialchars($c['name']) ?></div>
                                        <a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="font-size: 12px; color: var(--color-primary);"><?= htmlspecialchars($c['email']) ?></a>
                                    </td>
                                    <td>
                                        <div style="max-width: 400px; color: #e5e7eb; font-size: 13.5px; line-height: 1.6; word-break: break-word;">
                                            <?= nl2br(htmlspecialchars($c['message'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($c['status'] === 'new'): ?>
                                            <span class="badge badge-pending">New Message</span>
                                        <?php else: ?>
                                            <span class="badge badge-live">Reviewed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px;">
                                            <?php if ($c['status'] === 'new'): ?>
                                                <form method="POST" action="admin-contacts" style="display: inline-block;">
                                                    <?= csrfInput() ?>
                                                    <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <button type="submit" class="btn btn-secondary btn-sm" title="Mark as Read"><i class="fa-solid fa-check"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="mailto:<?= htmlspecialchars($c['email']) ?>?subject=Re: Inquiring with Nexus Tune" class="btn btn-primary btn-sm" title="Reply via Email"><i class="fa-solid fa-reply"></i></a>
                                            <form method="POST" action="admin-contacts" style="display: inline-block;" onsubmit="return confirm('Delete message?');">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
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
