<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Artist Dashboard";

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM releases WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_releases = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM releases WHERE user_id = ? AND status = 'live'");
$stmt->execute([$user['id']]);
$live_releases = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(streams), 0), COALESCE(SUM(earnings), 0.0) FROM royalties WHERE user_id = ?");
$stmt->execute([$user['id']]);
list($total_streams, $total_earnings) = $stmt->fetch(PDO::FETCH_NUM);

// Payout balance
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0.0) FROM payouts WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$user['id']]);
$paid_amount = $stmt->fetchColumn();
$available_balance = max(0, $total_earnings - $paid_amount);

// Recent releases
$stmt = $pdo->prepare("SELECT * FROM releases WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$stmt->execute([$user['id']]);
$recent_releases = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Welcome back, <?= htmlspecialchars($user['name']) ?></h1>
        </div>
        <div class="header-right">
            <a href="create-release" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus"></i> New Release
            </a>
            <a href="../" target="_blank" class="header-btn-link">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Visit Main Site
            </a>
        </div>
    </header>

    <main class="page-body">
        <?php if (isset($_GET['welcome'])): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-sparkles"></i>
                <div>Welcome to Nexus Tune! Your artist portal account is ready. Start distributing your music worldwide.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($user['email_verified']) && $user['email_verified'] == 0): ?>
            <div class="alert alert-warning" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-envelope-circle-check" style="font-size: 18px; color: #fbbf24;"></i>
                    <div>
                        <strong>Email Verification Required:</strong> Please verify your email address (<strong><?= htmlspecialchars($user['email']) ?></strong>) to unlock instant royalty withdrawals and live release distribution.
                    </div>
                </div>
                <a href="verify-email?resend=1&email=<?= urlencode($user['email']) ?>" class="btn btn-secondary btn-sm" style="white-space: nowrap;">
                    <i class="fa-solid fa-paper-plane"></i> Resend Verification Email
                </a>
            </div>
        <?php endif; ?>

        <!-- Metric Cards -->
        <div class="grid-metrics">
            <div class="metric-card">
                <div class="metric-icon-box icon-green">
                    <i class="fa-solid fa-headphones"></i>
                </div>
                <div class="metric-label">Total Streams</div>
                <div class="metric-value"><?= number_format($total_streams) ?></div>
                <div class="metric-sub trend-up"><i class="fa-solid fa-arrow-trend-up"></i> +18.4% this month</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-cyan">
                    <i class="fa-solid fa-dollar-sign"></i>
                </div>
                <div class="metric-label">Royalty Balance</div>
                <div class="metric-value">$<?= number_format($available_balance, 2) ?></div>
                <div class="metric-sub"><a href="royalties" style="color: var(--color-primary); text-decoration: underline;">Request Payout</a></div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-cyan">
                    <i class="fa-solid fa-compact-disc"></i>
                </div>
                <div class="metric-label">Total Releases</div>
                <div class="metric-value"><?= $total_releases ?></div>
                <div class="metric-sub"><?= $live_releases ?> Live on DSP stores</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon-box icon-amber">
                    <i class="fa-solid fa-globe"></i>
                </div>
                <div class="metric-label">Active DSP Stores</div>
                <div class="metric-value">250+</div>
                <div class="metric-sub">Spotify, Apple, TikTok & more</div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="glass-card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-chart-line" style="color: var(--color-primary);"></i> Streaming & Revenue Performance</div>
                <span style="font-size: 12px; color: var(--text-dim);">Last 6 Months (2026)</span>
            </div>
            <div style="height: 280px; position: relative;">
                <canvas id="streamsChart"></canvas>
            </div>
        </div>

        <!-- Recent Releases Table -->
        <div class="glass-card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-list-music" style="color: var(--color-secondary);"></i> Recent Releases</div>
                <a href="releases" class="btn btn-secondary btn-sm">View All (<?= $total_releases ?>)</a>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Release</th>
                            <th>Genre</th>
                            <th>ISRC / UPC</th>
                            <th>Release Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_releases)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-dim); padding: 30px;">
                                    No music releases yet. Click <strong>"New Release"</strong> to distribute your first track!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_releases as $rel): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 44px; height: 44px; border-radius: 8px; background: #1a2234; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--color-primary);">
                                                <?php if (!empty($rel['cover_art'])): ?>
                                                    <img src="<?= htmlspecialchars($rel['cover_art']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <i class="fa-solid fa-music"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($rel['title']) ?></div>
                                                <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($rel['artist_name']) ?> <?= !empty($rel['featured_artists']) ? '(feat. ' . htmlspecialchars($rel['featured_artists']) . ')' : '' ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($rel['genre']) ?></td>
                                    <td>
                                        <div style="font-family: monospace; font-size: 12px; color: var(--text-muted);">
                                            <?= !empty($rel['isrc']) ? htmlspecialchars($rel['isrc']) : 'Auto' ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($rel['release_date']) ?></td>
                                    <td>
                                        <?php if ($rel['status'] === 'live'): ?>
                                            <span class="badge badge-live"><i class="fa-solid fa-circle-check"></i> Live on Stores</span>
                                        <?php elseif ($rel['status'] === 'pending'): ?>
                                            <span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Under Review</span>
                                        <?php else: ?>
                                            <span class="badge badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="release-view?id=<?= $rel['id'] ?>" class="btn btn-secondary btn-sm">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('streamsChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['March', 'April', 'May', 'June', 'July', 'August'],
                datasets: [
                    {
                        label: 'Total Streams',
                        data: [42000, 68000, 110000, 195000, 310000, 596600],
                        borderColor: '#57ff52',
                        backgroundColor: 'rgba(87, 255, 82, 0.08)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Earnings ($ USD)',
                        data: [120, 210, 380, 560, 720, 875.60],
                        borderColor: '#00f2fe',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.4,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#9ca3af' } },
                    y: { type: 'linear', display: true, position: 'left', grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#9ca3af' } },
                    y1: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#00f2fe' } }
                },
                plugins: {
                    legend: { labels: { color: '#f3f4f6', font: { family: 'Inter' } } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
