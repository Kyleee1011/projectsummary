<?php
session_start();
header('Content-Type: application/json');

ini_set('log_errors', 1);
ini_set('error_log', 'C:\inetpub\wwwroot\projectsummary\php_errors.log');

if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    error_log('Unauthorized access attempt in fetch_reports.php');
    echo json_encode([]);
    exit();
}

if (!extension_loaded('sqlsrv')) {
    error_log('SQLSRV extension not loaded in fetch_reports.php');
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
    error_log('Database connection failed in fetch_reports.php: ' . print_r(sqlsrv_errors(), true));
    echo json_encode([]);
    exit();
}

try {
    $query = "SELECT r.id, r.report_date AS date, r.created_at, r.updated_at, t.task_description AS description, t.image_path AS image
              FROM reports r
              LEFT JOIN tasks t ON r.id = t.report_id
              WHERE r.empcode = ?";
    $params = [$_SESSION['empcode']];

    if (!empty($_GET['search'])) {
        $query .= " AND t.task_description LIKE ?";
        $params[] = '%' . $_GET['search'] . '%';
    }

    if (!empty($_GET['date_from'])) {
        $query .= " AND r.report_date >= ?";
        $params[] = $_GET['date_from'];
    }

    if (!empty($_GET['date_to'])) {
        $query .= " AND r.report_date <= ?";
        $params[] = $_GET['date_to'];
    }

    $query .= " ORDER BY r.report_date DESC, r.created_at DESC";

    $stmt = sqlsrv_prepare($conn, $query, $params);
    if (!$stmt || !sqlsrv_execute($stmt)) {
        error_log('Failed to fetch reports: ' . print_r(sqlsrv_errors(), true));
        throw new Exception('Failed to fetch reports');
    }

    $reports = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $report_id = $row['id'];
        if (!isset($reports[$report_id])) {
            $reports[$report_id] = [
                'id' => $report_id,
                'date' => $row['date'] ? $row['date']->format('Y-m-d') : null,
                'created_at' => $row['created_at'] ? $row['created_at']->format('Y-m-d H:i:s') : null,
                'updated_at' => $row['updated_at'] ? $row['updated_at']->format('Y-m-d H:i:s') : null,
                'tasks' => []
            ];
        }
        if ($row['description']) {
            $image_path = $row['image'] ? $base_url . basename($row['image']) : null;
            error_log("Fetch Reports Image URL: $image_path"); // Debug log
            $reports[$report_id]['tasks'][] = [
                'description' => $row['description'],
                'image' => $image_path
            ];
        }
    }

    $reports = array_values($reports);
    echo json_encode($reports);

} catch (Exception $e) {
    error_log('Error in fetch_reports.php: ' . $e->getMessage());
    echo json_encode([]);
} finally {
    sqlsrv_close($conn);
}
?>