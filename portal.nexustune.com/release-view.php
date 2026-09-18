<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM releases WHERE id = ? AND (user_id = ? OR ? = 'admin')");
$stmt->execute([$id, $user['id'], $user['role']]);
$release = $stmt->fetch();

if (!$release) {
    header('Location: releases');
    exit;
}

$page_title = $release['title'] . " - Release Details";

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title"><?= htmlspecialchars($release['title']) ?></h1>
        </div>
        <div class="header-right">
            <a href="releases" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </header>

    <main class="page-body">
        <div class="grid-split-view">
            <!-- Cover Art Card -->
            <div class="glass-card" style="text-align: center;">
                <div style="width: 100%; aspect-ratio: 1/1; border-radius: 12px; background: #1a2234; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 48px; color: var(--color-primary); margin-bottom: 16px;">
                    <?php if (!empty($release['cover_art'])): ?>
                        <img src="<?= htmlspecialchars($release['cover_art']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <i class="fa-solid fa-music"></i>
                    <?php endif; ?>
                </div>

                <h3 style="font-size: 16px; color: #fff; margin-bottom: 4px;"><?= htmlspecialchars($release['title']) ?></h3>
                <p style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($release['artist_name']) ?></p>

                <div style="margin-top: 14px;">
                    <?php if ($release['status'] === 'live'): ?>
                        <span class="badge badge-live" style="font-size: 12px; padding: 6px 14px;"><i class="fa-solid fa-circle-check"></i> Live on 250+ Stores</span>
                    <?php elseif ($release['status'] === 'pending'): ?>
                        <span class="badge badge-pending" style="font-size: 12px; padding: 6px 14px;"><i class="fa-solid fa-clock"></i> In Quality Moderation</span>
                    <?php else: ?>
                        <span class="badge badge-rejected" style="font-size: 12px; padding: 6px 14px;"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($release['audio_file'])): ?>
                    <div style="margin-top: 20px; text-align: left;">
                        <label class="form-label" style="font-size: 11px;"><i class="fa-solid fa-play"></i> Audio Master Preview</label>
                        <audio controls style="width: 100%; height: 36px; border-radius: 8px;">
                            <source src="<?= htmlspecialchars($release['audio_file']) ?>">
                        </audio>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Details Card -->
            <div>
                <div class="glass-card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-list-check" style="color: var(--color-primary);"></i> Metadata & Identifiers</div>
                    </div>

                    <div class="form-row" style="font-size: 13.5px;">
                        <div>
                            <span style="color: var(--text-dim); display: block; font-size: 11.5px; text-transform: uppercase;">ISRC Code</span>
                            <strong style="color: var(--color-primary); font-family: monospace; font-size: 15px;"><?= htmlspecialchars($release['isrc']) ?></strong>
                        </div>

                        <div>
                            <span style="color: var(--text-dim); display: block; font-size: 11.5px; text-transform: uppercase;">UPC / EAN Barcode</span>
                            <strong style="color: var(--color-secondary); font-family: monospace; font-size: 15px;"><?= htmlspecialchars($release['upc']) ?></strong>
                        </div>

                        <div>
                            <span style="color: var(--text-dim); display: block; font-size: 11.5px; text-transform: uppercase;">Primary Genre</span>
                            <span style="color: #fff;"><?= htmlspecialchars($release['genre']) ?></span>
                        </div>

                        <div>
                            <span style="color: var(--text-dim); display: block; font-size: 11.5px; text-transform: uppercase;">Record Label</span>
                            <span style="color: #fff;"><?= htmlspecialchars($release['label']) ?></span>
                        </div>

                        <div>
                            <span style="color: var(--text-dim); display: block; font-size: 11.5px; text-transform: uppercase;">Original Release Date</span>
                            <span style="color: #fff;"><?= htmlspecialchars($release['release_date']) ?></span>
                        </div>

                        <div>
                            <span style="color: var(--text-dim); display: block; font-size: 11.5px; text-transform: uppercase;">Explicit Lyrics</span>
                            <span style="color: #fff;"><?= $release['explicit'] ? '<span style="color:#ef4444;">Yes (Parental Advisory)</span>' : 'No (Clean)' ?></span>
                        </div>
                    </div>

                    <?php if (!empty($release['rejection_reason'])): ?>
                        <div style="margin-top: 20px; padding: 14px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px;">
                            <strong style="color: #f87171;"><i class="fa-solid fa-triangle-exclamation"></i> Rejection Feedback from Moderation:</strong>
                            <p style="font-size: 13px; color: #fca5a5; margin-top: 4px;"><?= htmlspecialchars($release['rejection_reason']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Stores status -->
                <div class="glass-card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-store" style="color: var(--color-secondary);"></i> Delivery Pipeline Status</div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                        <div style="padding: 12px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="font-weight: 600; color: #fff;">Spotify</div>
                            <div style="font-size: 11.5px; color: var(--color-primary); margin-top: 4px;"><i class="fa-solid fa-check"></i> <?= $release['status'] === 'live' ? 'Delivered (Live)' : 'Queued' ?></div>
                        </div>
                        <div style="padding: 12px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="font-weight: 600; color: #fff;">Apple Music</div>
                            <div style="font-size: 11.5px; color: var(--color-primary); margin-top: 4px;"><i class="fa-solid fa-check"></i> <?= $release['status'] === 'live' ? 'Delivered (Live)' : 'Queued' ?></div>
                        </div>
                        <div style="padding: 12px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="font-weight: 600; color: #fff;">YouTube Music</div>
                            <div style="font-size: 11.5px; color: var(--color-primary); margin-top: 4px;"><i class="fa-solid fa-check"></i> <?= $release['status'] === 'live' ? 'Delivered (Live)' : 'Queued' ?></div>
                        </div>
                        <div style="padding: 12px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="font-weight: 600; color: #fff;">TikTok</div>
                            <div style="font-size: 11.5px; color: var(--color-primary); margin-top: 4px;"><i class="fa-solid fa-check"></i> <?= $release['status'] === 'live' ? 'Delivered (Live)' : 'Queued' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
