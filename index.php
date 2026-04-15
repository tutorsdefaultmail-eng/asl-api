<?php
// --- 1. HEADERS & CORS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// --- 2. CONFIGURATION ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = '://tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
$ssl  = "/var/www/html/isrgrootx1.pem";

function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    // Use mysqli_connect_error() for better debugging
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("TiDB Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// --- 3. PULL LOGIC (GET) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $email = trim($_GET['tutor_email']); // Remove accidental spaces
        $conn = getTiDBConnection();
        $data = [];

        // Query: Match tutor email OR tutor name OR Shared (empty owner)
        $sql = "SELECT q_id, activity_name, question_text, correct_answer, tutor_name, tutor_email 
                FROM local_questions 
                WHERE tutor_email = ? 
                   OR tutor_name = ? 
                   OR ( (tutor_email IS NULL OR tutor_email = '') AND (tutor_name IS NULL OR tutor_name = '') )";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while($row = $res->fetch_assoc()) { 
            $data[] = $row; 
        }

        if(count($data) > 0) {
            echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        } else {
            echo json_encode(["status" => "error", "message" => "No activities found", "searched_for" => $email]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 4. PUSH LOGIC (POST) ---
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        $stmt->bind_param("sssssiss", 
            $input['q_id'], $input['question_text'], $input['correct_answer'], 
            $input['activity_type'], $input['activity_name'], $version, 
            $input['tutor_name'], $input['tutor_email']
        );
       
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "msg" => "Synced to Cloud"]);
        } else {
            echo json_encode(["status" => "error", "msg" => $stmt->error]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 5. DEFAULT STATUS ---
echo json_encode([
    "status" => "online", 
    "service" => "ASL API",
    "endpoint" => "https://asl-tutor-api.onrender.com"
]);
