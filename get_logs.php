<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401); exit;
}
require 'config.php';
header('Content-Type: application/json');

$recent = $pdo->query("
    SELECT ul.log_id, ul.humidity_id, u.username,
           p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM user_logs ul
    JOIN users u    ON ul.user_id     = u.user_id
    JOIN humidity h ON ul.humidity_id = h.humidity_id
    LEFT JOIN plants p ON h.plant_id  = p.plant_id
    ORDER BY h.recorded_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$stats  = array_column($counts, 'total', 'status');

echo json_encode(['logs' => $recent, 'stats' => $stats]);
