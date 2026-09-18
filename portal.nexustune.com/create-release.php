<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "Upload New Release";
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $artist_name = trim($_POST['artist_name'] ?? '');
        $featured_artists = trim($_POST['featured_artists'] ?? '');
        $genre = trim($_POST['genre'] ?? '');
        $label = trim($_POST['label'] ?? 'Nexus Tune Independent');
        $release_date = $_POST['release_date'] ?? date('Y-m-d');
        $upc = trim($_POST['upc'] ?? '');
        $isrc = trim($_POST['isrc'] ?? '');
        $explicit = isset($_POST['explicit']) ? 1 : 0;
        
        // Auto-generate if blank
        if (empty($isrc)) {
            $year = date('y');
            $isrc = 'QZNT1' . $year . rand(10000, 99999);
        }
        if (empty($upc)) {
            $upc = (string) rand(100000000000, 999999999999);
        }

        if (empty($title) || empty($artist_name) || empty($genre)) {
            $error = 'Please complete all required fields (Title, Artist Name, Genre).';
        } else {
            // Ensure upload directories exist
            $covers_dir = __DIR__ . '/uploads/covers';
            $audio_dir = __DIR__ . '/uploads/audio';
            if (!file_exists($covers_dir)) mkdir($covers_dir, 0755, true);
            if (!file_exists($audio_dir)) mkdir($audio_dir, 0755, true);

            // Handle Cover Art Upload with strict MIME & image verification
            $cover_art_path = '';
            if (isset($_FILES['cover_art']) && $_FILES['cover_art']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['cover_art']['tmp_name'];
                $file_size = $_FILES['cover_art']['size'];
                $ext = strtolower(pathinfo($_FILES['cover_art']['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                
                // Max 15MB
                if ($file_size > 15 * 1024 * 1024) {
                    $error = 'Cover artwork must be under 15MB.';
                } elseif (!in_array($ext, $allowed_exts)) {
                    $error = 'Cover artwork must be a JPG, PNG, or WebP image.';
                } else {
                    $img_info = @getimagesize($file_tmp);
                    if ($img_info === false) {
                        $error = 'Uploaded cover artwork file is not a valid image.';
                    } else {
                        $cover_name = 'cover_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $target = $covers_dir . '/' . $cover_name;
                        if (move_uploaded_file($file_tmp, $target)) {
                            $cover_art_path = 'uploads/covers/' . $cover_name;
                        }
                    }
                }
            }

            // Handle Audio File Upload with strict validation
            $audio_path = '';
            if (empty($error) && isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['audio_file']['tmp_name'];
                $file_size = $_FILES['audio_file']['size'];
                $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
                $allowed_audio_exts = ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg'];
                
                // Max 100MB
                if ($file_size > 100 * 1024 * 1024) {
                    $error = 'Audio file must be under 100MB.';
                } elseif (!in_array($ext, $allowed_audio_exts)) {
                    $error = 'Audio file must be in MP3, WAV, FLAC, or M4A format.';
                } else {
                    $audio_name = 'track_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $target = $audio_dir . '/' . $audio_name;
                    if (move_uploaded_file($file_tmp, $target)) {
                        $audio_path = 'uploads/audio/' . $audio_name;
                    }
                }
            }

            if (empty($error)) {
                $stmt = $pdo->prepare("INSERT INTO releases (user_id, title, artist_name, featured_artists, genre, label, release_date, upc, isrc, cover_art, audio_file, explicit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([
                    $user['id'], $title, $artist_name, $featured_artists, $genre, $label, $release_date, $upc, $isrc, $cover_art_path, $audio_path, $explicit
                ]);

                header('Location: releases?msg=submitted');
                exit;
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
            <button class="mobile-toggle" id="mobileToggleBtn" title="Toggle Menu"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Create New Music Release</h1>
        </div>
        <div class="header-right">
            <a href="releases" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Catalog</a>
        </div>
    </header>

    <main class="page-body">
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="create-release" enctype="multipart/form-data">
            <?= csrfInput() ?>

            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-info-circle" style="color: var(--color-primary);"></i> 1. Release Metadata</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Release Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Lost in Cyber City" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Primary Artist *</label>
                        <input type="text" name="artist_name" class="form-control" placeholder="e.g. <?= htmlspecialchars($user['name']) ?>" required value="<?= htmlspecialchars($_POST['artist_name'] ?? $user['name']) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Featured Artists (Optional)</label>
                        <input type="text" name="featured_artists" class="form-control" placeholder="e.g. DJ Pulse, Anna Vance" value="<?= htmlspecialchars($_POST['featured_artists'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Primary Genre *</label>
                        <select name="genre" class="form-control" required>
                            <option value="Pop">Pop</option>
                            <option value="Hip-Hop / Rap">Hip-Hop / Rap</option>
                            <option value="Electronic / EDM">Electronic / EDM</option>
                            <option value="Rock / Alternative">Rock / Alternative</option>
                            <option value="R&B / Soul">R&B / Soul</option>
                            <option value="Lo-Fi / Ambient">Lo-Fi / Ambient</option>
                            <option value="World / Folk / Bangla">World / Folk / Bangla</option>
                            <option value="Cinematic / Soundtrack">Cinematic / Soundtrack</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Record Label</label>
                        <input type="text" name="label" class="form-control" value="Nexus Tune Independent" placeholder="Label name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Release Date *</label>
                        <input type="date" name="release_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                    </div>
                </div>
            </div>

            <!-- Codes & Audio -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-barcode" style="color: var(--color-secondary);"></i> 2. ISRC, UPC & Audio Master</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ISRC Code (Leave blank for auto)</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="isrc" id="isrcInput" class="form-control" placeholder="e.g. QZNT12600001">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="generateISRC('isrcInput')">Generate</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">UPC / Barcode (Leave blank for auto)</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="upc" id="upcInput" class="form-control" placeholder="e.g. 890123456789">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="generateUPC('upcInput')">Generate</button>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Cover Artwork (3000x3000px JPG/PNG recommended)</label>
                        <input type="file" name="cover_art" id="coverArtInput" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <div style="margin-top: 10px;">
                            <img id="coverArtPreview" src="#" alt="Cover Preview" style="display: none; max-width: 140px; border-radius: 10px; border: 1px solid var(--border-color);">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Master Audio File (WAV, FLAC, or 320kbps MP3)</label>
                        <input type="file" name="audio_file" class="form-control" accept="audio/*">
                        <small style="color: var(--text-dim); display: block; margin-top: 6px;">Supported: .wav, .mp3, .flac, .m4a (Max 100MB)</small>
                    </div>
                </div>

                <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                    <input type="checkbox" name="explicit" id="explicitCheck" style="width: 18px; height: 18px; accent-color: var(--color-primary);">
                    <label for="explicitCheck" style="font-size: 13.5px; color: #fff;">Contains Explicit Lyrics / Content (Parental Advisory)</label>
                </div>
            </div>

            <!-- Stores -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-store" style="color: var(--color-accent);"></i> 3. DSP Stores & Delivery Channels</div>
                </div>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px;">Your music will be automatically ingested and delivered to all selected platforms globally upon moderation approval:</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #fff; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 8px;">
                        <input type="checkbox" checked disabled style="accent-color: var(--color-primary);"> Spotify
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #fff; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 8px;">
                        <input type="checkbox" checked disabled style="accent-color: var(--color-primary);"> Apple Music / iTunes
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #fff; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 8px;">
                        <input type="checkbox" checked disabled style="accent-color: var(--color-primary);"> YouTube Music & CID
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #fff; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 8px;">
                        <input type="checkbox" checked disabled style="accent-color: var(--color-primary);"> TikTok & ByteDance
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #fff; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 8px;">
                        <input type="checkbox" checked disabled style="accent-color: var(--color-primary);"> Amazon Music & Tidal
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #fff; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 8px;">
                        <input type="checkbox" checked disabled style="accent-color: var(--color-primary);"> 200+ Other Global Stores
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: 16px; margin-bottom: 40px; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary" style="flex: 1; min-width: 240px; padding: 14px;">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Submit Music for Global Distribution
                </button>
                <a href="releases" class="btn btn-secondary" style="padding: 14px 28px;">Cancel</a>
            </div>
        </form>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
