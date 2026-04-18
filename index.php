header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// ── CONFIG ────────────────────────────────────────────────────────
$host = 'gateway01.us-east-1.prod.aws.tidbcloud.com';
$port = 4000;
$user = '4UEUqD3k7NuvmvP.root';
$db   = 'signlms';
$pass = getenv('DB_PASS') ?: '2i4QkHGpfOATuMod';
$ssl  = "/var/www/html/isrgrootx1.pem";

function getConn() {
    global $host, $user, $pass, $db, $port, $ssl;
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, $ssl, NULL, NULL);
    if (!$conn->real_connect($host, $user, $pass, $db, $port))
        throw new Exception("Connection failed: " . $conn->connect_error);
    return $conn;
}

function dedup($rows) {
    $seen = []; $out = [];
    foreach ($rows as $row) {
        if (!isset($seen[$row['q_id']])) { $seen[$row['q_id']] = true; $out[] = $row; }
    }
    return $out;
}

function ok($data)  { echo json_encode(["status" => "success"] + $data); }
function err($msg)  { echo json_encode(["status" => "error", "message" => $msg]); }

// ── ENSURE grades TABLE EXISTS ────────────────────────────────────
// Run this once — TiDB is MySQL-compatible so CREATE TABLE IF NOT EXISTS works.
function ensureGradesTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS grades (
            grade_id        VARCHAR(40)     PRIMARY KEY,
            tutor_email     VARCHAR(255)    NOT NULL,
            student_name    VARCHAR(255)    NOT NULL,
            submitted_at    DATETIME        DEFAULT CURRENT_TIMESTAMP,
            total_questions INT             NOT NULL DEFAULT 0,
            total_correct   INT             NOT NULL DEFAULT 0,
            score_pct       DECIMAL(5,2)    DEFAULT 0.00,
            report          JSON            NOT NULL,
            INDEX idx_tutor (tutor_email),
            INDEX idx_student (student_name)
        )
    ");
}

// ════════════════════════════════════════════════════════════════
//  PING — keeps Render dyno warm
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ping'])) {
    ok(["ts" => time(), "msg" => "awake"]);
    exit;
}

// ════════════════════════════════════════════════════════════════
//  POST — two actions via ?action= param
//   submit_grade  — student submits final grade
//   (default)     — tutor uploads a question
// ════════════════════════════════════════════════════════════════
$input = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $input) {
    $action = $_GET['action'] ?? 'upload_question';
    try {
        $conn = getConn();

        // ── A. Submit grade (from student) ────────────────────────
        if ($action === 'submit_grade') {
            ensureGradesTable($conn);

            $grade_id    = $input['grade_id']        ?? ("g_" . time());
            $tutor_email = trim($input['tutor_email'] ?? '');
            $student     = trim($input['student_name']?? '');
            $total_q     = intval($input['total_questions'] ?? 0);
            $total_c     = intval($input['total_correct']   ?? 0);
            $score_pct   = floatval($input['score_pct']     ?? 0);
            $report_json = json_encode($input['report']     ?? []);

            if (!$tutor_email || !$student) { err("tutor_email and student_name required"); exit; }

            $stmt = $conn->prepare("
                INSERT INTO grades
                    (grade_id, tutor_email, student_name, total_questions, total_correct, score_pct, report)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_questions = VALUES(total_questions),
                    total_correct   = VALUES(total_correct),
                    score_pct       = VALUES(score_pct),
                    report          = VALUES(report),
                    submitted_at    = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param("sssiidd",
                // Note: report is TEXT-compatible via string binding
                $grade_id, $tutor_email, $student,
                $total_q, $total_c, $score_pct, $report_json
            );
            // report is JSON string so rebind with correct types
            $stmt->close();

            // Use separate stmt for the JSON field (bind as string)
            $stmt2 = $conn->prepare("
                INSERT INTO grades
                    (grade_id, tutor_email, student_name, total_questions, total_correct, score_pct, report)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_questions = VALUES(total_questions),
                    total_correct   = VALUES(total_correct),
                    score_pct       = VALUES(score_pct),
                    report          = VALUES(report),
                    submitted_at    = CURRENT_TIMESTAMP
            ");
            $stmt2->bind_param("ssssiis",
                $grade_id, $tutor_email, $student,
                $total_q, $total_c, $score_pct, $report_json
            );

            if ($stmt2->execute()) {
                error_log("[ASL] Grade submitted: " . $grade_id . " student=" . $student . " score=" . $score_pct . "%");
                ok(["grade_id" => $grade_id, "score_pct" => $score_pct]);
            } else {
                err("DB error: " . $stmt2->error);
            }
            $conn->close();
            exit;
        }

        // ── B. Upload question (from tutor) ───────────────────────
        $sql  = "INSERT INTO local_questions
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
        $ver  = intval($input['version'] ?? 1);
        $stmt->bind_param("sssssiss",
            $input['q_id'], $input['question_text'], $input['correct_answer'],
            $input['activity_type'], $input['activity_name'], $ver,
            $input['tutor_name'], $input['tutor_email']
        );
        if ($stmt->execute()) { ok([]); } else { err($stmt->error); }
        $conn->close();

    } catch (Exception $e) { err($e->getMessage()); }
    exit;
}

// ════════════════════════════════════════════════════════════════
//  DELETE — remove a single question
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['q_id'])) {
    try {
        $conn = getConn();
        $stmt = $conn->prepare("DELETE FROM local_questions WHERE q_id = ?");
        $stmt->bind_param("s", $_GET['q_id']);
        if ($stmt->execute()) { ok([]); } else { err($stmt->error); }
        $conn->close();
    } catch (Exception $e) { err($e->getMessage()); }
    exit;
}

