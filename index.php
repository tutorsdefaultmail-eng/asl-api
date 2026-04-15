<?php
// --- 1. HEADERS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// --- 2. CONFIG ---
error_reporting(0); // Set to 0 to prevent HTML error text breaking JSON
ini_set('display_errors', 0);

function getTiDBConnection() {
    $host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
    $port = 4000;
    $user = '4UEUqD3k7NuvmvP.root';
    $db   = 'signlms';
    $pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
    $ssl  = "/var/www/html/isrgrootx1.pem";

    if (!file_exists($ssl)) {
        throw new Exception("SSL Cert Missing");
    }

    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    if (!$conn->real_connect($host, $user, $pass, $db, $port)) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    return $conn;
}

// --- 3. FETCH LOGIC (GET) ---
// Now handles both specific email search and general landing page hits
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $conn  = getTiDBConnection();
        $email = isset($_GET['tutor_email']) ? trim($_GET['tutor_email']) : "";
        $data  = [];
        $seen  = [];

        // Single Query Logic:
        // 1. Match specific email (if provided)
        // 2. Match specific name (if provided)
        // 3. Match Global records (where both fields are empty/null)
        $sql = "SELECT * FROM local_questions 
                WHERE (LOWER(TRIM(tutor_email)) = LOWER(?) AND ? != '')
                   OR (LOWER(TRIM(tutor_name)) = LOWER(?) AND ? != '')
                   OR (
                        (tutor_email IS NULL OR TRIM(tutor_email) = '')
                        AND
                        (tutor_name IS NULL OR TRIM(tutor_name) = '')
                      )";

        $stmt = $conn->prepare($sql);
        // We pass the email string 4 times to satisfy the AND checks in the SQL
        $stmt->bind_param("ssss", $email, $email, $email, $email);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $id = $row['q_id'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $data[] = $row;
            }
        }

        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        $conn->close();
        exit;

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        exit;
    }
}

// --- 4. SYNC LOGIC (POST) ---
$input = json_decode(file_get_contents("php://input"), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $input) {
    try {
        $conn = getTiDBConnection();
        $sql = "INSERT INTO local_questions 
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
        
        $stmt = $conn->prepare($sql);
        $version = $input['version'] ?? 1;
        $stmt->bind_param("sssssiss",
            $input['q_id'], $input['question_text'], $input['correct_answer'],
            $input['activity_type'], $input['activity_name'], $version,
            $input['tutor_name'], $input['tutor_email']
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

// --- 5. FALLBACK ---
echo json_encode(["status" => "online", "message" => "ASL API Ready"]);
