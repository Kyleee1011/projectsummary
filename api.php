<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$serverName = "10.2.0.9";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "it_project_db"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    error_log("Connection failed: " . print_r(sqlsrv_errors(), true));
    die(json_encode(['success' => false, 'error' => 'Database connection failed']));
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uriSegments = explode('/', trim($uri, '/'));

if ($uriSegments[0] === 'login' && $requestMethod === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        die(json_encode(['success' => false, 'error' => 'Username and password are required']));
    }

    $sql = "SELECT username, password, role, empcode FROM it_project_db.dbo.users WHERE username = ?";
    $params = [$username];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        error_log("Query failed: " . print_r(sqlsrv_errors(), true));
        die(json_encode(['success' => false, 'error' => 'Query error']));
    }

    if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (password_verify($password, $row['password'])) {
            $token = bin2hex(random_bytes(16));
            $hashedToken = password_hash($token, PASSWORD_DEFAULT);
            $updateQuery = "UPDATE it_project_db.dbo.users SET login_token = ? WHERE username = ?";
            $updateStmt = sqlsrv_query($conn, $updateQuery, [$hashedToken, $username]);
            if ($updateStmt === false) {
                error_log("Token update failed: " . print_r(sqlsrv_errors(), true));
            }
            $response = [
                'success' => true,
                'token' => $token,
                'username' => $row['username'],
                'role' => $row['role'],
                'empcode' => $row['empcode']
            ];
        } else {
            $response = ['success' => false, 'error' => 'Invalid credentials'];
        }
    } else {
        $response = ['success' => false, 'error' => 'User not found'];
    }
    echo json_encode($response);
    sqlsrv_free_stmt($stmt);
} elseif ($uriSegments[0] === 'reports' && $requestMethod === 'GET') {
    $category = $_GET['category'] ?? '';
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);

    if (empty($token)) {
        die(json_encode(['success' => false, 'error' => 'Unauthorized']));
    }

    $sql = "SELECT username FROM it_project_db.dbo.users WHERE login_token = ?";
    $stmt = sqlsrv_query($conn, $sql, [password_hash($token, PASSWORD_DEFAULT)]);
    if ($stmt === false || !sqlsrv_has_rows($stmt)) {
        die(json_encode(['success' => false, 'error' => 'Invalid token']));
    }

    $sql = "SELECT * FROM it_project_db.dbo.reports WHERE category = ?";
    $params = [$category];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        error_log("Reports query failed: " . print_r(sqlsrv_errors(), true));
        die(json_encode(['success' => false, 'error' => 'Query error']));
    }

    $reports = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $reports[] = [
            'report_date' => $row['report_date'] instanceof DateTime ? $row['report_date']->format('Y-m-d') : $row['report_date'],
            'tasks' => json_decode($row['tasks'], true),
            'category' => $row['category']
        ];
    }
    echo json_encode($reports);
    sqlsrv_free_stmt($stmt);
} elseif ($uriSegments[0] === 'reports' && $requestMethod === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);

    if (empty($token)) {
        die(json_encode(['success' => false, 'error' => 'Unauthorized']));
    }

    $sql = "SELECT username FROM it_project_db.dbo.users WHERE login_token = ?";
    $stmt = sqlsrv_query($conn, $sql, [password_hash($token, PASSWORD_DEFAULT)]);
    if ($stmt === false || !sqlsrv_has_rows($stmt)) {
        die(json_encode(['success' => false, 'error' => 'Invalid token']));
    }

    $reportDate = $input['report_date'] ?? '';
    $tasks = $input['tasks'] ?? [];

    $sql = "INSERT INTO it_project_db.dbo.reports (report_date, tasks, category) VALUES (?, ?, ?)";
    foreach ($tasks as $task) {
        $params = [
            $reportDate,
            json_encode($tasks),
            $task['category']
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            error_log("Report insert failed: " . print_r(sqlsrv_errors(), true));
            die(json_encode(['success' => false, 'error' => 'Query error']));
        }
    }
    echo json_encode(['success' => true]);
    sqlsrv_free_stmt($stmt);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid endpoint']);
}

sqlsrv_close($conn);
?>