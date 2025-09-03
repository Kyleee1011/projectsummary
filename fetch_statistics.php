<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    echo json_encode([
        'total_reports' => 0,
        'monthly_reports' => 0,
        'weekly_reports' => 0,
        'completion_rate' => '0%'
    ]);
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
    echo json_encode([
        'total_reports' => 0,
        'monthly_reports' => 0,
        'weekly_reports' => 0,
        'completion_rate' => '0%'
    ]);
    exit;
}

try {
    $empcode = $_SESSION['empcode'];
    $stats = [];

    // Total reports
    $totalQuery = "SELECT COUNT(*) as total FROM daily_report_db.dbo.reports WHERE empcode = ?";
    $totalStmt = sqlsrv_query($conn, $totalQuery, [$empcode]);
    if ($totalStmt !== false) {
        $totalResult = sqlsrv_fetch_array($totalStmt, SQLSRV_FETCH_ASSOC);
        $stats['total_reports'] = $totalResult['total'] ?? 0;
        sqlsrv_free_stmt($totalStmt);
    } else {
        $stats['total_reports'] = 0;
    }

    // Monthly reports (current month)
    $monthlyQuery = "SELECT COUNT(*) as monthly FROM daily_report_db.dbo.reports 
                     WHERE empcode = ? AND YEAR(report_date) = YEAR(GETDATE()) AND MONTH(report_date) = MONTH(GETDATE())";
    $monthlyStmt = sqlsrv_query($conn, $monthlyQuery, [$empcode]);
    if ($monthlyStmt !== false) {
        $monthlyResult = sqlsrv_fetch_array($monthlyStmt, SQLSRV_FETCH_ASSOC);
        $stats['monthly_reports'] = $monthlyResult['monthly'] ?? 0;
        sqlsrv_free_stmt($monthlyStmt);
    } else {
        $stats['monthly_reports'] = 0;
    }

    // Weekly reports (current week)
    $weeklyQuery = "SELECT COUNT(*) as weekly FROM daily_report_db.dbo.reports 
                    WHERE empcode = ? AND report_date >= DATEADD(week, DATEDIFF(week, 0, GETDATE()), 0)
                    AND report_date < DATEADD(week, DATEDIFF(week, 0, GETDATE()) + 1, 0)";
    $weeklyStmt = sqlsrv_query($conn, $weeklyQuery, [$empcode]);
    if ($weeklyStmt !== false) {
        $weeklyResult = sqlsrv_fetch_array($weeklyStmt, SQLSRV_FETCH_ASSOC);
        $stats['weekly_reports'] = $weeklyResult['weekly'] ?? 0;
        sqlsrv_free_stmt($weeklyStmt);
    } else {
        $stats['weekly_reports'] = 0;
    }

    // Completion rate (percentage of working days with reports this month)
    $currentYear = date('Y');
    $currentMonth = date('m');
    $daysInMonth = date('t');
    $currentDay = date('j');
    
    // Calculate working days (excluding weekends) up to current date
    $workingDays = 0;
    for ($day = 1; $day <= min($currentDay, $daysInMonth); $day++) {
        $dayOfWeek = date('w', mktime(0, 0, 0, $currentMonth, $day, $currentYear));
        if ($dayOfWeek != 0 && $dayOfWeek != 6) { // Not Sunday (0) or Saturday (6)
            $workingDays++;
        }
    }
    
    if ($workingDays > 0) {
        $completionRate = round(($stats['monthly_reports'] / $workingDays) * 100);
        $stats['completion_rate'] = min($completionRate, 100) . '%';
    } else {
        $stats['completion_rate'] = '0%';
    }

    echo json_encode($stats);

} catch (Exception $e) {
    error_log("Error in fetch_statistics.php: " . $e->getMessage());
    echo json_encode([
        'total_reports' => 0,
        'monthly_reports' => 0,
        'weekly_reports' => 0,
        'completion_rate' => '0%'
    ]);
} finally {
    if ($conn) {
        sqlsrv_close($conn);
    }
}
?>