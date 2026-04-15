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

// --- PULL LOGIC (GET) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $conn = getTiDBConnection();
        $data = [];

        if (isset($_GET['tutor_email']) && !empty($_GET['tutor_email'])) {
            $email = trim($_GET['tutor_email']);
            
            // USE LOWER() and TRIM() to ensure a match even if there are spaces or caps
            $sql = "SELECT q_id, activity_name, question_text, correct_answer 
                    FROM local_questions 
                    WHERE LOWER(TRIM(tutor_email)) = LOWER(?) 
                       OR LOWER(TRIM(tutor_name)) = LOWER(?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) { $data[] = $row; }
            $stmt->close();
        }

        // ALWAYS get Global questions (Owner-less)
        $global_res = $conn->query("SELECT q_id, activity_name, question_text, correct_answer FROM local_questions WHERE tutor_email IS NULL OR TRIM(tutor_email) = ''");
        while($row = $global_res->fetch_assoc()) {
            if (!in_array($row['q_id'], array_column($data, 'q_id'))) {
                $data[] = $row;
            }
        }

        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
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

echo json_encode(["status" => "online"]);
