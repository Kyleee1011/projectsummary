<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    echo json_encode([]);
    exit;
}

// Database connection
$serverName = "10.2.0.9";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    echo json_encode([]);
    exit;
}

try {
    $empcode = $_SESSION['empcode'];
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    // Validate category
    if (!in_array($category, ['minor', 'major'])) {
        throw new Exception('Invalid category specified');
    }

    // Build base query using the unified tasks table
    $sql = "
        SELECT 
            r.id,
            r.empcode,
            r.report_date,
            r.created_at,
            r.updated_at,
            t.id as task_id,
            t.task_description,
            t.image_path,
            t.category
        FROM daily_report_db.dbo.reports r
        LEFT JOIN daily_report_db.dbo.tasks t ON r.id = t.report_id
        WHERE r.empcode = ? AND t.category = ?
    ";
    
    $params = [$empcode, $category];

    // Add date filters
    if (!empty($dateFrom)) {
        $sql .= " AND r.report_date >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $sql .= " AND r.report_date <= ?";
        $params[] = $dateTo;
    }

    // Add search filter
    if (!empty($search)) {
        $sql .= " AND t.task_description LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $sql .= " ORDER BY r.report_date DESC, r.created_at DESC, t.id";

    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception('Database query failed: ' . print_r(sqlsrv_errors(), true));
    }

    $reports = [];
    $currentReportId = null;
    $currentReport = null;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if ($currentReportId !== $row['id']) {
            // Save previous report if exists
            if ($currentReport !== null) {
                $reports[] = $currentReport;
            }

            // Start new report
            $currentReportId = $row['id'];
            $currentReport = [
                'id' => $row['id'],
                'empcode' => $row['empcode'],
                'date' => $row['report_date'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'tasks' => []
            ];
        }

        // Add task to current report if task exists
        if (!empty($row['task_id'])) {
            $imagePath = '';
            if (!empty($row['image_path'])) {
                // Create full URL for image
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $basePath = dirname($_SERVER['REQUEST_URI']);
                $imagePath = $protocol . '://' . $host . $basePath . '/' . $row['image_path'];
            }

            $currentReport['tasks'][] = [
                'id' => $row['task_id'],
                'description' => $row['task_description'],
                'image' => $imagePath,
                'category' => $row['category']
            ];
        }
    }

    // Add the last report
    if ($currentReport !== null) {
        $reports[] = $currentReport;
    }

    sqlsrv_free_stmt($stmt);
    echo json_encode($reports);

} catch (Exception $e) {
    error_log("Error in fetch_reports.php: " . $e->getMessage());
    echo json_encode([]);
} finally {
    if ($conn) {
        sqlsrv_close($conn);
    }
}
?>