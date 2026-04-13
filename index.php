<?php
// --- 1. HEADERS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// --- 2. CONFIG ---
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production

$host = '://tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
$ssl  = __DIR__ . "/isrgrootx1.pem"; // DYNAMIC PATH

function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    
    if (!file_exists($ssl)) {
        throw new Exception("SSL Cert Missing at " . $ssl);
    }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// --- 3. THE "TEST" ENDPOINT ---
// Visit ://your-url.com to check connection
if (isset($_GET['test'])) {
    try {
        $conn = getTiDBConnection();
        echo json_encode(["status" => "online", "database" => "connected"]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "offline", "error" => $e->getMessage()]);
    }
    exit;
}

// --- 4. POST LOGIC ---
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$input) {
        echo json_encode(["status" => "error", "msg" => "No JSON data received", "raw_received" => $rawInput]);
        exit;
    }

    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare Error: " . $conn->error);

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
            echo json_encode(["status" => "success"]);
        } else {
            echo json_encode(["status" => "error", "msg" => $stmt->error]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 5. GET LOGIC ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $conn = getTiDBConnection();
        $stmt = $conn->prepare("SELECT * FROM local_questions WHERE tutor_email = ?");
        $stmt->bind_param("s", $_GET['tutor_email']);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(["status" => "success", "data" => $data]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// Default response if no conditions met
echo json_encode(["status" => "ready", "msg" => "Send a POST or GET request with tutor_email"]);
