<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in.']);
    exit;
}

// Get JSON input or POST data
$input = json_decode(file_get_contents('php://input'), true);
$report_id = null;

if ($input && isset($input['id'])) {
    $report_id = $input['id'];
} elseif (isset($_POST['report_id'])) {
    $report_id = $_POST['report_id'];
}

// Check if report_id is provided
if (!$report_id || empty($report_id)) {
    echo json_encode(['success' => false, 'error' => 'Report ID is required.']);
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
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

try {
    $reportId = (int)$report_id;
    $empcode = $_SESSION['empcode'];

    // Start transaction
    if (sqlsrv_begin_transaction($conn) === false) {
        throw new Exception('Failed to start database transaction.');
    }

    // First, verify that the report belongs to the current user
    $verifyQuery = "SELECT id FROM reports WHERE id = ? AND empcode = ?";
    $verifyStmt = sqlsrv_query($conn, $verifyQuery, [$reportId, $empcode]);
    
    if ($verifyStmt === false) {
        throw new Exception('Failed to verify report ownership.');
    }

    $reportExists = sqlsrv_fetch_array($verifyStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($verifyStmt);

    if (!$reportExists) {
        throw new Exception('Report not found or you do not have permission to delete it.');
    }

    // Get image paths before deletion for cleanup
    $imagePathsQuery = "SELECT image_path FROM tasks WHERE report_id = ? AND image_path IS NOT NULL";
    $imageStmt = sqlsrv_query($conn, $imagePathsQuery, [$reportId]);
    
    $imagePaths = [];
    if ($imageStmt !== false) {
        while ($row = sqlsrv_fetch_array($imageStmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($row['image_path'])) {
                $imagePaths[] = $row['image_path'];
            }
        }
        sqlsrv_free_stmt($imageStmt);
    }

    // Delete from tasks table first (due to foreign key constraint)
    $deleteTasksQuery = "DELETE FROM tasks WHERE report_id = ?";
    $tasksStmt = sqlsrv_query($conn, $deleteTasksQuery, [$reportId]);
    if ($tasksStmt === false) {
        throw new Exception('Failed to delete tasks: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($tasksStmt);

    // Delete from reports table
    $deleteReportQuery = "DELETE FROM reports WHERE id = ? AND empcode = ?";
    $reportStmt = sqlsrv_query($conn, $deleteReportQuery, [$reportId, $empcode]);
    
    if ($reportStmt === false) {
        throw new Exception('Failed to delete report: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($reportStmt);

    // Commit transaction
    if (sqlsrv_commit($conn) === false) {
        throw new Exception('Failed to commit transaction: ' . print_r(sqlsrv_errors(), true));
    }

    // Delete associated image files
    foreach ($imagePaths as $imagePath) {
        $fullPath = __DIR__ . '/' . $imagePath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Report deleted successfully.']);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn) {
        sqlsrv_rollback($conn);
    }
    error_log('Delete report error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);

} finally {
    if ($conn) {
        sqlsrv_close($conn);
    }
}
?>