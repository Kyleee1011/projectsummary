<?php
session_start();
header('Content-Type: application/json');

ini_set('log_errors', 1);
ini_set('error_log', 'C:\inetpub\wwwroot\projectsummary\php_errors.log');

if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    error_log('Unauthorized access attempt in fetch_report_details.php');
    echo json_encode(null);
    exit();
}

if (!isset($_GET['id'])) {
    error_log('Missing report ID in fetch_report_details.php');
    echo json_encode(null);
    exit();
}

if (!extension_loaded('sqlsrv')) {
    error_log('SQLSRV extension not loaded in fetch_report_details.php');
    echo json_encode(['error' => 'SQLSRV extension is required but not loaded']);
    exit();
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/projectsummary/Uploads/";

$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    error_log('Database connection failed in fetch_report_details.php: ' . print_r(sqlsrv_errors(), true));
    echo json_encode(null);
    exit();
}

try {
    $report_id = $_GET['id'];

    $query = "SELECT r.report_date, r.created_at, r.updated_at
              FROM reports r
              WHERE r.id = ? AND r.empcode = ?";
    
    $stmt = sqlsrv_prepare($conn, $query, [$report_id, $_SESSION['empcode']]);
    if (!$stmt || !sqlsrv_execute($stmt)) {
        error_log('Failed to fetch report details: ' . print_r(sqlsrv_errors(), true));
        throw new Exception('Failed to fetch report details');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$row) {
        error_log('Report not found for ID ' . $report_id . ' and empcode ' . $_SESSION['empcode']);
        echo json_encode(null);
        exit();
    }

    $tasksQuery = "SELECT task_description AS description, image_path AS image
                   FROM tasks
                   WHERE report_id = ?
                   ORDER BY id";
    
    $tasksStmt = sqlsrv_prepare($conn, $tasksQuery, [$report_id]);
    if (!$tasksStmt || !sqlsrv_execute($tasksStmt)) {
        error_log('Failed to fetch tasks: ' . print_r(sqlsrv_errors(), true));
        throw new Exception('Failed to fetch tasks');
    }

    $tasks = [];
    while ($taskRow = sqlsrv_fetch_array($tasksStmt, SQLSRV_FETCH_ASSOC)) {
        $image_path = $taskRow['image'] ? $base_url . basename($taskRow['image']) : null;
        error_log("Fetch Report Details Image URL: $image_path"); // Debug log
        $tasks[] = [
            'description' => $taskRow['description'],
            'image' => $image_path
        ];
    }

    $report = [
        'id' => $report_id,
        'date' => $row['report_date'] ? $row['report_date']->format('Y-m-d') : null,
        'created_at' => $row['created_at'] ? $row['created_at']->format('Y-m-d H:i:s') : null,
        'updated_at' => $row['updated_at'] ? $row['updated_at']->format('Y-m-d H:i:s') : null,
        'tasks' => $tasks
    ];

    echo json_encode($report);

} catch (Exception $e) {
    error_log('Error in fetch_report_details.php: ' . $e->getMessage());
    echo json_encode(null);
} finally {
    sqlsrv_close($conn);
}
?>