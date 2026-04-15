<?php
// ─── 1. HEADERS & CORS ──────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS"); // Added DELETE
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

error_reporting(0); 
ini_set('display_errors', 0);

// ─── 2. DATABASE CONNECTION ─────────────────────────────────────
function getTiDBConnection() {
    $host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
    $port = 4000;
    $user = '4UEUqD3k7NuvmvP.root';
    $db   = 'signlms';
    $pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
    $ssl  = __DIR__ . "/isrgrootx1.pem"; 

    if (!file_exists($ssl)) { throw new Exception("SSL Missing"); }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// ─── 3. GET LOGIC (FETCH / SYNC) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $conn = getTiDBConnection();
        $data = [];
        $email = isset($_GET['tutor_email']) ? trim($_GET['tutor_email']) : '';

        if (!empty($email)) {
            // Fetch tutor specific + global items
            $stmt = $conn->prepare("SELECT q_id, activity_name, question_text, correct_answer FROM local_questions WHERE tutor_email = ? OR tutor_name = ? OR tutor_email IS NULL OR tutor_email = ''");
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) { $data[] = $row; }
            $stmt->close();
        } else {
            // Show everything (Show All Mode)
            $res = $conn->query("SELECT q_id, activity_name, question_text, correct_answer FROM local_questions");
            while($row = $res->fetch_assoc()) { $data[] = $row; }
        }

        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// ─── 4. DELETE LOGIC (NEW) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    try {
        $conn = getTiDBConnection();
        $email = isset($_GET['tutor_email']) ? trim($_GET['tutor_email']) : '';
        $q_id  = isset($_GET['q_id']) ? trim($_GET['q_id']) : '';

        if (empty($email)) {
            throw new Exception("Email required to verify ownership.");
        }

        if (!empty($q_id)) {
            // Delete specific item
            $stmt = $conn->prepare("DELETE FROM local_questions WHERE tutor_email = ? AND q_id = ?");
            $stmt->bind_param("ss", $email, $q_id);
        } else {
            // Delete all for this tutor
            $stmt = $conn->prepare("DELETE FROM local_questions WHERE tutor_email = ?");
            $stmt->bind_param("s", $email);
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();

        echo json_encode(["status" => "success", "message" => "Deleted $affected row(s)."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// ─── 5. POST LOGIC (INSERT OR UPDATE) ───────────────────────────
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        
        // This SQL handles the EDIT function via ON DUPLICATE KEY UPDATE
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                question_text=VALUES(question_text), 
                correct_answer=VALUES(correct_answer), 
                activity_name=VALUES(activity_name),
                activity_type=VALUES(activity_type)";

        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        $stmt->bind_param("sssssiss", 
            $input['q_id'], $input['question_text'], $input['correct_answer'], 
            $input['activity_type'], $input['activity_name'], $version, 
            $input['tutor_name'], $input['tutor_email']
        );
        $stmt->execute();
        
        echo json_encode(["status" => "success", "message" => "Cloud synced"]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "online"]);
?>
