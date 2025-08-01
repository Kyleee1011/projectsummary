<?php
session_start();

// Set the response header to JSON
header('Content-Type: application/json');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', 'C:\inetpub\wwwroot\projectsummary\php_errors.log');

// --- Pre-flight Checks ---
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    error_log('Unauthorized access attempt.');
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log('Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit();
}

if (empty($_POST['report_date']) || empty($_POST['tasks_completed']) || empty($_FILES['task_images'])) {
    error_log('Missing required fields: report_date=' . ($_POST['report_date'] ?? 'null') . ', tasks_completed=' . (empty($_POST['tasks_completed']) ? 'empty' : 'set') . ', task_images=' . (empty($_FILES['task_images']) ? 'empty' : 'set'));
    echo json_encode(['success' => false, 'error' => 'Missing required fields. Please fill out the entire form.']);
    exit();
}

// Validate report_date format
$reportDate = $_POST['report_date'];
if (!DateTime::createFromFormat('Y-m-d', $reportDate)) {
    error_log('Invalid report_date format: ' . $reportDate);
    echo json_encode(['success' => false, 'error' => 'Invalid report_date format.']);
    exit();
}

// --- Check Extensions ---
if (!extension_loaded('sqlsrv')) {
    error_log('SQLSRV extension not loaded.');
    echo json_encode(['success' => false, 'error' => 'SQLSRV extension is required but not loaded.']);
    exit();
}

if (!extension_loaded('fileinfo')) {
    error_log('Fileinfo extension not loaded, falling back to client-provided MIME type.');
}

// --- REMOVED: Image resize function is no longer needed ---

// --- Database and File Setup ---
$uploadDirectory = __DIR__ . '/Uploads/';

if (!is_dir($uploadDirectory)) {
    if (!mkdir($uploadDirectory, 0755, true)) {
        error_log('Failed to create uploads directory: ' . $uploadDirectory);
        echo json_encode(['success' => false, 'error' => 'Failed to create uploads directory.']);
        exit();
    }
}

$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    error_log('Database connection failed: ' . print_r(sqlsrv_errors(), true));
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . print_r(sqlsrv_errors(), true)]);
    exit();
}

// --- Data Processing and Database Insertion ---
if (sqlsrv_begin_transaction($conn) === false) {
    error_log('Failed to start transaction: ' . print_r(sqlsrv_errors(), true));
    echo json_encode(['success' => false, 'error' => 'Failed to start database transaction.']);
    exit();
}

try {
    // Insert into reports table
    $empcode = $_SESSION['empcode'];
    $sqlInsertReport = "INSERT INTO dbo.reports (empcode, report_date) OUTPUT Inserted.id VALUES (?, ?);";
    $paramsInsertReport = [$empcode, $reportDate];
    $stmtInsertReport = sqlsrv_query($conn, $sqlInsertReport, $paramsInsertReport);

    if ($stmtInsertReport === false) {
        error_log('Report insert failed: ' . print_r(sqlsrv_errors(), true));
        throw new Exception('Failed to execute the report creation query.');
    }

    $row = sqlsrv_fetch_array($stmtInsertReport, SQLSRV_FETCH_ASSOC);
    if (!$row || !isset($row['id'])) {
        error_log('Failed to retrieve report ID.');
        throw new Exception('Failed to retrieve the new report ID.');
    }
    $reportId = $row['id'];

    // Process tasks and images
    $tasks = $_POST['tasks_completed'];
    $images = $_FILES['task_images'];
    $taskCount = count($tasks);

    if (count($images['name']) !== $taskCount) {
        error_log('Task count mismatch: tasks=' . $taskCount . ', images=' . count($images['name']));
        throw new Exception('Number of tasks and images do not match.');
    }

    for ($i = 0; $i < $taskCount; $i++) {
        $taskDescription = $tasks[$i];
        $taskName = $taskDescription;

        if ($images['error'][$i] !== UPLOAD_ERR_OK) {
            error_log('File upload error for task ' . ($i + 1) . ': ' . $images['error'][$i]);
            throw new Exception('Error uploading file for Task ' . ($i + 1) . '. Code: ' . $images['error'][$i]);
        }

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($images['tmp_name'][$i]);
        
        if (!in_array($fileType, $allowedTypes)) {
            error_log('Invalid file type for task ' . ($i + 1) . ': ' . $fileType);
            throw new Exception('Invalid file type for Task ' . ($i + 1) . '. Only JPEG, PNG, GIF, and WebP are allowed.');
        }

        // --- CHANGED: Save original image instead of resizing ---
        $originalExtension = pathinfo($images['name'][$i], PATHINFO_EXTENSION);
        $imageName = time() . '_' . uniqid() . '_task_' . ($i + 1) . '.' . strtolower($originalExtension);
        $imagePath = $uploadDirectory . $imageName;

        if (!move_uploaded_file($images['tmp_name'][$i], $imagePath)) {
            throw new Exception('Failed to move uploaded file for task ' . ($i + 1) . '.');
        }

        $assignedTo = $_SESSION['empcode'];
        // Storing the physical server path in the database
        $sqlInsertTask = "INSERT INTO dbo.tasks (report_id, task_description, image_path, task_name, assigned_to) 
                          VALUES (?, ?, ?, ?, ?);";
        $paramsInsertTask = [$reportId, $taskDescription, $imagePath, $taskName, $assignedTo];
        $stmtInsertTask = sqlsrv_query($conn, $sqlInsertTask, $paramsInsertTask);

        if ($stmtInsertTask === false) {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
            error_log('Task insert failed for task ' . ($i + 1) . ': ' . print_r(sqlsrv_errors(), true));
            throw new Exception('Failed to save task ' . ($i + 1) . ' to the database.');
        }
    }

    sqlsrv_commit($conn);
    echo json_encode(['success' => true, 'message' => 'Report submitted successfully.']);

} catch (Exception $e) {
    sqlsrv_rollback($conn);
    error_log('Submission error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if ($conn) {
        sqlsrv_close($conn);
    }
}
?>