// ════════════════════════════════════════════════════════════════
//  GET — two modes via ?action= param
//   get_grades    — tutor fetches all grades for their email
//   (default)     — student fetches questions
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_questions';

    // ── A. Get grades (for tutor dashboard) ───────────────────────
    if ($action === 'get_grades') {
        $tutor_email = trim($_GET['tutor_email'] ?? '');
        if (!$tutor_email) { err("tutor_email required"); exit; }

        try {
            $conn = getConn();
            ensureGradesTable($conn);

            $stmt = $conn->prepare("
                SELECT grade_id, student_name, submitted_at,
                       total_questions, total_correct, score_pct, report
                FROM grades
                WHERE tutor_email = ?
                ORDER BY submitted_at DESC
            ");
            $stmt->bind_param("s", $tutor_email);
            $stmt->execute();
            $res  = $stmt->get_result();
            $data = [];
            while ($row = $res->fetch_assoc()) {
                // Decode report JSON for convenience
                $row['report'] = json_decode($row['report'], true) ?? [];
                $data[] = $row;
            }
            error_log("[ASL] Grades fetched for " . $tutor_email . " — " . count($data) . " records");
            ok(["data" => $data, "count" => count($data)]);
            $conn->close();
        } catch (Exception $e) { err($e->getMessage()); }
        exit;
    }

    // ── B. Get questions (default — student fetch) ─────────────────
    try {
        $conn = getConn();
        $data = [];

        if (!isset($_GET['tutor_email']) || trim($_GET['tutor_email']) === '') {
            // Show All mode
            $stmt = $conn->prepare("SELECT * FROM local_questions");
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $data[] = $row; }
        } else {
            $email = trim($_GET['tutor_email']);

            // Owned by tutor (email or name)
            $stmt = $conn->prepare("SELECT * FROM local_questions WHERE tutor_email = ? OR tutor_name = ?");
            $stmt->bind_param("ss", $email, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $data[] = $row; }

            // Unowned / shared questions (blank/null both fields)
            $stmt2 = $conn->prepare("
                SELECT * FROM local_questions
                WHERE (tutor_email IS NULL OR TRIM(tutor_email) = '')
                  AND (tutor_name  IS NULL OR TRIM(tutor_name)  = '')
            ");
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) { $data[] = $row; }

            $data = dedup($data);
        }

        error_log("[ASL] Questions: " . count($data) . " rows");
        ok(["data" => $data, "count" => count($data)]);
        $conn->close();
    } catch (Exception $e) { err($e->getMessage()); }
    exit;
}

err("Invalid request");
