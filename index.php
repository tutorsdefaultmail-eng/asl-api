<?php
// ─── 1. HEADERS & CORS ──────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// Use script 2's error reporting for debugging, but keep it clean
error_reporting(E_ALL); 
ini_set('display_errors', 0); 

// ─── 2. DATABASE CONNECTION ─────────────────────────────────────
function getTiDBConnection() {
    $host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
    $port = 4000;
    $user = '4UEUqD3k7NuvmvP.root';
    $db   = 'signlms';
    // Use environment variable or fallback to provided password
    $pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
    // Use script 1's dynamic path logic
    $ssl  = __DIR__ . "/isrgrootx1.pem"; 

    if (!file_exists($ssl)) { throw new Exception("SSL Certificate Missing at $ssl"); }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    // Use script 2's more descriptive connection error reporting
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("TiDB Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// ─── 3. PING (Keep Render Warm - from script 2) ────────────────
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['ping'])) {
    echo json_encode(["status" => "online", "timestamp" => time()]);
    exit;
}

// ─── 4. GET LOGIC (FETCH / SYNC) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $conn = getTiDBConnection();
        $data = [];
        $email = isset($_GET['tutor_email']) ? trim($_GET['tutor_email']) : '';

        if (!empty($email)) {
            // Fetch tutor specific + global items (Security from script 1)
            $stmt = $conn->prepare("SELECT q_id, activity_name, question_text, correct_answer, version FROM local_questions WHERE tutor_email = ? OR tutor_name = ? OR tutor_email IS NULL OR tutor_email = ''");
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) { $data[] = $row; }
            $stmt->close();
        } else {
            $res = $conn->query("SELECT q_id, activity_name, question_text, correct_answer, version FROM local_questions");
            while($row = $res->fetch_assoc()) { $data[] = $row; }
        }

        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// ─── 5. DELETE LOGIC (Ownership Secured) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    try {
        $conn = getTiDBConnection();
        
        // Support both URL params and JSON body for flexibilty
        $input = json_decode(file_get_contents("php://input"), true);
        $email = $_GET['tutor_email'] ?? $input['tutor_email'] ?? '';
        $q_id  = $_GET['q_id'] ?? $input['q_id'] ?? '';

        if (empty($email)) { throw new Exception("Tutor email required for security."); }

        if (!empty($q_id)) {
            // Delete specific item (Ownership verified)
            $stmt = $conn->prepare("DELETE FROM local_questions WHERE tutor_email = ? AND q_id = ?");
            $stmt->bind_param("ss", $email, $q_id);
        } else {
            // Optional: Delete all for this tutor
            $stmt = $conn->prepare("DELETE FROM local_questions WHERE tutor_email = ?");
            $stmt->bind_param("s", $email);
        }

        $stmt->execute();
        echo json_encode(["status" => "success", "affected" => $stmt->affected_rows]);
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// ─── 6. POST LOGIC (INSERT OR UPDATE + VERSIONING) ─────────────
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        
        // Includes VERSION update (from script 2)
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                question_text=VALUES(question_text), 
                correct_answer=VALUES(correct_answer), 
                activity_name=VALUES(activity_name),
                activity_type=VALUES(activity_type),
                version=VALUES(version)";

        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        $stmt->bind_param("sssssiss", 
            $input['q_id'], $input['question_text'], $input['correct_answer'], 
            $input['activity_type'], $input['activity_name'], $version, 
            $input['tutor_name'], $input['tutor_email']
        );
        
        if($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Sync complete"]);
        } else {
            echo json_encode(["status" => "error", "message" => $stmt->error]);
        }
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "online", "service" => "TiDB ASL Gateway"]);
?>
