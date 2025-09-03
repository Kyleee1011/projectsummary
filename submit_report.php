<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Please log in.']);
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
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . print_r(sqlsrv_errors(), true)]);
    exit;
}

// Validate required fields
if (!isset($_POST['report_date'])) {
    echo json_encode(['success' => false, 'error' => 'Report date is required.']);
    exit;
}

$empcode = $_SESSION['empcode'];
$report_date = $_POST['report_date'];
$task_categories = $_POST['task_categories'] ?? [];
$task_types = $_POST['task_types'] ?? [];
$tasks_completed = $_POST['tasks_completed'] ?? [];
$requested_dates = $_POST['requested_dates'] ?? [];
$completion_dates = $_POST['completion_dates'] ?? [];
$statuses = $_POST['statuses'] ?? [];

// Validate that we have at least one task
if (empty($task_categories) || count($task_categories) === 0) {
    echo json_encode(['success' => false, 'error' => 'At least one task is required.']);
    exit;
}

// Validate task data consistency
for ($i = 0; $i < count($task_categories); $i++) {
    if (empty($task_categories[$i])) {
        echo json_encode(['success' => false, 'error' => 'Task category is required for all tasks.']);
        exit;
    }
    
    // For minor tasks, task_type is required
    if ($task_categories[$i] === 'minor' && (empty($task_types[$i]))) {
        echo json_encode(['success' => false, 'error' => 'Task type is required for minor tasks.']);
        exit;
    }
    
    // Task description is required for all tasks
    if (empty($tasks_completed[$i]) || trim($tasks_completed[$i]) === '') {
        echo json_encode(['success' => false, 'error' => 'Task description is required for all tasks.']);
        exit;
    }
}

// Handle file uploads
$uploads_dir = __DIR__ . '/Uploads/';
if (!is_dir($uploads_dir)) {
    if (!mkdir($uploads_dir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create uploads directory.']);
        exit;
    }
}

$uploaded_files = [];
if (isset($_FILES['task_images']) && is_array($_FILES['task_images']['name'])) {
    for ($i = 0; $i < count($_FILES['task_images']['name']); $i++) {
        if (!empty($_FILES['task_images']['name'][$i]) && $_FILES['task_images']['error'][$i] === UPLOAD_ERR_OK) {
            // Only allow images for major tasks
            if (isset($task_categories[$i]) && $task_categories[$i] === 'major') {
                $file_tmp = $_FILES['task_images']['tmp_name'][$i];
                $file_name = $_FILES['task_images']['name'][$i];
                $file_size = $_FILES['task_images']['size'][$i];
                
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
                $file_type = mime_content_type($file_tmp);
                
                if (!in_array($file_type, $allowed_types)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid file type for task ' . ($i + 1) . '. Only images are allowed.']);
                    exit;
                }
                
                // Validate file size (5MB max)
                if ($file_size > 5 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'error' => 'File size too large for task ' . ($i + 1) . '. Maximum 5MB allowed.']);
                    exit;
                }
                
                // Generate unique filename
                $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                $unique_filename = $empcode . '_' . date('Y-m-d_H-i-s') . '_' . $i . '.' . $file_extension;
                $upload_path = $uploads_dir . $unique_filename;
                
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $uploaded_files[$i] = 'Uploads/' . $unique_filename;
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to upload image for task ' . ($i + 1) . '.']);
                    exit;
                }
            } else {
                $uploaded_files[$i] = null;
            }
        } else {
            $uploaded_files[$i] = null;
        }
    }
}

// Begin database transaction
if (sqlsrv_begin_transaction($conn) === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to begin transaction.']);
    exit;
}

