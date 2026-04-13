<?php
// --- 1. HEADERS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// --- 2. CONFIG & SSL ---
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide raw errors from users, log them instead

$host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7Nuvmv.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
// DYNAMIC PATH FOR CLOUD DEPLOYMENT
$ssl  = __DIR__ . "/isrgrootx1.pem"; 

function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    
    if (!file_exists($ssl)) {
        throw new Exception("SSL Certificate not found at: " . $ssl);
    }

    $conn = mysqli_init();
    // TiDB Cloud requires SSL
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    // Use @ to suppress raw warnings, handle via Exception
    if (!@$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connect Error (" . mysqli_connect_errno() . "): " . mysqli_connect_error());
    }
    return $conn;
}

// --- 3. SYNC POST ---
$input = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$input) {
        echo json_encode(["status" => "error", "message" => "No JSON input received"]);
        exit;
    }

    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

        $version = $input['version'] ?? 1;
        
        // Ensure this matches your DB: s = string, i = integer
        // If version is a string in DB, change 'i' to 's'
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
            echo json_encode(["status" => "success"]);
        } else {
            echo json_encode(["status" => "error", "message" => $stmt->error]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}
