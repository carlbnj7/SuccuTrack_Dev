<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = $error = "";

// Add plant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_plant_name'])) {
    $name    = trim($_POST['new_plant_name']);
    $city    = trim($_POST['new_city']);
    $user_id = intval($_POST['plant_user_id']);
    if ($name && $city && $user_id) {
        $pdo->prepare("INSERT INTO plants (user_id, plant_name, city) VALUES (?,?,?)")
            ->execute([$user_id, $name, $city]);
        $msg = "Plant '$name' added successfully.";
    } else {
        $error = "All fields are required.";
    }
}

// Delete plant
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pid = intval($_GET['id']);
    // Cascade: remove humidity + user_logs linked to this plant
    $humIds = $pdo->prepare("SELECT humidity_id FROM humidity WHERE plant_id = ?");
    $humIds->execute([$pid]);
    foreach ($humIds->fetchAll() as $row) {
        $pdo->prepare("DELETE FROM user_logs WHERE humidity_id = ?")->execute([$row['humidity_id']]);
    }
    $pdo->prepare("DELETE FROM humidity WHERE plant_id = ?")->execute([$pid]);
    $pdo->prepare("DELETE FROM plants WHERE plant_id = ?")->execute([$pid]);
    header("Location: manage_plants.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "Plant deleted successfully.";

$users  = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC")->fetchAll();
$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.created_at,
           u.username,
           (SELECT COUNT(*) FROM humidity h
            JOIN user_logs ul ON h.humidity_id = ul.humidity_id
            WHERE h.plant_id = p.plant_id AND ul.user_id = p.user_id) AS reading_count,
           (SELECT h2.humidity_percent FROM humidity h2
            WHERE h2.plant_id = p.plant_id
            ORDER BY h2.recorded_at DESC LIMIT 1) AS last_humidity,
           (SELECT h3.status FROM humidity h3
            WHERE h3.plant_id = p.plant_id
            ORDER BY h3.recorded_at DESC LIMIT 1) AS last_status
    FROM plants p
    JOIN users u ON p.user_id = u.user_id
    ORDER BY u.username, p.plant_id
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Plants – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack <span class="admin-badge">Admin</span></div>
  <div class="nav-links">
    <a href="admin_dashboard.php" class="btn btn-sm">← Dashboard</a>
    <a href="manage_users.php"    class="btn btn-sm">Users</a>
    <a href="logout.php"          class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- Add Plant Form -->
  <div class="card">
    <h2>Add Plant / IoT Device</h2>
    <p class="subtitle">Assign a new plant sensor to a user account</p>
    <form method="POST" class="add-plant-form">
      <div class="form-group">
        <label>Assign to User</label>
        <select name="plant_user_id" required>
          <option value="">— Select user —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Plant Name</label>
        <input type="text" name="new_plant_name" required placeholder="e.g. Echeveria #1">
      </div>
      <div class="form-group">
        <label>City (for weather API)</label>
        <input type="text" name="new_city" required placeholder="e.g. Manila">
      </div>
      <button type="submit" class="btn btn-primary">Add Plant</button>
    </form>
  </div>

  <!-- All Plants Table -->
  <div class="card">
    <h2>All Plants <span class="user-count"><?= count($plants) ?></span></h2>
    <?php if (empty($plants)): ?>
      <p class="empty-msg">No plants yet. Add one above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="det-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Plant Name</th>
            <th>Owner</th>
            <th>City</th>
            <th>Readings</th>
            <th>Last Humidity</th>
            <th>Status</th>
            <th>Added</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plants as $p): ?>
          <tr>
            <td><?= $p['plant_id'] ?></td>
            <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
            <td><?= htmlspecialchars($p['username']) ?></td>
            <td><?= htmlspecialchars($p['city']) ?></td>
            <td><?= $p['reading_count'] ?></td>
            <td><?= $p['last_humidity'] ? $p['last_humidity'] . '%' : '—' ?></td>
            <td>
              <?php if ($p['last_status']): ?>
              <span class="badge badge-<?= strtolower($p['last_status']) ?>"><?= $p['last_status'] ?></span>
              <?php else: ?>
              <span style="color:var(--text-3);font-size:.78rem;">No data</span>
              <?php endif; ?>
            </td>
            <td><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
            <td>
              <a href="manage_plants.php?action=delete&id=<?= $p['plant_id'] ?>"
                 class="btn btn-sm btn-danger"
                 onclick="return confirm('Delete <?= htmlspecialchars($p['plant_name']) ?> and all its readings?')">Delete</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>