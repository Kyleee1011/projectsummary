<?php
session_start();
date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- DATABASE CONNECTION SETUP ---
$serverName = "10.2.0.9";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("Database connection failed. Details: " . print_r(sqlsrv_errors(), true));
}

// --- LOAD TCPDF LIBRARY AT THE TOP ---
require_once('tcpdf/tcpdf.php');

// --- FETCH REPORTS HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_reports') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['empcode'])) {
        echo json_encode([]); // Return empty array if not logged in
        exit;
    }

    $empcode = $_SESSION['empcode'];
    $category = $_GET['category'] ?? null;

    if (!$category) {
        echo json_encode([]);
        exit;
    }
    
    $sql = "
        SELECT 
            r.report_date,
            t.id,
            t.task_description,
            t.status,
            t.task_type,
            t.image_path
        FROM reports r
        JOIN tasks t ON r.id = t.report_id
        WHERE r.empcode = ? AND t.category = ?
        ORDER BY r.report_date DESC, t.created_at DESC
    ";
    
    $params = [$empcode, $category];
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        // Log error instead of echoing it to user
        error_log(print_r(sqlsrv_errors(), true));
        echo json_encode(['error' => 'Database query failed']);
        exit;
    }

    $reports = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dateStr = $row['report_date']->format('Y-m-d');
        if (!isset($reports[$dateStr])) {
            $reports[$dateStr] = [
                'date' => $row['report_date'],
                'tasks' => []
            ];
        }
        $reports[$dateStr]['tasks'][] = [
            'id' => $row['id'],
            'description' => $row['task_description'],
            'status' => $row['status'],
            'task_type' => $row['task_type'],
            'image' => $row['image_path']
        ];
    }
    
    // Convert associative array back to a simple indexed array for JS
    echo json_encode(array_values($reports));
    exit;
}

// --- SUBMIT REPORT HANDLER ---
if (isset($_POST['action']) && $_POST['action'] === 'submit_report') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['empcode'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in.']);
        exit;
    }
    
    $empcode = $_SESSION['empcode'];
    $report_date = date('Y-m-d');

    // Make sure there is at least one non-empty task
    $has_tasks = false;
    if (isset($_POST['tasks_completed']) && is_array($_POST['tasks_completed'])) {
        foreach ($_POST['tasks_completed'] as $desc) {
            if (!empty(trim($desc))) {
                $has_tasks = true;
                break;
            }
        }
    }

    if (!$has_tasks) {
        echo json_encode(['success' => false, 'error' => 'Cannot submit a report with no tasks.']);
        exit;
    }

    // Begin transaction
    if (sqlsrv_begin_transaction($conn) === false) {
        error_log("Failed to begin transaction: " . print_r(sqlsrv_errors(), true));
        echo json_encode(['success' => false, 'error' => 'Failed to start a database transaction.']);
        exit;
    }

    $report_id = null;
    try {
        // Step 1: Check if a report for this user and date already exists.
        $sql_select = "SELECT id FROM reports WHERE empcode = ? AND report_date = ?";
        $params = [$empcode, $report_date];
        $stmt_select = sqlsrv_query($conn, $sql_select, $params);
        if ($stmt_select === false) {
            throw new Exception("Database error while checking for existing report.");
        }

        if ($row = sqlsrv_fetch_array($stmt_select, SQLSRV_FETCH_ASSOC)) {
            // Report found, use its ID.
            $report_id = $row['id'];
        } else {
            // Step 2: Report not found, so create a new one.
            $sql_insert = "INSERT INTO reports (empcode, report_date) OUTPUT INSERTED.id VALUES (?, ?)";
            $stmt_insert = sqlsrv_query($conn, $sql_insert, $params);
            
            if ($stmt_insert === false) {
                // The query failed, throw a detailed exception. This will be caught below.
                throw new Exception("Failed to execute report creation statement.");
            }
            
            if (sqlsrv_fetch($stmt_insert)) {
                $report_id = sqlsrv_get_field($stmt_insert, 0);
            } else {
                throw new Exception("Failed to retrieve new report ID after creation.");
            }
        }

        if (!$report_id) {
            // This is a safeguard
            throw new Exception("Could not obtain a valid report ID.");
        }

        // --- Process Tasks ---
        $task_categories = $_POST['task_categories'] ?? [];
        $task_types = $_POST['task_types'] ?? [];
        $tasks_completed = $_POST['tasks_completed'] ?? [];
        $statuses = $_POST['statuses'] ?? [];
        
        foreach ($tasks_completed as $index => $description) {
            if (empty(trim($description))) {
                continue;
            }

            $category = $task_categories[$index] ?? null;
            $task_type = $task_types[$index] ?? null;
            $status = $statuses[$index] ?? 'Completed';
            
            if ($category !== 'minor') {
                $task_type = null;
            }
            
            $image_path = null;
            if ($category === 'major' && isset($_FILES['task_images']['name'][$index]) && $_FILES['task_images']['error'][$index] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $filename = uniqid() . '-' . basename($_FILES['task_images']['name'][$index]);
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['task_images']['tmp_name'][$index], $targetPath)) {
                    $image_path = $targetPath;
                }
            }
            
            $sql_task = "INSERT INTO tasks (report_id, task_description, status, category, task_type, image_path, created_at) VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
            $params_task = [$report_id, $description, $status, $category, $task_type, $image_path];
            $stmt_task = sqlsrv_query($conn, $sql_task, $params_task);

            if ($stmt_task === false) {
                throw new Exception("Failed to save one or more tasks.");
            }
        }

        // If everything was successful, commit the transaction
        sqlsrv_commit($conn);
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        // If any error occurred, roll back the entire transaction
        sqlsrv_rollback($conn);
        $sql_errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        
        // Log the technical details for the admin/developer
        $logMessage = "Report Submission Error: " . $e->getMessage() . "\nSQL Errors: " . print_r($sql_errors, true);
        error_log($logMessage); 
        
        $userMessage = "An error occurred while saving the report. Please contact support.";
        // Provide a more specific user-friendly error if possible
        if (!empty($sql_errors)) {
            // Check for our new, correct foreign key constraint
            if (strpos($sql_errors[0]['message'], 'FK_reports_users_empcode') !== false) {
                 $userMessage = 'Submission failed. Your employee code is not correctly registered. Please contact an administrator.';
            } else if (strpos($sql_errors[0]['message'], 'UQ_users_empcode') !== false) {
                 $userMessage = 'A system error occurred due to a duplicate employee code. Please contact an administrator.';
            }
        }
        
        echo json_encode(['success' => false, 'error' => $userMessage]);
    }
    
    exit;
}


