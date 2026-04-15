<?php
// --- 1. MOBILE APP SECURITY HEADERS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
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

function getTiDBConnection() {
    global $host, $user, $pass, $db, $port, $ssl;
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connection Failed: " . $conn->connect_error);
    }
    return $conn;
}

// --- 3. SYNC FROM DEVICE TO CLOUD (POST) ---
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn    = getTiDBConnection();
        $sql     = "INSERT INTO local_questions
                        (q_id, question_text, correct_answer, activity_type, activity_name, version, tutor_name, tutor_email)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        question_text  = VALUES(question_text),
                        correct_answer = VALUES(correct_answer),
                        activity_type  = VALUES(activity_type),
                        activity_name  = VALUES(activity_name),
                        version        = VALUES(version),
                        tutor_name     = VALUES(tutor_name),
                        tutor_email    = VALUES(tutor_email)";
        $stmt    = $conn->prepare($sql);
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

// --- 4. FETCH FROM CLOUD TO DEVICE (GET) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['tutor_email'])) {
    try {
        $email = trim($_GET['tutor_email']);
        $conn  = getTiDBConnection();

        // ── QUERY: three cases in one statement ──────────────────────
        // Case 1: record belongs to this tutor by email  (your 2 questions)
        // Case 2: record belongs to this tutor by name   (future-proof)
        // Case 3: record has NO owner at all             (Q1776036647 - blank email AND blank/null name)
        //
        // We pass $email twice (for the email match and the name match).
        // The third OR arm catches every row where both fields are empty/null.
        $sql = "SELECT * FROM local_questions
                WHERE tutor_email = ?
                   OR tutor_name  = ?
                   OR (
                        (tutor_email IS NULL OR TRIM(tutor_email) = '')
                        AND
                        (tutor_name  IS NULL OR TRIM(tutor_name)  = '')
                      )";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $res  = $stmt->get_result();

        $data = [];
        $seen = [];                         // deduplicate by q_id
        while ($row = $res->fetch_assoc()) {
            $id = $row['q_id'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $data[]    = $row;
            }
        }

        // Log how many rows each arm matched (visible in Render logs)
        error_log("[ASL-API] email=" . $email . " | rows returned=" . count($data));

        echo json_encode(["status" => "success", "data" => $data]);
        $conn->close();

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

// --- 5. HEALTH CHECK (GET with ?ping=1) keeps Render dyno warm ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['ping'])) {
    echo json_encode(["status" => "ok", "ts" => time()]);
    exit;
}

// --- 6. FALLBACK ---
echo json_encode(["status" => "error", "message" => "Invalid request"]);
