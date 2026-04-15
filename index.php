<?php
// --- 1. HEADERS & CORS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// --- 2. CONFIGURATION ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
$ssl  = "/var/www/html/isrgrootx1.pem";

/**
 * Establish a secure connection to TiDB Cloud
 */
function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("TiDB Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// --- 3. PULL LOGIC (GET REQUEST) ---
// Fetches questions for a specific tutor AND global questions
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $email = $_GET['tutor_email'];
        $conn = getTiDBConnection();
        $data = [];

        // Query A: Get questions belonging to this tutor (by email or name)
        $stmt = $conn->prepare("
            SELECT q_id, activity_name, question_text, correct_answer, tutor_name, tutor_email 
            FROM local_questions 
            WHERE tutor_email = ? OR tutor_name = ?
        ");
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) { 
            $data[] = $row; 
        }
        $stmt->close();

        // Query B: Get "Shared" questions (where tutor info is empty)
        $stmt2 = $conn->prepare("
            SELECT q_id, activity_name, question_text, correct_answer, tutor_name, tutor_email 
            FROM local_questions 
            WHERE (tutor_email IS NULL OR tutor_email = '') 
              AND (tutor_name IS NULL OR tutor_name = '')
        ");
        $stmt2->execute();
        $shared = $stmt2->get_result();
        
        while($row = $shared->fetch_assoc()) {
            // Only add shared question if it's not already in the list
            if (!in_array($row['q_id'], array_column($data, 'q_id'))) {
                $data[] = $row;
            }
        }
        $stmt2->close();
       
        // Output combined results
        if(count($data) > 0) {
            echo json_encode(["status" => "success", "data" => $data]);
        } else {
            echo json_encode(["status" => "error", "message" => "No activities found"]);
        }
        $conn->close();

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 4. PUSH LOGIC (POST REQUEST) ---
// Inserts new questions into the cloud database
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        
        $stmt->bind_param("sssssiss", 
            $input['q_id'], 
            $input['question_text'], 
            $input['correct_answer'], 
            $input['activity_type'], 
            $input['activity_name'], 
            $version, 
            $input['tutor_name'], 
            $input['tutor_email']
        );
       
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "msg" => "Synced to Cloud"]);
        } else {
            echo json_encode(["status" => "error", "msg" => $stmt->error]);
        }
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 5. DEFAULT STATUS ---
// Shown if no parameters are passed
echo json_encode([
    "status" => "online", 
    "service" => "ASL API",
    "endpoint" => "https://onrender.com"
]);
