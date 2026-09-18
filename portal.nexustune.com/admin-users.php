<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$user = currentUser();
$page_title = "Artist & Label Management";
$success = '';
$error = '';

// Handle status change / role change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $target_id = intval($_POST['user_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        if ($target_id > 0 && $target_id !== intval($user['id'])) {
            if ($action === 'toggle_status') {
                $curr = $pdo->prepare("SELECT status FROM users WHERE id = ?");
                $curr->execute([$target_id]);
                $new_st = ($curr->fetchColumn() === 'active') ? 'suspended' : 'active';
                $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $upd->execute([$new_st, $target_id]);
                $success = "User status updated to $new_st.";
            } elseif ($action === 'toggle_role') {
                $curr = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $curr->execute([$target_id]);
                $new_r = ($curr->fetchColumn() === 'admin') ? 'artist' : 'admin';
                $upd = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $upd->execute([$new_r, $target_id]);
                $success = "User role updated to $new_r.";
            }
        }
    }
}

// Fetch users with release count
$stmt = $pdo->query("SELECT u.*, COUNT(r.id) as release_count FROM users u LEFT JOIN releases r ON u.id = r.user_id GROUP BY u.id ORDER BY u.id DESC");
$users_list = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Registered Artists & Labels (<?= count($users_list) ?>)</h1>
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
                            <th>User / Organization</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Country</th>
                            <th>Releases</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_list as $u): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; color: #fff;"><?= htmlspecialchars($u['name']) ?></div>
                                    <div style="font-size: 11px; color: var(--text-dim);"><?= htmlspecialchars($u['account_type']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge badge-admin">Admin</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: rgba(255,255,255,0.06); color: #fff;">Artist</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($u['country']) ?></td>
                                <td><strong><?= $u['release_count'] ?></strong> titles</td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="badge badge-live">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-rejected">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['id'] != $user['id']): ?>
                                        <form method="POST" action="admin-users" style="display: inline-block;">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <button type="submit" class="btn btn-secondary btn-sm" title="Toggle Active / Suspended">
                                                <?= $u['status'] === 'active' ? '<i class="fa-solid fa-user-slash" style="color:#ef4444;"></i>' : '<i class="fa-solid fa-user-check" style="color:#10b981;"></i>' ?>
                                            </button>
                                        </form>

                                        <form method="POST" action="admin-users" style="display: inline-block;">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="action" value="toggle_role">
                                            <button type="submit" class="btn btn-secondary btn-sm" title="Toggle Admin / Artist Role">
                                                <i class="fa-solid fa-shield"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: var(--text-dim);">Current User</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
