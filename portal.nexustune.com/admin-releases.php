<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$user = currentUser();
$page_title = "Music Moderation Queue";
$success = '';
$error = '';

// Handle Moderation Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $release_id = intval($_POST['release_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');

        if ($action === 'approve' && $release_id > 0) {
            $upd = $pdo->prepare("UPDATE releases SET status = 'live', rejection_reason = '' WHERE id = ?");
            $upd->execute([$release_id]);
            $success = 'Release #' . $release_id . ' has been approved and delivered live to DSP stores!';
        } elseif ($action === 'reject' && $release_id > 0) {
            $upd = $pdo->prepare("UPDATE releases SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            $upd->execute([$reason ?: 'Metadata or audio quality does not meet DSP store guidelines.', $release_id]);
            $success = 'Release #' . $release_id . ' marked as rejected with artist feedback.';
        }
    }
}

// Fetch all releases
$stmt = $pdo->query("SELECT r.*, u.name as user_name, u.email as user_email FROM releases r JOIN users u ON r.user_id = u.id ORDER BY CASE WHEN r.status = 'pending' THEN 1 ELSE 2 END, r.id DESC");
$all_releases = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Release Moderation Center</h1>
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
                            <th>Cover & Title</th>
                            <th>Artist / User</th>
                            <th>Identifiers</th>
                            <th>Audio / Media</th>
                            <th>Status</th>
                            <th>Moderation Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_releases)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-dim); padding: 40px;">No music releases found in system.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_releases as $rel): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 48px; height: 48px; border-radius: 8px; background: #1a2234; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--color-primary); flex-shrink: 0;">
                                                <?php if (!empty($rel['cover_art'])): ?>
                                                    <img src="<?= htmlspecialchars($rel['cover_art']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <i class="fa-solid fa-music"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: #fff;"><?= htmlspecialchars($rel['title']) ?></div>
                                                <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($rel['genre']) ?> • <?= htmlspecialchars($rel['release_date']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($rel['user_name']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-dim);"><?= htmlspecialchars($rel['user_email']) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size: 11.5px; font-family: monospace;">
                                            <div>ISRC: <strong style="color: var(--color-primary);"><?= htmlspecialchars($rel['isrc'] ?: 'N/A') ?></strong></div>
                                            <div>UPC: <strong style="color: var(--color-secondary);"><?= htmlspecialchars($rel['upc'] ?: 'N/A') ?></strong></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($rel['audio_file'])): ?>
                                            <audio controls style="height: 30px; width: 160px;">
                                                <source src="<?= htmlspecialchars($rel['audio_file']) ?>">
                                            </audio>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: var(--text-dim);">No Audio</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rel['status'] === 'live'): ?>
                                            <span class="badge badge-live"><i class="fa-solid fa-circle-check"></i> Live</span>
                                        <?php elseif ($rel['status'] === 'pending'): ?>
                                            <span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                        <?php else: ?>
                                            <span class="badge badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rel['status'] === 'pending' || $rel['status'] === 'rejected'): ?>
                                            <form method="POST" action="admin-releases" style="display: inline-block;">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="release_id" value="<?= $rel['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Approve</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($rel['status'] === 'pending' || $rel['status'] === 'live'): ?>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $rel['id'] ?>, <?= htmlspecialchars(json_encode($rel['title']), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="fa-solid fa-ban"></i> Reject
                                            </button>
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

<!-- Reject Modal -->
<div id="rejectModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 999; align-items: center; justify-content: center;">
    <div class="auth-card" style="max-width: 440px;">
        <h3 style="color: #fff; margin-bottom: 12px;">Reject Release: <span id="rejectTitle" style="color: var(--color-primary);"></span></h3>
        <form method="POST" action="admin-releases">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="release_id" id="rejectReleaseId">
            <div class="form-group">
                <label class="form-label">Feedback for Artist</label>
                <textarea name="rejection_reason" class="form-control" placeholder="Specify what needs correction (e.g. artwork blurry, title casing, audio clipping)..." required></textarea>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">Confirm Rejection</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('rejectModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(id, title) {
    document.getElementById('rejectReleaseId').value = id;
    document.getElementById('rejectTitle').textContent = title;
    document.getElementById('rejectModal').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
