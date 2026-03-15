<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = "";

if (($_GET['action'] ?? '') === 'delete_log' && isset($_GET['log_id'], $_GET['humidity_id'])) {
    $pdo->prepare("DELETE FROM user_logs WHERE log_id = ?")->execute([$_GET['log_id']]);
    $pdo->prepare("DELETE FROM humidity WHERE humidity_id = ?")->execute([$_GET['humidity_id']]);
    header("Location: admin_dashboard.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "Record deleted successfully.";

$users  = $pdo->query("SELECT user_id, username, email, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll();
$stats  = array_column($counts, 'total', 'status');
$total  = array_sum(array_column($counts, 'total'));

$recent = $pdo->query("
    SELECT ul.log_id, ul.humidity_id, u.username,
           p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM user_logs ul
    JOIN users u    ON ul.user_id     = u.user_id
    JOIN humidity h ON ul.humidity_id = h.humidity_id
    LEFT JOIN plants p ON h.plant_id  = p.plant_id
    ORDER BY h.recorded_at DESC LIMIT 50
")->fetchAll();

// Latest reading per plant for map markers
$plantLatest = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, u.username,
           h.humidity_percent, h.status, h.recorded_at
    FROM plants p
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN humidity h ON h.humidity_id = (
        SELECT h2.humidity_id FROM humidity h2
        WHERE h2.plant_id = p.plant_id
        ORDER BY h2.recorded_at DESC LIMIT 1
    )
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
</head>
<body>

<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack <span class="admin-badge">Admin</span></div>
  <div class="nav-links">
    <span class="nav-user">Hi, <?= htmlspecialchars($_SESSION['username']) ?></span>
    <div class="live-indicator" id="liveIndicator">
      <span class="dot dot-on"></span> Live
    </div>
    <a href="manage_plants.php" class="btn btn-sm">Manage Plants</a>
    <a href="manage_users.php" class="btn btn-sm">Manage Users</a>
    <a href="logout.php" class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card stat-total">
      <div class="stat-num" id="stat-total"><?= $total ?></div>
      <div class="stat-label">📊 Total</div>
    </div>
    <div class="stat-card stat-dry">
      <div class="stat-num" id="stat-dry"><?= $stats['Dry'] ?? 0 ?></div>
      <div class="stat-label">🏜️ Dry</div>
    </div>
    <div class="stat-card stat-ideal">
      <div class="stat-num" id="stat-ideal"><?= $stats['Ideal'] ?? 0 ?></div>
      <div class="stat-label">✅ Ideal</div>
    </div>
    <div class="stat-card stat-humid">
      <div class="stat-num" id="stat-humid"><?= $stats['Humid'] ?? 0 ?></div>
      <div class="stat-label">💧 Humid</div>
    </div>
    <div class="stat-card stat-users">
      <div class="stat-num"><?= count($users) ?></div>
      <div class="stat-label">👤 Users</div>
    </div>
  </div>

  <!-- Two-column: Map + Device Summary -->
  <div class="two-col">

    <!-- Map -->
    <div class="card map-card">
      <h2>📍 Device Locations</h2>
      <p class="subtitle">All IoT sensors on the map</p>
      <div id="map"></div>
    </div>

    <!-- Device Summary -->
    <div class="card device-card">
      <h2>🌿 Device Status</h2>
      <p class="subtitle">Latest reading per device</p>
      <div class="device-list">
        <?php foreach ($plantLatest as $p): ?>
        <?php $s = strtolower($p['status'] ?? 'none'); ?>
        <div class="device-item device-item-<?= $s ?>">
          <div class="device-top">
            <div>
              <div class="device-name">🪴 <?= htmlspecialchars($p['plant_name']) ?></div>
              <div class="device-user">👤 <?= htmlspecialchars($p['username']) ?></div>
            </div>
            <div class="device-reading">
              <?php if ($p['humidity_percent']): ?>
                <span class="device-val"><?= $p['humidity_percent'] ?>%</span>
                <span class="badge badge-<?= $s ?>"><?= $p['status'] ?></span>
              <?php else: ?>
                <span class="no-data">No data</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($p['recorded_at']): ?>
          <div class="device-time">🕐 <?= date('M d, Y H:i', strtotime($p['recorded_at'])) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Live Readings Table -->
  <div class="card">
    <div class="card-header-row">
      <h2>Live Readings <span class="user-count" id="readingCount"><?= count($recent) ?></span></h2>
      <span class="auto-refresh-note">Auto-refreshes every 5s</span>
    </div>
    <div id="logsTable">
      <?php if (empty($recent)): ?>
        <p class="empty-msg">No readings yet.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="det-table">
          <thead>
            <tr>
              <th>User</th>
              <th>Plant / Device</th>
              <th>Humidity</th>
              <th>Status</th>
              <th>Recorded At</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['username']) ?></td>
              <td><?= htmlspecialchars($r['plant_name'] ?? '—') ?></td>
              <td><strong><?= $r['humidity_percent'] ?>%</strong></td>
              <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
              <td><?= $r['recorded_at'] ?></td>
              <td>
                <a href="admin_dashboard.php?action=delete_log&log_id=<?= $r['log_id'] ?>&humidity_id=<?= $r['humidity_id'] ?>"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('Delete this record?')">Delete</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Registered Users -->
  <div class="card">
    <h2>Registered Users <span class="user-count"><?= count($users) ?></span></h2>
    <div class="table-wrap">
      <table class="det-table">
        <thead>
          <tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= $u['user_id'] ?></td>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
            <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            <td>
              <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
              <a href="delete_user.php?id=<?= $u['user_id'] ?>"
                 class="btn btn-sm btn-danger"
                 onclick="return confirm('Delete <?= htmlspecialchars($u['username']) ?>?')">Delete</a>
              <?php else: ?>
              <span class="you-label">You</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// ── Leaflet Map ──
const map = L.map('map').setView([12.8797, 121.7740], 13);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors',
  maxZoom: 18
}).addTo(map);

const plants = <?= json_encode($plantLatest) ?>;
const colors = { dry: '#b85c2a', ideal: '#4a7c59', humid: '#3a6fa8', '': '#96aea0' };
const allMarkers = [];

function makePinIcon(color) {
  return L.divIcon({
    className: '',
    html: `<div style="
      background:${color};
      width:34px;height:34px;
      border-radius:50% 50% 50% 0;
      transform:rotate(-45deg);
      border:3px solid white;
      box-shadow:0 2px 8px rgba(0,0,0,.25);
    "></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 34]
  });
}

// Geocode a city string via Nominatim (rate-limited: 1 request/sec)
async function geocodeCity(city) {
  const url = `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(city)}&format=json&limit=1`;
  try {
    const res  = await fetch(url, { headers: { 'Accept-Language': 'en' } });
    const data = await res.json();
    if (data && data.length > 0) {
      return { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon), display: data[0].display_name };
    }
  } catch (e) { /* network error */ }
  return null;
}

// Sleep helper for Nominatim rate limit (1 req/s)
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function addAllMarkers() {
  // Deduplicate cities so we don't geocode the same city twice
  const cityCache = {};

  for (let i = 0; i < plants.length; i++) {
    const p      = plants[i];
    const status = (p.status || '').toLowerCase();
    const color  = colors[status] || colors[''];
    const city   = p.city;

    let coords = cityCache[city];

    if (!coords) {
      if (i > 0) await sleep(1100); // Nominatim rate limit: 1 req/sec
      coords = await geocodeCity(city);
      if (coords) cityCache[city] = coords;
    }

    if (!coords) {
      console.warn('Could not geocode city:', city);
      continue;
    }

    // Tiny random offset (±0.0015°) so pins on the same city don't fully overlap
    const jitter = () => (Math.random() - 0.5) * 0.003;
    const lat = coords.lat + (cityCache[city]._used ? jitter() : 0);
    const lng = coords.lng + (cityCache[city]._used ? jitter() : 0);
    cityCache[city]._used = true;

    const humidity = p.humidity_percent ? p.humidity_percent + '%' : 'No data';
    const time     = p.recorded_at ? new Date(p.recorded_at).toLocaleString() : '—';

    const marker = L.marker([lat, lng], { icon: makePinIcon(color) })
      .addTo(map)
      .bindPopup(`
        <div style="font-family:'DM Sans',sans-serif;min-width:160px;padding:4px 0;">
          <div style="font-weight:600;font-size:.9rem;margin-bottom:4px;">🪴 ${p.plant_name}</div>
          <div style="font-size:.78rem;color:#506358;margin-bottom:2px;">👤 ${p.username}</div>
          <div style="font-size:.78rem;color:#506358;margin-bottom:6px;">📍 ${city}</div>
          <div style="font-size:1.1rem;font-weight:700;color:${color};">${humidity}</div>
          <div style="font-size:.72rem;color:#96aea0;margin-top:2px;">${time}</div>
        </div>
      `);

    allMarkers.push(marker);
  }

  // Fit map to show all markers if any were added
  if (allMarkers.length > 0) {
    const group = L.featureGroup(allMarkers);
    map.fitBounds(group.getBounds().pad(0.15), { maxZoom: 13 });
  }
}

addAllMarkers();

// ── Auto-refresh logs every 5s ──
function refreshLogs() {
  fetch('get_logs.php')
    .then(r => r.json())
    .then(data => {
      const dry   = parseInt(data.stats['Dry']   || 0);
      const ideal = parseInt(data.stats['Ideal'] || 0);
      const humid = parseInt(data.stats['Humid'] || 0);
      document.getElementById('stat-dry').textContent   = dry;
      document.getElementById('stat-ideal').textContent = ideal;
      document.getElementById('stat-humid').textContent = humid;
      document.getElementById('stat-total').textContent = dry + ideal + humid;
      document.getElementById('readingCount').textContent = data.logs.length;

      if (!data.logs.length) {
        document.getElementById('logsTable').innerHTML = '<p class="empty-msg">No readings yet.</p>';
        return;
      }

      let html = `<div class="table-wrap"><table class="det-table">
        <thead><tr>
          <th>User</th><th>Plant / Device</th><th>Humidity</th>
          <th>Status</th><th>Recorded At</th><th>Action</th>
        </tr></thead><tbody>`;
      data.logs.forEach(r => {
        const s = r.status.toLowerCase();
        html += `<tr>
          <td>${esc(r.username)}</td>
          <td>${esc(r.plant_name || '—')}</td>
          <td><strong>${r.humidity_percent}%</strong></td>
          <td><span class="badge badge-${s}">${r.status}</span></td>
          <td>${r.recorded_at}</td>
          <td><a href="admin_dashboard.php?action=delete_log&log_id=${r.log_id}&humidity_id=${r.humidity_id}"
                 class="btn btn-sm btn-danger"
                 onclick="return confirm('Delete?')">Delete</a></td>
        </tr>`;
      });
      html += '</tbody></table></div>';
      document.getElementById('logsTable').innerHTML = html;
      document.getElementById('liveIndicator').innerHTML = '<span class="dot dot-on"></span> Live';
    })
    .catch(() => {
      document.getElementById('liveIndicator').innerHTML = '<span class="dot dot-off"></span> Offline';
    });
}

function esc(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(str));
  return d.innerHTML;
}

setInterval(refreshLogs, 5000);
</script>

</body>
</html>