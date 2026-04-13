<?php
// --- 1. MOBILE APP SECURITY HEADERS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle pre-flight checks from Capacitor/Mobile Device
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit; 
}

// --- 2. CONFIGURATION & CREDENTIALS ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = '://tidbcloud.com'; 
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$pass = '2i4QkHGpfOATuMod'; // Recommendation: Use Render Env Vars for this later
$db   = 'signlms';
$ssl  = __DIR__ . "/isrgrootx1.pem"; 

// --- 3. DATABASE CONNECTION FUNCTION ---
function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    
    if (!file_exists($ssl)) {
        throw new Exception("SSL Certificate file missing on server.");
    }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("TiDB Connection Failed: " . $conn->connect_error);
    }
    
    return $conn;
}

// --- 4. HANDLE DATA FROM DEVICE ---
$input = json_decode(file_get_contents("php://input"), true);

if ($input) {
    try {
        $conn = getTiDBConnection();
        
        $sql = "INSERT INTO local_questions 
                (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $version = isset($input['version']) ? (int)$input['version'] : 1;
        
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
            echo json_encode(["status" => "success", "msg" => "Synced to TiDB Cloud"]);
        } else {
            echo json_encode(["status" => "error", "msg" => $stmt->error]);
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 5. BROWSER STATUS CHECK ---
echo json_encode([
    "status" => "online",
    "service" => "ASL Tutor API",
    "endpoint" => "Ready for Device Sync"
]);
