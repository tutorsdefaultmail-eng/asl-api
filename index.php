<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

error_reporting(0); 
ini_set('display_errors', 0);

// --- 1. FIXED CONFIGURATION ---
function getTiDBConnection() {
    // We define these INSIDE to avoid "Global" scope issues on some servers
    $host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
    $port = 4000;
    $user = '4UEUqD3k7NuvmvP.root';
    $db   = 'signlms';
    $pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
    $ssl  = __DIR__ . "/isrgrootx1.pem"; 

    if (!file_exists($ssl)) { 
        throw new Exception("SSL Certificate file missing at " . $ssl); 
    }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    // Attempt connection with explicit variables
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    
    return $conn;
}

// --- 2. PULL LOGIC (GET) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $email = trim($_GET['tutor_email']);
        $conn = getTiDBConnection();
        
        $sql = "SELECT q_id, activity_name, question_text, correct_answer 
                FROM local_questions 
                WHERE tutor_email = ? 
                   OR tutor_name = ? 
                   OR ( (tutor_email IS NULL OR tutor_email = '') AND (tutor_name IS NULL OR tutor_name = '') )";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $data = [];
        while($row = $res->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 3. PUSH LOGIC (POST) ---
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

echo json_encode(["status" => "online", "message" => "Send tutor_email to sync"]);
