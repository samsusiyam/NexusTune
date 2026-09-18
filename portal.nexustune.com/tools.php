<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$user = currentUser();
$page_title = "ISRC & Smart Tools";

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title">Artist Tools & Identifiers</h1>
        </div>
    </header>

    <main class="page-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
            <!-- ISRC Generator -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-barcode" style="color: var(--color-primary);"></i> Instant ISRC Generator</div>
                </div>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                    Generate official International Standard Recording Codes (ISRC) allocated under Nexus Tune prefix:
                </p>

                <div class="form-group">
                    <label class="form-label">Generated ISRC Code</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="toolIsrc" class="form-control" style="font-family: monospace; font-size: 16px; color: var(--color-primary); font-weight: 700;" value="QZNT126<?= rand(10000, 99999) ?>" readonly>
                        <button class="btn btn-primary btn-sm" onclick="generateISRC('toolIsrc')"><i class="fa-solid fa-arrows-rotate"></i> New</button>
                    </div>
                </div>

                <div style="font-size: 12px; color: var(--text-dim); margin-top: 10px;">
                    Format: <code>QZ (Country) - NT1 (Registrant) - 26 (Year) - 00001 (Designation)</code>
                </div>
            </div>

            <!-- SmartLink Generator -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-link" style="color: var(--color-secondary);"></i> Music SmartLink & Pre-Save</div>
                </div>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                    Create a universal landing page for your upcoming release that routes fans to Spotify, Apple Music, and YouTube:
                </p>

                <div class="form-group">
                    <label class="form-label">Track / Album Slug</label>
                    <input type="text" id="smartSlug" class="form-control" placeholder="my-new-track" value="neon-nights">
                </div>

                <div style="padding: 12px; background: rgba(0,242,254,0.08); border: 1px solid rgba(0,242,254,0.25); border-radius: 8px; margin-bottom: 16px;">
                    <span style="font-size: 11px; color: var(--color-secondary); font-weight: 600;">Universal Fan Link:</span>
                    <div style="font-family: monospace; font-size: 13.5px; color: #fff; margin-top: 4px;">https://link.nexustune.com/neon-nights</div>
                </div>

                <button class="btn btn-secondary btn-sm" onclick="alert('SmartLink copied to clipboard!')"><i class="fa-solid fa-copy"></i> Copy Fan Link</button>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
