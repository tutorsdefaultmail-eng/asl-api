<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

error_reporting(0); 
ini_set('display_errors', 0);

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

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $conn = getTiDBConnection();
        $data = [];

        // MODE 1: Email Sync (The logic that works for at least one)
        if (isset($_GET['tutor_email']) && !empty($_GET['tutor_email'])) {
            $email = trim($_GET['tutor_email']);
            
            // Search for specific email or name
            $stmt = $conn->prepare("SELECT q_id, activity_name, question_text, correct_answer FROM local_questions WHERE tutor_email = ? OR tutor_name = ?");
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) { $data[] = $row; }
            $stmt->close();

            // ALWAYS add Global questions (like "The Sun") to the email results
            $global_res = $conn->query("SELECT q_id, activity_name, question_text, correct_answer FROM local_questions WHERE tutor_email IS NULL OR TRIM(tutor_email) = ''");
            while($row = $global_res->fetch_assoc()) {
                if (!in_array($row['q_id'], array_column($data, 'q_id'))) {
                    $data[] = $row;
                }
            }
        } 
        // MODE 2: "Show Everything" (The GUI logic that shows all 3)
        else {
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

// --- PUSH LOGIC ---
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE question_text=VALUES(question_text), correct_answer=VALUES(correct_answer), activity_name=VALUES(activity_name)";
        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        $stmt->bind_param("sssssiss", 
            $input['q_id'], $input['question_text'], $input['correct_answer'], 
            $input['activity_type'], $input['activity_name'], $version, 
            $input['tutor_name'], $input['tutor_email']
        );
        $stmt->execute();
        echo json_encode(["status" => "success"]);
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["status" => "online"]);
