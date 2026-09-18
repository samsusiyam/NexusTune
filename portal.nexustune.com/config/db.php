<?php
// Nexus Tune Portal - SQLite Database Configuration & Auto-Migration
$db_file = __DIR__ . '/../data/portal.sqlite';
$data_dir = dirname($db_file);

if (!file_exists($data_dir)) {
    mkdir($data_dir, 0777, true);
}

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Initialize Schema
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'artist',
            account_type TEXT DEFAULT 'Individual Artist',
            country TEXT DEFAULT 'Bangladesh',
            avatar TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            spotify_id TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            artist_name TEXT NOT NULL,
            featured_artists TEXT DEFAULT '',
            genre TEXT NOT NULL,
            label TEXT DEFAULT 'Nexus Tune Independent',
            release_date DATE NOT NULL,
            upc TEXT DEFAULT '',
            isrc TEXT DEFAULT '',
            cover_art TEXT DEFAULT '',
            audio_file TEXT DEFAULT '',
            explicit INTEGER DEFAULT 0,
            stores TEXT DEFAULT 'Spotify,Apple Music,YouTube Music,TikTok,Deezer,Amazon Music,Tidal',
            status TEXT DEFAULT 'pending',
            rejection_reason TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS royalties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            period TEXT NOT NULL,
            store TEXT NOT NULL,
            streams INTEGER DEFAULT 0,
            earnings REAL DEFAULT 0.0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS payouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            method TEXT NOT NULL,
            account_details TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            transaction_ref TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            category TEXT NOT NULL,
            priority TEXT DEFAULT 'normal',
            status TEXT DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ticket_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            message TEXT NOT NULL,
            status TEXT DEFAULT 'new',
            ip_address TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");

    // Auto-migrate users columns for email verification
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('email_verified', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0");
    }
    if (!in_array('verification_token', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN verification_token TEXT DEFAULT ''");
    }
    if (!in_array('verification_expires', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN verification_expires DATETIME DEFAULT NULL");
    }

    // Default settings
    $default_settings = [
        'smtp_enabled' => '1',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => '587',
        'smtp_user' => 'support@nexustune.com',
        'smtp_pass' => '',
        'smtp_secure' => 'tls',
        'smtp_from_email' => 'support@nexustune.com',
        'smtp_from_name' => 'Nexus Tune Distribution',
        'site_url' => 'https://portal.nexustune.com'
    ];
    $st_check = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($default_settings as $k => $v) {
        $st_check->execute([$k, $v]);
    }

    // Seed default admin and demo artist if not exist
    $check_admin = $pdo->query("SELECT id FROM users WHERE email = 'admin@nexustune.com'")->fetch();
    if (!$check_admin) {
        $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, account_type, country) VALUES (?, ?, ?, 'admin', 'Super Administrator', 'Bangladesh')");
        $stmt->execute(['Nexus Admin', 'admin@nexustune.com', $admin_pass]);
    }

    $check_artist = $pdo->query("SELECT id FROM users WHERE email = 'artist@nexustune.com'")->fetch();
    if (!$check_artist) {
        $artist_pass = password_hash('artist123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, account_type, country) VALUES (?, ?, ?, 'artist', 'Individual Artist', 'Bangladesh')");
        $stmt->execute(['Echo Wave', 'artist@nexustune.com', $artist_pass]);
        $artist_id = $pdo->lastInsertId();

        // Seed demo releases for artist
        $demo_releases = [
            [
                'title' => 'Neon Nights',
                'artist_name' => 'Echo Wave',
                'featured_artists' => 'Luna Star',
                'genre' => 'Cyberpop / Synthwave',
                'label' => 'Nexus Records',
                'release_date' => '2026-08-15',
                'upc' => '890123456789',
                'isrc' => 'QZNT12600001',
                'status' => 'live'
            ],
            [
                'title' => 'Midnight Horizon',
                'artist_name' => 'Echo Wave',
                'featured_artists' => '',
                'genre' => 'Electronic',
                'label' => 'Nexus Records',
                'release_date' => '2026-09-10',
                'upc' => '890123456790',
                'isrc' => 'QZNT12600002',
                'status' => 'pending'
            ]
        ];

        foreach ($demo_releases as $rel) {
            $r_stmt = $pdo->prepare("INSERT INTO releases (user_id, title, artist_name, featured_artists, genre, label, release_date, upc, isrc, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $r_stmt->execute([$artist_id, $rel['title'], $rel['artist_name'], $rel['featured_artists'], $rel['genre'], $rel['label'], $rel['release_date'], $rel['upc'], $rel['isrc'], $rel['status']]);
        }

        // Seed demo royalties
        $p_stmt = $pdo->prepare("INSERT INTO royalties (user_id, period, store, streams, earnings) VALUES (?, ?, ?, ?, ?)");
        $p_stmt->execute([$artist_id, 'August 2026', 'Spotify', 84500, 338.00]);
        $p_stmt->execute([$artist_id, 'August 2026', 'Apple Music', 42100, 252.60]);
        $p_stmt->execute([$artist_id, 'August 2026', 'YouTube Music', 120000, 180.00]);
        $p_stmt->execute([$artist_id, 'August 2026', 'TikTok', 350000, 105.00]);
    }

} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}
