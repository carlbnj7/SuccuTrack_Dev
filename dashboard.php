<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config.php';

$uid = $_SESSION['user_id'];

// Fetch ALL plants for this user
$stmt = $pdo->prepare("SELECT * FROM plants WHERE user_id = ? ORDER BY plant_id ASC");
$stmt->execute([$uid]);
$plants = $stmt->fetchAll();

// For each plant, get latest reading + stats + last 20 readings for chart
$plantData = [];
foreach ($plants as $plant) {
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

    // Stats
    $s = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=?");
    $s->execute([$uid, $pid]); $total = $s->fetchColumn();

    $s2 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Dry'");
    $s2->execute([$uid, $pid]); $dry = $s2->fetchColumn();

    $s3 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Ideal'");
    $s3->execute([$uid, $pid]); $ideal = $s3->fetchColumn();

    $s4 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Humid'");
    $s4->execute([$uid, $pid]); $humid = $s4->fetchColumn();

    // Last 50 readings for combined chart (oldest first)
    $chartQ = $pdo->prepare("
        SELECT h.humidity_percent, h.status, h.recorded_at
        FROM humidity h
        JOIN user_logs ul ON h.humidity_id = ul.humidity_id
        WHERE h.plant_id = ? AND ul.user_id = ?
        ORDER BY h.recorded_at DESC LIMIT 50
    ");
    $chartQ->execute([$pid, $uid]);
    $chartRows = array_reverse($chartQ->fetchAll());

    $chartLabels   = array_map(fn($r) => date('M d H:i', strtotime($r['recorded_at'])), $chartRows);
    $chartValues   = array_map(fn($r) => (float)$r['humidity_percent'], $chartRows);
    $chartStatuses = array_map(fn($r) => $r['status'], $chartRows);

    $plantData[] = [
        'plant'       => $plant,
        'latest'      => $latest,
        'stats'       => compact('total','dry','ideal','humid'),
        'chartLabels'   => $chartLabels,
        'chartValues'   => $chartValues,
        'chartStatuses' => $chartStatuses,
    ];
}

// All logs for this user (combined)
$logs = $pdo->prepare("
    SELECT p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    JOIN user_logs ul ON h.humidity_id = ul.humidity_id
    JOIN plants p ON h.plant_id = p.plant_id
    WHERE ul.user_id = ?
    ORDER BY h.recorded_at DESC LIMIT 100
");
$logs->execute([$uid]);
$allLogs = $logs->fetchAll();

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
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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

<?php if (empty($plants)): ?>
  <div class="card"><p class="empty-msg">No plants assigned to your account. Contact your admin.</p></div>
<?php else: ?>

  <!-- Summary stats across all plants -->
  <?php
    $sumTotal = array_sum(array_column(array_column($plantData, 'stats'), 'total'));
    $sumDry   = array_sum(array_column(array_column($plantData, 'stats'), 'dry'));
    $sumIdeal = array_sum(array_column(array_column($plantData, 'stats'), 'ideal'));
    $sumHumid = array_sum(array_column(array_column($plantData, 'stats'), 'humid'));
  ?>
  <div class="stats-row" style="grid-template-columns: repeat(5,1fr);">
    <div class="stat-card stat-plants">
      <div class="stat-num"><?= count($plants) ?></div>
      <div class="stat-label">🪴 My Plants</div>
    </div>
    <div class="stat-card stat-total">
      <div class="stat-num"><?= $sumTotal ?></div>
      <div class="stat-label">📊 Total Readings</div>
    </div>
    <div class="stat-card stat-dry">
      <div class="stat-num"><?= $sumDry ?></div>
      <div class="stat-label">🏜️ Dry</div>
    </div>
    <div class="stat-card stat-ideal">
      <div class="stat-num"><?= $sumIdeal ?></div>
      <div class="stat-label">✅ Ideal</div>
    </div>
    <div class="stat-card stat-humid">
      <div class="stat-num"><?= $sumHumid ?></div>
      <div class="stat-label">💧 Humid</div>
    </div>
  </div>

  <!-- One card per plant -->
  <?php foreach ($plantData as $idx => $pd):
    $plant       = $pd['plant'];
    $latest      = $pd['latest'];
    $stats       = $pd['stats'];
    $chartLabels   = $pd['chartLabels'];
    $chartValues   = $pd['chartValues'];
    $chartStatuses = $pd['chartStatuses'];
    $pid         = $plant['plant_id'];
    $statusClass = $latest ? strtolower($latest['status']) : 'none';
    $chartId     = 'chart-' . $pid;
    $cardId      = 'card-' . $pid;
  ?>
  <div class="plant-card plant-border-<?= $statusClass ?>" id="<?= $cardId ?>">

    <!-- Plant header -->
    <div class="plant-header">
      <div>
        <div class="plant-name">🪴 <?= htmlspecialchars($plant['plant_name']) ?></div>
        <div class="plant-city">📍 <?= htmlspecialchars($plant['city']) ?> &nbsp;|&nbsp; IoT Device #<?= $pid ?></div>
      </div>
      <div style="text-align:right;" id="header-reading-<?= $pid ?>">
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

    <!-- Per-plant mini stats -->
    <div class="plant-mini-stats">
      <div class="mini-stat"><span class="mini-val" id="stat-total-<?= $pid ?>"><?= $stats['total'] ?></span><span class="mini-lbl">Total</span></div>
      <div class="mini-stat mini-dry"><span class="mini-val" id="stat-dry-<?= $pid ?>"><?= $stats['dry'] ?></span><span class="mini-lbl">Dry</span></div>
      <div class="mini-stat mini-ideal"><span class="mini-val" id="stat-ideal-<?= $pid ?>"><?= $stats['ideal'] ?></span><span class="mini-lbl">Ideal</span></div>
      <div class="mini-stat mini-humid"><span class="mini-val" id="stat-humid-<?= $pid ?>"><?= $stats['humid'] ?></span><span class="mini-lbl">Humid</span></div>
    </div>

    <!-- Last reading info -->
    <div id="reading-info-<?= $pid ?>">
      <?php if ($latest): ?>
      <div class="weather-info">
        <span>📍 <?= htmlspecialchars($plant['city']) ?></span>
        <span>🕐 <?= date('M d, Y H:i:s', strtotime($latest['recorded_at'])) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fetch Button -->
    <div style="margin-top:16px;">
      <button class="btn btn-primary btn-sim" id="fetchBtn-<?= $pid ?>"
              onclick="fetchReading(<?= $pid ?>)">
        🌐 Fetch Live Reading
      </button>
      <div class="sim-status-text" id="fetchStatus-<?= $pid ?>"></div>
    </div>

    <!-- Care Tips -->
    <div id="tips-<?= $pid ?>">
      <?php if ($latest && isset($tips[$latest['status']])): ?>
      <div class="plant-tips plant-tips-<?= $statusClass ?>">
        <?php foreach ($tips[$latest['status']] as $tip): ?>
          <div class="plant-tip"><?= $tip ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end plant-card -->

  <?php endforeach; ?>

  <!-- Combined Humidity Log Chart -->
  <?php
    // Build datasets for all plants — collect all unique timestamps as labels
    $allTimestamps = [];
    foreach ($plantData as $pd) {
      foreach ($pd['chartLabels'] as $lbl) $allTimestamps[$lbl] = true;
    }
    ksort($allTimestamps);
    $combinedLabels = array_keys($allTimestamps);
  ?>
  <div class="card">
    <div class="chart-header" style="margin-bottom:14px;">
      <h2 style="margin:0;">📊 Recent Humidity Logs</h2>
      <span class="chart-count">Last 50 readings per plant</span>
    </div>
    <?php
      $hasAnyData = array_reduce($plantData, fn($c, $pd) => $c || !empty($pd['chartValues']), false);
    ?>
    <?php if (!$hasAnyData): ?>
      <p class="empty-msg">No readings yet. Click Fetch Live Reading on any plant above.</p>
    <?php else: ?>
      <div style="position:relative; height:240px;">
        <canvas id="combined-chart"></canvas>
      </div>
      <div class="chart-legend" id="chart-legend"></div>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    const plantDatasets = <?= json_encode(array_map(fn($pd) => [
      'name'     => $pd['plant']['plant_name'],
      'labels'   => $pd['chartLabels'],
      'values'   => $pd['chartValues'],
      'statuses' => $pd['chartStatuses'],
    ], $plantData)) ?>;

    if (!plantDatasets.length) return;

    // Colour palette — one per plant (cycles if >5)
    const PALETTE = [
      { line: '#4a7c59', point: '#4a7c59', bg: 'rgba(74,124,89,0.08)'  },
      { line: '#3a6fa8', point: '#3a6fa8', bg: 'rgba(58,111,168,0.08)' },
      { line: '#b85c2a', point: '#b85c2a', bg: 'rgba(184,92,42,0.08)'  },
      { line: '#7a4fa8', point: '#7a4fa8', bg: 'rgba(122,79,168,0.08)' },
      { line: '#a85c7a', point: '#a85c7a', bg: 'rgba(168,92,122,0.08)' },
    ];

    // Status dot colours for per-point colouring
    const STATUS_PT = { Dry:'#b85c2a', Ideal:'#4a7c59', Humid:'#3a6fa8' };

    // Collect all unique labels in chronological order
    const labelSet = new Set();
    plantDatasets.forEach(pd => pd.labels.forEach(l => labelSet.add(l)));
    const allLabels = Array.from(labelSet).sort();

    // Build one dataset per plant — null for missing timestamps so Chart.js spans gaps
    const datasets = plantDatasets.map((pd, i) => {
      const col = PALETTE[i % PALETTE.length];
      const labelMap = {};
      pd.labels.forEach((l, idx) => { labelMap[l] = { v: pd.values[idx], s: pd.statuses[idx] }; });

      const data      = allLabels.map(l => labelMap[l]?.v ?? null);
      const ptColors  = allLabels.map(l => STATUS_PT[labelMap[l]?.s] || col.point);

      return {
        label: pd.name,
        data,
        borderColor: col.line,
        backgroundColor: col.bg,
        borderWidth: 2,
        fill: false,
        tension: 0.35,
        spanGaps: true,
        pointRadius: allLabels.length > 40 ? 2 : 4,
        pointHoverRadius: 6,
        pointBackgroundColor: ptColors,
        pointBorderColor: '#fff',
        pointBorderWidth: 1.5,
      };
    });

    // Zone bands
    const zoneDry = {
      label: 'Dry zone', data: allLabels.map(() => 20),
      fill: { target: 'origin', above: 'rgba(184,92,42,0.05)' },
      borderWidth: 0.8, borderColor: 'rgba(184,92,42,0.2)',
      borderDash: [4,4], pointRadius: 0, tension: 0
    };
    const zoneHumid = {
      label: 'Humid zone', data: allLabels.map(() => 100),
      fill: { target: { value: 60 }, above: 'rgba(58,111,168,0.05)' },
      borderWidth: 0.8, borderColor: 'rgba(58,111,168,0.2)',
      borderDash: [4,4], pointRadius: 0, tension: 0
    };

    const ctx = document.getElementById('combined-chart');
    if (!ctx) return;

    window._combinedChart = new Chart(ctx, {
      type: 'line',
      data: { labels: allLabels, datasets: [zoneDry, zoneHumid, ...datasets] },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: item => item.datasetIndex >= 2,
            callbacks: {
              title: ctx => ctx[0]?.label || '',
              label: ctx => {
                if (ctx.parsed.y === null) return null;
                const v = ctx.parsed.y;
                const s = v < 20 ? 'Dry' : v <= 60 ? 'Ideal' : 'Humid';
                return ` ${ctx.dataset.label}: ${v}% (${s})`;
              }
            }
          }
        },
        scales: {
          x: {
            ticks: { font:{size:10}, color:'#96aea0', maxRotation:40, maxTicksLimit:10, autoSkip:true },
            grid: { color:'rgba(150,174,160,0.1)' }
          },
          y: {
            min:0, max:100,
            ticks: { font:{size:10}, color:'#96aea0', callback: v => v+'%', stepSize:20 },
            grid: { color:'rgba(150,174,160,0.1)' }
          }
        }
      }
    });

    // Custom legend
    const legend = document.getElementById('chart-legend');
    if (legend) {
      legend.innerHTML = plantDatasets.map((pd, i) => {
        const col = PALETTE[i % PALETTE.length];
        return `<span class="legend-item">
          <span class="legend-dot" style="background:${col.line}"></span>
          ${pd.name}
        </span>`;
      }).join('');
    }
  })();
  </script>

  <!-- Combined Detection Records -->
  <div class="card">
    <h2>📋 All Detection Records <span class="user-count"><?= count($allLogs) ?></span></h2>
    <p class="subtitle">Combined humidity readings from all your IoT devices</p>
    <?php if (empty($allLogs)): ?>
      <p class="empty-msg">No records yet. Click Fetch Live Reading on any plant above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="det-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Plant</th>
            <th>Humidity %</th>
            <th>Status</th>
            <th>Date &amp; Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allLogs as $i => $log): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>🪴 <?= htmlspecialchars($log['plant_name']) ?></td>
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

