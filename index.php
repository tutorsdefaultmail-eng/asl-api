<?php
// ─── 1. HEADERS & CORS ──────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

error_reporting(E_ALL); 
ini_set('display_errors', 0); 

// ─── 2. DATABASE CONNECTION ─────────────────────────────────────
function getTiDBConnection() {
    $host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
    $port = 4000;
    $user = '4UEUqD3k7NuvmvP.root';
    $db   = 'signlms';
    $pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
    $ssl  = __DIR__ . "/isrgrootx1.pem"; 

    if (!file_exists($ssl)) { throw new Exception("SSL Certificate Missing"); }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("TiDB Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// ─── 3. PING ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['ping'])) {
    echo json_encode(["status" => "online", "timestamp" => time()]);
    exit;
}

// ─── 4. GET LOGIC (Removed Version from SELECT) ────────────────
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $conn = getTiDBConnection();
        $data = [];
        $email = isset($_GET['tutor_email']) ? trim($_GET['tutor_email']) : '';

        // Removed 'version' from SELECT to match JS tiny-cache (6 columns)
        $query = "SELECT q_id, activity_name, question_text, correct_answer, tutor_name, tutor_email FROM local_questions";

        if (!empty($email)) {
            $stmt = $conn->prepare($query . " WHERE tutor_email = ? OR tutor_name = ? OR tutor_email IS NULL OR tutor_email = ''");
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) { $data[] = $row; }
            $stmt->close();
        } else {
            $res = $conn->query($query);
            while($row = $res->fetch_assoc()) { $data[] = $row; }
        }

        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// ─── 5. DELETE LOGIC ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    try {
        $conn = getTiDBConnection();
        $input = json_decode(file_get_contents("php://input"), true);
        $email = $_GET['tutor_email'] ?? $input['tutor_email'] ?? '';
        $q_id  = $_GET['q_id'] ?? $input['q_id'] ?? '';

        if (empty($email)) { throw new Exception("Tutor email required."); }

        if (!empty($q_id)) {
            $stmt = $conn->prepare("DELETE FROM local_questions WHERE tutor_email = ? AND q_id = ?");
            $stmt->bind_param("ss", $email, $q_id);
        } else {
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

// ─── 6. POST LOGIC (Removed Version column and 'i' type) ───────
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        
        // SQL now matches 7 strings (including activity_type)
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, tutor_name, tutor_email) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                question_text=VALUES(question_text), 
                correct_answer=VALUES(correct_answer), 
                activity_name=VALUES(activity_name),
                activity_type=VALUES(activity_type)";

        $stmt = $conn->prepare($sql);

        $q_id = $input['q_id'] ?? '';
        $q_text = $input['question_text'] ?? '';
        $c_ans = $input['correct_answer'] ?? '';
        $a_type = $input['activity_type'] ?? '';
        $a_name = $input['activity_name'] ?? '';
        $t_name = $input['tutor_name'] ?? '';
        $t_email = $input['tutor_email'] ?? '';

        // Changed to "sssssss" (Removed the 'i')
        $stmt->bind_param("sssssss", 
            $q_id, $q_text, $c_ans, 
            $a_type, $a_name, $t_name, $t_email
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

echo json_encode(["status" => "online"]);
?>
