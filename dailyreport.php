<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- DATABASE CONNECTION SETUP ---
$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("Database connection failed. Details: " . print_r(sqlsrv_errors(), true));
}

// --- LOAD TCPDF LIBRARY AT THE TOP ---
require_once('tcpdf/tcpdf.php');

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

    if (empty($date_from) || empty($date_to) || empty($empcodes)) {
        die('Missing required parameters for export.');
    }

    if ($format === 'pdf') {
        exportReportsAsPdf($conn, $date_from, $date_to, $empcodes);
        exit;
    } else {
        exportReportsAsExcelHtml($conn, $date_from, $date_to, $empcodes);
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

    if (empty($date_from) || empty($date_to) || empty($empcodes)) {
        echo json_encode(['error' => 'Missing required parameters for preview.']);
        exit;
    }

    $data = getReportsData($conn, $date_from, $date_to, $empcodes);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    $_SESSION['redirect_after_login'] = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: login.php");
    exit();
}

// Table creation logic...
$createReportsTable = "
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'reports')
BEGIN
    CREATE TABLE daily_report_db.dbo.reports (
        id INT IDENTITY(1,1) PRIMARY KEY,
        empcode NVARCHAR(50) NOT NULL,
        report_date DATE NOT NULL,
        created_at DATETIME DEFAULT GETDATE(),
        updated_at DATETIME DEFAULT GETDATE()
    )
END";
$createTasksTable = "
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tasks')
BEGIN
    CREATE TABLE daily_report_db.dbo.tasks (
        id INT IDENTITY(1,1) PRIMARY KEY,
        report_id INT NOT NULL,
        task_description NVARCHAR(2000) NOT NULL,
        image_path NVARCHAR(255) NOT NULL,
        task_name NVARCHAR(255),
        assigned_to NVARCHAR(50),
        FOREIGN KEY (report_id) REFERENCES daily_report_db.dbo.reports(id) ON DELETE CASCADE
    )
END";
sqlsrv_query($conn, $createReportsTable);
sqlsrv_query($conn, $createTasksTable);

// Fetch team members
$teamMembers = [];
$query = "SELECT empcode, username FROM users ORDER BY username";
$userResult = sqlsrv_query($conn, $query);
if ($userResult) {
    while ($row = sqlsrv_fetch_array($userResult, SQLSRV_FETCH_ASSOC)) {
        $teamMembers[] = $row;
    }
    sqlsrv_free_stmt($userResult);
}

function getReportsData($conn, $date_from, $date_to, $empcodes) {
    $placeholders = implode(',', array_fill(0, count($empcodes), '?'));
    $sql = "
        SELECT
            r.empcode,
            u.username,
            r.report_date,
            r.created_at,
            t.task_description,
            t.image_path
        FROM daily_report_db.dbo.reports r
        JOIN daily_report_db.dbo.tasks t ON r.id = t.report_id
        LEFT JOIN users u ON r.empcode = u.empcode
        WHERE r.report_date BETWEEN ? AND ?
        AND r.empcode IN ($placeholders)
        ORDER BY r.report_date, r.empcode, t.id
    ";

    $params = array_merge([$date_from, $date_to], $empcodes);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return ['error' => 'Database query failed: ' . print_r(sqlsrv_errors(), true)];
    }

    $reportsByEmployee = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $empcode = $row['empcode'];
        if (!isset($reportsByEmployee[$empcode])) {
            $reportsByEmployee[$empcode] = [
                'username' => $row['username'] ?: $empcode,
                'reports' => []
            ];
        }
        $reportsByEmployee[$empcode]['reports'][] = $row;
    }
    sqlsrv_free_stmt($stmt);

    return [
        'date_from' => $date_from,
        'date_to' => $date_to,
        'reports' => $reportsByEmployee
    ];
}

function getMimeType($filePath) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mime;
    }
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp'
    ];
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}

