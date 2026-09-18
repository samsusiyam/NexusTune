<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$user = currentUser();
$page_title = "Payout Requests";
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $payout_id = intval($_POST['payout_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $ref = trim($_POST['transaction_ref'] ?? 'TXN_' . strtoupper(bin2hex(random_bytes(4))));

        if ($payout_id > 0) {
            if ($action === 'approve') {
                $upd = $pdo->prepare("UPDATE payouts SET status = 'approved', transaction_ref = ? WHERE id = ?");
                $upd->execute([$ref, $payout_id]);
                $success = 'Payout marked as approved and processed!';
            } elseif ($action === 'reject') {
                $upd = $pdo->prepare("UPDATE payouts SET status = 'rejected' WHERE id = ?");
                $upd->execute([$payout_id]);
                $success = 'Payout rejected.';
            }
        }
    }
}

$stmt = $pdo->query("SELECT p.*, u.name as user_name, u.email as user_email FROM payouts p JOIN users u ON p.user_id = u.id ORDER BY p.id DESC");
$payouts = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Artist Payout Withdrawals (<?= count($payouts) ?>)</h1>
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
                            <th>Requested At</th>
                            <th>Artist</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Account Details</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payouts)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--text-dim); padding: 40px;">No payout requests found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payouts as $p): ?>
                                <tr>
                                    <td style="font-size: 12px; color: var(--text-dim);"><?= htmlspecialchars($p['created_at']) ?></td>
                                    <td>
                                        <div style="font-weight: 700; color: #fff;"><?= htmlspecialchars($p['user_name']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($p['user_email']) ?></div>
                                    </td>
                                    <td style="font-weight: 700; color: var(--color-primary); font-size: 15px;">$<?= number_format($p['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($p['method']) ?></td>
                                    <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($p['account_details']) ?></td>
                                    <td>
                                        <?php if ($p['status'] === 'approved'): ?>
                                            <span class="badge badge-live">Paid (<?= htmlspecialchars($p['transaction_ref']) ?>)</span>
                                        <?php elseif ($p['status'] === 'pending'): ?>
                                            <span class="badge badge-pending">Pending</span>
                                        <?php else: ?>
                                            <span class="badge badge-rejected">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <form method="POST" action="admin-payouts" style="display: inline-block;">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Mark Paid</button>
                                            </form>
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
