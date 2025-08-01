<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in again.']);
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', 'C:\inetpub\wwwroot\projectsummary\php_errors.log');

// Check extensions
if (!extension_loaded('gd')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'GD extension is required but not loaded.']);
    exit();
}
if (!extension_loaded('sqlsrv')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'SQLSRV extension is required but not loaded.']);
    exit();
}
if (!extension_loaded('fileinfo')) {
    error_log('Fileinfo extension not loaded in export_reports.php.');
}

// Database connection
$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . print_r(sqlsrv_errors(), true)]);
    exit();
}

// Handle form data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dateFrom = $_POST['date_from'];
    $dateTo = $_POST['date_to'];
    $empcodes = $_POST['empcodes'] ? explode(',', $_POST['empcodes']) : [];

    if (!$dateFrom || !$dateTo) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Please select both from and to dates.']);
        exit();
    }

    $params = [];
    $whereClauses = ['r.report_date BETWEEN ? AND ?'];
    $params[] = $dateFrom;
    $params[] = $dateTo;

    if (!empty($empcodes)) {
        $placeholders = implode(',', array_fill(0, count($empcodes), '?'));
        $whereClauses[] = "r.empcode IN ($placeholders)";
        $params = array_merge($params, $empcodes);
    }

    $query = "
        SELECT r.id, r.empcode, r.report_date, r.created_at, t.task_description, t.image_path
        FROM daily_report_db.dbo.reports r
        LEFT JOIN daily_report_db.dbo.tasks t ON r.id = t.report_id
        WHERE " . implode(' AND ', $whereClauses) . "
        ORDER BY r.report_date DESC, r.id, t.id
    ";

    $stmt = sqlsrv_query($conn, $query, $params);
    if ($stmt === false) {
        error_log('Query execution failed: ' . print_r(sqlsrv_errors(), true));
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Query execution failed: ' . print_r(sqlsrv_errors(), true)]);
        exit();
    }

    // Process data into HTML for Excel
    $reportsByEmployee = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $empcode = $row['empcode'];
        if (!isset($reportsByEmployee[$empcode])) {
            $reportsByEmployee[$empcode] = ['reports' => []];
        }
        $reportsByEmployee[$empcode]['reports'][] = $row;
    }

    // Function to resize and encode image
    function resizeImage($imagePath) {
        // Use dynamic path based on __DIR__
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        $fullPath = $baseDir . $imagePath;
        error_log("Attempting to process image at: $fullPath");

        if (!file_exists($fullPath)) {
            error_log("Image file does not exist: $fullPath");
            return false;
        }
        if (!is_readable($fullPath)) {
            error_log("Image file not readable: $fullPath");
            return false;
        }

        $imageInfo = getimagesize($fullPath);
        if ($imageInfo === false) {
            error_log("Failed to get image info for: $fullPath");
            return false;
        }

        $mime = $imageInfo['mime'];
        error_log("Detected MIME type: $mime");
        $image = false;
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($fullPath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($fullPath);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($fullPath);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($fullPath);
                break;
            default:
                error_log("Unsupported MIME type: $mime for $fullPath");
                return false;
        }

        if ($image === false) {
            error_log("Failed to create image resource from: $fullPath");
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $newWidth = min(50, $width);
        $newHeight = ($newWidth / $width) * $height;
        $resized = imagecreatetruecolor($newWidth, $newHeight);

        if ($resized === false) {
            imagedestroy($image);
            error_log("Failed to create resized image for: $fullPath");
            return false;
        }

        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        if ($mime == 'image/jpeg') {
            imagejpeg($resized, null, 90);
        } elseif ($mime == 'image/png') {
            imagepng($resized, null, 9);
        } elseif ($mime == 'image/gif') {
            imagegif($resized);
        } elseif ($mime == 'image/webp') {
            imagewebp($resized, null, 90);
        }
        $imageData = ob_get_contents();
        ob_end_clean();

        $base64Data = base64_encode($imageData);
        error_log("Encoded image data length: " . strlen($base64Data));
        imagedestroy($image);
        imagedestroy($resized);

        return ['data' => $base64Data, 'mime' => $mime];
    }

    // Generate HTML content
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Daily Reports Export</title>
    </head>
    <body>
        <table border="1" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee Code</th>
                    <th>Task Description</th>
                    <th>Task Image</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
    <?php
    if (empty($reportsByEmployee)) {
        echo '<tr><td colspan="5">No reports found for the selected criteria.</td></tr>';
    } else {
        foreach ($reportsByEmployee as $empcode => $data) {
            foreach ($data['reports'] as $report) {
                $imageData = '';
                $imageMime = '';
                if ($report['image_path']) {
                    $resizedImage = resizeImage($report['image_path']);
                    if ($resizedImage) {
                        $imageData = $resizedImage['data'];
                        $imageMime = $resizedImage['mime'];
                        error_log("Successfully encoded image for path: " . $report['image_path']);
                    } else {
                        error_log("Failed to encode image for path: " . $report['image_path']);
                    }
                }

                echo '<tr>';
                echo '<td>' . ($report['report_date'] instanceof DateTime ? $report['report_date']->format('Y-m-d') : 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($empcode) . '</td>';
                echo '<td>' . htmlspecialchars($report['task_description'] ?: 'No task description') . '</td>';
                echo '<td>';
                if ($imageData && $imageMime) {
                    echo '<img src="data:' . htmlspecialchars($imageMime) . ';base64,' . $imageData . '" style="max-width: 50px; max-height: 50px;">';
                } else {
                    echo 'No image available';
                }
                echo '</td>';
                echo '<td>' . ($report['created_at'] instanceof DateTime ? $report['created_at']->format('Y-m-d H:i') : 'N/A') . '</td>';
                echo '</tr>';
            }
        }
    }
    ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    $htmlContent = ob_get_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Daily_Reports_' . date('YmdHis') . '.xls"');
    header('Cache-Control: max-age=0');

    echo $htmlContent;

    sqlsrv_free_stmt($stmt);
}

if ($conn) {
    sqlsrv_close($conn);
}
?>