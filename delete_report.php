<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'error' => 'Report ID is required']);
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
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

try {
    // Get user ID - try both username and empcode
    $getUserQuery = "SELECT id, username, empcode FROM users WHERE username = ? OR empcode = ?";
    $getUserStmt = sqlsrv_prepare($conn, $getUserQuery, [$_SESSION['username'], $_SESSION['empcode']]);
    
    if (!$getUserStmt || !sqlsrv_execute($getUserStmt)) {
        throw new Exception('Failed to get user information');
    }
    
    $userRow = sqlsrv_fetch_array($getUserStmt, SQLSRV_FETCH_ASSOC);
    if (!$userRow) {
        throw new Exception('User not found');
    }
    $user_id = $userRow['id'];

    $report_id = $_POST['id'];

    // Get image path before deleting
    $getImageQuery = "SELECT image_path FROM daily_reports WHERE id = ? AND user_id = ?";
    $getImageStmt = sqlsrv_prepare($conn, $getImageQuery, [$report_id, $user_id]);
    
    if (!$getImageStmt || !sqlsrv_execute($getImageStmt)) {
        throw new Exception('Failed to get report information');
    }
    
    $imageRow = sqlsrv_fetch_array($getImageStmt, SQLSRV_FETCH_ASSOC);
    if (!$imageRow) {
        throw new Exception('Report not found or you do not have permission to delete it');
    }

    // Delete from database
    $deleteQuery = "DELETE FROM daily_reports WHERE id = ? AND user_id = ?";
    $deleteStmt = sqlsrv_prepare($conn, $deleteQuery, [$report_id, $user_id]);
    
    if (!$deleteStmt || !sqlsrv_execute($deleteStmt)) {
        throw new Exception('Failed to delete report from database');
    }

    // Delete image file if it exists
    if (!empty($imageRow['image_path']) && file_exists($imageRow['image_path'])) {
        unlink($imageRow['image_path']);
    }

    echo json_encode(['success' => true, 'message' => 'Report deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    sqlsrv_close($conn);
}
?>