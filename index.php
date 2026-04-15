<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// Prevent HTML error output from breaking JSON
error_reporting(0);
ini_set('display_errors', 0);

$host = '://tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
$ssl  = __DIR__ . "/isrgrootx1.pem"; 

function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    if (!file_exists($ssl)) { throw new Exception("SSL Missing"); }
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// --- PULL LOGIC (The updated part) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $email = trim($_GET['tutor_email']);
        $conn = getTiDBConnection();
        $data = [];

        // This single query gets: 
        // 1. Everything matching the email 
        // 2. Everything matching the name
        // 3. Everything where both are empty (The "Global" questions)
        $sql = "SELECT q_id, activity_name, question_text, correct_answer 
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

        echo json_encode(["status" => "success", "data" => $data]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- PUSH LOGIC (Insert) ---
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
            echo json_encode(["status" => "success", "msg" => "Synced"]);
        } else {
            echo json_encode(["status" => "error", "msg" => $stmt->error]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "ready"]);
