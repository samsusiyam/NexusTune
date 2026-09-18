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
            } elseif ($action === 'delete_user') {
                // Permanently delete user and clean up dependent data
                $pdo->beginTransaction();
                try {
                    // 1. Delete ticket messages
                    $stmt_tm = $pdo->prepare("DELETE FROM ticket_messages WHERE user_id = ? OR ticket_id IN (SELECT id FROM tickets WHERE user_id = ?)");
                    $stmt_tm->execute([$target_id, $target_id]);

                    // 2. Delete tickets
                    $stmt_t = $pdo->prepare("DELETE FROM tickets WHERE user_id = ?");
                    $stmt_t->execute([$target_id]);

                    // 3. Delete payouts
                    $stmt_p = $pdo->prepare("DELETE FROM payouts WHERE user_id = ?");
                    $stmt_p->execute([$target_id]);

                    // 4. Delete royalties
                    $stmt_roy = $pdo->prepare("DELETE FROM royalties WHERE user_id = ?");
                    $stmt_roy->execute([$target_id]);

                    // 5. Delete releases
                    $stmt_rel = $pdo->prepare("DELETE FROM releases WHERE user_id = ?");
                    $stmt_rel->execute([$target_id]);

                    // 6. Delete user
                    $stmt_u = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt_u->execute([$target_id]);

                    $pdo->commit();
                    $success = "User #$target_id and all associated releases, royalties, and tickets were permanently deleted.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Failed to delete user: " . $e->getMessage();
                }
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

                                        <button type="button" class="btn btn-secondary btn-sm" title="Permanently Delete User" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.25);" onclick="openDeleteModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($u['email']), ENT_QUOTES) ?>', <?= intval($u['release_count']) ?>)">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
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

<!-- Cyber-Luxe Delete User Confirmation Modal -->
<div id="deleteModal" class="delete-modal-overlay" style="display: none;">
    <div class="delete-modal-backdrop" onclick="closeDeleteModal()"></div>
    <div class="delete-modal-dialog">
        <div class="delete-modal-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="delete-modal-icon">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h3 class="delete-modal-title">Confirm Deletion</h3>
                    <div style="font-size: 12px; color: var(--text-dim); margin-top: 2px;">Irreversible Administrative Action</div>
                </div>
            </div>
            <button type="button" class="delete-modal-close" onclick="closeDeleteModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="delete-modal-body">
            <p style="font-size: 14px; color: #e5e7eb; line-height: 1.6; margin-bottom: 18px;">
                Are you sure you want to permanently delete this user account from Nexus Tune?
            </p>

            <div class="delete-user-card">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <div class="delete-user-avatar" id="modalUserAvatar">
                        U
                    </div>
                    <div style="overflow: hidden; flex: 1;">
                        <div id="modalUserName" style="font-weight: 700; font-size: 14.5px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">User Name</div>
                        <div id="modalUserEmail" style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">user@example.com</div>
                    </div>
                </div>
                <div class="delete-warning-note">
                    <i class="fa-solid fa-triangle-exclamation" style="flex-shrink: 0; margin-top: 1px;"></i>
                    <div>This will permanently delete <strong id="modalReleaseCount" style="color: #fff;">0</strong> releases, royalty ledgers, payout records, and support tickets. This action <strong>cannot be undone</strong>.</div>
                </div>
            </div>

            <form method="POST" action="admin-users" id="deleteUserForm">
                <?= csrfInput() ?>
                <input type="hidden" name="user_id" id="modalUserIdInput" value="">
                <input type="hidden" name="action" value="delete_user">

                <div class="delete-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()" style="padding: 11px 22px;">
                        Cancel
                    </button>
                    <button type="submit" class="btn-delete-confirm">
                        <i class="fa-solid fa-trash-can"></i> Delete Permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.delete-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
}
.delete-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(3, 5, 8, 0.85);
    backdrop-filter: blur(8px);
}
.delete-modal-dialog {
    position: relative;
    z-index: 1;
    background: #0c1017;
    border: 1px solid rgba(239, 68, 68, 0.35);
    border-radius: 20px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 0 50px rgba(239, 68, 68, 0.2), 0 25px 60px rgba(0, 0, 0, 0.9);
    overflow: hidden;
    animation: deleteModalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes deleteModalPop {
    from { opacity: 0; transform: scale(0.92) translateY(12px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.delete-modal-header {
    padding: 22px 26px 18px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.delete-modal-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ef4444;
    font-size: 19px;
}
.delete-modal-title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: #fff;
    font-family: 'Unbounded', sans-serif;
    letter-spacing: -0.02em;
}
.delete-modal-close {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
    color: var(--text-dim);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}
.delete-modal-close:hover {
    color: #fff;
    background: rgba(255,255,255,0.12);
}
.delete-modal-body {
    padding: 24px 26px;
}
.delete-user-card {
    background: rgba(255, 255, 255, 0.025);
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 22px;
}
.delete-user-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(239,68,68,0.3) 0%, rgba(14,19,29,0.9) 100%);
    border: 1px solid rgba(239, 68, 68, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    text-transform: uppercase;
}
.delete-warning-note {
    font-size: 12.5px;
    color: #f87171;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    line-height: 1.5;
}
.delete-modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    align-items: center;
}
.btn-delete-confirm {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #ffffff;
    border: 1px solid rgba(239, 68, 68, 0.6);
    box-shadow: 0 0 25px rgba(239, 68, 68, 0.35);
    padding: 11px 24px;
    font-weight: 700;
    font-size: 13.5px;
    border-radius: 9999px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition);
}
.btn-delete-confirm:hover {
    box-shadow: 0 0 35px rgba(239, 68, 68, 0.55);
    transform: translateY(-1px);
}
</style>

<script>
function openDeleteModal(userId, name, email, releaseCount) {
    document.getElementById('modalUserIdInput').value = userId;
    document.getElementById('modalUserName').textContent = name;
    document.getElementById('modalUserEmail').textContent = email;
    document.getElementById('modalReleaseCount').textContent = releaseCount;
    document.getElementById('modalUserAvatar').textContent = name ? name.charAt(0).toUpperCase() : 'U';
    
    const modal = document.getElementById('deleteModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
