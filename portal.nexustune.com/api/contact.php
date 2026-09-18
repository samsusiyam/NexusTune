<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

// Get JSON or Form POST data
$input_raw = file_get_contents('php://input');
$data = json_decode($input_raw, true);

if (!$data) {
    $data = $_POST;
}

$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$message = trim($data['message'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Validation
if (empty($name) || empty($email) || empty($message)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide your Name, Email, and Message.'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid email address.'
    ]);
    exit;
}

// Length limits
if (strlen($name) > 100 || strlen($email) > 120 || strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Message or fields exceed allowable length limits.'
    ]);
    exit;
}

try {
    // Rate limit: Max 10 messages per hour per IP
    $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE ip_address = ? AND created_at > datetime('now', '-1 hour')");
    $chk_stmt->execute([$ip]);
    $recent_count = $chk_stmt->fetchColumn();

    if ($recent_count >= 10) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'You have submitted too many requests recently. Please try again later.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO contacts (name, email, message, status, ip_address) VALUES (?, ?, ?, 'new', ?)");
    $stmt->execute([$name, $email, $message, $ip]);

    echo json_encode([
        'success' => true,
        'message' => 'Thank you, ' . htmlspecialchars($name) . '! Your message has been sent to Nexus Tune Support. We will get back to you shortly.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving your inquiry. Please try again later.'
    ]);
}
