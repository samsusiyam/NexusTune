<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Royalties & Payouts";
$error = '';
$success = '';

// Calculate
$stmt = $pdo->prepare("SELECT COALESCE(SUM(earnings), 0.0), COALESCE(SUM(streams), 0) FROM royalties WHERE user_id = ?");
$stmt->execute([$user['id']]);
list($total_earnings, $total_streams) = $stmt->fetch(PDO::FETCH_NUM);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0.0) FROM payouts WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$user['id']]);
$paid_out = $stmt->fetchColumn();

$available_balance = max(0, $total_earnings - $paid_out);

// Process Payout Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payout'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $amount = round(floatval($_POST['amount'] ?? 0), 2);
        $method = trim($_POST['method'] ?? 'PayPal');
        $details = trim($_POST['details'] ?? '');

        if ($amount <= 0 || $amount > $available_balance) {
            $error = 'Invalid payout amount. Maximum withdrawable is $' . number_format($available_balance, 2);
        } elseif ($amount < 20) {
            $error = 'Minimum payout withdrawal threshold is $20.00 USD.';
        } elseif (empty($details)) {
            $error = 'Please provide your recipient payment details (e.g. PayPal email or Bank account).';
        } else {
            $ins = $pdo->prepare("INSERT INTO payouts (user_id, amount, method, account_details, status) VALUES (?, ?, ?, ?, 'pending')");
            $ins->execute([$user['id'], $amount, $method, $details]);
            $success = 'Payout request of $' . number_format($amount, 2) . ' submitted successfully. Finance team will process within 24-48 hours.';
            $available_balance -= $amount;
        }
    }
}

// Royalties Breakdown
$stmt = $pdo->prepare("SELECT store, SUM(streams) as total_streams, SUM(earnings) as total_earnings FROM royalties WHERE user_id = ? GROUP BY store ORDER BY total_earnings DESC");
$stmt->execute([$user['id']]);
$store_royalties = $stmt->fetchAll();

// Payout History
$stmt = $pdo->prepare("SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$user['id']]);
$payouts_history = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Royalties & Earnings</h1>
        </div>
    </header>

    <main class="page-body">
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <div class="grid-metrics">
            <div class="metric-card">
                <div class="metric-icon-box icon-green"><i class="fa-solid fa-dollar-sign"></i></div>
                <div class="metric-label">Available Balance</div>
                <div class="metric-value">$<?= number_format($available_balance, 2) ?></div>
                <div class="metric-sub">Ready for instant withdrawal</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-cyan"><i class="fa-solid fa-money-bill-trend-up"></i></div>
                <div class="metric-label">Lifetime Gross Earnings</div>
                <div class="metric-value">$<?= number_format($total_earnings, 2) ?></div>
                <div class="metric-sub">100% Artist Royalties Kept</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-green"><i class="fa-solid fa-receipt"></i></div>
                <div class="metric-label">Total Withdrawn</div>
                <div class="metric-value">$<?= number_format($paid_out, 2) ?></div>
                <div class="metric-sub">Successfully transferred</div>
            </div>
        </div>

        <div class="grid-2col">
            <!-- Request Payout Card -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-money-bill-transfer" style="color: var(--color-primary);"></i> Request Royalty Withdrawal</div>
                </div>

                <form method="POST" action="royalties">
                    <?= csrfInput() ?>
                    <input type="hidden" name="request_payout" value="1">

                    <div class="form-group">
                        <label class="form-label">Withdrawal Amount ($ USD)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" max="<?= $available_balance ?>" placeholder="Min $20.00" value="<?= $available_balance > 0 ? $available_balance : '' ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Payout Method</label>
                        <select name="method" class="form-control">
                            <option value="PayPal">PayPal</option>
                            <option value="Bank Wire / Swift">Bank Wire Transfer</option>
                            <option value="bKash / Nagad (BD)">bKash / Nagad (Bangladesh MFS)</option>
                            <option value="UPI / Bank (India)">UPI / NEFT (India)</option>
                            <option value="Crypto USDT (TRC20)">Crypto USDT (TRC-20)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Account / Recipient Details</label>
                        <textarea name="details" class="form-control" style="min-height: 80px;" placeholder="e.g. PayPal Email, Bank IBAN/SWIFT, or Mobile Number" required></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;" <?= $available_balance < 20 ? 'disabled title="Minimum balance $20 required"' : '' ?>>
                        <i class="fa-solid fa-arrow-right"></i> Submit Withdrawal Request
                    </button>
                </form>
            </div>

            <!-- DSP Breakdown -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-chart-pie" style="color: var(--color-secondary);"></i> Platform Breakdown</div>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Platform</th>
                                <th>Streams</th>
                                <th>Earnings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($store_royalties)): ?>
                                <tr><td colspan="3" style="text-align: center; color: var(--text-dim);">No royalty reports generated yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($store_royalties as $sr): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($sr['store']) ?></strong></td>
                                        <td><?= number_format($sr['total_streams']) ?></td>
                                        <td style="color: var(--color-primary); font-weight: 600;">$<?= number_format($sr['total_earnings'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payout History -->
        <div class="glass-card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color: var(--color-accent);"></i> Withdrawal History</div>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Transaction Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payouts_history)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-dim); padding: 30px;">No withdrawal requests yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payouts_history as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['created_at']) ?></td>
                                    <td style="font-weight: 700; color: #fff;">$<?= number_format($p['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($p['method']) ?></td>
                                    <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($p['account_details']) ?></td>
                                    <td>
                                        <?php if ($p['status'] === 'approved'): ?>
                                            <span class="badge badge-live"><i class="fa-solid fa-check"></i> Paid</span>
                                        <?php elseif ($p['status'] === 'pending'): ?>
                                            <span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Processing</span>
                                        <?php else: ?>
                                            <span class="badge badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code style="font-size: 11px;"><?= htmlspecialchars($p['transaction_ref'] ?: 'N/A') ?></code></td>
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
