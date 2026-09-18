<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Account Settings";
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        if (isset($_POST['update_profile'])) {
            $name = trim($_POST['name'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $spotify_id = trim($_POST['spotify_id'] ?? '');

            if (!empty($name)) {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, country = ?, spotify_id = ? WHERE id = ?");
                $stmt->execute([$name, $country, $spotify_id, $user['id']]);
                $success = 'Profile details updated successfully.';
                $_SESSION['user_name'] = $name;
                $user = currentUser();
            } else {
                $error = 'Name cannot be empty.';
            }
        } elseif (isset($_POST['change_password'])) {
            $curr = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!password_verify($curr, $user['password'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 6) {
                $error = 'New password must be at least 6 characters.';
            } elseif ($new !== $confirm) {
                $error = 'New passwords do not match.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hash, $user['id']]);
                $success = 'Password changed successfully.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Account Settings</h1>
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
            <!-- Profile Info -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-user-pen" style="color: var(--color-primary);"></i> Artist Profile</div>
                </div>

                <form method="POST" action="settings">
                    <?= csrfInput() ?>
                    <input type="hidden" name="update_profile" value="1">

                    <div class="form-group">
                        <label class="form-label">Artist / Organization Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address (Read-Only)</label>
                        <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly style="opacity: 0.6;">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <input type="text" name="country" class="form-control" value="<?= htmlspecialchars($user['country']) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Spotify Artist URI / ID (For instant mapping)</label>
                        <input type="text" name="spotify_id" class="form-control" placeholder="spotify:artist:..." value="<?= htmlspecialchars($user['spotify_id'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Profile Changes</button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-lock" style="color: var(--color-secondary);"></i> Security & Password</div>
                </div>

                <form method="POST" action="settings">
                    <?= csrfInput() ?>
                    <input type="hidden" name="change_password" value="1">

                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" placeholder="••••••••" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Min. 6 characters" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                    </div>

                    <button type="submit" class="btn btn-secondary" style="width: 100%;">Update Password</button>
                </form>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
