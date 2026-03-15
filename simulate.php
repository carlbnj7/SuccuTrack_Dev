<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

$conn    = new mysqli("localhost", "root", "", "succutrack");
$user_id = $_SESSION['user_id'];
$plant_id = intval($_POST['plant_id'] ?? 0);

if (!$plant_id) {
    echo json_encode(['success' => false, 'error' => 'No plant specified.']); exit;
}

// Verify plant belongs to this user
$check = $conn->prepare("SELECT * FROM plants WHERE plant_id = ? AND user_id = ?");
$check->bind_param("ii", $plant_id, $user_id);
$check->execute();
$plant = $check->get_result()->fetch_assoc();
if (!$plant) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']); exit;
}

$api_key = '10e1cd7f9a2dc254e99c16980370adbf';

$city     = urlencode($plant['city']);
$url      = "https://api.openweathermap.org/data/2.5/weather?q={$city}&appid={$api_key}&units=metric";
$response = @file_get_contents($url);

if (!$response) {
    echo json_encode(['success' => false, 'error' => 'Cannot reach OpenWeatherMap. Check your internet.']);
    exit;
}

$weather = json_decode($response, true);

if (($weather['cod'] ?? '') == 401) {
    echo json_encode(['success' => false, 'error' => 'Invalid API key. Check simulate.php.']);
    exit;
}

if (!isset($weather['main']['humidity'])) {
    echo json_encode(['success' => false, 'error' => 'Weather data unavailable. Try again shortly.']);
    exit;
}

// Get humidity from API
$humidity    = floatval($weather['main']['humidity']);
$city_name   = $weather['name'] ?? $plant['city'];
$country     = $weather['sys']['country'] ?? '';
$description = ucfirst($weather['weather'][0]['description'] ?? '');

// Classify humidity for succulents
if ($humidity < 20)      $status = 'Dry';
elseif ($humidity <= 60) $status = 'Ideal';
else                     $status = 'Humid';

// Save to database
$stmt = $conn->prepare("INSERT INTO humidity (plant_id, humidity_percent, status) VALUES (?,?,?)");
$stmt->bind_param("ids", $plant_id, $humidity, $status);
$stmt->execute();
$humidity_id = $conn->insert_id;

$stmt2 = $conn->prepare("INSERT INTO user_logs (user_id, humidity_id) VALUES (?,?)");
$stmt2->bind_param("ii", $user_id, $humidity_id);
$stmt2->execute();

// Stats for this user's plant
$total = $conn->query("SELECT COUNT(*) as t FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=$user_id AND h.plant_id=$plant_id")->fetch_assoc()['t'];
$dry   = $conn->query("SELECT COUNT(*) as t FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=$user_id AND h.plant_id=$plant_id AND h.status='Dry'")->fetch_assoc()['t'];
$ideal = $conn->query("SELECT COUNT(*) as t FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=$user_id AND h.plant_id=$plant_id AND h.status='Ideal'")->fetch_assoc()['t'];
$humid = $conn->query("SELECT COUNT(*) as t FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=$user_id AND h.plant_id=$plant_id AND h.status='Humid'")->fetch_assoc()['t'];

echo json_encode([
    'success'     => true,
    'humidity'    => $humidity,
    'status'      => $status,
    'city'        => "$city_name, $country",
    'description' => $description,
    'detected_at' => date('Y-m-d H:i:s'),
    'total'       => $total,
    'dry'         => $dry,
    'ideal'       => $ideal,
    'humid'       => $humid,
]);