// --- EXPORT HANDLER ---
if (isset($_POST['action']) && $_POST['action'] === 'export_reports') {
    if (!isset($_SESSION['username'])) {
        http_response_code(403);
        die('Access Denied. Please log in.');
    }

    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $empcodes = isset($_POST['empcodes']) && is_array($_POST['empcodes']) ? $_POST['empcodes'] : [];
    $format = $_POST['format'] ?? 'excel';
    $export_all = isset($_POST['export_all']) && $_POST['export_all'] === 'true';
    $task_category = $_POST['task_category'] ?? 'all';

    if (empty($date_from) || empty($date_to)) {
        die('Missing required date parameters for export.');
    }

    if ($export_all) {
        $query = "SELECT empcode FROM users ORDER BY empcode";
        $result = sqlsrv_query($conn, $query);
        $empcodes = [];
        if ($result) {
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $empcodes[] = $row['empcode'];
            }
            sqlsrv_free_stmt($result);
        }
    }

    if (empty($empcodes)) {
        die('No employees selected for export.');
    }

    if ($format === 'pdf') {
        exportReportsAsPdf($conn, $date_from, $date_to, $empcodes, $task_category);
        exit;
    } else {
        exportReportsAsExcelTemplate($conn, $date_from, $date_to, $empcodes, $task_category);
        exit;
    }
}

// --- PREVIEW HANDLER ---
if (isset($_POST['action']) && $_POST['action'] === 'preview_reports') {
    if (!isset($_SESSION['username'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access Denied. Please log in.']);
        exit;
    }

    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $empcodes = isset($_POST['empcodes']) && is_array($_POST['empcodes']) ? $_POST['empcodes'] : [];
    $export_all = isset($_POST['export_all']) && $_POST['export_all'] === 'true';
    $task_category = $_POST['task_category'] ?? 'all';

    if (empty($date_from) || empty($date_to)) {
        echo json_encode(['error' => 'Missing required date parameters for preview.']);
        exit;
    }

    if ($export_all) {
        $query = "SELECT empcode FROM users ORDER BY empcode";
        $result = sqlsrv_query($conn, $query);
        $empcodes = [];
        if ($result) {
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $empcodes[] = $row['empcode'];
            }
            sqlsrv_free_stmt($result);
        }
    }

    if (empty($empcodes)) {
        echo json_encode(['error' => 'No employees selected for preview.']);
        exit;
    }

    $data = getReportsDataForTemplate($conn, $date_from, $date_to, $empcodes, $task_category);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// --- SESSION CHECK FOR PAGE ACCESS ---
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    header("Location: login.php");
    exit();
}

// --- FETCH DATA FOR INITIAL PAGE LOAD ---
$teamMembers = [];
$query = "SELECT empcode, username, department FROM users ORDER BY username";
$userResult = sqlsrv_query($conn, $query);
if ($userResult) {
    while ($row = sqlsrv_fetch_array($userResult, SQLSRV_FETCH_ASSOC)) {
        $teamMembers[] = $row;
    }
    sqlsrv_free_stmt($userResult);
}

$minorTaskTypes = [
    'IT Infra Project', 'Attend IT Tickets and Provide Desktop Support', 'CCTV, Biometrics, Telephone Support',
    'NetSuite Support', 'System Enhancement', 'System Support', 'Development'
];

// --- HELPER FUNCTIONS ---

function getReportsDataForTemplate($conn, $date_from, $date_to, $empcodes, $task_category = 'all') {
    $placeholders = implode(',', array_fill(0, count($empcodes), '?'));
    $sql = "
        SELECT
            r.empcode, u.username, u.department,
            r.report_date, t.task_description, t.task_type, t.resolution,
            t.requested_date, t.completion_date, t.status, t.image_path, t.category
        FROM reports r
        JOIN tasks t ON r.id = t.report_id
        LEFT JOIN users u ON r.empcode = u.empcode
        WHERE r.report_date BETWEEN ? AND ? AND r.empcode IN ($placeholders)
    ";

    $params = array_merge([$date_from, $date_to], $empcodes);

    if ($task_category === 'minor' || $task_category === 'major') {
        $sql .= " AND t.category = ?";
        $params[] = $task_category;
    }

    $sql .= " ORDER BY u.department, r.report_date, t.category DESC, t.created_at";
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return ['error' => 'Database query failed: ' . print_r(sqlsrv_errors(), true)];
    }

    $reportsByDepartment = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $department = $row['department'] ?? 'Unknown Department';
        $reportsByDepartment[$department][] = $row;
    }
    sqlsrv_free_stmt($stmt);

    return [
        'date_from' => $date_from, 'date_to' => $date_to,
        'reports' => $reportsByDepartment, 'task_category' => $task_category
    ];
}

