<?php
session_start();
header('Content-Type: application/json');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', 'C:\inetpub\wwwroot\projectsummary\php_errors.log');

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    error_log('Unauthorized access attempt in fetch_statistics.php');
    echo json_encode([
        'total_reports' => 0,
        'monthly_reports' => 0,
        'weekly_reports' => 0,
        'completion_rate' => '0%'
    ]);
    exit();
}

// Check sqlsrv extension
if (!extension_loaded('sqlsrv')) {
    error_log('SQLSRV extension not loaded in fetch_statistics.php');
    echo json_encode(['error' => 'SQLSRV extension is required but not loaded']);
    exit();
}

// Database connection details
$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];

// Establish the connection to the SQL Server
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    error_log('Database connection failed in fetch_statistics.php: ' . print_r(sqlsrv_errors(), true));
    echo json_encode([
        'total_reports' => 0,
        'monthly_reports' => 0,
        'weekly_reports' => 0,
        'completion_rate' => '0%'
    ]);
    exit();
}

try {
    // Get total reports count
    $totalQuery = "SELECT COUNT(DISTINCT report_date) as total FROM reports WHERE empcode = ?";
    $totalStmt = sqlsrv_prepare($conn, $totalQuery, [$_SESSION['empcode']]);
    sqlsrv_execute($totalStmt);
    $totalRow = sqlsrv_fetch_array($totalStmt, SQLSRV_FETCH_ASSOC);
    $total_reports = $totalRow['total'] ?? 0;

    // Get monthly reports count
    $monthlyQuery = "SELECT COUNT(DISTINCT report_date) as monthly 
                     FROM reports 
                     WHERE empcode = ? 
                     AND YEAR(report_date) = YEAR(GETDATE()) 
                     AND MONTH(report_date) = MONTH(GETDATE())";
    $monthlyStmt = sqlsrv_prepare($conn, $monthlyQuery, [$_SESSION['empcode']]);
    sqlsrv_execute($monthlyStmt);
    $monthlyRow = sqlsrv_fetch_array($monthlyStmt, SQLSRV_FETCH_ASSOC);
    $monthly_reports = $monthlyRow['monthly'] ?? 0;

    // Get weekly reports count
    $weeklyQuery = "SELECT COUNT(DISTINCT report_date) as weekly 
                    FROM reports 
                    WHERE empcode = ? 
                    AND report_date >= DATEADD(day, -7, GETDATE())";
    $weeklyStmt = sqlsrv_prepare($conn, $weeklyQuery, [$_SESSION['empcode']]);
    sqlsrv_execute($weeklyStmt);
    $weeklyRow = sqlsrv_fetch_array($weeklyStmt, SQLSRV_FETCH_ASSOC);
    $weekly_reports = $weeklyRow['weekly'] ?? 0;

    // Calculate completion rate (reports this month / working days this month)
    $working_days = date('j'); // Current day of month as approximation
    $completion_rate = $working_days > 0 ? round(($monthly_reports / $working_days) * 100) : 0;

    echo json_encode([
        'total_reports' => $total_reports,
        'monthly_reports' => $monthly_reports,
        'weekly_reports' => $weekly_reports,
        'completion_rate' => $completion_rate . '%'
    ]);

} catch (Exception $e) {
    error_log('Error in fetch_statistics.php: ' . $e->getMessage());
    echo json_encode([
        'total_reports' => 0,
        'monthly_reports' => 0,
        'weekly_reports' => 0,
        'completion_rate' => '0%'
    ]);
} finally {
    sqlsrv_close($conn);
}
?>