<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
$ssl  = "/var/www/html/isrgrootx1.pem";

function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("TiDB Connection Failed: " . $conn->connect_error);
    }
    return $conn;
}

// --- PULL LOGIC (Student Sync) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $email = $_GET['tutor_email'];
        $conn = getTiDBConnection();
        $stmt = $conn->prepare("SELECT q_id, activity_name, question_text, correct_answer FROM local_questions WHERE tutor_email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
       
        $data = [];
        while($row = $res->fetch_assoc()) { $data[] = $row; }
       
        if(count($data) > 0) {
            echo json_encode(["status" => "success", "data" => $data]);
        } else {
            echo json_encode(["status" => "error", "message" => "No activities found for this email"]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- PUSH LOGIC (Insert Form) ---
$input = json_decode(file_get_contents("php://input"), true);
if ($input) {
    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        $stmt->bind_param("sssssiss", $input['q_id'], $input['question_text'], $input['correct_answer'], $input['activity_type'], $input['activity_name'], $version, $input['tutor_name'], $input['tutor_email']);
       
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

// --- DEFAULT STATUS ---
echo json_encode(["status" => "online", "service" => "ASL API"]);