try {
    // First, ensure the employee exists in the employees table
    $check_employee_sql = "SELECT empcode FROM employees WHERE empcode = ?";
    $check_employee_stmt = sqlsrv_query($conn, $check_employee_sql, [$empcode]);
    
    if ($check_employee_stmt === false) {
        throw new Exception('Failed to check employee: ' . print_r(sqlsrv_errors(), true));
    }
    
    $employee_exists = sqlsrv_fetch_array($check_employee_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_employee_stmt);
    
    // If employee doesn't exist, create one
    if (!$employee_exists) {
        $insert_employee_sql = "INSERT INTO employees (empcode, name) VALUES (?, ?)";
        $employee_name = $_SESSION['username'] ?? $empcode;
        $insert_employee_stmt = sqlsrv_query($conn, $insert_employee_sql, [$empcode, $employee_name]);
        
        if ($insert_employee_stmt === false) {
            throw new Exception('Failed to create employee record: ' . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($insert_employee_stmt);
    }
    
    // Check if report already exists for this date
    $check_sql = "SELECT id FROM reports WHERE empcode = ? AND report_date = ?";
    $check_params = [$empcode, $report_date];
    $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
    
    if ($check_stmt === false) {
        throw new Exception('Database query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $existing_report = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_stmt);
    
    if ($existing_report) {
        // Delete existing tasks first
        $delete_tasks_sql = "DELETE FROM tasks WHERE report_id = ?";
        $delete_tasks_stmt = sqlsrv_query($conn, $delete_tasks_sql, [$existing_report['id']]);
        if ($delete_tasks_stmt === false) {
            throw new Exception('Failed to delete existing tasks: ' . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_tasks_stmt);
        
        // Delete the report
        $delete_sql = "DELETE FROM reports WHERE id = ?";
        $delete_params = [$existing_report['id']];
        $delete_stmt = sqlsrv_query($conn, $delete_sql, $delete_params);
        
        if ($delete_stmt === false) {
            throw new Exception('Failed to delete existing report: ' . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
    }
    
    // Insert new report with OUTPUT clause to get the ID immediately
    $report_sql = "INSERT INTO reports (empcode, report_date, created_at, updated_at) 
                   OUTPUT INSERTED.id 
                   VALUES (?, ?, GETDATE(), GETDATE())";
    $report_params = [$empcode, $report_date];
    $report_stmt = sqlsrv_query($conn, $report_sql, $report_params);
    
    if ($report_stmt === false) {
        throw new Exception('Failed to insert report: ' . print_r(sqlsrv_errors(), true));
    }
    
    // Get the report ID from the OUTPUT clause
    $report_id_row = sqlsrv_fetch_array($report_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($report_stmt);
    
    if (!$report_id_row || !isset($report_id_row['id'])) {
        throw new Exception('Failed to retrieve report ID from insert operation');
    }
    
    $report_id = (int)$report_id_row['id'];
    
    if (!$report_id || $report_id <= 0) {
        throw new Exception('Invalid report ID retrieved: ' . $report_id);
    }
    
    // Insert tasks
    for ($i = 0; $i < count($task_categories); $i++) {
        $category = $task_categories[$i];
        $task_description = isset($tasks_completed[$i]) ? trim($tasks_completed[$i]) : '';
        $task_type = ($category === 'minor' && isset($task_types[$i])) ? trim($task_types[$i]) : null;
        $image_path = isset($uploaded_files[$i]) ? $uploaded_files[$i] : null;
        $requested_date = !empty($requested_dates[$i]) ? $requested_dates[$i] : null;
        $completion_date = !empty($completion_dates[$i]) ? $completion_dates[$i] : null;
        $status = isset($statuses[$i]) && !empty($statuses[$i]) ? $statuses[$i] : 'Completed';
        
        // For major tasks, use the task description as task_name
        // For minor tasks, use the task_type as task_name
        $task_name = ($category === 'minor' && $task_type) ? $task_type : $task_description;
        
        // Ensure task_name is not empty
        if (empty($task_name)) {
            throw new Exception('Task name/type is required for task ' . ($i + 1));
        }
        
        // Prepare assigned_to field (required in original schema)
        $assigned_to = $_SESSION['username'] ?? $empcode;
        
        $task_sql = "INSERT INTO tasks (
            report_id, 
            task_name, 
            task_description, 
            assigned_to,
            category, 
            task_type,
            image_path, 
            requested_date, 
            completion_date, 
            status, 
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())";
        
        $task_params = [
            $report_id,
            $task_name,
            $task_description,
            $assigned_to,
            $category,
            $task_type,
            $image_path,
            $requested_date,
            $completion_date,
            $status
        ];
        
        $task_stmt = sqlsrv_query($conn, $task_sql, $task_params);
        
        if ($task_stmt === false) {
            throw new Exception('Failed to insert task ' . ($i + 1) . ': ' . print_r(sqlsrv_errors(), true));
        }
        
        sqlsrv_free_stmt($task_stmt);
    }
    
    // Commit transaction
    if (sqlsrv_commit($conn) === false) {
        throw new Exception('Failed to commit transaction: ' . print_r(sqlsrv_errors(), true));
    }
    
    echo json_encode(['success' => true, 'message' => 'Report submitted successfully!']);
    
} catch (Exception $e) {
    // Rollback transaction on error
    sqlsrv_rollback($conn);
    
    // Clean up any uploaded files on error
    foreach ($uploaded_files as $file_path) {
        if ($file_path && file_exists(__DIR__ . '/' . $file_path)) {
            unlink(__DIR__ . '/' . $file_path);
        }
    }
    
    error_log('Report submission error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// Close database connection
if ($conn) {
    sqlsrv_close($conn);
}
?>