function exportReportsAsExcelTemplate($conn, $date_from, $date_to, $empcodes, $task_category = 'all') {
    $reportData = getReportsDataForTemplate($conn, $date_from, $date_to, $empcodes, $task_category);
    if (isset($reportData['error'])) die('Error fetching data: ' . $reportData['error']);

    $reportsByDepartment = $reportData['reports'];
    $filename = "Daily_Reports_{$task_category}_" . date('Y-m-d') . ".xls";
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    
    $reportTitle = strtoupper($task_category) . ' TASK REPORT';
    $col_span = ($task_category === 'major') ? 5 : 6;
    echo '<tr><td colspan="'.$col_span.'" style="background-color: #4472C4; color: white; font-weight: bold; text-align: center; font-size: 14pt; padding: 8px;">'.$reportTitle.'</td></tr>';
    
    echo '<tr style="background-color: #4472C4; color: white; font-weight: bold; text-align: center;">';
    
    if ($task_category === 'major') {
        echo '<th style="padding: 8px;">Task/Project</th>';
        echo '<th style="padding: 8px;">Start Date</th>';
        echo '<th style="padding: 8px;">End Date</th>';
        echo '<th style="padding: 8px;">Status</th>';
        echo '<th style="padding: 8px;">Image</th>';
    } else {
        echo '<th style="padding: 8px;">IT Team</th>';
        echo '<th style="padding: 8px;">Activity / Task</th>';
        echo '<th style="padding: 8px;">Resolution</th>';
        echo '<th style="padding: 8px;">Req. Date</th>';
        echo '<th style="padding: 8px;">Comp. Date</th>';
        echo '<th style="padding: 8px;">Status</th>';
    }
    echo '</tr>';

    if (empty($reportsByDepartment)) {
        echo '<tr><td colspan="'.$col_span.'" style="text-align: center; padding: 8px;">No reports found.</td></tr>';
    } else {
        foreach ($reportsByDepartment as $department => $reports) {
            foreach ($reports as $report) {
                 if ($task_category !== 'all' && $report['category'] !== $task_category) continue;
                 
                 echo '<tr>';
                 
                 if ($task_category === 'major') {
                    $image_path = $report['image_path'] ?? '';
                    $image_data = '';
                    if (!empty($image_path) && file_exists($image_path)) {
                        $image_type = pathinfo($image_path, PATHINFO_EXTENSION);
                        $data = file_get_contents($image_path);
                        $image_data = 'data:image/' . $image_type . ';base64,' . base64_encode($data);
                    }
                    
                    echo '<td style="padding: 8px; vertical-align: top;">' . htmlspecialchars($report['task_description'] ?? '') . '</td>';
                    $reqDate = $report['requested_date'] ? $report['requested_date']->format('n/j/Y') : '';
                    echo '<td style="padding: 8px; vertical-align: top;">' . $reqDate . '</td>';
                    $compDate = $report['completion_date'] ? $report['completion_date']->format('n/j/Y') : ($report['report_date'] ? $report['report_date']->format('n/j/Y') : '');
                    echo '<td style="padding: 8px; vertical-align: top;">' . $compDate . '</td>';
                    echo '<td style="padding: 8px; vertical-align: top;">' . htmlspecialchars($report['status'] ?? 'Completed') . '</td>';
                    
                    if (!empty($image_data)) {
                        echo '<td style="padding: 8px; text-align: center; vertical-align: middle;"><img src="' . $image_data . '" width="150" alt="Task Image"></td>';
                    } else {
                        echo '<td style="padding: 8px; vertical-align: top;">No Image Provided</td>';
                    }
                 } else {
                    echo '<td style="padding: 8px; vertical-align: top;">' . htmlspecialchars($department ?? '') . '</td>';
                    echo '<td style="padding: 8px; vertical-align: top;">' . htmlspecialchars($report['task_type'] ?? '') . '</td>';
                    echo '<td style="padding: 8px; vertical-align: top;">' . htmlspecialchars($report['task_description'] ?? '') . '</td>';
                    $reqDate = $report['requested_date'] ? $report['requested_date']->format('n/j/Y') : '';
                    echo '<td style="padding: 8px; vertical-align: top;">' . $reqDate . '</td>';
                    $compDate = $report['completion_date'] ? $report['completion_date']->format('n/j/Y') : ($report['report_date'] ? $report['report_date']->format('n/j/Y') : '');
                    echo '<td style="padding: 8px; vertical-align: top;">' . $compDate . '</td>';
                    echo '<td style="padding: 8px; vertical-align: top;">' . htmlspecialchars($report['status'] ?? 'Completed') . '</td>';
                 }
                 
                 echo '</tr>';
            }
        }
    }
    echo '</table></body></html>';
}

