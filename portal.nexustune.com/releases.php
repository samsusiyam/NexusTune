<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Music Catalog";

$status_filter = $_GET['status'] ?? 'all';

$query = "SELECT * FROM releases WHERE user_id = ?";
$params = [$user['id']];

if (in_array($status_filter, ['live', 'pending', 'rejected'])) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$releases = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Music Catalog (<?= count($releases) ?>)</h1>
        </div>
        <div class="header-right">
            <a href="create-release" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus"></i> Upload Release
            </a>
        </div>
    </header>

    <main class="page-body">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'submitted'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div>Your release has been successfully submitted! Our team is reviewing metadata for DSP store ingestion.</div>
            </div>
        <?php endif; ?>

        <!-- Filter tabs -->
        <div style="display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap;">
            <a href="releases?status=all" class="btn btn-sm <?= $status_filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All Releases</a>
            <a href="releases?status=live" class="btn btn-sm <?= $status_filter === 'live' ? 'btn-primary' : 'btn-secondary' ?>">Live on DSPs</a>
            <a href="releases?status=pending" class="btn btn-sm <?= $status_filter === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">Under Review</a>
            <a href="releases?status=rejected" class="btn btn-sm <?= $status_filter === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">Action Required</a>
        </div>

        <div class="glass-card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cover & Title</th>
                            <th>Genre & Label</th>
                            <th>ISRC</th>
                            <th>UPC</th>
                            <th>Release Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($releases)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-dim); padding: 40px;">
                                    No releases found in this category.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($releases as $rel): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 14px;">
                                            <div style="width: 50px; height: 50px; border-radius: 8px; background: #1a2234; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--color-primary); flex-shrink: 0;">
                                                <?php if (!empty($rel['cover_art'])): ?>
                                                    <img src="<?= htmlspecialchars($rel['cover_art']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <i class="fa-solid fa-music"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: #fff; font-size: 14.5px;"><?= htmlspecialchars($rel['title']) ?></div>
                                                <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($rel['artist_name']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="color: #fff; font-size: 13px;"><?= htmlspecialchars($rel['genre']) ?></div>
                                        <div style="font-size: 11.5px; color: var(--text-dim);"><?= htmlspecialchars($rel['label']) ?></div>
                                    </td>
                                    <td><code style="color: var(--color-primary);"><?= htmlspecialchars($rel['isrc'] ?: 'Pending') ?></code></td>
                                    <td><code style="color: var(--color-secondary);"><?= htmlspecialchars($rel['upc'] ?: 'Pending') ?></code></td>
                                    <td><?= htmlspecialchars($rel['release_date']) ?></td>
                                    <td>
                                        <?php if ($rel['status'] === 'live'): ?>
                                            <span class="badge badge-live"><i class="fa-solid fa-circle-check"></i> Live</span>
                                        <?php elseif ($rel['status'] === 'pending'): ?>
                                            <span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Reviewing</span>
                                        <?php else: ?>
                                            <span class="badge badge-rejected"><i class="fa-solid fa-triangle-exclamation"></i> Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="release-view?id=<?= $rel['id'] ?>" class="btn btn-secondary btn-sm">
                                            <i class="fa-solid fa-eye"></i> Details
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