const chartInstances = {};

const STATUS_COLORS = {
  Dry:   { point: '#b85c2a', line: 'rgba(184,92,42,0.7)',  bg: 'rgba(184,92,42,0.08)' },
  Ideal: { point: '#4a7c59', line: 'rgba(74,124,89,0.7)',  bg: 'rgba(74,124,89,0.08)'  },
  Humid: { point: '#3a6fa8', line: 'rgba(58,111,168,0.7)', bg: 'rgba(58,111,168,0.08)' },
};
const DEFAULT_COLOR = { point: '#96aea0', line: 'rgba(150,174,160,0.5)', bg: 'rgba(150,174,160,0.05)' };
function statusColor(s) { return STATUS_COLORS[s] || DEFAULT_COLOR; }

// Map plant_id → dataset index inside combined chart (index 0/1 are zone bands)
const plantDatasetIndex = {};
<?php foreach ($plantData as $i => $pd): ?>
plantDatasetIndex[<?= $pd['plant']['plant_id'] ?>] = <?= $i + 2 ?>;
<?php endforeach; ?>

function pushToCombinedChart(pid, label, value, status) {
  const chart = window._combinedChart;
  if (!chart) return;

  const dsIdx = plantDatasetIndex[pid];
  if (dsIdx === undefined) return;

  // Add label if it's new
  if (!chart.data.labels.includes(label)) {
    chart.data.labels.push(label);
    // Extend zone bands
    chart.data.datasets[0].data.push(20);
    chart.data.datasets[1].data.push(100);
    // Pad all other plant datasets with null for this new timestamp
    chart.data.datasets.forEach((ds, i) => {
      if (i >= 2) ds.data.push(null);
    });
  }

  // Set the value for this plant at this timestamp
  const labelIdx = chart.data.labels.indexOf(label);
  const ds = chart.data.datasets[dsIdx];
  ds.data[labelIdx] = value;

  // Update per-point colour
  const ptColors = ds.pointBackgroundColor;
  if (Array.isArray(ptColors)) {
    ptColors[labelIdx] = statusColor(status).point;
  }

  chart.update();
}