function exportReportsAsExcelHtml($conn, $date_from, $date_to, $empcodes) {
    $reportData = getReportsData($conn, $date_from, $date_to, $empcodes);

    if (isset($reportData['error'])) {
        die('Error fetching data: ' . $reportData['error']);
    }

    $reportsByEmployee = $reportData['reports'];
    $filename = "Daily_Reports_" . date('Y-m-d') . ".xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $uploads_dir = __DIR__ . '/Uploads/';

    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4472C4; color: white; font-weight: bold; text-align: center; vertical-align: middle; padding: 10px; border: 1px solid #2F4F4F; }
        td { border: 1px solid #CCCCCC; padding: 8px; vertical-align: middle; min-height: 60px; }
        .align-center-middle { text-align: center; vertical-align: middle; }
        .align-left-top { text-align: left; vertical-align: top; white-space: pre-wrap; word-wrap: break-word; max-width: 300px; }
        .image-cell { text-align: center; vertical-align: middle; width: 150px; height: 120px; }
        .title { font-size: 14pt; font-weight: bold; text-align: center; margin: 20px 0; color: #4472C4; }
        .report-image { max-width: 100px; max-height: 100px; border-radius: 5px; }
    </style></head><body>';

    echo '<div class="title">DAILY REPORT EXPORT</div>';
    echo '<div><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' to ' . htmlspecialchars($date_to) . '</div>';
    echo '<div><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</div><br>';
    echo '<table><thead><tr>
        <th style="width: 100px;">Report Date</th>
        <th style="width: 100px;">Employee Code</th>
        <th style="width: 150px;">Username</th>
        <th style="width: 300px;">Task Description</th>
        <th style="width: 150px;">Image</th>
        <th style="width: 150px;">Created At</th>
    </tr></thead><tbody>';

    if (empty($reportsByEmployee)) {
        echo '<tr><td colspan="6" class="align-center-middle">No reports found.</td></tr>';
    } else {
        foreach ($reportsByEmployee as $empcode => $data) {
            foreach ($data['reports'] as $report) {
                echo '<tr>';
                echo '<td class="align-center-middle">' . ($report['report_date'] instanceof DateTime ? $report['report_date']->format('Y-m-d') : htmlspecialchars($report['report_date'])) . '</td>';
                echo '<td class="align-center-middle">' . htmlspecialchars($report['empcode']) . '</td>';
                echo '<td class="align-center-middle">' . htmlspecialchars($report['username'] ?: $report['empcode']) . '</td>';
                echo '<td class="align-left-top">' . htmlspecialchars($report['task_description'] ?: 'No task description') . '</td>';
                echo '<td class="image-cell">';
                if (!empty($report['image_path'])) {
                    $image_filename = basename($report['image_path']);
                    $image_path = rtrim($uploads_dir, '/\\') . DIRECTORY_SEPARATOR . $image_filename;
                    if (file_exists($image_path) && is_readable($image_path)) {
                        $mime = getMimeType($image_path);
                        $base64 = base64_encode(file_get_contents($image_path));
                        echo '<img src="data:' . $mime . ';base64,' . $base64 . '" alt="Task Image" class="report-image">';
                    } else {
                        echo '<span>Image not found</span>';
                    }
                } else {
                    echo '<span>No image</span>';
                }
                echo '</td>';
                echo '<td class="align-center-middle">' . ($report['created_at'] instanceof DateTime ? $report['created_at']->format('Y-m-d H:i') : htmlspecialchars($report['created_at'])) . '</td>';
                echo '</tr>';
            }
        }
    }
    echo '</tbody></table></body></html>';
    exit;
}

// --- REVISED PDF TABLE DRAWING LOGIC ---
function exportReportsAsPdf($conn, $date_from, $date_to, $empcodes) {
    $reportData = getReportsData($conn, $date_from, $date_to, $empcodes);

    if (isset($reportData['error'])) {
        die('Error fetching data: ' . $reportData['error']);
    }

    $reportsByEmployee = $reportData['reports'];
    $uploads_dir = __DIR__ . '/Uploads/';

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Daily Report System');
    $pdf->SetAuthor('Daily Report System');
    $pdf->SetTitle('Daily Reports');
    $pdf->SetMargins(12, 18, 12);
    $pdf->SetAutoPageBreak(TRUE, 25);
    $pdf->AddPage();

    // Title section
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, 'DAILY REPORT EXPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Date Range: ' . $date_from . ' to ' . $date_to, 0, 1, 'C');
    $pdf->Cell(0, 8, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    $pdf->Ln(6);

    $header = ['Report Date', 'Username', 'Employee Code', 'Task Description', 'Image', 'Created At'];
    $widths = [30, 30, 40, 80, 50, 35]; // Adjusted widths - increased image column

    // Function to draw header
    $drawHeader = function($pdf) use ($header, $widths) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(68, 114, 196);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($header as $i => $col) {
            $pdf->Cell($widths[$i], 10, $col, 1, 0, 'C', 1);
        }
        $pdf->Ln();
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
    };

    $drawHeader($pdf);

    if (empty($reportsByEmployee)) {
        $pdf->Cell(array_sum($widths), 12, 'No reports found.', 1, 1, 'C');
    } else {
        foreach ($reportsByEmployee as $empcode => $data) {
            foreach ($data['reports'] as $report) {
                $rowHeight = 35; // Increased row height for better image display

                // Check for page break BEFORE drawing the row
                if ($pdf->GetY() + $rowHeight > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
                    $pdf->AddPage();
                    $drawHeader($pdf);
                }

                $startX = $pdf->GetX();
                $startY = $pdf->GetY();

                // Draw cell borders first
                $pdf->Rect($startX, $startY, $widths[0], $rowHeight);
                $pdf->Rect($startX + $widths[0], $startY, $widths[1], $rowHeight);
                $pdf->Rect($startX + $widths[0] + $widths[1], $startY, $widths[2], $rowHeight);
                $pdf->Rect($startX + $widths[0] + $widths[1] + $widths[2], $startY, $widths[3], $rowHeight);
                $pdf->Rect($startX + $widths[0] + $widths[1] + $widths[2] + $widths[3], $startY, $widths[4], $rowHeight);
                $pdf->Rect($startX + $widths[0] + $widths[1] + $widths[2] + $widths[3] + $widths[4], $startY, $widths[5], $rowHeight);

                // Reset cursor position for content
                $currentX = $startX;

                // Column 1: Report Date
                $reportDate = ($report['report_date'] instanceof DateTime) 
                    ? $report['report_date']->format('Y-m-d') 
                    : $report['report_date'];
                $pdf->SetXY($currentX, $startY);
                $pdf->MultiCell($widths[0], $rowHeight, $reportDate, 0, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $currentX += $widths[0];

                // Column 2: Employee Code
                $pdf->SetXY($currentX, $startY);
                $pdf->MultiCell($widths[1], $rowHeight, $report['empcode'], 0, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $currentX += $widths[1];

                // Column 3: Username
                $username = $data['username'] ?: $report['empcode'];
                $pdf->SetXY($currentX, $startY);
                $pdf->MultiCell($widths[2], $rowHeight, $username, 0, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $currentX += $widths[2];

                // Column 4: Task Description
                $taskDescription = $report['task_description'] ?: 'No task description';
                $pdf->SetXY($currentX, $startY);
                $pdf->MultiCell($widths[3], $rowHeight, $taskDescription, 0, 'L', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $currentX += $widths[3];

                // Column 5: Image - THIS IS THE KEY ENHANCEMENT
                $imageX = $currentX;
                $imageY = $startY;
                $imageWidth = $widths[4];
                $imageHeight = $rowHeight;
                
                // Prepare image path
                $image_filename = !empty($report['image_path']) ? basename($report['image_path']) : '';
                $image_path = '';
                
                if (!empty($image_filename)) {
                    $image_path = rtrim($uploads_dir, '/\\') . DIRECTORY_SEPARATOR . $image_filename;
                }

                // Check if image exists and is valid
                $imageDisplayed = false;
                if (!empty($image_path) && file_exists($image_path) && is_readable($image_path)) {
                    // Get image info to maintain aspect ratio
                    $imageInfo = getimagesize($image_path);
                    if ($imageInfo !== false) {
                        $imgWidth = $imageInfo[0];
                        $imgHeight = $imageInfo[1];
                        
                        // Calculate scaled dimensions to fit within cell with padding
                        $padding = 3;
                        $maxWidth = $imageWidth - (2 * $padding);
                        $maxHeight = $imageHeight - (2 * $padding);
                        
                        $scaleX = $maxWidth / $imgWidth;
                        $scaleY = $maxHeight / $imgHeight;
                        $scale = min($scaleX, $scaleY);
                        
                        $scaledWidth = $imgWidth * $scale;
                        $scaledHeight = $imgHeight * $scale;
                        
                        // Center the image in the cell
                        $imgX = $imageX + ($imageWidth - $scaledWidth) / 2;
                        $imgY = $imageY + ($imageHeight - $scaledHeight) / 2;
                        
                        try {
                            // Place the image
                            $pdf->Image(
                                $image_path, 
                                $imgX, 
                                $imgY, 
                                $scaledWidth, 
                                $scaledHeight, 
                                '', 
                                '', 
                                'T', 
                                false, 
                                300, 
                                '', 
                                false, 
                                false, 
                                0, 
                                false, 
                                false, 
                                false
                            );
                            $imageDisplayed = true;
                        } catch (Exception $e) {
                            // Image failed to load, will show placeholder text
                            error_log("PDF Image Error: " . $e->getMessage());
                        }
                    }
                }
                
                // If image wasn't displayed, show placeholder text
                if (!$imageDisplayed) {
                    $pdf->SetXY($imageX, $imageY);
                    $placeholderText = empty($image_filename) ? 'No image' : 'Image not found';
                    $pdf->MultiCell($imageWidth, $imageHeight, $placeholderText, 0, 'C', false, 0, '', '', true, 0, false, true, $imageHeight, 'M');
                }
                
                $currentX += $widths[4];

                // Column 6: Created At
                $createdAt = ($report['created_at'] instanceof DateTime) 
                    ? $report['created_at']->format('Y-m-d H:i') 
                    : $report['created_at'];
                $pdf->SetXY($currentX, $startY);
                $pdf->MultiCell($widths[5], $rowHeight, $createdAt, 0, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');

                // Move to next row
                $pdf->SetXY($startX, $startY + $rowHeight);
            }
        }
    }

    $pdf->Output('Daily_Reports_' . date('Y-m-d') . '.pdf', 'D');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Daily Report System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #38c172 0%, #2d995b 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            --info-gradient: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            --dark-gradient: linear-gradient(135deg, #2d3436 0%, #636e72 100%);
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-primary: #2d3436;
            --text-secondary: #636e72;
            --border-color: #e9ecef;
            --shadow-light: 0 2px 10px rgba(0, 0, 0, 0.1);
            --shadow-medium: 0 4px 20px rgba(0, 0, 0, 0.15);
            --shadow-heavy: 0 8px 30px rgba(0, 0, 0, 0.2);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header h1 {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
            background: var(--light-bg);
            padding: 15px 25px;
            border-radius: 50px;
            box-shadow: var(--shadow-light);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .user-details h3 {
            margin: 0;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .user-details p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            position: relative;
            overflow: hidden;
        }

        .btn:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover:before {
            left: 100%;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-success {
            background: var(--success-gradient);
            color: white;
        }

        .btn-warning {
            background: var(--warning-gradient);
            color: white;
        }

        .btn-danger {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-info {
            background: var(--info-gradient);
            color: white;
        }

        .btn-dark {
            background: var(--dark-gradient);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btn:active {
            transform: translateY(0);
        }

        .nav-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 30px;
            background: var(--card-bg);
            padding: 8px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            overflow-x: auto;
        }

        .nav-tab {
            padding: 15px 25px;
            background: transparent;
            border: none;
            border-radius: calc(var(--border-radius) - 4px);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            color: var(--text-secondary);
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-tab.active {
            background: var(--primary-gradient);
            color: white;
            box-shadow: var(--shadow-light);
        }

        .nav-tab:hover:not(.active) {
            background: var(--light-bg);
            color: var(--text-primary);
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 30px;
            box-shadow: var(--shadow-light);
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-bg);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-control {
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            background: #fafbfc;
            resize: vertical;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-control.textarea {
            min-height: 120px;
            font-family: inherit;
        }

        .char-counter {
            margin-top: 5px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-align: right;
        }

        .char-counter.warning {
            color: #f5576c;
        }

        .required {
            color: #ee5a52;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--shadow-light);
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .stat-card.primary { border-left-color: #667eea; }
        .stat-card.success { border-left-color: #38c172; }
        .stat-card.warning { border-left-color: #f093fb; }
        .stat-card.info { border-left-color: #74b9ff; }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background: var(--light-bg);
            font-weight: 600;
            color: var(--text-primary);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .image-thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .image-thumbnail:hover {
            transform: scale(1.1);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 30px;
            max-width: 90vw;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-heavy);
            position: relative;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-bg);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-btn:hover {
            background: var(--light-bg);
            color: var(--danger-gradient);
        }

        .image-modal img {
            max-width: 100%;
            max-height: 80vh;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
        }

        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 600;
            box-shadow: var(--shadow-medium);
            animation: slideInRight 0.5s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .alert-success { background: var(--success-gradient); }
        .alert-danger { background: var(--danger-gradient); }
        .alert-warning { background: var(--warning-gradient); }
        .alert-info { background: var(--info-gradient); }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light-bg);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 15px;
        }

        .progress-fill {
            height: 100%;
            background: var(--success-gradient);
            transition: width 0.3s ease;
            border-radius: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--light-bg);
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }

        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .nav-tabs {
                flex-direction: column;
            }
            
            .nav-tab {
                justify-content: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
        }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(56, 193, 114, 0.1);
            color: #2d995b;
        }

        .badge-warning {
            background: rgba(240, 147, 251, 0.1);
            color: #f5576c;
        }

        .badge-info {
            background: rgba(116, 185, 255, 0.1);
            color: #0984e3;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .section-divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-gradient), transparent);
            margin: 40px 0;
            border-radius: 1px;
        }

        .multiselect-container {
            position: relative;
            width: 100%;
        }

        .multiselect-display {
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 10px;
            min-height: 44px;
            background: #fafbfc;
            cursor: pointer;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 5px;
        }

        .multiselect-display.empty {
            color: var(--text-secondary);
            font-style: italic;
        }

        .selected-item {
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .selected-item .remove {
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
        }

        .multiselect-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
        }

        .multiselect-dropdown.show {
            display: block;
        }

        .multiselect-option {
            padding: 10px 15px;
            cursor: pointer;
            transition: var(--transition);
        }

        .multiselect-option:hover {
            background: var(--light-bg);
        }

        .multiselect-option.selected {
            background: #667eea;
            color: white;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .preview-table th,
        .preview-table td {
            border: 1px solid var(--border-color);
            padding: 12px;
            text-align: left;
        }

        .preview-table th {
            background: var(--light-bg);
            font-weight: 600;
            color: var(--text-primary);
        }

        .preview-table tbody tr:hover {
            background: #f8f9fa;
        }

        .preview-table img {
            max-width: 100px;
            max-height: 100px;
            border-radius: 8px;
            cursor: pointer;
        }

        .task-item {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .task-header h4 {
            color: var(--text-primary);
            margin: 0;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-clipboard-list"></i>
                Daily Report System
            </h1>
            <div class="user-info">
                <div class="user-avatar" id="userAvatar"><?php echo substr($_SESSION['username'], 0, 2); ?></div>
                <div class="user-details">
                    <h3 id="userName"><?php echo htmlspecialchars($_SESSION['empcode']); ?></h3>
                    <p id="userRole"><?php echo isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'User'; ?></p>
                </div>
                <a href="#" class="btn btn-danger" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-header">
                    <span class="stat-title">Total Reports</span>
                    <div class="stat-icon" style="background: #667eea;">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
                <div class="stat-value" id="totalReports">0</div>
            </div>
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">This Month</span>
                    <div class="stat-icon" style="background: #38c172;">
                        <i class="fas fa-calendar-month"></i>
                    </div>
                </div>
                <div class="stat-value" id="monthlyReports">0</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-title">This Week</span>
                    <div class="stat-icon" style="background: #f093fb;">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                </div>
                <div class="stat-value" id="weeklyReports">0</div>
            </div>
            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-title">Completion Rate</span>
                    <div class="stat-icon" style="background: #74b9ff;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-value" id="completionRate">0%</div>
            </div>
        </div>

        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="create-report">
                <i class="fas fa-plus-circle"></i> Create Report
            </button>
            <button class="nav-tab" data-tab="my-reports">
                <i class="fas fa-list-ul"></i> My Reports
            </button>
            <button class="nav-tab" data-tab="analytics">
                <i class="fas fa-chart-bar"></i> Analytics
            </button>
            <button class="nav-tab" data-tab="export">
                <i class="fas fa-download"></i> Export
            </button>
        </div>

        <div id="create-report" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-plus-circle"></i>
                        Create Daily Report
                    </h2>
                </div>
                
                <form id="reportForm" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-day"></i>
                                Report Date <span class="required">*</span>
                            </label>
                            <input type="date" class="form-control" id="reportDate" name="report_date" required>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-tasks"></i>
                            Tasks & Images <span class="required">*</span>
                        </label>
                        <div id="tasksList">
                            <div class="task-item" data-index="0">
                                <div class="task-header">
                                    <h4>Task 1</h4>
                                    <button type="button" class="btn btn-danger btn-sm remove-task" onclick="removeTask(0)" style="display: none;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <div class="form-grid" style="margin-bottom: 15px;">
                                    <div class="form-group">
                                        <label class="form-label">Task Description <span class="required">*</span></label>
                                        <textarea class="form-control" name="tasks_completed[]" 
                                                placeholder="Describe the task completed..." required maxlength="2000"
                                                oninput="updateCharCounter(this, this.nextElementSibling, 2000)"></textarea>
                                        <div class="char-counter">0/2000 characters</div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Task Image <span class="required">*</span></label>
                                        <input type="file" class="form-control" name="task_images[]" 
                                               accept="image/*" required onchange="previewTaskImage(this, 0)">
                                        <small style="color: var(--text-secondary); margin-top: 5px;">
                                            Supported formats: JPEG, PNG, GIF, WebP (Max: 5MB)
                                        </small>
                                        <div class="task-image-preview" style="display: none; margin-top: 10px;">
                                            <img style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 2px solid var(--border-color);">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-success btn-sm" onclick="addTask()">
                            <i class="fas fa-plus"></i> Add Another Task
                        </button>
                    </div>

                    <div class="action-buttons">
                        <button type="button" class="btn btn-dark" onclick="window.location.href='projectsummary.php'">
                            <i class="fas fa-arrow-left"></i> Back to Summary
                        </button>
                        <button type="button" class="btn btn-dark" onclick="resetForm()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="button" class="btn btn-info" onclick="saveDraft()">
                            <i class="fas fa-save"></i> Save Draft
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="my-reports" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-list-ul"></i>
                        My Reports
                    </h2>
                </div>

                <div class="filter-bar">
                    <div class="search-box">
                        <input type="text" placeholder="Search reports..." id="searchReports" oninput="filterReports()">
                        <i class="fas fa-search"></i>
                    </div>
                    <input type="date" class="form-control" id="dateFrom" placeholder="From Date" onchange="filterReports()" style="width: auto;">
                    <input type="date" class="form-control" id="dateTo" placeholder="To Date" onchange="filterReports()" style="width: auto;">
                    <button class="btn btn-info" onclick="refreshReports()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>

                <div classs="table-container">
                    <table class="table" id="reportsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Task Description</th>
                                <th>Task Image</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="reportsTableBody">
                            <tr>
                                <td colspan="5" class="text-center">
                                    <div class="loading">
                                        <div class="spinner"></div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="analytics" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        Analytics Dashboard
                    </h2>
                </div>
                
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <h3>Analytics Coming Soon</h3>
                    <p>Detailed analytics and reporting features will be available here.</p>
                </div>
            </div>
        </div>

        <div id="export" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-download"></i>
                        Export Reports
                    </h2>
                </div>
                
                <form id="exportForm" method="POST" action="dailyreport.php">
                    <input type="hidden" name="action" value="export_reports">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-day"></i>
                                From Date <span class="required">*</span>
                            </label>
                            <input type="date" class="form-control" id="exportDateFrom" name="date_from" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-day"></i>
                                To Date <span class="required">*</span>
                            </label>
                            <input type="date" class="form-control" id="exportDateTo" name="date_to" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-users"></i>
                                Select Team Members <span class="required">*</span>
                            </label>
                            <div class="multiselect-container">
                                <div class="multiselect-display empty" id="multiselectDisplay">
                                    <span>Click to select team members...</span>
                                </div>
                                <div class="multiselect-dropdown" id="multiselectDropdown">
                                    <?php foreach ($teamMembers as $member) : ?>
                                        <div class="multiselect-option" data-value="<?php echo htmlspecialchars($member['empcode']); ?>">
                                            <?php echo htmlspecialchars($member['username']); ?> (<?php echo htmlspecialchars($member['empcode']); ?>)
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <select class="form-control" name="empcodes[]" id="empcodesSelect" multiple style="display: none;">
                                    <?php foreach ($teamMembers as $member) : ?>
                                        <option value="<?php echo htmlspecialchars($member['empcode']); ?>">
                                            <?php echo htmlspecialchars($member['username']); ?> (<?php echo htmlspecialchars($member['empcode']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-file-excel"></i>
                            Export Format
                        </label>
                        <select class="form-control" name="format">
                            <option value="excel">Excel (.xls)</option>
                            <option value="pdf">PDF (.pdf)</option>
                        </select>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="button" class="btn btn-info" onclick="previewExport()">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export Reports
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="imageModal" class="modal">
        <div class="modal-content" style="max-width: 90vw;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-image"></i>
                    Task Image
                </h3>
                <button class="close-btn" onclick="closeModal('imageModal')">&times;</button>
            </div>
            <div class="image-modal">
                <img id="modalImage" alt="Task Image">
            </div>
        </div>
    </div>

    <div id="viewReportModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-eye"></i>
                    View Report Details
                </h3>
                <button class="close-btn" onclick="closeModal('viewReportModal')">&times;</button>
            </div>
            <div id="reportDetails"></div>
        </div>
    </div>

    <div id="exportPreviewModal" class="modal">
        <div class="modal-content" style="max-width: 1200px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-eye"></i>
                    Export Preview
                </h3>
                <button class="close-btn" onclick="closeModal('exportPreviewModal')">&times;</button>
            </div>
            <div id="exportPreviewContent"></div>
        </div>
    </div>

    <script>
        // Global Variables
        let taskCounter = 1;
        let reports = [];
        let currentUser = {
            name: '<?php echo addslashes($_SESSION['username']); ?>',
            role: '<?php echo isset($_SESSION['role']) ? addslashes($_SESSION['role']) : 'User'; ?>',
            empcode: '<?php echo addslashes($_SESSION['empcode']); ?>'
        };

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            initializeApp();
            initializeMultiselect();
        });

        function initializeApp() {
            document.getElementById('reportDate').value = new Date().toISOString().split('T')[0];
            setupEventListeners();
            initializeCharCounters();
            loadReports();
            updateStatistics();
        }

        function setupEventListeners() {
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    switchTab(this.getAttribute('data-tab'));
                });
            });

            document.getElementById('reportForm').addEventListener('submit', handleFormSubmit);

            document.getElementById('exportForm').addEventListener('submit', function(e) {
                const empcodesSelect = document.getElementById('empcodesSelect');
                if (empcodesSelect.selectedOptions.length === 0) {
                    showAlert('Please select at least one team member to export.', 'danger');
                    e.preventDefault();
                }
            });
        }

        function initializeMultiselect() {
            const multiselectDisplay = document.getElementById('multiselectDisplay');
            const multiselectDropdown = document.getElementById('multiselectDropdown');
            const empcodesSelect = document.getElementById('empcodesSelect');
            let isDropdownOpen = false;

            multiselectDisplay.addEventListener('click', function(e) {
                if (!isDropdownOpen) {
                    multiselectDropdown.classList.add('show');
                    isDropdownOpen = true;
                } else if (!e.target.classList.contains('remove')) {
                    multiselectDropdown.classList.remove('show');
                    isDropdownOpen = false;
                }
            });

            multiselectDropdown.querySelectorAll('.multiselect-option').forEach(option => {
                option.addEventListener('click', function() {
                    const value = this.getAttribute('data-value');
                    const selectOption = empcodesSelect.querySelector(`option[value="${value}"]`);
                    if (selectOption) {
                        selectOption.selected = !selectOption.selected;
                        updateMultiselectDisplay();
                    }
                });
            });

            multiselectDisplay.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove')) {
                    const value = e.target.getAttribute('data-value');
                    const selectOption = empcodesSelect.querySelector(`option[value="${value}"]`);
                    if (selectOption) {
                        selectOption.selected = false;
                        updateMultiselectDisplay();
                    }
                }
            });

            document.addEventListener('click', function(e) {
                if (!multiselectDisplay.contains(e.target) && !multiselectDropdown.contains(e.target)) {
                    multiselectDropdown.classList.remove('show');
                    isDropdownOpen = false;
                }
            });

            function updateMultiselectDisplay() {
                const selectedOptions = Array.from(empcodesSelect.selectedOptions);
                multiselectDisplay.innerHTML = '';
                multiselectDisplay.classList.remove('empty');

                if (selectedOptions.length === 0) {
                    multiselectDisplay.classList.add('empty');
                    multiselectDisplay.innerHTML = `<span>Click to select team members...</span>`;
                } else {
                    selectedOptions.forEach(option => {
                        const item = document.createElement('span');
                        item.className = 'selected-item';
                        item.innerHTML = `${option.textContent} <span class="remove" data-value="${option.value}">&times;</span>`;
                        multiselectDisplay.appendChild(item);
                    });
                }

                multiselectDropdown.querySelectorAll('.multiselect-option').forEach(opt => {
                    const value = opt.getAttribute('data-value');
                    if (empcodesSelect.querySelector(`option[value="${value}"]:checked`)) {
                        opt.classList.add('selected');
                    } else {
                        opt.classList.remove('selected');
                    }
                });
            }
        }

        function switchTab(tabId) {
            document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector(`[data-tab="${tabId}"]`).classList.add('active');
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            if (tabId === 'my-reports') {
                loadReports();
            }
        }

        function handleFormSubmit(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const tasks = formData.getAll('tasks_completed[]');
            const images = formData.getAll('task_images[]');
            
            if (tasks.length === 0 || tasks.some(task => !task.trim())) {
                showAlert('Please fill in all task descriptions.', 'danger');
                return;
            }
            
            if (images.length !== tasks.length || images.some(img => !img.name)) {
                showAlert('Each task must have an image.', 'danger');
                return;
            }
            
            submitReport(formData);
        }

        function submitReport(formData) {
            showAlert('Submitting report...', 'info');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'submit_report.php', true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showAlert('Report submitted successfully!', 'success');
                                resetForm();
                                updateStatistics();
                                switchTab('my-reports');
                            } else {
                                showAlert('Error submitting report: ' + response.error, 'danger');
                            }
                        } catch (e) {
                            showAlert('An unexpected error occurred while submitting the report.', 'danger');
                        }
                    } else {
                        showAlert('Error submitting report. Server status: ' + xhr.status, 'danger');
                    }
                }
            };
            xhr.send(formData);
        }

        function addTask() {
            const tasksList = document.getElementById('tasksList');
            const taskItem = document.createElement('div');
            taskItem.className = 'task-item';
            taskItem.setAttribute('data-index', taskCounter);
            
            taskItem.innerHTML = `
                <div class="task-header">
                    <h4>Task ${taskCounter + 1}</h4>
                    <button type="button" class="btn btn-danger btn-sm remove-task" onclick="removeTask(${taskCounter})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="form-grid" style="margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="form-label">Task Description <span class="required">*</span></label>
                        <textarea class="form-control" name="tasks_completed[]" 
                                placeholder="Describe the task completed..." required maxlength="2000"
                                oninput="updateCharCounter(this, this.nextElementSibling, 2000)"></textarea>
                        <div class="char-counter">0/2000 characters</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Task Image <span class="required">*</span></label>
                        <input type="file" class="form-control" name="task_images[]" 
                               accept="image/*" required onchange="previewTaskImage(this, ${taskCounter})">
                        <small style="color: var(--text-secondary); margin-top: 5px;">
                            Supported formats: JPEG, PNG, GIF, WebP (Max: 5MB)
                        </small>
                        <div class="task-image-preview" style="display: none; margin-top: 10px;">
                            <img style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 2px solid var(--border-color);">
                        </div>
                    </div>
                </div>
            `;
            
            tasksList.appendChild(taskItem);
            taskCounter++;
            updateRemoveButtons();
        }

        function removeTask(index) {
            const taskItem = document.querySelector(`[data-index="${index}"]`);
            if (taskItem) {
                taskItem.remove();
                updateTaskNumbers();
                updateRemoveButtons();
            }
        }

        function updateTaskNumbers() {
            const taskItems = document.querySelectorAll('.task-item');
            taskItems.forEach((item, index) => {
                item.setAttribute('data-index', index);
                item.querySelector('h4').textContent = `Task ${index + 1}`;
                const removeBtn = item.querySelector('.remove-task');
                if (removeBtn) {
                    removeBtn.setAttribute('onclick', `removeTask(${index})`);
                }
            });
            taskCounter = taskItems.length;
        }

        function updateRemoveButtons() {
            const taskItems = document.querySelectorAll('.task-item');
            const removeButtons = document.querySelectorAll('.remove-task');
            removeButtons.forEach(btn => {
                btn.style.display = taskItems.length > 1 ? 'inline-flex' : 'none';
            });
        }

        function previewTaskImage(input, index) {
            const preview = input.parentElement.querySelector('.task-image-preview');
            const img = preview.querySelector('img');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }

        function updateCharCounter(textarea, counterElement, maxLength) {
            const currentLength = textarea.value.length;
            counterElement.textContent = `${currentLength}/${maxLength} characters`;
            if (currentLength > maxLength * 0.9) {
                counterElement.classList.add('warning');
            } else {
                counterElement.classList.remove('warning');
            }
        }

        function initializeCharCounters() {
            document.querySelectorAll('textarea[maxlength]').forEach(textarea => {
                const counter = textarea.nextElementSibling;
                if (counter && counter.classList.contains('char-counter')) {
                    updateCharCounter(textarea, counter, textarea.getAttribute('maxlength'));
                }
            });
        }

        function resetForm() {
            document.getElementById('reportForm').reset();
            const tasksList = document.getElementById('tasksList');
            tasksList.innerHTML = '';
            taskCounter = 0;
            addTask();
            document.getElementById('reportDate').value = new Date().toISOString().split('T')[0];
            initializeCharCounters();
        }

        function saveDraft() {
            showAlert('Draft saving is not implemented yet.', 'warning');
        }

        function loadReports() {
            const tbody = document.getElementById('reportsTableBody');
            tbody.innerHTML = `<tr><td colspan="5"><div class="loading"><div class="spinner"></div></div></td></tr>`;
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_reports.php', true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        reports = JSON.parse(xhr.responseText);
                        displayReports();
                    } catch(e) {
                        tbody.innerHTML = `<tr><td colspan="5"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading reports</h3><p>Invalid data received from server.</p></div></td></tr>`;
                    }
                } else if (xhr.readyState === 4) {
                    tbody.innerHTML = `<tr><td colspan="5"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading reports</h3><p>Could not connect to the server.</p></div></td></tr>`;
                }
            };
            xhr.send();
        }

        function displayReports() {
            const tbody = document.getElementById('reportsTableBody');
            if (!reports || reports.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="fas fa-clipboard-list"></i>
                                <h3>No Reports Found</h3>
                                <p>You haven't submitted any reports yet or none match the filter.</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            reports.forEach(report => {
                if (report.tasks && report.tasks.length > 0) {
                    report.tasks.forEach((task, taskIndex) => {
                        html += `
                            <tr data-report-id="${report.id}">
                                <td>${formatDate(report.date)}</td>
                                <td>${truncateText(task.description, 50)}</td>
                                <td>
                                    ${task.image ? `<img src="${task.image}" class="image-thumbnail" onclick="openImageModal('${task.image}')" alt="Task Image">` : 'No Image'}
                                </td>
                                <td>${formatDateTime(report.created_at)}</td>
                                <td>
                                    <button class="btn btn-info btn-sm" onclick="viewReport(${report.id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteReport(${report.id})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                }
            });
            tbody.innerHTML = html;
        }

        function filterReports() {
            const searchTerm = document.getElementById('searchReports').value.toLowerCase();
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `fetch_reports.php?search=${encodeURIComponent(searchTerm)}&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        reports = JSON.parse(xhr.responseText);
                        displayReports();
                    } catch(e) {
                        showAlert('Failed to filter reports due to invalid server response.', 'danger');
                    }
                }
            };
            xhr.send();
        }

        function refreshReports() {
            document.getElementById('searchReports').value = '';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            showAlert('Reports refreshed!', 'info');
            loadReports();
        }

        function updateStatistics() {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_statistics.php', true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const stats = JSON.parse(xhr.responseText);
                    document.getElementById('totalReports').textContent = stats.total_reports || 0;
                    document.getElementById('monthlyReports').textContent = stats.monthly_reports || 0;
                    document.getElementById('weeklyReports').textContent = stats.weekly_reports || 0;
                    document.getElementById('completionRate').textContent = stats.completion_rate || '0%';
                }
            };
            xhr.send();
        }

        function previewExport() {
            const dateFrom = document.getElementById('exportDateFrom').value;
            const dateTo = document.getElementById('exportDateTo').value;
            const empcodesSelect = document.getElementById('empcodesSelect');
            const empcodes = Array.from(empcodesSelect.selectedOptions).map(opt => opt.value);

            if (!dateFrom || !dateTo || empcodes.length === 0) {
                showAlert('Please fill in all required fields: From Date, To Date, and at least one team member.', 'danger');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'preview_reports');
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            empcodes.forEach(empcode => formData.append('empcodes[]', empcode));

            showAlert('Loading preview...', 'info');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'dailyreport.php', true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                showAlert('Error loading preview: ' + response.error, 'danger');
                            } else {
                                displayExportPreview(response);
                            }
                        } catch (e) {
                            showAlert('An unexpected error occurred while loading the preview.', 'danger');
                        }
                    } else {
                        showAlert('Error loading preview. Server status: ' + xhr.status, 'danger');
                    }
                }
            };
            xhr.send(formData);
        }

        function displayExportPreview(data) {
            const content = document.getElementById('exportPreviewContent');
            const protocol = window.location.protocol;
            const host = '172.16.2.8'; // Hardcode the correct IP for the preview URL
            const projectFolder = '/projectsummary';
            const baseUrl = `${protocol}//${host}${projectFolder}/Uploads/`;

            let html = `
                <div style="margin-bottom: 20px;">
                    <strong>Date Range:</strong> ${formatDate(data.date_from)} to ${formatDate(data.date_to)}
                </div>
                <table class="preview-table">
                    <thead>
                        <tr>
                            <th>Report Date</th>
                            <th>Employee Code</th>
                            <th>Username</th>
                            <th>Task Description</th>
                            <th>Image</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            if (!data.reports || Object.keys(data.reports).length === 0) {
                html += `<tr><td colspan="6" style="text-align: center;">No reports found.</td></tr>`;
            } else {
                for (const empcode in data.reports) {
                    const employee = data.reports[empcode];
                    employee.reports.forEach(report => {
                        const imageFilename = report.image_path ? report.image_path.split(/[\\/]/).pop() : '';
                        const imageUrl = imageFilename ? baseUrl + imageFilename : '';
                        html += `
                            <tr>
                                <td>${formatDate(report.report_date)}</td>
                                <td>${htmlspecialchars(empcode)}</td>
                                <td>${htmlspecialchars(employee.username)}</td>
                                <td style="white-space: pre-wrap;">${htmlspecialchars(report.task_description)}</td>
                                <td>${imageUrl ? `<img src="${imageUrl}" alt="Task Image" style="width:100px; height:auto;">` : 'No image'}</td>
                                <td>${formatDateTime(report.created_at)}</td>
                            </tr>
                        `;
                    });
                }
            }

            html += `</tbody></table>`;
            content.innerHTML = html;
            document.getElementById('exportPreviewModal').classList.add('show');
        }


        function htmlspecialchars(str) {
            if (typeof str !== 'string') return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return str.replace(/[&<>"']/g, m => map[m]);
        }

        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            document.getElementById('imageModal').classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function viewReport(reportId) {
            showAlert('View report functionality not implemented yet.', 'info');
        }

        function deleteReport(reportId) {
            if (confirm('Are you sure you want to delete this report?')) {
                showAlert('Delete report functionality not implemented yet.', 'warning');
            }
        }

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        function showAlert(message, type = 'info') {
            const alertContainer = document.body;
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            const iconMap = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            alertDiv.innerHTML = `<i class="fas ${iconMap[type]}"></i><span>${message}</span>`;
            alertContainer.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                setTimeout(() => alertDiv.remove(), 500);
            }, 3000);
        }
        
        function formatDate(dateObj) {
            if (!dateObj || !dateObj.date) return 'N/A';
            const date = new Date(dateObj.date);
            const userTimezoneOffset = date.getTimezoneOffset() * 60000;
            return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('en-CA');
        }
        
        function formatDateTime(dateTimeObj) {
            if (!dateTimeObj || !dateTimeObj.date) return 'N/A';
            return new Date(dateTimeObj.date).toLocaleString('en-US');
        }


        function truncateText(text, length) {
            if (!text || text.length <= length) return text;
            return text.substring(0, length) + '...';
        }

        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });
    </script>
</body>
</html>
<?php
if ($conn) {
    sqlsrv_close($conn);
}
?>