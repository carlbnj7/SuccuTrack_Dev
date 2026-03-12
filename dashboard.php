<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config.php';

$uid = $_SESSION['user_id'];

// Fetch this user's plant
$stmt = $pdo->prepare("SELECT * FROM plants WHERE user_id = ? LIMIT 1");
$stmt->execute([$uid]);
$plant = $stmt->fetch();

$latest = null;
$stats  = ['total' => 0, 'dry' => 0, 'ideal' => 0, 'humid' => 0];

if ($plant) {
    $pid = $plant['plant_id'];

    $q = $pdo->prepare("
        SELECT h.humidity_percent, h.status, h.recorded_at
        FROM humidity h
        JOIN user_logs ul ON h.humidity_id = ul.humidity_id
        WHERE h.plant_id = ? AND ul.user_id = ?
        ORDER BY h.recorded_at DESC LIMIT 1
    ");
    $q->execute([$pid, $uid]);
    $latest = $q->fetch();

    $s = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=?");
    $s->execute([$uid, $pid]); $stats['total'] = $s->fetchColumn();

    $s2 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Dry'");
    $s2->execute([$uid, $pid]); $stats['dry'] = $s2->fetchColumn();

    $s3 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Ideal'");
    $s3->execute([$uid, $pid]); $stats['ideal'] = $s3->fetchColumn();

    $s4 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Humid'");
    $s4->execute([$uid, $pid]); $stats['humid'] = $s4->fetchColumn();
}

// All logs for this user
$logs = $pdo->prepare("
    SELECT h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    JOIN user_logs ul ON h.humidity_id = ul.humidity_id
    WHERE ul.user_id = ?
    ORDER BY h.recorded_at DESC LIMIT 50
");
$logs->execute([$uid]);
$allLogs = $logs->fetchAll();

$statusClass = $latest ? strtolower($latest['status']) : 'none';

$tips = [
    'Dry'   => ['💧 Water immediately — give a thorough soak and let it drain fully.',
                '☀️ Too much direct sun speeds up drying — consider partial shade.',
                '🪴 Check if soil is pulling away from the pot edges.'],
    'Ideal' => ['✅ Perfect conditions! Maintain your current watering schedule.',
                '🌿 A diluted succulent fertiliser will boost growth right now.',
                '🔄 Rotate the pot a quarter turn weekly for even sun exposure.'],
    'Humid' => ['🚫 Stop watering until the top 2cm of soil is completely dry.',
                '💨 Move to a well-ventilated area to reduce excess moisture.',
                '🪨 Ensure the pot has drainage holes to prevent root rot.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack</div>
  <div class="nav-links">
    <span>Hi, <?= htmlspecialchars($_SESSION['username']) ?></span>
    <a href="logout.php" class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if (!$plant): ?>
    <div class="card"><p class="empty-msg">No plant assigned to your account. Contact your admin.</p></div>
  <?php else: ?>

  <!-- Stats Row -->
  <div class="stats-row">
    <div class="stat-card stat-total">
      <div class="stat-num" id="stat-total"><?= $stats['total'] ?></div>
      <div class="stat-label">📊 Total Readings</div>
    </div>
    <div class="stat-card stat-dry">
      <div class="stat-num" id="stat-dry"><?= $stats['dry'] ?></div>
      <div class="stat-label">🏜️ Dry</div>
    </div>
    <div class="stat-card stat-ideal">
      <div class="stat-num" id="stat-ideal"><?= $stats['ideal'] ?></div>
      <div class="stat-label">✅ Ideal</div>
    </div>
    <div class="stat-card stat-humid">
      <div class="stat-num" id="stat-humid"><?= $stats['humid'] ?></div>
      <div class="stat-label">💧 Humid</div>
    </div>
  </div>

  <!-- Plant Card -->
  <div class="plant-card plant-border-<?= $statusClass ?>" id="card-main">
    <div class="plant-header">
      <div>
        <div class="plant-name">🪴 <?= htmlspecialchars($plant['plant_name']) ?></div>
        <div class="plant-city">📍 <?= htmlspecialchars($plant['city']) ?> &nbsp;|&nbsp; IoT Device #<?= $plant['plant_id'] ?></div>
      </div>
      <div style="text-align:right;" id="header-reading">
        <?php if ($latest): ?>
        <div class="reading-value reading-<?= $statusClass ?>">
          <?= $latest['humidity_percent'] ?><span class="reading-unit">%</span>
        </div>
        <span class="badge badge-<?= $statusClass ?> badge-lg"><?= $latest['status'] ?></span>
        <?php else: ?>
        <div class="reading-empty">No reading yet</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Last reading info -->
    <div id="reading-info">
      <?php if ($latest): ?>
      <div class="weather-info">
        <span>📍 <?= htmlspecialchars($plant['city']) ?></span>
        <span>🕐 <?= date('M d, Y H:i:s', strtotime($latest['recorded_at'])) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fetch Button -->
    <div style="margin-top:16px;">
      <button class="btn btn-primary btn-sim" id="fetchBtn"
              onclick="fetchReading(<?= $plant['plant_id'] ?>)">
        🌐 Fetch Live Reading
      </button>
      <div class="sim-status-text" id="fetchStatus"></div>
    </div>

    <!-- Care Tips -->
    <div id="tips-main">
      <?php if ($latest && isset($tips[$latest['status']])): ?>
      <div class="plant-tips plant-tips-<?= $statusClass ?>">
        <?php foreach ($tips[$latest['status']] as $tip): ?>
          <div class="plant-tip"><?= $tip ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detection Records -->
  <div class="card">
    <h2>📋 Detection Records <span class="user-count" id="recordCount"><?= count($allLogs) ?></span></h2>
    <p class="subtitle">All humidity readings from your IoT device</p>
    <?php if (empty($allLogs)): ?>
      <p class="empty-msg">No records yet. Click Fetch Live Reading above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="det-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Humidity %</th>
            <th>Status</th>
            <th>Date &amp; Time</th>
          </tr>
        </thead>
        <tbody id="logsTbody">
          <?php foreach ($allLogs as $i => $log): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= $log['humidity_percent'] ?>%</strong></td>
            <td><span class="badge badge-<?= strtolower($log['status']) ?>"><?= $log['status'] ?></span></td>
            <td><?= date('M d, Y H:i:s', strtotime($log['recorded_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<script>
const tipsData = {
  'Dry':   ['💧 Water immediately — give a thorough soak and let it drain fully.',
             '☀️ Too much direct sun speeds up drying — consider partial shade.',
             '🪴 Check if soil is pulling away from the pot edges.'],
  'Ideal': ['✅ Perfect conditions! Maintain your current watering schedule.',
             '🌿 A diluted succulent fertiliser will boost growth right now.',
             '🔄 Rotate the pot a quarter turn weekly for even sun exposure.'],
  'Humid': ['🚫 Stop watering until the top 2cm of soil is completely dry.',
             '💨 Move to a well-ventilated area to reduce excess moisture.',
             '🪨 Ensure the pot has drainage holes to prevent root rot.'],
};

function fetchReading(pid) {
  const btn    = document.getElementById('fetchBtn');
  const status = document.getElementById('fetchStatus');
  btn.disabled    = true;
  btn.textContent = '⏳ Fetching from API...';
  status.textContent = '';

  const form = new FormData();
  form.append('plant_id', pid);

  fetch('simulate.php', { method: 'POST', body: form })
    .then(r => r.json())
    .then(data => {
      btn.disabled    = false;
      btn.textContent = '🌐 Fetch Live Reading';

      if (!data.success) {
        status.textContent = '⚠️ ' + data.error;
        status.style.color = 'var(--dry)';
        return;
      }

      status.textContent = '✅ Reading saved — ' + data.detected_at;
      status.style.color = 'var(--ideal)';

      const s = data.status.toLowerCase();

      // Update card border
      const card = document.getElementById('card-main');
      card.className = card.className.replace(/plant-border-\w+/, 'plant-border-' + s);

      // Update header reading
      document.getElementById('header-reading').innerHTML = `
        <div class="reading-value reading-${s}">${data.humidity}<span class="reading-unit">%</span></div>
        <span class="badge badge-${s} badge-lg">${data.status}</span>
      `;

      // Update reading info strip
      document.getElementById('reading-info').innerHTML = `
        <div class="weather-info">
          <span>📍 ${data.city}</span>
          <span>🌤️ ${data.description}</span>
          <span>🕐 ${data.detected_at}</span>
        </div>
      `;

      // Update stats
      document.getElementById('stat-total').textContent = data.total;
      document.getElementById('stat-dry').textContent   = data.dry;
      document.getElementById('stat-ideal').textContent = data.ideal;
      document.getElementById('stat-humid').textContent = data.humid;
      document.getElementById('recordCount').textContent = data.total;

      // Update care tips
      const tipsDiv = document.getElementById('tips-main');
      tipsDiv.innerHTML = `<div class="plant-tips plant-tips-${s}">` +
        (tipsData[data.status] || []).map(t => `<div class="plant-tip">${t}</div>`).join('') +
        `</div>`;

      // Add new row to top of table
      const tbody = document.getElementById('logsTbody');
      if (tbody) {
        const count = tbody.querySelectorAll('tr').length;
        tbody.insertAdjacentHTML('afterbegin', `<tr>
          <td>${count + 1}</td>
          <td><strong>${data.humidity}%</strong></td>
          <td><span class="badge badge-${s}">${data.status}</span></td>
          <td>${data.detected_at}</td>
        </tr>`);
      }

      // Pulse animation
      const r = document.getElementById('reading-info');
      r.classList.add('pulse');
      setTimeout(() => r.classList.remove('pulse'), 600);
    })
    .catch(() => {
      btn.disabled    = false;
      btn.textContent = '🌐 Fetch Live Reading';
      status.textContent = '⚠️ Server error. Try again.';
      status.style.color = 'var(--dry)';
    });
}
</script>
</body>
</html>
