<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';
$id = intval($_GET['id'] ?? 0);
if ($id && $id !== $_SESSION['user_id']) {
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);
}
header("Location: manage_users.php?deleted=1");
exit;