function fetchReading(pid) {
  const btn    = document.getElementById('fetchBtn-' + pid);
  const status = document.getElementById('fetchStatus-' + pid);
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
      const card = document.getElementById('card-' + pid);
      card.className = card.className.replace(/plant-border-\w+/, 'plant-border-' + s);

      // Update header reading
      document.getElementById('header-reading-' + pid).innerHTML = `
        <div class="reading-value reading-${s}">${data.humidity}<span class="reading-unit">%</span></div>
        <span class="badge badge-${s} badge-lg">${data.status}</span>
      `;

      // Update reading info
      document.getElementById('reading-info-' + pid).innerHTML = `
        <div class="weather-info">
          <span>📍 ${data.city}</span>
          <span>🌤️ ${data.description}</span>
          <span>🕐 ${data.detected_at}</span>
        </div>
      `;

      // Update per-plant mini stats
      document.getElementById('stat-total-' + pid).textContent = data.total;
      document.getElementById('stat-dry-'   + pid).textContent = data.dry;
      document.getElementById('stat-ideal-' + pid).textContent = data.ideal;
      document.getElementById('stat-humid-' + pid).textContent = data.humid;

      // Push new point into combined chart
      const label = data.detected_at.slice(5, 16);
      pushToCombinedChart(pid, label, data.humidity, data.status);

      // Update care tips
      document.getElementById('tips-' + pid).innerHTML =
        `<div class="plant-tips plant-tips-${s}">` +
        (tipsData[data.status] || []).map(t => `<div class="plant-tip">${t}</div>`).join('') +
        `</div>`;

      // Pulse animation
      const ri = document.getElementById('reading-info-' + pid);
      ri.classList.add('pulse');
      setTimeout(() => ri.classList.remove('pulse'), 600);
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