function exportReportsAsPdf($conn, $date_from, $date_to, $empcodes, $task_category = 'all') {
    $reportData = getReportsDataForTemplate($conn, $date_from, $date_to, $empcodes, $task_category);
    if (isset($reportData['error'])) die('Error fetching data: ' . $reportData['error']);

    $reportsByDepartment = $reportData['reports'];
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    $pdf->SetCreator('Daily Report System');
    $pdf->SetAuthor('IT Department');
    $pdf->SetTitle("{$task_category} Task Report - {$date_from} to {$date_to}");
    $pdf->SetMargins(10, 15, 10);
    $pdf->SetAutoPageBreak(TRUE, 25);
    
    // Add a page
    $pdf->AddPage();
    
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, strtoupper($task_category) . ' TASK REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, "Date Range: {$date_from} to {$date_to}", 0, 1, 'C');
    $pdf->Ln(8);

    // Define headers and widths
    if ($task_category === 'major') {
        $headers = ['Task/Project', 'Start Date', 'End Date', 'Status', 'Image'];
        $widths = [100, 25, 25, 25, 102];
    } else {
        $headers = ['IT Team', 'Activity / Task', 'Resolution', 'Req. Date', 'Comp. Date', 'Status'];
        $widths = [35, 60, 85, 30, 30, 25];
    }

    // Function to draw the header
    $drawHeader = function() use ($pdf, $headers, $widths) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(68, 114, 196);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 10, $header, 1, 0, 'C', 1);
        }
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 8);
    };

    $drawHeader();

    if (empty($reportsByDepartment)) {
        $pdf->Cell(array_sum($widths), 15, 'No reports found.', 1, 1, 'C');
    } else {
        foreach ($reportsByDepartment as $department => $reports) {
            foreach ($reports as $report) {
                if ($task_category !== 'all' && $report['category'] !== $task_category) continue;

                // Check for page break
                $rowHeight = ($task_category === 'major') ? 40 : 15;
                if ($pdf->GetY() + $rowHeight > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
                    $pdf->AddPage();
                    $drawHeader();
                }
                
                if ($task_category === 'major') {
                    $y_start = $pdf->GetY();
                    $image_file = __DIR__ . DIRECTORY_SEPARATOR . ($report['image_path'] ?? '');
                    
                    $pdf->MultiCell($widths[0], $rowHeight, htmlspecialchars($report['task_description'] ?? ''), 1, 'L', 0, 0, '', '', true, 0, false, true, $rowHeight, 'T');
                    $reqDate = $report['requested_date'] ? $report['requested_date']->format('n/j/Y') : '';
                    $pdf->MultiCell($widths[1], $rowHeight, $reqDate, 1, 'C', 0, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                    $compDate = $report['completion_date'] ? $report['completion_date']->format('n/j/Y') : ($report['report_date'] ? $report['report_date']->format('n/j/Y') : '');
                    $pdf->MultiCell($widths[2], $rowHeight, $compDate, 1, 'C', 0, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                    $pdf->MultiCell($widths[3], $rowHeight, htmlspecialchars($report['status'] ?? 'Completed'), 1, 'C', 0, 0, '', '', true, 0, false, true, $rowHeight, 'M');

                    $x_pos = $pdf->GetX();
                    if (!empty($report['image_path']) && file_exists($image_file)) {
                        @$pdf->Image($image_file, $x_pos + 1, $y_start + 1, $widths[4] - 2, $rowHeight - 2, '', '', 'T', false, 300, '', false, false, 0, 'CM');
                    } else {
                        $pdf->MultiCell($widths[4], $rowHeight, 'No Image Available', 0, 'C', 0, 0, $x_pos, $y_start, true, 0, false, true, $rowHeight, 'M');
                    }
                    // Draw the border for the last cell manually
                    $pdf->MultiCell($widths[4], $rowHeight, '', 1, 'C', 0, 1, $x_pos, $y_start);

                } else {
                    $descHeight = $pdf->getStringHeight($widths[1], $report['task_description'] ?? '');
                    $finalRowHeight = ($descHeight > $rowHeight) ? $descHeight : $rowHeight;

                    if ($pdf->GetY() + $finalRowHeight > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
                        $pdf->AddPage();
                        $drawHeader();
                    }
                    
                    $pdf->MultiCell($widths[0], $finalRowHeight, htmlspecialchars($department ?? ''), 1, 'L', 0, 0, '', '', true, 0, false, true, $finalRowHeight, 'T');
                    $pdf->MultiCell($widths[1], $finalRowHeight, htmlspecialchars($report['task_type'] ?? ''), 1, 'L', 0, 0, '', '', true, 0, false, true, $finalRowHeight, 'T');
                    $pdf->MultiCell($widths[2], $finalRowHeight, htmlspecialchars($report['task_description'] ?? ''), 1, 'L', 0, 0, '', '', true, 0, false, true, $finalRowHeight, 'T');
                    $reqDate = $report['requested_date'] ? $report['requested_date']->format('n/j/Y') : '';
                    $pdf->MultiCell($widths[3], $finalRowHeight, $reqDate, 1, 'C', 0, 0, '', '', true, 0, false, true, $finalRowHeight, 'M');
                    $compDate = $report['completion_date'] ? $report['completion_date']->format('n/j/Y') : ($report['report_date'] ? $report['report_date']->format('n/j/Y') : '');
                    $pdf->MultiCell($widths[4], $finalRowHeight, $compDate, 1, 'C', 0, 0, '', '', true, 0, false, true, $finalRowHeight, 'M');
                    $pdf->MultiCell($widths[5], $finalRowHeight, htmlspecialchars($report['status'] ?? 'Completed'), 1, 'C', 0, 1, '', '', true, 0, false, true, $finalRowHeight, 'M');
                }
            }
        }
    }
    
    $pdf->Output("Daily_Reports_{$task_category}_" . date('Y-m-d') . '.pdf', 'D');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Report System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS styles remain the same as the previous version */
:root {
    --primary: #2563eb;
    --primary-light: #3b82f6;
    --primary-hover: #1d4ed8;
    --success: #10b981;
    --success-hover: #059669;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --warning: #f59e0b;
    --warning-hover: #d97706;
    --info: #06b6d4;
    --info-hover: #0891b2;
    --white: #ffffff;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --body-bg: #f9fafb;
    --card-bg: #ffffff;
    --input-bg: #ffffff;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
    --border-radius: 8px;
    --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s ease;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
html { background-color: var(--body-bg) !important; scroll-behavior: smooth; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: var(--body-bg) !important; color: var(--text-primary) !important; line-height: 1.6; min-height: 100vh; }
.container { max-width: 1400px; margin: 0 auto; padding: 16px; background-color: transparent; }
.header { background-color: var(--card-bg) !important; border-radius: var(--border-radius); box-shadow: var(--shadow); padding: 16px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; border: 1px solid var(--border-color); }
.header h1 { font-size: 1.5rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; }
.user-info { display: flex; align-items: center; gap: 12px; font-size: 0.9rem; }
.user-info span { color: var(--text-secondary); }
.nav-tabs { display: flex; gap: 4px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 2px; background-color: transparent; }
.nav-tab { padding: 8px 16px; background-color: var(--card-bg) !important; border: 1px solid var(--border-color); border-radius: var(--border-radius); cursor: pointer; font-weight: 500; font-size: 0.9rem; color: var(--text-secondary); transition: var(--transition); white-space: nowrap; display: flex; align-items: center; gap: 6px; min-width: fit-content; }
.nav-tab:hover { background-color: var(--gray-50) !important; border-color: var(--gray-300); transform: translateY(-1px); }
.nav-tab.active { background-color: var(--primary) !important; color: var(--white) !important; border-color: var(--primary); box-shadow: var(--shadow-md); }
.card { background-color: var(--card-bg) !important; border-radius: var(--border-radius); box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; border: 1px solid var(--border-color); }
.card-header { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border-color); }
.card-title { font-size: 1.2rem; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.form-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
@media (min-width: 640px) { .form-grid { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); } }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label { font-weight: 500; color: var(--text-secondary); font-size: 0.9rem; }
.form-control { padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: 0.95rem; transition: var(--transition); background-color: var(--input-bg) !important; color: var(--text-primary) !important; }
.form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); background-color: var(--input-bg) !important; }
.form-control:disabled { background-color: var(--gray-50) !important; color: var(--gray-500) !important; cursor: not-allowed; }
textarea.form-control { min-height: 80px; resize: vertical; font-family: inherit; }
select.form-control { cursor: pointer; -webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: none; padding-right: 12px; }
.btn { padding: 8px 16px; border: none; border-radius: var(--border-radius); font-weight: 500; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.9rem; min-height: 36px; border: 1px solid transparent; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-primary { background-color: var(--primary); color: var(--white); border-color: var(--primary); }
.btn-primary:hover:not(:disabled) { background-color: var(--primary-hover); border-color: var(--primary-hover); transform: translateY(-1px); box-shadow: var(--shadow-md); }
.btn-success { background-color: var(--success); color: var(--white); border-color: var(--success); }
.btn-success:hover:not(:disabled) { background-color: var(--success-hover); border-color: var(--success-hover); transform: translateY(-1px); box-shadow: var(--shadow-md); }
.btn-danger { background-color: var(--danger); color: var(--white); border-color: var(--danger); }
.btn-danger:hover:not(:disabled) { background-color: var(--danger-hover); border-color: var(--danger-hover); transform: translateY(-1px); box-shadow: var(--shadow-md); }
.btn-info { background-color: var(--info); color: var(--white); border-color: var(--info); }
.btn-info:hover:not(:disabled) { background-color: var(--info-hover); border-color: var(--info-hover); transform: translateY(-1px); box-shadow: var(--shadow-md); }
.btn-secondary { background-color: var(--gray-600); color: var(--white); border-color: var(--gray-600); }
.btn-secondary:hover:not(:disabled) { background-color: var(--gray-700); border-color: var(--gray-700); transform: translateY(-1px); box-shadow: var(--shadow-md); }
.btn-outline { background-color: var(--white); border-color: var(--border-color); color: var(--text-secondary); }
.btn-outline:hover:not(:disabled) { background-color: var(--gray-50); border-color: var(--gray-300); color: var(--text-primary); }
.action-buttons { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; flex-wrap: wrap; }
.table-container { overflow-x: auto; border-radius: var(--border-radius); border: 1px solid var(--border-color); background-color: var(--white) !important; }
.table { width: 100%; border-collapse: collapse; font-size: 0.9rem; background-color: var(--white) !important; }
.table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color); background-color: var(--white) !important; color: var(--text-primary) !important; }
.table th { background-color: var(--gray-50) !important; font-weight: 600; color: var(--text-secondary) !important; }
.table tbody tr:hover { background-color: var(--gray-50) !important; }
.image-thumbnail { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; cursor: pointer; border: 1px solid var(--border-color); }
.tab-content { display: none; animation: fadeIn 0.3s ease; }
.tab-content.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.task-item { border: 1px solid var(--border-color); border-radius: var(--border-radius); padding: 16px; margin-bottom: 16px; background-color: var(--white) !important; }
.task-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); }
.task-title { font-weight: 600; color: var(--text-primary); }
.checkbox-group { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.checkbox-group input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary); }
.multiselect-container { position: relative; }
.multiselect-display { border: 1px solid var(--border-color); border-radius: var(--border-radius); padding: 8px 12px; min-height: 42px; cursor: pointer; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; background-color: var(--white) !important; color: var(--text-primary) !important; }
.multiselect-display:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); outline: none; }
.selected-item { background-color: var(--primary); color: var(--white); padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; display: flex; align-items: center; gap: 4px; }
.multiselect-dropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; background-color: var(--white) !important; border: 1px solid var(--border-color); border-radius: var(--border-radius); box-shadow: var(--shadow-md); max-height: 200px; overflow-y: auto; z-index: 1000; margin-top: 2px; }
.multiselect-dropdown.show { display: block; }
.multiselect-option { padding: 8px 12px; cursor: pointer; font-size: 0.9rem; background-color: var(--white) !important; color: var(--text-primary) !important; }
.multiselect-option:hover { background-color: var(--gray-50) !important; }
.multiselect-option.selected { background-color: var(--primary) !important; color: var(--white) !important; }
.preview-table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 0.85rem; background-color: var(--white) !important; }
.preview-table th, .preview-table td { border: 1px solid var(--border-color); padding: 8px; background-color: var(--white) !important; color: var(--text-primary) !important; }
.preview-table th { background-color: var(--primary) !important; color: var(--white) !important; font-weight: 600; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 2000; align-items: center; justify-content: center; padding: 16px; }
.modal.show { display: flex; }
.modal-content { background-color: var(--white) !important; border-radius: var(--border-radius); max-width: 95vw; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg); border: 1px solid var(--border-color); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border-color); background-color: var(--white) !important; }
.modal-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); }
.modal-body { padding: 20px; background-color: var(--white) !important; color: var(--text-primary) !important; }
.close-btn { background-color: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gray-400); padding: 4px; border-radius: 4px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; }
.close-btn:hover { color: var(--gray-600); background-color: var(--gray-100); }
.alert { position: fixed; top: 20px; right: 20px; z-index: 3000; padding: 12px 16px; border-radius: var(--border-radius); color: var(--white); font-weight: 500; box-shadow: var(--shadow-lg); max-width: 400px; animation: slideIn 0.3s ease; }
.alert-success { background-color: var(--success); border: 1px solid var(--success-hover); }
.alert-danger { background-color: var(--danger); border: 1px solid var(--danger-hover); }
.alert-info { background-color: var(--info); border: 1px solid var(--info-hover); }
.alert-warning { background-color: var(--warning); border: 1px solid var(--warning-hover); }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.badge { display: inline-block; padding: 4px 8px; font-size: 0.75rem; font-weight: 500; border-radius: 12px; text-align: center; white-space: nowrap; }
.badge-success { background-color: var(--success); color: var(--white); }
.badge-warning { background-color: var(--warning); color: var(--white); }
.badge-info { background-color: var(--info); color: var(--white); }
.badge-secondary { background-color: var(--gray-500); color: var(--white); }
.badge-danger { background-color: var(--danger); color: var(--white); }
.required { color: var(--danger); }
.loading { opacity: 0.6; pointer-events: none; }
.spinner { width: 20px; height: 20px; border: 2px solid var(--gray-300); border-top: 2px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@media (max-width: 640px) { .container { padding: 12px; } .header { flex-direction: column; align-items: flex-start; gap: 12px; padding: 16px; } .header h1 { font-size: 1.3rem; } .user-info { font-size: 0.85rem; width: 100%; justify-content: space-between; } .nav-tabs { gap: 2px; margin-bottom: 16px; } .nav-tab { padding: 8px 12px; font-size: 0.85rem; flex: 1; justify-content: center; } .card { padding: 16px; } .card-title { font-size: 1.1rem; } .form-grid { gap: 12px; grid-template-columns: 1fr; } .btn { padding: 10px 16px; font-size: 0.9rem; } .action-buttons { flex-direction: column; align-items: stretch; } .action-buttons .btn { justify-content: center; } .table { font-size: 0.8rem; } .table th, .table td { padding: 8px 6px; } .modal-content { margin: 8px; max-width: calc(100vw - 16px); } .modal-header, .modal-body { padding: 16px; } .task-item { padding: 12px; } }
@media (max-width: 480px) { .form-control { font-size: 16px; } .nav-tab { font-size: 0.8rem; padding: 6px 8px; } .btn { font-size: 0.85rem; padding: 8px 12px; } }
@media print { .nav-tabs, .action-buttons, .btn { display: none !important; } .card { box-shadow: none; border: 1px solid #ccc; background-color: white !important; } body { background-color: white !important; } }
.btn:focus-visible, .form-control:focus-visible, .nav-tab:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.text-center { text-align: center; } .text-right { text-align: right; } .text-left { text-align: left; } .text-sm { font-size: 0.875rem; } .text-xs { font-size: 0.75rem; } .text-lg { font-size: 1.125rem; } .font-normal { font-weight: 400; } .font-medium { font-weight: 500; } .font-semibold { font-weight: 600; } .font-bold { font-weight: 700; } .mt-1 { margin-top: 4px; } .mt-2 { margin-top: 8px; } .mt-4 { margin-top: 16px; } .mb-1 { margin-bottom: 4px; } .mb-2 { margin-bottom: 8px; } .mb-4 { margin-bottom: 16px; } .mr-2 { margin-right: 8px; } .ml-2 { margin-left: 8px; } .p-2 { padding: 8px; } .p-4 { padding: 16px; } .px-2 { padding-left: 8px; padding-right: 8px; } .py-2 { padding-top: 8px; padding-bottom: 8px; } .hidden { display: none !important; } .block { display: block; } .inline-block { display: inline-block; } .flex { display: flex; } .inline-flex { display: inline-flex; } .grid { display: grid; } .items-center { align-items: center; } .items-start { align-items: flex-start; } .items-end { align-items: flex-end; } .justify-center { justify-content: center; } .justify-between { justify-content: space-between; } .justify-end { justify-content: flex-end; } .gap-1 { gap: 4px; } .gap-2 { gap: 8px; } .gap-4 { gap: 16px; } .w-full { width: 100%; } .h-full { height: 100%; } .rounded { border-radius: var(--border-radius); } .shadow { box-shadow: var(--shadow); } .shadow-md { box-shadow: var(--shadow-md); } .shadow-lg { box-shadow: var(--shadow-lg); }
::-webkit-scrollbar { width: 8px; height: 8px; } ::-webkit-scrollbar-track { background: var(--gray-100); border-radius: 4px; } ::-webkit-scrollbar-thumb { background: var(--gray-400); border-radius: 4px; } ::-webkit-scrollbar-thumb:hover { background: var(--gray-500); }
::selection { background-color: rgba(37, 99, 235, 0.2); color: var(--text-primary); }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1><i class="fas fa-clipboard-list"></i> Daily Report System</h1>
            <div class="user-info">
                <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                <span class="text-sm">(<?php echo htmlspecialchars($_SESSION['empcode']); ?>)</span>
                <a href="logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i><span class="hidden sm:inline">Logout</span></a>
            </div>
        </header>

        <nav class="nav-tabs">
            <button class="nav-tab active" data-tab="create-report"><i class="fas fa-plus-circle"></i><span>Create</span></button>
            <button class="nav-tab" data-tab="minor-reports"><i class="fas fa-tasks"></i><span>Minor Tasks</span></button>
            <button class="nav-tab" data-tab="major-reports"><i class="fas fa-star"></i><span>Major Tasks</span></button>
            <button class="nav-tab" data-tab="export"><i class="fas fa-download"></i><span>Export</span></button>
        </nav>

        <div id="create-report" class="tab-content active">
            <div class="card">
                <div class="card-header"><h2 class="card-title"><i class="fas fa-plus-circle"></i> Create Daily Report for <?php echo date("F j, Y"); ?></h2></div>
                <form id="reportForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_report">
                    <div id="tasksList"></div>
                    <div class="mt-4 mb-4"><button type="button" class="btn btn-secondary" onclick="addTask()"><i class="fas fa-plus"></i> Add Task</button></div>
                    <div class="action-buttons"><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Report</button></div>
                </form>
            </div>
        </div>
        <div id="minor-reports" class="tab-content">
            <div class="card">
                <div class="card-header"><h2 class="card-title"><i class="fas fa-tasks"></i> Minor Tasks Log</h2></div>
                <div class="flex gap-4 p-4 border-b" style="border-color: var(--border-color);">
                    <div class="form-group flex-1">
                        <label for="minorTaskTypeFilter" class="form-label">Task Type</label>
                        <select id="minorTaskTypeFilter" class="form-control"><option value="all">All Task Types</option></select>
                    </div>
                    <div class="form-group flex-1">
                        <label for="minorStatusFilter" class="form-label">Status</label>
                        <select id="minorStatusFilter" class="form-control">
                            <option value="all">All Statuses</option><option value="Completed">Completed</option><option value="In Progress">In Progress</option><option value="Pending">Pending</option><option value="On Hold">On Hold</option><option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Task Type</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="minorReportsTableBody"><tr><td colspan="5" class="text-center">Loading...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div id="major-reports" class="tab-content">
            <div class="card">
                <div class="card-header"><h2 class="card-title"><i class="fas fa-star"></i> Major Tasks Log</h2></div>
                <div class="table-container">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Description</th><th>Image</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="majorReportsTableBody"><tr><td colspan="5" class="text-center">Loading...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div id="export" class="tab-content">
            <div class="card">
                <div class="card-header"><h2 class="card-title"><i class="fas fa-download"></i> Export Reports</h2></div>
                <form id="exportForm" method="POST" action="dailyreport.php">
                    <input type="hidden" name="action" value="export_reports">
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">From Date <span class="required">*</span></label><input type="date" class="form-control" name="date_from" required></div>
                        <div class="form-group"><label class="form-label">To Date <span class="required">*</span></label><input type="date" class="form-control" name="date_to" required></div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="exportAllMembers" name="export_all" value="true" onchange="toggleMemberSelection()">
                        <label for="exportAllMembers" class="form-label">Export For All Team Members</label>
                    </div>
                    <div class="form-group" id="memberSelectionGroup">
                        <label class="form-label">Select Team Members <span class="required">*</span></label>
                        <div class="multiselect-container">
                            <div class="multiselect-display" id="multiselectDisplay" tabindex="0">Click to select...</div>
                            <div class="multiselect-dropdown" id="multiselectDropdown">
                                <?php foreach ($teamMembers as $member): ?><div class="multiselect-option" data-value="<?php echo htmlspecialchars($member['empcode']); ?>"><?php echo htmlspecialchars($member['username'] . ' (' . $member['empcode'] . ' - ' . $member['department'] . ')'); ?></div><?php endforeach; ?>
                            </div>
                            <select name="empcodes[]" id="empcodesSelect" multiple style="display: none;"><?php foreach ($teamMembers as $member): ?><option value="<?php echo htmlspecialchars($member['empcode']); ?>"></option><?php endforeach; ?></select>
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Task Category</label><select class="form-control" name="task_category"><option value="all">All Tasks</option><option value="minor">Minor Tasks Only</option><option value="major">Major Tasks Only</option></select></div>
                    <div class="form-group"><label class="form-label">Export Format</label><select class="form-control" name="format"><option value="excel">Excel (.xls)</option><option value="pdf">PDF (.pdf)</option></select></div>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-info" onclick="previewExport()"><i class="fas fa-eye"></i> Preview</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="imageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h3 class="modal-title">Task Image</h3><button class="close-btn" onclick="closeModal('imageModal')" aria-label="Close">&times;</button></div>
            <div class="modal-body"><img id="modalImage" style="max-width: 100%; border-radius: var(--border-radius);" alt="Task image"></div>
        </div>
    </div>
    <div id="exportPreviewModal" class="modal">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="modal-header"><h3 class="modal-title">Export Preview</h3><button class="close-btn" onclick="closeModal('exportPreviewModal')" aria-label="Close">&times;</button></div>
            <div class="modal-body"><div id="exportPreviewContent"></div></div>
        </div>
    </div>

    <script>
        let taskCounter = 0;
        const minorTaskTypes = <?php echo json_encode($minorTaskTypes); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            initializeMultiselect();
            populateMinorTaskFilter();
            addTask();
            loadReports('minor');
            loadReports('major');
        });

        function setupEventListeners() {
            document.querySelectorAll('.nav-tab').forEach(tab => tab.addEventListener('click', function() { switchTab(this.dataset.tab); }));
            document.getElementById('reportForm').addEventListener('submit', handleFormSubmit);
            document.getElementById('minorTaskTypeFilter')?.addEventListener('change', filterMinorReports);
            document.getElementById('minorStatusFilter')?.addEventListener('change', filterMinorReports);
            document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', function(e) { if (e.target === this) closeModal(this.id); }));
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(modal => closeModal(modal.id)); });
        }

        function populateMinorTaskFilter() {
            const select = document.getElementById('minorTaskTypeFilter');
            if (!select) return;
            minorTaskTypes.forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                select.appendChild(option);
            });
        }

        function filterMinorReports() {
            const typeFilter = document.getElementById('minorTaskTypeFilter').value;
            const statusFilter = document.getElementById('minorStatusFilter').value;
            const tableBody = document.getElementById('minorReportsTableBody');
            
            tableBody.querySelectorAll('tr').forEach(row => {
                if (row.cells.length < 5) return;
                const taskType = row.cells[1].textContent.trim();
                const status = row.cells[3].textContent.trim();
                const typeMatch = (typeFilter === 'all' || taskType === typeFilter);
                const statusMatch = (statusFilter === 'all' || status === statusFilter);
                row.style.display = (typeMatch && statusMatch) ? '' : 'none';
            });
        }

        function switchTab(tabId) {
            document.querySelectorAll('.nav-tab, .tab-content').forEach(el => el.classList.remove('active'));
            document.querySelector(`[data-tab="${tabId}"]`).classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        function showAlert(message, type = 'success') {
            document.querySelectorAll('.alert').forEach(alert => alert.remove());
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `<span>${message}</span>`;
            document.body.appendChild(alertDiv);
            setTimeout(() => { if (alertDiv.parentNode) alertDiv.remove(); }, 4000);
        }

        async function loadReports(category) {
            const tbody = document.getElementById(`${category}ReportsTableBody`);
            tbody.innerHTML = `<tr><td colspan="5" class="text-center">Loading...</td></tr>`;
            try {
                const response = await fetch(`dailyreport.php?action=fetch_reports&category=${category}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const reports = await response.json();
                if (reports.error) throw new Error(reports.error);
                let html = '';
                if (reports.length === 0) {
                    html = `<tr><td colspan="5" class="text-center">No ${category} reports found.</td></tr>`;
                } else {
                    reports.forEach(report => {
                        report.tasks.forEach(task => {
                            html += `
                                <tr data-task-id="${task.id}">
                                    <td>${new Date(report.date.date).toLocaleDateString()}</td>
                                    ${category === 'minor' ? `<td>${htmlspecialchars(task.task_type || 'N/A')}</td>` : ''}
                                    <td>${htmlspecialchars(task.description)}</td>
                                    ${category === 'major' ? `<td>${task.image ? `<img src="${task.image}" class="image-thumbnail" onclick="openImageModal('${task.image}')" alt="Task image">` : 'No Image'}</td>` : ''}
                                    <td><span class="badge badge-${getStatusColor(task.status || 'Completed')}">${htmlspecialchars(task.status || 'Completed')}</span></td>
                                    <td><button class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8rem;" onclick="deleteTask(${task.id}, '${category}')" title="Delete task"><i class="fas fa-trash"></i></button></td>
                                </tr>`;
                        });
                    });
                }
                tbody.innerHTML = html;
                if (category === 'minor') filterMinorReports();
            } catch (error) {
                console.error(`Error loading ${category} reports:`, error);
                tbody.innerHTML = `<tr><td colspan="5" class="text-center">Error loading reports. Please try again.</td></tr>`;
            }
        }

        function getStatusColor(status) {
            const colors = { 'Completed': 'success', 'In Progress': 'warning', 'Pending': 'info', 'On Hold': 'secondary', 'Cancelled': 'danger' };
            return colors[status] || 'secondary';
        }

        async function handleFormSubmit(e) {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            submitBtn.disabled = true;
            try {
                const formData = new FormData(e.target);
                const response = await fetch('dailyreport.php', { method: 'POST', body: formData });
                if (!response.ok) throw new Error(`Network error: ${response.statusText}`);
                const result = await response.json();
                if (result.success) {
                    showAlert('Report submitted successfully!', 'success');
                    e.target.reset();
                    document.getElementById('tasksList').innerHTML = '';
                    addTask();
                    loadReports('minor');
                    loadReports('major');
                } else {
                    showAlert(`Error: ${result.error}`, 'danger');
                }
            } catch (error) {
                console.error('Error submitting form:', error);
                showAlert('An unexpected error occurred. Please try again.', 'danger');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        }

        async function deleteTask(taskId, category) {
            if (!confirm('Are you sure you want to delete this task?')) return;
            try {
                const response = await fetch('delete_task.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: taskId })
                });
                if (!response.ok) throw new Error('Network response was not ok');
                const result = await response.json();
                if (result.success) {
                    showAlert('Task deleted successfully.', 'success');
                    loadReports(category);
                } else {
                    showAlert(`Error: ${result.error}`, 'danger');
                }
            } catch (error) {
                console.error('Error deleting task:', error);
                showAlert('Failed to delete task. Please try again.', 'danger');
            }
        }

        function addTask() {
            const taskIndex = taskCounter++;
            const taskItem = document.createElement('div');
            taskItem.className = 'task-item';
            taskItem.innerHTML = `
                <div class="task-header">
                    <h4 class="task-title">Task ${taskIndex + 1}</h4>
                    ${taskIndex > 0 ? `<button type="button" class="btn btn-danger" onclick="removeTask(this)" title="Remove task"><i class="fas fa-times"></i></button>` : ''}
                </div>
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div class="form-group">
                        <label class="form-label">Category <span class="required">*</span></label>
                        <select class="form-control" name="task_categories[]" required onchange="handleCategoryChange(this)">
                            <option value="">-- Select --</option><option value="minor">Minor Task</option><option value="major">Major Task</option>
                        </select>
                    </div>
                    <div class="form-group task-type-group">
                        <label class="form-label">Task Type <span class="required">*</span></label>
                        <select class="form-control" name="task_types[]" required>
                            <option value="">-- Select --</option>
                            ${minorTaskTypes.map(type => `<option value="${type}">${type}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status <span class="required">*</span></label>
                        <select class="form-control" name="statuses[]" required>
                            <option value="">-- Select --</option><option value="Completed">Completed</option><option value="In Progress">In Progress</option>
                        </select>
                    </div>
                </div>
                <div class="form-group mt-2">
                    <label class="form-label">Description/Resolution <span class="required">*</span></label>
                    <textarea name="tasks_completed[]" required class="form-control" placeholder="Provide detailed description..."></textarea>
                </div>
                <div class="form-group major-task-image mt-2" style="display: none;">
                    <label class="form-label">Task Image</label>
                    <input type="file" class="form-control" name="task_images[]" accept="image/*">
                    <small class="text-xs text-gray-500 mt-1">Optional. Supported formats: JPG, PNG, GIF</small>
                </div>`;
            document.getElementById('tasksList').appendChild(taskItem);
            handleCategoryChange(taskItem.querySelector('select[name="task_categories[]"]'));
        }

        function removeTask(button) {
            if (confirm('Are you sure?')) {
                button.closest('.task-item').remove();
                updateTaskNumbers();
            }
        }

        function updateTaskNumbers() {
            document.querySelectorAll('.task-item .task-title').forEach((title, index) => {
                title.textContent = `Task ${index + 1}`;
            });
        }

        function handleCategoryChange(select) {
            const taskItem = select.closest('.task-item');
            const category = select.value;
            const taskTypeGroup = taskItem.querySelector('.task-type-group');
            const taskTypeSelect = taskItem.querySelector('select[name="task_types[]"]');
            const imageGroup = taskItem.querySelector('.major-task-image');
            const imageInput = taskItem.querySelector('input[type="file"]');

            taskTypeGroup.style.display = category === 'minor' ? 'block' : 'none';
            taskTypeSelect.required = category === 'minor';
            imageGroup.style.display = category === 'major' ? 'block' : 'none';
            imageInput.required = false; 
            
            if (category !== 'minor') taskTypeSelect.value = '';
            if (category !== 'major') imageInput.value = '';
        }

        function toggleMemberSelection() {
            const isChecked = document.getElementById('exportAllMembers').checked;
            document.getElementById('memberSelectionGroup').style.display = isChecked ? 'none' : 'block';
            if (isChecked) {
                document.querySelectorAll('#empcodesSelect option').forEach(option => { option.selected = false; });
                window.updateMultiselectDisplay();
            }
        }

        async function previewExport() {
            const form = document.getElementById('exportForm');
            const formData = new FormData(form);
            formData.set('action', 'preview_reports');
            const previewBtn = document.querySelector('button[onclick="previewExport()"]');
            const originalText = previewBtn.innerHTML;
            previewBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            previewBtn.disabled = true;
            try {
                const response = await fetch('dailyreport.php', { method: 'POST', body: formData });
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                if (data.error) {
                    showAlert(data.error, 'danger');
                    return;
                }
                displayExportPreview(data);
            } catch (error) {
                console.error('Error previewing export:', error);
                showAlert('Failed to load preview.', 'danger');
            } finally {
                previewBtn.innerHTML = originalText;
                previewBtn.disabled = false;
            }
        }

        function displayExportPreview(data) {
            const content = document.getElementById('exportPreviewContent');
            const isMajor = data.task_category === 'major';
            const headers = isMajor 
                ? ['Task/Project', 'Start Date', 'End Date', 'Status', 'Image'] 
                : ['IT Team', 'Activity/Task', 'Resolution', 'Req. Date', 'Comp. Date', 'Status'];
            
            let html = `
                <div class="mb-4">
                    <h4>Export Preview</h4>
                    <p class="text-sm text-gray-600">Range: ${data.date_from} to ${data.date_to} | Category: ${data.task_category.toUpperCase()}</p>
                </div>
                <table class="preview-table">
                    <thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
                    <tbody>`;
            if (!data.reports || Object.keys(data.reports).length === 0) {
                html += `<tr><td colspan="${headers.length}" class="text-center">No reports found.</td></tr>`;
            } else {
                for (const department in data.reports) {
                    data.reports[department].forEach(report => {
                        const reqDate = report.requested_date ? new Date(report.requested_date.date).toLocaleDateString() : '';
                        const compDate = report.completion_date ? new Date(report.completion_date.date).toLocaleDateString() : (report.report_date ? new Date(report.report_date.date).toLocaleDateString() : '');
                        html += `<tr>`;
                        if (isMajor) {
                            html += `<td>${htmlspecialchars(report.task_description)}</td><td>${reqDate}</td><td>${compDate}</td><td>${htmlspecialchars(report.status)}</td><td>${report.image_path ? 'Yes' : 'No'}</td>`;
                        } else {
                            html += `<td>${htmlspecialchars(department)}</td><td>${htmlspecialchars(report.task_type)}</td><td>${htmlspecialchars(report.task_description)}</td><td>${reqDate}</td><td>${compDate}</td><td>${htmlspecialchars(report.status)}</td>`;
                        }
                        html += `</tr>`;
                    });
                }
            }
            html += `</tbody></table>`;
            content.innerHTML = html;
            openModal('exportPreviewModal');
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('show');
            const focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            const firstFocusable = focusable[0];
            firstFocusable?.focus();
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function openImageModal(src) {
            document.getElementById('modalImage').src = src;
            openModal('imageModal');
        }

        function initializeMultiselect() {
            const display = document.getElementById('multiselectDisplay');
            const dropdown = document.getElementById('multiselectDropdown');
            const select = document.getElementById('empcodesSelect');

            display.addEventListener('click', () => dropdown.classList.toggle('show'));
            display.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropdown.classList.toggle('show'); } });
            
            dropdown.querySelectorAll('.multiselect-option').forEach(option => {
                option.addEventListener('click', function() {
                    const value = this.dataset.value;
                    const selectOption = select.querySelector(`option[value="${value}"]`);
                    selectOption.selected = !selectOption.selected;
                    updateMultiselectDisplay();
                });
            });

            document.addEventListener('click', e => { if (!display.parentElement.contains(e.target)) dropdown.classList.remove('show'); });

            function updateMultiselectDisplay() {
                const selected = Array.from(select.selectedOptions);
                display.innerHTML = selected.length === 0 
                    ? 'Click to select...' 
                    : selected.map(opt => `<span class="selected-item">${dropdown.querySelector(`.multiselect-option[data-value="${opt.value}"]`).textContent.split('(')[0].trim()}</span>`).join('');
                
                dropdown.querySelectorAll('.multiselect-option').forEach(opt => {
                    opt.classList.toggle('selected', select.querySelector(`option[value="${opt.dataset.value}"]`).selected);
                });
            }
            updateMultiselectDisplay();
            window.updateMultiselectDisplay = updateMultiselectDisplay;
        }

        function htmlspecialchars(str) {
            if (str === null || str === undefined) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(str).replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>
<?php
if ($conn) {
    sqlsrv_close($conn);
}
?>