<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
if (function_exists('opcache_reset')) {
    opcache_reset();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- SESSION MANAGEMENT ---
ini_set('session.cookie_lifetime', 0); // Expires on browser close
ini_set('session.gc_maxlifetime', 1440); // Server session cleanup in 24 minutes
ini_set('session.cookie_httponly', 1);  // Prevent JS access
ini_set('session.cookie_secure', 0);    // Use 1 if using HTTPS
session_start();

// --- EXPORT HANDLER ---
// This block handles the EXCEL export request before any other output is sent.
if (isset($_POST['action']) && $_POST['action'] === 'export_summary') {
    if (!isset($_SESSION['username'])) {
        http_response_code(403); // Forbidden
        die('Access Denied. Please log in.');
    }

    // Include the configuration and database class
    require_once 'config/config.php';
    

    $db = new Database();
    $selected_groups = isset($_POST['groups']) && is_array($_POST['groups']) ? $_POST['groups'] : [];
    // Switched to the HTML-based Excel export function which requires no Composer.
    exportSummaryAsExcelHtml($db, $selected_groups); 
    exit; // Stop execution after sending the file
}


// --- DATABASE CONNECTION ---
// The user's config.php file will be included here.
// Make sure the path is correct for your project structure.
require_once 'config/config.php';

// --- SESSION CHECK ---
if (!isset($_SESSION['username'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// --- ROLE & CONFIGURATION ---
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'viewer';
$db = new Database(); // Instantiate the real database class

// --- AJAX REQUEST HANDLER ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Backend Role-Based Access Control
    $action = $_POST['action'];
    if (in_array($action, ['add_project', 'update_project', 'delete_project']) && $user_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized action. Admin privileges required.']);
        exit;
    }

    try {
        switch ($action) {
            case 'get_dashboard_stats':
                echo json_encode(getDashboardStats($db));
                break;
            case 'get_projects':
                echo json_encode(getProjects($db));
                break;
            case 'get_completed_projects':
                echo json_encode(getCompletedProjects($db));
                break;
            case 'add_project':
                echo json_encode(addProject($db, $_POST));
                break;
            case 'update_project':
                echo json_encode(updateProject($db, $_POST));
                break;
            case 'delete_project':
                echo json_encode(deleteProject($db, $_POST['id']));
                break;
            case 'get_project_details':
                echo json_encode(getProjectDetails($db, $_POST['id']));
                break;
            case 'get_it_members': // New action to fetch IT members
                echo json_encode(getITMembers($db));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action.']);
                break;
        }
    } catch (Exception $e) {
        // Log the error for debugging
        error_log("AJAX Error in projectsummary.php: " . $e->getMessage());
        // Send a generic error message to the client
        echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred. Please contact support.']);
    }
    exit;
}


// --- DATABASE FUNCTIONS ---

/**
 * Fetches and processes data for the dashboard.
 */
function getDashboardStats($db) {
    $db->query("SELECT * FROM projects");
    $db->execute();
    $projects = $db->resultSet();

    $stats = [
        'total' => count($projects),
        'completed' => 0,
        'ongoing' => 0,
        'not_started' => 0
    ];

    $upcoming = [];
    $recent = [];
    $group_summary = [];

    foreach ($projects as $project) {
        // Calculate overall status counts
        if ($project['status'] === 'COMPLETED') {
            $stats['completed']++;
            if (isset($project['completion_date']) && strtotime($project['completion_date']) > strtotime('-7 days')) {
                $recent[] = $project;
            }
        } elseif ($project['status'] === 'ONGOING') {
            $stats['ongoing']++;
        } elseif ($project['status'] === 'NOT STARTED') {
            $stats['not_started']++;
        }

        // Get upcoming target dates (next 30 days)
        if ($project['status'] !== 'COMPLETED' && isset($project['target_date'])) {
            if (strtotime($project['target_date']) < strtotime('+30 days') && strtotime($project['target_date']) >= time()) {
                $upcoming[] = $project;
            }
        }

        // Group summary
        $group = $project['group_name'];
        if (!isset($group_summary[$group])) {
            $group_summary[$group] = [
                'group_name' => $group,
                'total_projects' => 0,
                'completed' => 0,
                'ongoing' => 0,
                'not_started' => 0
            ];
        }

        $group_summary[$group]['total_projects']++;
        if ($project['status'] === 'COMPLETED') {
            $group_summary[$group]['completed']++;
        } elseif ($project['status'] === 'ONGOING') {
            $group_summary[$group]['ongoing']++;
        } elseif ($project['status'] === 'NOT STARTED') {
            $group_summary[$group]['not_started']++;
        }
    }

    // Sort upcoming by target date
    usort($upcoming, fn($a, $b) => strtotime($a['target_date']) - strtotime($b['target_date']));
    $upcoming = array_slice($upcoming, 0, 5);

    // Sort recent by completion date
    usort($recent, fn($a, $b) => strtotime($b['completion_date']) - strtotime($a['completion_date']));
    $recent = array_slice($recent, 0, 5);

    return [
        'success' => true,
        'data' => [
            'stats' => $stats,
            'upcoming' => $upcoming,
            'recent' => $recent,
            'group_summary' => array_values($group_summary)
        ]
    ];
}

function exportSummaryAsCsv($db, $groups = []) {
    try {
        error_log("Starting exportSummaryAsCsv with groups: " . json_encode($groups));
        
        // Increase limits
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 300);

        // Build Query
        $sql = "SELECT * FROM projects";
        $params = [];
        if (!empty($groups)) {
            $placeholders = [];
            foreach ($groups as $i => $group) {
                $param = ":group" . ($i + 1);
                $placeholders[] = $param;
                $params[$param] = $group;
            }
            $sql .= " WHERE group_name IN (" . implode(', ', $placeholders) . ")";
        }
        $sql .= " ORDER BY group_name, name ASC";

        $db->query($sql);
        foreach ($params as $param => $value) {
            $db->bind($param, $value);
        }
        $db->execute();
        $projects = $db->resultSet();

        // Set download headers
        $filename = "project_summary_" . date('Y-m-d_H-i-s') . ".csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM for Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        // Title and headers
        fputcsv($output, ["PROJECT SUMMARY REPORT - " . date('F j, Y')]);
        fputcsv($output, []);
        
        $headers = [
            'Project Name', 'Group', 'Requestor', 'POC', 'Purpose',
            'Risks & Help Items', 'Status', 'Progress (%)', 'Start Date',
            'Target Date', 'Completion Date'
        ];
        fputcsv($output, $headers);

        // Data rows
        $currentGroup = '';
        $rowCount = 0;
        
        foreach ($projects as $project) {
            // Group separator
            if (!empty($project['group_name']) && $project['group_name'] !== $currentGroup) {
                if ($rowCount > 0) fputcsv($output, []);
                fputcsv($output, ["=== " . strtoupper($project['group_name']) . " ==="]);
                $currentGroup = $project['group_name'];
            }
            
            // Format progress
            $progress = floatval($project['progress'] ?? 0);
            if ($progress <= 1) $progress *= 100;
            
            $row = [
                cleanText($project['name'] ?? ''),
                cleanText($project['group_name'] ?? ''),
                cleanText($project['requestor'] ?? ''),
                cleanText($project['poc'] ?? ''),
                cleanText($project['purpose'] ?? '', 60),
                cleanText($project['risks'] ?? '', 60),
                cleanText($project['status'] ?? ''),
                number_format($progress, 1) . '%',
                formatDate($project['start_date'] ?? ''),
                formatDate($project['target_date'] ?? ''),
                formatDate($project['completion_date'] ?? '')
            ];
            
            fputcsv($output, $row);
            $rowCount++;
        }
        
        // Footer
        fputcsv($output, []);
        fputcsv($output, ["Total Projects: " . $rowCount]);
        fputcsv($output, ["Generated: " . date('Y-m-d H:i:s')]);
        
        fclose($output);
        exit;
        
    } catch (Exception $e) {
        error_log("CSV Export Error: " . $e->getMessage());
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo "Export failed: " . $e->getMessage();
    }
}

function exportSummaryAsExcelHtml($db, $groups = []) {
    try {
        // Same query logic
        $sql = "SELECT * FROM projects";
        $params = [];
        if (!empty($groups)) {
            $placeholders = [];
            foreach ($groups as $i => $group) {
                $param = ":group" . ($i + 1);
                $placeholders[] = $param;
                $params[$param] = $group;
            }
            $sql .= " WHERE group_name IN (" . implode(', ', $placeholders) . ")";
        }
        $sql .= " ORDER BY group_name, name ASC";
        
        $db->query($sql);
        foreach ($params as $param => $value) {
            $db->bind($param, $value);
        }
        $db->execute();
        $projects = $db->resultSet();
        
        // Excel download headers
        $filename = "project_summary_" . date('Y-m-d_H-i-s') . ".xls";
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Excel-compatible HTML with borders
        echo "\xEF\xBB\xBF";
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        table { border-collapse: collapse; width: 100%; }
        th { 
            background-color: #4472C4; 
            color: white; 
            font-weight: bold; 
            text-align: center;
            padding: 8px;
            border: 2px solid #2F4F4F;
        }
        td { 
            border: 1px solid #CCCCCC; 
            padding: 6px;
            vertical-align: top;
        }
        .group-header {
            background-color: #D9E1F2;
            font-weight: bold;
            text-align: center;
            font-size: 11pt;
            border: 2px solid #4472C4;
        }
        .number { text-align: right; }
        .center { text-align: center; }
        .date { text-align: center; }
        .title { 
            text-align: center; 
            font-size: 14pt; 
            font-weight: bold; 
            color: #4472C4; 
            margin: 20px 0; 
        }
    </style>
</head>
<body>
    <div class="title">PROJECT SUMMARY REPORT - ' . date('F j, Y') . '</div>
    <table>
        <tr>
            <th>Project Name</th>
            <th>Group</th>
            <th>Requestor</th>
            <th>Point of Contact</th>
            <th>Purpose</th>
            <th>Risks & Help Items</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Start Date</th>
            <th>Target Date</th>
            <th>Completion Date</th>
        </tr>';
        
        $currentGroup = '';
        $rowCount = 0;
        
        foreach ($projects as $project) {
            // Group header
            if (!empty($project['group_name']) && $project['group_name'] !== $currentGroup) {
                if ($rowCount > 0) {
                    echo '<tr><td colspan="11" style="height: 5px; border: none;"></td></tr>';
                }
                echo '<tr><td colspan="11" class="group-header">' . 
                     htmlspecialchars(strtoupper($project['group_name'])) . 
                     '</td></tr>';
                $currentGroup = $project['group_name'];
            }
            
            $progress = floatval($project['progress'] ?? 0);
            if ($progress <= 1) $progress *= 100;
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($project['name'] ?? '') . '</td>';
            echo '<td class="center">' . htmlspecialchars($project['group_name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['requestor'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['poc'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars(truncateText($project['purpose'] ?? '', 80)) . '</td>';
            echo '<td>' . htmlspecialchars(truncateText($project['risks'] ?? '', 80)) . '</td>';
            echo '<td class="center">' . htmlspecialchars($project['status'] ?? '') . '</td>';
            echo '<td class="number">' . number_format($progress, 1) . '%</td>';
            echo '<td class="date">' . htmlspecialchars(formatDate($project['start_date'] ?? '')) . '</td>';
            echo '<td class="date">' . htmlspecialchars(formatDate($project['target_date'] ?? '')) . '</td>';
            echo '<td class="date">' . htmlspecialchars(formatDate($project['completion_date'] ?? '')) . '</td>';
            echo '</tr>';
            
            $rowCount++;
        }
        
        echo '<tr>
            <td colspan="11" style="text-align: center; font-weight: bold; background-color: #F2F2F2; padding: 10px; border: 2px solid #4472C4;">
                Total Projects: ' . $rowCount . ' | Generated: ' . date('Y-m-d H:i:s') . '
            </td>
        </tr>
    </table>
</body>
</html>';
        
        exit;
        
    } catch (Exception $e) {
        error_log("Excel HTML Export Error: " . $e->getMessage());
        http_response_code(500);
        echo "Export failed: " . $e->getMessage();
    }
}

// Helper functions
function cleanText($text, $maxLength = null) {
    $text = trim($text);
    $text = str_replace(["\r\n", "\r", "\n"], " ", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    
    if ($maxLength && strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength - 3) . "...";
    }
    
    return $text;
}

function truncateText($text, $length) {
    if (strlen($text) > $length) {
        return substr($text, 0, $length - 3) . "...";
    }
    return $text;
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '';
    }
    
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format('m/d/Y');
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Fetches IT Members.
 * NOTE: This is currently a static list. To make this fully dynamic,
 * create a table in your database (e.g., 'it_members') and query it here.
 */
function getITMembers($db) {
    // ---- TODO: Replace this with a database query ----
    // Example:
    // $db->query("SELECT member_name FROM it_members ORDER BY member_name ASC");
    // $db->execute();
    // $results = $db->resultSet();
    // $members = array_column($results, 'member_name');
    // return ['success' => true, 'data' => $members];
    // ---------------------------------------------------

    $members = [
        "Ranzel Laroya", "Lemuel Sigua", "Ronell Evaristo", "Jayson Gaon",
        "Hyacinth Faye Mendez", "Alain Jake Alimurong", "Renniel Ramos",
        "Justin Luna", "Jairha Cortez", "Ashley Kent San Pedro",
        "Kyle Justine Dimla", "Stephen Karlle Dimitui", "Russel Pineda"
    ];
    return ['success' => true, 'data' => $members];
}


/**
 * Fetches active (not completed) projects.
 */
function getProjects($db) {
    $db->query("SELECT * FROM projects WHERE status <> 'COMPLETED' ORDER BY target_date ASC");
    $db->execute();
    return ['success' => true, 'data' => $db->resultSet()];
}

/**
 * Fetches completed projects.
 */
function getCompletedProjects($db) {
    $db->query("SELECT * FROM projects WHERE status = 'COMPLETED' ORDER BY completion_date DESC");
    $db->execute();
    return ['success' => true, 'data' => $db->resultSet()];
}

/**
 * Adds a new project to the database.
 */
function addProject($db, $data) {
    $required_fields = ['name', 'group_name', 'poc', 'target_date', 'status', 'requestor', 'purpose'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || (is_array($data[$field]) ? empty($data[$field]) : trim($data[$field]) === '')) {
             return ['success' => false, 'message' => "Please fill all required fields. Missing: " . ucfirst(str_replace('_', ' ', $field))];
        }
    }

    $name = trim($data['name']);
    $requestor = trim($data['requestor']);
    if (strlen($name) > 100) return ['success' => false, 'message' => 'Project Name must be 100 characters or less.'];
    if (strlen($requestor) > 100) return ['success' => false, 'message' => 'Requestor must be 100 characters or less.'];
    if (strlen($data['purpose']) > 2000) return ['success' => false, 'message' => 'Purpose is too long.'];
    if (strlen($data['risks']) > 2000) return ['success' => false, 'message' => 'Risks field is too long.'];
    if (strlen($data['remarks']) > 2000) return ['success' => false, 'message' => 'Remarks field is too long.'];

    $target_date = new DateTime($data['target_date']);
    if (!empty($data['start_date'])) {
        $start_date = new DateTime($data['start_date']);
        if ($start_date > $target_date) return ['success' => false, 'message' => 'Start Date cannot be after the Target Completion Date.'];
    }

    $db->query("SELECT COUNT(*) as count FROM projects WHERE LOWER(name) = LOWER(:name)");
    $db->bind(':name', $name);
    $db->execute();
    if ($db->single()['count'] > 0) {
        return ['success' => false, 'message' => 'A project with this name already exists.'];
    }

    $poc = is_array($data['poc']) ? implode(', ', $data['poc']) : ($data['poc'] ?? '');

    $status = $data['status'];
    $progress = isset($data['progress']) ? (int)$data['progress'] : 0;
    if ($status === 'COMPLETED') $progress = 100;
    elseif ($status === 'NOT STARTED') $progress = 0;
    elseif ($status === 'ONGOING' && $progress === 0) $progress = 5;
    elseif ($status === 'ONGOING' && $progress === 100) $progress = 99;

    $completion_date = ($status === 'COMPLETED') ? date('Y-m-d H:i:s') : null;

    $sql = "INSERT INTO projects (name, group_name, requestor, poc, purpose, risks, target_date, start_date, completion_date, status, progress, remarks)
            VALUES (:name, :group_name, :requestor, :poc, :purpose, :risks, :target_date, :start_date, :completion_date, :status, :progress, :remarks)";

    $db->query($sql)
        ->bind(':name', $name)
        ->bind(':group_name', $data['group_name'])
        ->bind(':requestor', $requestor)
        ->bind(':poc', $poc)
        ->bind(':purpose', trim($data['purpose']))
        ->bind(':risks', !empty($data['risks']) ? trim($data['risks']) : null)
        ->bind(':target_date', $data['target_date'])
        ->bind(':start_date', !empty($data['start_date']) ? $data['start_date'] : null)
        ->bind(':completion_date', $completion_date)
        ->bind(':status', $status)
        ->bind(':progress', $progress)
        ->bind(':remarks', !empty($data['remarks']) ? trim($data['remarks']) : null);

    $db->execute();

    return ['success' => true, 'message' => 'Project added successfully!', 'new_id' => $db->lastInsertId()];
}

/**
 * Updates an existing project in the database.
 */
function updateProject($db, $data) {
    $required_fields = ['id', 'name', 'group_name', 'poc', 'target_date', 'status', 'requestor', 'purpose'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || (is_array($data[$field]) ? empty($data[$field]) : trim($data[$field]) === '')) {
           return ['success' => false, 'message' => "Validation error. Missing: " . ucfirst(str_replace('_', ' ', $field))];
       }
   }

    $id = $data['id'];
    // First, check if the project exists
    $db->query("SELECT id FROM projects WHERE id = :id");
    $db->bind(':id', $id);
    $db->execute();
    if (!$db->single()) {
        return ['success' => false, 'message' => 'Error: Project not found.'];
    }

    $name = trim($data['name']);
    $requestor = trim($data['requestor']);
    if (strlen($name) > 100) return ['success' => false, 'message' => 'Project Name must be 100 characters or less.'];
    if (strlen($requestor) > 100) return ['success' => false, 'message' => 'Requestor must be 100 characters or less.'];
    if (strlen($data['purpose']) > 2000) return ['success' => false, 'message' => 'Purpose is too long.'];
    if (strlen($data['risks']) > 2000) return ['success' => false, 'message' => 'Risks field is too long.'];
    if (strlen($data['remarks']) > 2000) return ['success' => false, 'message' => 'Remarks field is too long.'];

    $target_date = new DateTime($data['target_date']);
    $start_date = !empty($data['start_date']) ? new DateTime($data['start_date']) : null;
    if ($start_date && $start_date > $target_date) return ['success' => false, 'message' => 'Start Date cannot be after the Target Completion Date.'];

    $status = $data['status'];
    $progress = isset($data['progress']) ? (int)$data['progress'] : 0;
    if ($status === 'COMPLETED') $progress = 100;
    elseif ($status === 'NOT STARTED') $progress = 0;
    elseif ($status === 'ONGOING' && $progress === 0) $progress = 5;
    elseif ($status === 'ONGOING' && $progress === 100) $progress = 99;

    $completion_date = ($status === 'COMPLETED') ? date('Y-m-d H:i:s') : null;
    $poc = is_array($data['poc']) ? implode(', ', $data['poc']) : ($data['poc'] ?? '');

    $sql = "UPDATE projects SET
                name = :name,
                group_name = :group_name,
                requestor = :requestor,
                poc = :poc,
                purpose = :purpose,
                risks = :risks,
                target_date = :target_date,
                start_date = :start_date,
                completion_date = :completion_date,
                status = :status,
                progress = :progress,
                remarks = :remarks
            WHERE id = :id";

    $db->query($sql)
        ->bind(':id', $id)
        ->bind(':name', $name)
        ->bind(':group_name', $data['group_name'])
        ->bind(':requestor', $requestor)
        ->bind(':poc', $poc)
        ->bind(':purpose', trim($data['purpose']))
        ->bind(':risks', !empty($data['risks']) ? trim($data['risks']) : null)
        ->bind(':target_date', $data['target_date'])
        ->bind(':start_date', !empty($data['start_date']) ? $data['start_date'] : null)
        ->bind(':completion_date', $completion_date)
        ->bind(':status', $status)
        ->bind(':progress', $progress)
        ->bind(':remarks', !empty($data['remarks']) ? trim($data['remarks']) : null);

    $db->execute();

    // FIX: Always return success if the execute call doesn't throw an exception.
    // This prevents the "no changes made" scenario from appearing as an error.
    return ['success' => true, 'message' => 'Project updated successfully.'];
}

/**
 * Deletes a project from the database.
 */
function deleteProject($db, $id) {
    if (empty($id)) return ['success' => false, 'message' => 'No project ID provided.'];

    $db->query("DELETE FROM projects WHERE id = :id");
    $db->bind(':id', $id);
    $db->execute();

    return $db->rowCount() > 0
        ? ['success' => true, 'message' => 'Project deleted successfully.']
        : ['success' => false, 'message' => 'Project not found.'];
}

/**
 * Fetches details for a single project.
 */
function getProjectDetails($db, $id) {
    if (empty($id)) return ['success' => false, 'message' => 'No project ID provided.'];

    $db->query("SELECT * FROM projects WHERE id = :id");
    $db->bind(':id', $id);
    $db->execute();
    $project = $db->single();

    return $project
        ? ['success' => true, 'data' => $project]
        : ['success' => false, 'message' => 'Project not found.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Project Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-start: #667eea;
            --primary-end: #764ba2;
            --danger-start: #ff6b6b;
            --danger-end: #ee5a52;
            --success-start: #38c172;
            --success-end: #2d995b;
            --warning-start: #ffed4a;
            --warning-end: #e6c231;
            --info-start: #6cb2eb;
            --info-end: #4a8bc9;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #343a40;
            --text-color: #333;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e7f4 100%);
            min-height: 100vh;
            padding: 20px;
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--card-shadow);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--medium-gray);
        }

        .header h1 {
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 2.5rem;
            text-align: center;
            font-weight: 700;
            letter-spacing: -0.5px;
            max-width: 70%;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--light-gray);
            padding: 10px 20px;
            border-radius: 50px;
            box-shadow: var(--card-shadow);
        }

        .welcome-text {
            display: flex;
            flex-direction: column;
            text-align: right;
        }

        .welcome-text span {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 1.1rem;
        }

        .welcome-text small {
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }

        .user-info a {
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 0.95em;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-info a:hover {
            background: linear-gradient(45deg, var(--primary-end), var(--primary-start));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: var(--light-gray);
            padding: 12px;
            border-radius: 15px;
            flex-wrap: wrap;
            box-shadow: var(--card-shadow);
        }

        .nav-tab, .nav-tab-link {
            padding: 12px 25px;
            background: transparent;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            color: var(--primary-start);
            font-size: 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .nav-tab.active, .nav-tab-link.active {
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
            color: white;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .nav-tab:hover:not(.active), .nav-tab-link:hover:not(.active) {
            background: rgba(102, 126, 234, 0.1);
        }

        .hidden {
            display: none !important;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .form-section, .table-container, .dashboard-grid {
            background: #fff;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--medium-gray);
            box-shadow: var(--card-shadow);
        }

        .section-title {
            font-size: 1.5rem;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            color: var(--primary-start);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            align-items: start;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .form-group input, .form-group select, .form-group textarea {
            padding: 14px;
            border: 1px solid #ced4da;
            border-radius: 10px;
            font-size: 15px;
            transition: var(--transition);
            width: 100%;
            word-break: break-word;
            resize: vertical;
            background: #f9fafb;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-start);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }

        .form-group textarea {
            height: 120px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            line-height: 1; /* Helps align icons and text */
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 13px;
        }

        .btn-primary {
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(45deg, var(--danger-start), var(--danger-end));
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 107, 107, 0.3);
        }

        .btn-success {
            background: linear-gradient(45deg, var(--success-start), var(--success-end));
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(56, 193, 114, 0.3);
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 20px;
            margin-top: 20px;
            border-top: 1px solid var(--medium-gray);
        }

        .actions .right-actions {
            margin-left: auto;
            display: flex;
            gap: 10px;
        }

        .table-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: nowrap;
        }

        /* FIX: Center align button content */
        .table-actions .btn {
            width: 35px;
            height: 35px;
            padding: 0;
            font-size: 14px;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 15px;
            border: 1px solid var(--medium-gray);
            box-shadow: var(--card-shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
            min-width: 1000px;
        }

        .fixed-layout-table {
            table-layout: fixed;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
            word-wrap: break-word;
            vertical-align: middle; /* FIX: Align cell content vertically */
        }

        th {
            background: var(--light-gray);
            color: var(--text-color);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr:hover {
            background: #f1f3f5;
        }

        .col-name { width: 22%; }
        .col-group { width: 8%; }
        .col-requestor { width: 10%; }
        .col-poc { width: 15%; }
        .col-status { width: 9%; }
        .col-progress { width: 8%; }
        .col-date { width: 9%; }
        .col-actions { width: 10%; } /* Adjusted width */

        .truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 0; /* Fix for ellipsis in fixed table layout */
        }

        .status-badge {
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            text-align: center;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .status-not-started {
            background: #f8d7da;
            color: #721c24;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: slideIn 0.3s;
        }

        @keyframes slideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            color: var(--primary-start);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #aaa;
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-btn:hover {
            background: var(--light-gray);
            color: var(--danger-start);
        }

        #alert-container {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 2000;
            width: 90%;
            max-width: 500px;
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 10px;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: none;
            animation: slideDown 0.5s forwards;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        @keyframes slideDown {
            from { top: -100px; opacity: 0; }
            to { top: 20px; opacity: 1; }
        }

        .alert.show {
            display: flex;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .required {
            color: #dc3545;
        }

        .dashboard-grid, .stats-grid {
            display: grid;
            gap: 20px;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            grid-column: 1 / -1;
        }

        .dashboard-grid {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 2.8rem;
            font-weight: 700;
            margin: 10px 0;
        }

        .total-projects .stat-number { color: var(--primary-start); }
        .completed-projects .stat-number { color: var(--success-start); }
        .ongoing-projects .stat-number { color: var(--info-start); }
        .not-started-projects .stat-number { color: var(--warning-start); }

        .stat-label {
            font-size: 1.1rem;
            color: #6c757d;
            font-weight: 500;
        }

        .dashboard-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--medium-gray);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-start);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-filter {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-filter input, .search-filter select {
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: 10px;
            font-size: 14px;
            width: 100%;
            max-width: 300px;
            min-height: 45px;
            background: #f9fafb;
        }

        .search-filter input:focus, .search-filter select:focus {
            outline: none;
            border-color: var(--primary-start);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }

        .modal-body-view .view-grid {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 15px;
        }

        .modal-body-view .view-grid > dt {
            font-weight: bold;
            color: var(--text-color);
        }

        .modal-body-view dd {
            word-break: break-word;
        }

        #confirm-modal .modal-content {
            max-width: 450px;
        }

        #confirm-modal .modal-body {
            font-size: 1.1rem;
        }

        .warning-text {
            color: var(--danger-start);
            margin-top: 15px;
            font-weight: 500;
        }

        .multiselect-container {
            border: 1px solid #ced4da;
            border-radius: 10px;
            background: #f9fafb;
            position: relative;
            transition: var(--transition);
        }

        .multiselect-container:focus-within {
            border-color: var(--primary-start);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .selected-items {
            padding: 6px;
            min-height: 48px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            cursor: pointer;
            max-height: 110px;
            overflow-y: auto;
        }

        .selected-tag {
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
            color: white;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .selected-tag .remove-tag {
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
        }

        .multiselect-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ced4da;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1001;
            display: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .multiselect-dropdown.open {
            display: block;
        }

        .multiselect-option {
            padding: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: var(--transition);
        }

        .multiselect-option:hover {
            background: #f8f9fa;
        }

        .multiselect-option.selected {
            background: #e3f2fd;
            color: var(--primary-start);
        }

        .multiselect-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-start);
        }

        .multiselect-option label {
            cursor: pointer;
            flex: 1;
            margin: 0;
        }

        .word-counter {
            font-size: 13px;
            color: #6c757d;
            text-align: right;
            margin-top: 6px;
        }

        .progress-bar {
            height: 10px;
            background: var(--medium-gray);
            border-radius: 5px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.5s ease;
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
        }

        .project-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .project-item:last-child {
            border-bottom: none;
        }

        .project-info {
            display: flex;
            flex-direction: column;
        }

        .project-name {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .project-meta {
            font-size: 0.9rem;
            color: #6c757d;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            .header {
                flex-direction: column;
                gap: 15px;
            }
            .user-info {
                width: 100%;
                justify-content: center;
            }
            .nav-tabs {
                justify-content: center;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .nav-tab, .nav-tab-link {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
            .section-title {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div id="alert-container"></div>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-project-diagram"></i> IT Project Management System</h1>
            <div class="user-info">
                <div class="welcome-text">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['empcode']); ?>!</span>
                    <small>Role: <strong><?php echo htmlspecialchars(ucfirst($user_role)); ?></strong></small>
                </div>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</button>
            <button class="nav-tab" data-tab="projects"><i class="fas fa-tasks"></i> Projects</button>
            <button class="nav-tab" data-tab="completed"><i class="fas fa-check-circle"></i> Completed</button>
            <button class="nav-tab <?php if ($user_role !== 'admin') echo 'hidden'; ?>" data-tab="add-project"><i class="fas fa-plus-circle"></i> Add Project</button>
            <a href="register.php" class="nav-tab-link <?php if ($user_role !== 'admin') echo 'hidden'; ?>"><i class="fas fa-users-cog"></i> User Management</a>
            <a href="dailyreport.php" class="nav-tab-link <?php if ($user_role !== 'admin') echo 'hidden'; ?>"><i class="fas fa-users-cog"></i> Daily Report</a>
        </div>
        <div id="dashboard" class="tab-content active">
            <div id="dashboard-loader">Loading dashboard...</div>
            <div class="dashboard-grid" style="display:none;">
                <div class="stats-grid">
                    <div class="stat-card total-projects">
                        <div class="stat-label">Total Projects</div>
                        <div class="stat-number" id="total-projects">0</div>
                        <div><i class="fas fa-folder-open fa-2x"></i></div>
                    </div>
                    <div class="stat-card completed-projects">
                        <div class="stat-label">Completed</div>
                        <div class="stat-number" id="completed-projects">0</div>
                        <div><i class="fas fa-check-circle fa-2x"></i></div>
                    </div>
                    <div class="stat-card ongoing-projects">
                        <div class="stat-label">Ongoing</div>
                        <div class="stat-number" id="ongoing-projects">0</div>
                        <div><i class="fas fa-sync-alt fa-2x"></i></div>
                    </div>
                    <div class="stat-card not-started-projects">
                        <div class="stat-label">Not Started</div>
                        <div class="stat-number" id="not-started-projects">0</div>
                        <div><i class="fas fa-hourglass-start fa-2x"></i></div>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-calendar-day"></i> Upcoming Target Dates</div>
                    </div>
                    <div id="upcoming-list"></div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-history"></i> Recently Completed</div>
                    </div>
                    <div id="recent-list"></div>
                </div>

                <div class="dashboard-card" style="grid-column: 1 / -1;">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-layer-group"></i> Group Summary</div>
                        <button id="export-summary-btn" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Export to Excel</button>
                    </div>
                    <div class="table-container">
                        <table id="group-summary-table">
                            <thead>
                                <tr>
                                    <th>Group</th>
                                    <th>Total Projects</th>
                                    <th>Completed</th>
                                    <th>Ongoing</th>
                                    <th>Not Started</th>
                                </tr>
                            </thead>
                            <tbody id="group-summary-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div id="projects" class="tab-content">
            <div class="search-filter">
                <input type="text" id="search-projects" placeholder="Search active projects...">
                <select id="filter-group">
                    <option value="">All Groups</option>
                    <option value="Dev">Dev</option>
                    <option value="Infra">Infra</option>
                    <option value="SA">SA</option>
                    <option value="Support">Support</option>
                </select>
            </div>
            <div class="table-container">
                <table id="projects-table" class="fixed-layout-table">
                    <thead>
                        <tr>
                            <th class="col-name">Project Name</th>
                            <th class="col-group">Group</th>
                            <th class="col-requestor">Requestor</th>
                            <th class="col-poc">POC</th>
                            <th class="col-status">Status</th>
                            <th class="col-progress">Progress</th>
                            <th class="col-date">Start Date</th>
                            <th class="col-date">Target Date</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="projects-tbody"></tbody>
                </table>
            </div>
        </div>
        <div id="completed" class="tab-content">
            <div class="search-filter">
                <input type="text" id="search-completed" placeholder="Search completed projects...">
                <select id="filter-completed-group">
                    <option value="">All Groups</option>
                    <option value="Dev">Dev</option>
                    <option value="Infra">Infra</option>
                    <option value="SA">SA</option>
                    <option value="Support">Support</option>
                </select>
            </div>
            <div class="table-container">
                <table id="completed-table" class="fixed-layout-table">
                    <thead>
                        <tr>
                            <th class="col-name">Project Name</th>
                            <th class="col-group">Group</th>
                            <th class="col-poc">POC</th>
                            <th class="col-date">Completion Date</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="completed-tbody"></tbody>
                </table>
            </div>
        </div>
        <div id="add-project" class="tab-content">
            <div class="form-section">
                <h3 class="section-title"><i class="fas fa-plus-circle"></i> Add New Project</h3>
                <form id="add-project-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="add-name"><i class="fas fa-project-diagram"></i> Project Name <span class="required">*</span></label>
                            <input type="text" id="add-name" name="name" required maxlength="100" placeholder="Enter project name">
                            <div class="word-counter" id="add-name-counter">0/100 characters</div>
                        </div>
                        <div class="form-group">
                            <label for="add-group"><i class="fas fa-users"></i> Group <span class="required">*</span></label>
                            <select id="add-group" name="group_name" required>
                                <option value="" disabled selected>Select a Group</option>
                                <option value="Dev">Development</option>
                                <option value="Infra">Infrastructure</option>
                                <option value="SA">System Administrator</option>
                                <option value="Support">Support</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-requestor"><i class="fas fa-user-tie"></i> Requestor <span class="required">*</span></label>
                            <input type="text" id="add-requestor" name="requestor" required maxlength="100" placeholder="Enter requestor name">
                            <div class="word-counter" id="add-requestor-counter">0/100 characters</div>
                        </div>
                        <div class="form-group">
                            <label for="add-poc-multiselect"><i class="fas fa-user-friends"></i> POC <span class="required">*</span></label>
                            <div class="multiselect-container" id="add-poc-multiselect">
                                <div class="selected-items" id="add-selected-items">
                                    <span class="empty" style="color:#6c757d;">Click to select POCs...</span>
                                </div>
                                <div class="multiselect-dropdown" id="add-dropdown"></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="add-start-date"><i class="fas fa-calendar-plus"></i> Start Date</label>
                            <input type="date" id="add-start-date" name="start_date" min="2020-01-01">
                        </div>
                        <div class="form-group">
                            <label for="add-target-date"><i class="fas fa-calendar-check"></i> Target Completion Date <span class="required">*</span></label>
                            <input type="date" id="add-target-date" name="target_date" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="add-status"><i class="fas fa-tasks"></i> Status <span class="required">*</span></label>
                            <select id="add-status" name="status" required>
                                <option value="NOT STARTED">Not Started</option>
                                <option value="ONGOING" selected>Ongoing</option>
                                <option value="COMPLETED">Completed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add-progress"><i class="fas fa-chart-line"></i> Progress (%)</label>
                            <select id="add-progress" name="progress"></select>
                        </div>
                        <div class="form-group full-width">
                            <label for="add-purpose"><i class="fas fa-file-alt"></i> Purpose/Details <span class="required">*</span></label>
                            <textarea id="add-purpose" name="purpose" required maxlength="2000" placeholder="Describe the project purpose and details..."></textarea>
                            <div class="word-counter" id="add-purpose-counter">0/2000 characters</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="add-risks"><i class="fas fa-exclamation-triangle"></i> Risk & Help Items</label>
                            <textarea id="add-risks" name="risks" maxlength="2000" placeholder="List potential risks and help needed..."></textarea>
                            <div class="word-counter" id="add-risks-counter">0/2000 characters</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="add-remarks"><i class="fas fa-sticky-note"></i> Remarks</label>
                            <textarea id="add-remarks" name="remarks" maxlength="2000" placeholder="Additional remarks or notes..."></textarea>
                            <div class="word-counter" id="add-remarks-counter">0/2000 characters</div>
                        </div>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-secondary" onclick="window.resetAddForm()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Project
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="project-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title"><i class="fas fa-project-diagram"></i> Project Details</h2>
                <button class="close-btn" onclick="window.closeModal('project-modal')">&times;</button>
            </div>
            <div id="modal-body"></div>
        </div>
    </div>

    <div id="confirm-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="confirm-title"><i class="fas fa-exclamation-triangle"></i> Are you sure?</h2>
                <button class="close-btn" onclick="window.closeModal('confirm-modal')">&times;</button>
            </div>
            <div id="confirm-body" class="modal-body"></div>
            <div class="modal-actions">
                <button id="confirm-cancel-btn" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button id="confirm-ok-btn" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <div id="export-modal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 id="export-title"><i class="fas fa-file-export"></i> Export Project Summary</h2>
                <button class="close-btn" onclick="window.closeModal('export-modal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="export-form" action="projectsummary.php" method="POST" target="_blank">
                    <input type="hidden" name="action" value="export_summary">
                    <p>Select the groups to include in the Excel export. If none are selected, all projects will be exported.</p>
                    <br>
                    <div class="form-group">
                        <div class="multiselect-option">
                             <input type="checkbox" id="export-select-all">
                             <label for="export-select-all"><strong>Select All Groups</strong></label>
                        </div>
                    </div>
                    <hr style="margin: 10px 0;">
                    <div id="export-groups-container">
                         <div class="multiselect-option">
                             <input type="checkbox" name="groups[]" value="Dev" id="group-dev">
                             <label for="group-dev">Development</label>
                         </div>
                         <div class="multiselect-option">
                             <input type="checkbox" name="groups[]" value="Infra" id="group-infra">
                             <label for="group-infra">Infrastructure</label>
                         </div>
                          <div class="multiselect-option">
                             <input type="checkbox" name="groups[]" value="SA" id="group-sa">
                             <label for="group-sa">System Administrator</label>
                         </div>
                          <div class="multiselect-option">
                             <input type="checkbox" name="groups[]" value="Support" id="group-support">
                             <label for="group-support">Support</label>
                         </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('export-modal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download Excel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- GLOBAL STATE & CONSTANTS ---
        const userRole = '<?php echo $user_role; ?>';
        let currentProjectId = null;
        let allProjects = [], completedProjects = [];
        let itMembers = []; // Will be fetched from server

        // --- INITIALIZATION ---
        async function init() {
            populateProgressDropdowns();
            await fetchITMembers(); // Fetch members before initializing forms that use them
            initMultiSelect('add');
            fetchAllData();
            setupEventListeners();
        }

        function populateProgressDropdowns() {
            const selects = document.querySelectorAll('select[name="progress"]');
            if(selects.length > 0) {
                let options = '';
                for (let i = 0; i <= 100; i += 5) {
                    options += `<option value="${i}">${i}%</option>`;
                }
                selects.forEach(select => {
                    select.innerHTML = options;
                    select.value = '0';
                });
            }
        }

        // --- AJAX & DATA FETCHING ---
        async function fetchWithAction(action, body = {}) {
            const formData = new FormData();
            formData.append('action', action);

            for (const key in body) {
                if (Array.isArray(body[key])) {
                    body[key].forEach(item => formData.append(key + '[]', item));
                } else {
                    formData.append(key, body[key]);
                }
            }

            try {
                const response = await fetch('projectsummary.php', { method: 'POST', body: formData });
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return await response.json();
            } catch (error) {
                console.error('Fetch error:', error);
                showAlert('Failed to communicate with the server. Please check your connection.', 'danger');
                return { success: false, message: error.message };
            }
        }

        async function fetchITMembers() {
            const result = await fetchWithAction('get_it_members');
            if (result.success) {
                itMembers = result.data;
            } else {
                showAlert('Could not load list of IT members.', 'danger');
            }
        }

        async function fetchAllData() {
            const dashboardLoader = document.getElementById('dashboard-loader');
            const dashboardGrid = document.querySelector('#dashboard .dashboard-grid');
            dashboardLoader.style.display = 'block';
            dashboardGrid.style.display = 'none';

            try {
                const [dashboardRes, projectsRes, completedRes] = await Promise.all([
                    fetchWithAction('get_dashboard_stats'),
                    fetchWithAction('get_projects'),
                    fetchWithAction('get_completed_projects')
                ]);

                if (dashboardRes.success) {
                    renderDashboard(dashboardRes.data);
                    dashboardLoader.style.display = 'none';
                    dashboardGrid.style.display = 'grid';
                } else {
                    dashboardLoader.textContent = `Error loading dashboard: ${dashboardRes.message}`;
                }

                if (projectsRes.success) {
                    allProjects = projectsRes.data || [];
                    renderProjectsTable(allProjects);
                } else {
                    document.getElementById('projects-tbody').innerHTML = `<tr><td colspan="9">Error loading projects: ${projectsRes.message}</td></tr>`;
                }

                if (completedRes.success) {
                    completedProjects = completedRes.data || [];
                    renderCompletedTable(completedProjects);
                } else {
                    document.getElementById('completed-tbody').innerHTML = `<tr><td colspan="5">Error loading completed projects: ${completedRes.message}</td></tr>`;
                }
            } catch (error) {
                 showAlert('A critical error occurred while loading data.', 'danger');
                 dashboardLoader.textContent = 'Failed to load data.';
            }
        }

        // --- RENDERING FUNCTIONS ---
        function renderDashboard(data) {
            const { stats, upcoming, recent, group_summary } = data;

            document.getElementById('total-projects').textContent = stats.total || 0;
            document.getElementById('completed-projects').textContent = stats.completed || 0;
            document.getElementById('ongoing-projects').textContent = stats.ongoing || 0;
            document.getElementById('not-started-projects').textContent = stats.not_started || 0;

            const upcomingList = document.getElementById('upcoming-list');
            upcomingList.innerHTML = upcoming.length > 0 ? upcoming.map(p => `
                <div class="project-item">
                    <div class="project-info">
                        <div class="project-name truncate" title="${p.name}">${p.name}</div>
                        <div class="project-meta">Target: ${formatDate(p.target_date)}</div>
                    </div>
                    <div class="project-date"><strong>${daysUntil(p.target_date)}</strong> days</div>
                </div>`).join('') : '<p>No upcoming deadlines.</p>';

            const recentList = document.getElementById('recent-list');
            recentList.innerHTML = recent.length > 0 ? recent.map(p => `
                <div class="project-item">
                    <div class="project-info">
                        <div class="project-name truncate" title="${p.name}">${p.name}</div>
                        <div class="project-meta">Completed: ${formatDate(p.completion_date)}</div>
                    </div>
                </div>`).join('') : '<p>No recently completed projects.</p>';

            const groupSummaryBody = document.getElementById('group-summary-tbody');
            groupSummaryBody.innerHTML = group_summary.length > 0 ? group_summary.map(g => `
                <tr>
                    <td><strong>${g.group_name}</strong></td>
                    <td>${g.total_projects}</td>
                    <td>${g.completed}</td>
                    <td>${g.ongoing}</td>
                    <td>${g.not_started}</td>
                </tr>`).join('') : '<tr><td colspan="5">No group data available.</td></tr>';
        }

        function renderProjectsTable(data) {
            const tbody = document.getElementById('projects-tbody');
            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;">No active projects found.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(p => `
                <tr>
                    <td class="col-name truncate" title="${p.name}">${p.name}</td>
                    <td class="col-group">${p.group_name}</td>
                    <td class="col-requestor truncate" title="${p.requestor}">${p.requestor}</td>
                    <td class="col-poc truncate" title="${p.poc}">${p.poc}</td>
                    <td class="col-status"><span class="status-badge status-${p.status.toLowerCase().replace(/ /g, '-')}">${p.status}</span></td>
                    <td class="col-progress">
                        <div>${p.progress}%</div>
                        <div class="progress-bar"><div class="progress-fill" style="width: ${p.progress}%;"></div></div>
                    </td>
                    <td class="col-date">${formatDate(p.start_date)}</td>
                    <td class="col-date">${formatDate(p.target_date)}</td>
                    <td class="col-actions">
                        <div class="table-actions">
                            ${userRole === 'admin' ? `
                            <button class="btn btn-primary btn-sm" title="Edit Project" onclick="window.editProject(${p.id})"><i class="fas fa-edit"></i></button>
                            ` : ''}
                        </div>
                    </td>
                </tr>`).join('');
        }

        function renderCompletedTable(data) {
            const tbody = document.getElementById('completed-tbody');
            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No completed projects found.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(p => `
                <tr>
                    <td class="col-name truncate" title="${p.name}">${p.name}</td>
                    <td class="col-group">${p.group_name}</td>
                    <td class="col-poc truncate" title="${p.poc}">${p.poc}</td>
                    <td class="col-date">${formatDate(p.completion_date)}</td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <button class="btn btn-secondary btn-sm" title="View Details" onclick="window.viewProject(${p.id})"><i class="fas fa-eye"></i></button>
                        </div>
                    </td>
                </tr>`).join('');
        }

        // --- EVENT LISTENERS & HANDLERS ---
        function setupEventListeners() {
            document.querySelector('.nav-tabs').addEventListener('click', (e) => {
                if (e.target.matches('.nav-tab')) {
                    document.querySelectorAll('.nav-tab, .tab-content').forEach(el => el.classList.remove('active'));
                    e.target.classList.add('active');
                    document.getElementById(e.target.dataset.tab).classList.add('active');
                    if (e.target.dataset.tab === 'dashboard') fetchAllData();
                }
            });

            document.getElementById('add-project-form').addEventListener('submit', handleAddOrUpdateProject);

            document.getElementById('search-projects').addEventListener('input', filterProjectsTable);
            document.getElementById('filter-group').addEventListener('change', filterProjectsTable);
            document.getElementById('search-completed').addEventListener('input', filterCompletedTable);
            document.getElementById('filter-completed-group').addEventListener('change', filterCompletedTable);

            document.getElementById('confirm-cancel-btn').addEventListener('click', () => closeModal('confirm-modal'));
            document.getElementById('confirm-ok-btn').addEventListener('click', handleDeleteProject);

            // Character counter listeners
            ['add-name', 'add-requestor', 'add-purpose', 'add-risks', 'add-remarks'].forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('input', () => updateCharCounter(id, `${id}-counter`));
                    updateCharCounter(id, `${id}-counter`); // Initial count
                }
            });

            // --- EXPORT LISTENERS ---
            document.getElementById('export-summary-btn').addEventListener('click', () => {
                openModal('export-modal');
                const form = document.getElementById('export-form');
                form.reset();
                document.getElementById('export-select-all').checked = false;
            });

            document.getElementById('export-select-all').addEventListener('change', (e) => {
                const isChecked = e.target.checked;
                document.querySelectorAll('#export-groups-container input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
            });

            document.getElementById('export-groups-container').addEventListener('change', (e) => {
                if (e.target.type === 'checkbox' && !e.target.checked) {
                    document.getElementById('export-select-all').checked = false;
                }
            });

            document.getElementById('export-form').addEventListener('submit', (e) => {
                // Let the form submit normally to trigger the download.
                // Close the modal after a short delay to allow the submission to start.
                setTimeout(() => {
                    closeModal('export-modal');
                }, 500);
            });
        }

        function filterProjectsTable() {
            const searchTerm = document.getElementById('search-projects').value.toLowerCase();
            const groupFilter = document.getElementById('filter-group').value;
            const filtered = allProjects.filter(p => {
                const matchesSearch = Object.values(p).some(val => String(val).toLowerCase().includes(searchTerm));
                const matchesGroup = groupFilter ? p.group_name === groupFilter : true;
                return matchesSearch && matchesGroup;
            });
            renderProjectsTable(filtered);
        }

        function filterCompletedTable() {
            const searchTerm = document.getElementById('search-completed').value.toLowerCase();
            const groupFilter = document.getElementById('filter-completed-group').value;
            const filtered = completedProjects.filter(p => {
                const matchesSearch = Object.values(p).some(val => String(val).toLowerCase().includes(searchTerm));
                const matchesGroup = groupFilter ? p.group_name === groupFilter : true;
                return matchesSearch && matchesGroup;
            });
            renderCompletedTable(filtered);
        }

        async function handleAddOrUpdateProject(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const action = formData.get('id') ? 'update_project' : 'add_project';

            const pocs = Array.from(form.querySelectorAll('.selected-tag span:first-child')).map(span => span.textContent);
            formData.delete('poc[]');
            pocs.forEach(poc => formData.append('poc[]', poc));

            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Saving...`;

            const result = await fetchWithAction(action, Object.fromEntries(formData));

            if (result.success) {
                showAlert(result.message, 'success');
                await fetchAllData();
                if (action === 'add_project') {
                    resetAddForm();
                    document.querySelector('[data-tab="projects"]').click();
                } else {
                    closeModal('project-modal');
                }
            } else {
                showAlert(result.message || 'An unknown error occurred.', 'danger');
            }
            button.disabled = false;
            button.innerHTML = action === 'add_project' ? '<i class="fas fa-plus"></i> Add Project' : '<i class="fas fa-save"></i> Save Changes';
        }

        window.viewProject = async function(id) {
            const result = await fetchWithAction('get_project_details', { id });
            if (!result.success) {
                showAlert(result.message, 'danger');
                return;
            }
            const p = result.data;
            const viewHTML = `<div class="modal-body-view">
                <dl class="view-grid">
                    <dt>Project Name</dt><dd>${p.name || ''}</dd>
                    <dt>Group</dt><dd>${p.group_name || ''}</dd>
                    <dt>Status</dt><dd><span class="status-badge status-${(p.status || '').toLowerCase().replace(/ /g, '-')}">${p.status}</span></dd>
                    <dt>Progress</dt><dd>${p.progress || 0}%</dd>
                    <dt>Requestor</dt><dd>${p.requestor || ''}</dd>
                    <dt>POC</dt><dd>${p.poc || ''}</dd>
                    <dt>Start Date</dt><dd>${formatDate(p.start_date)}</dd>
                    <dt>Target Date</dt><dd>${formatDate(p.target_date)}</dd>
                    ${p.completion_date ? `<dt>Completion Date</dt><dd>${formatDate(p.completion_date)}</dd>` : ''}
                    <dt>Purpose</dt><dd>${p.purpose || 'N/A'}</dd>
                    <dt>Risks</dt><dd>${p.risks || 'None'}</dd>
                    <dt>Remarks</dt><dd>${p.remarks || 'None'}</dd>
                </dl></div>
                <div class="modal-actions"><button class="btn btn-secondary" onclick="closeModal('project-modal')"><i class="fas fa-times"></i> Close</button></div>`;
            document.getElementById('modal-title').innerHTML = `<i class="fas fa-eye"></i> ${p.name}`;
            document.getElementById('modal-body').innerHTML = viewHTML;
            openModal('project-modal');
        };

        window.editProject = async function(id) {
            const result = await fetchWithAction('get_project_details', { id });
            if (!result.success) {
                showAlert(result.message, 'danger'); return;
            }
            const p = result.data;
            const editFormHTML = `<form id="edit-project-form">
                <input type="hidden" name="id" value="${p.id}">
                <div class="form-grid">
                    <div class="form-group"><label>Name <span class="required">*</span></label><input type="text" name="name" value="${p.name || ''}" required maxlength="100"></div>
                    <div class="form-group"><label>Group <span class="required">*</span></label><select name="group_name" required></select></div>
                    <div class="form-group"><label>Requestor <span class="required">*</span></label><input type="text" name="requestor" value="${p.requestor || ''}" required maxlength="100"></div>
                    <div class="form-group"><label>POC <span class="required">*</span></label><div class="multiselect-container" id="edit-poc-multiselect"><div class="selected-items" id="edit-selected-items"></div><div class="multiselect-dropdown" id="edit-dropdown"></div></div></div>
                    <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="${p.start_date ? p.start_date.split(' ')[0] : ''}"></div>
                    <div class="form-group"><label>Target Date <span class="required">*</span></label><input type="date" name="target_date" value="${p.target_date ? p.target_date.split(' ')[0] : ''}" required></div>
                    <div class="form-group"><label>Status <span class="required">*</span></label><select name="status" required></select></div>
                    <div class="form-group"><label>Progress (%)</label><select name="progress"></select></div>
                    <div class="form-group full-width"><label>Purpose <span class="required">*</span></label><textarea name="purpose" required maxlength="2000">${p.purpose || ''}</textarea></div>
                    <div class="form-group full-width"><label>Risks</label><textarea name="risks" maxlength="2000">${p.risks || ''}</textarea></div>
                    <div class="form-group full-width"><label>Remarks</label><textarea name="remarks" maxlength="2000">${p.remarks || ''}</textarea></div>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-danger" onclick="window.confirmDelete(${p.id}, '${escapeJS(p.name)}')"><i class="fas fa-trash"></i> Delete</button>
                    <div class="right-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('project-modal')"><i class="fas fa-times"></i> Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </div></form>`;

            document.getElementById('modal-title').innerHTML = `<i class="fas fa-edit"></i> Edit ${p.name}`;
            document.getElementById('modal-body').innerHTML = editFormHTML;

            const form = document.getElementById('edit-project-form');
            form.querySelector('select[name="group_name"]').innerHTML = `<option value="Dev" ${p.group_name === 'Dev' ? 'selected' : ''}>Dev</option><option value="Infra" ${p.group_name === 'Infra' ? 'selected' : ''}>Infra</option><option value="SA" ${p.group_name === 'SA' ? 'selected' : ''}>SA</option><option value="Support" ${p.group_name === 'Support' ? 'selected' : ''}>Support</option>`;
            form.querySelector('select[name="status"]').innerHTML = `<option value="NOT STARTED" ${p.status === 'NOT STARTED' ? 'selected' : ''}>Not Started</option><option value="ONGOING" ${p.status === 'ONGOING' ? 'selected' : ''}>Ongoing</option><option value="COMPLETED" ${p.status === 'COMPLETED' ? 'selected' : ''}>Completed</option>`;
            const progressSelect = form.querySelector('select[name="progress"]');
            let progressOptions = '';
            for(let i=0; i<=100; i+=5) progressOptions += `<option value="${i}" ${i === p.progress ? 'selected' : ''}>${i}%</option>`;
            progressSelect.innerHTML = progressOptions;

            initMultiSelect('edit', p.poc ? p.poc.split(', ') : []);
            form.addEventListener('submit', handleAddOrUpdateProject);
            openModal('project-modal');
        };

        window.confirmDelete = function(id, name) {
            currentProjectId = id;
            document.getElementById('confirm-body').innerHTML = `<p>Are you sure you want to delete the project:</p><p><strong>${name}</strong>?</p><p class="warning-text"><i class="fas fa-exclamation-circle"></i> This action cannot be undone.</p>`;
            openModal('confirm-modal');
        };

        async function handleDeleteProject() {
            if (!currentProjectId) return;
            const result = await fetchWithAction('delete_project', { id: currentProjectId });
            if (result.success) {
                showAlert(result.message, 'success');
                await fetchAllData();
            } else {
                showAlert(result.message, 'danger');
            }
            closeModal('confirm-modal');
            closeModal('project-modal');
            currentProjectId = null;
        }

        window.resetAddForm = function() {
            const form = document.getElementById('add-project-form');
            form.reset();
            resetMultiSelect('add');
            ['name', 'requestor', 'purpose', 'risks', 'remarks'].forEach(id => updateCharCounter(`add-${id}`, `add-${id}-counter`));
        };

        // --- UTILITY FUNCTIONS ---
        function initMultiSelect(prefix, selected = []) {
            const container = document.getElementById(`${prefix}-poc-multiselect`);
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            if (!container || !dropdown) return;

            dropdown.innerHTML = itMembers.map(member => {
                const isSelected = selected.includes(member);
                const id = `${prefix}-poc-${member.replace(/[^a-zA-Z0-9]/g, '-')}`;
                return `<div class="multiselect-option ${isSelected ? 'selected' : ''}" data-value="${escapeJS(member)}">
                    <input type="checkbox" id="${id}" value="${escapeJS(member)}" ${isSelected ? 'checked' : ''}>
                    <label for="${id}">${member}</label>
                </div>`;
            }).join('');

            updateSelectedTags(prefix);

            container.addEventListener('click', e => {
                if (!dropdown.contains(e.target) && !e.target.classList.contains('remove-tag')) {
                    dropdown.classList.toggle('open');
                }
            });

            dropdown.addEventListener('change', e => {
                if (e.target.type === 'checkbox') {
                    const optionDiv = e.target.closest('.multiselect-option');
                    optionDiv.classList.toggle('selected', e.target.checked);
                    updateSelectedTags(prefix);
                }
            });
        }

        function updateSelectedTags(prefix) {
            const selectedItems = document.getElementById(`${prefix}-selected-items`);
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            if (!selectedItems || !dropdown) return;

            const selectedCheckboxes = dropdown.querySelectorAll('input:checked');

            if (selectedCheckboxes.length === 0) {
                selectedItems.innerHTML = `<span class="empty" style="color:#6c757d;">Click to select POCs...</span>`;
            } else {
                selectedItems.innerHTML = Array.from(selectedCheckboxes).map(cb =>
                    `<div class="selected-tag"><span>${cb.value}</span><span class="remove-tag" data-value="${escapeJS(cb.value)}">&times;</span></div>`
                ).join('');
            }
        }

        document.body.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-tag')) {
                const prefix = e.target.closest('form, .modal-content').id.includes('edit') ? 'edit' : 'add';
                const value = e.target.dataset.value;
                const dropdown = document.getElementById(`${prefix}-dropdown`);
                if (dropdown) {
                    const checkbox = dropdown.querySelector(`input[value="${value}"]`);
                    if(checkbox) {
                        checkbox.checked = false;
                        const optionDiv = checkbox.closest('.multiselect-option');
                        if(optionDiv) optionDiv.classList.remove('selected');
                    }
                }
                updateSelectedTags(prefix);
            }

            // Close dropdowns if clicking outside
            if (!e.target.closest('.multiselect-container')) {
                document.querySelectorAll('.multiselect-dropdown.open').forEach(d => d.classList.remove('open'));
            }
        });

        function resetMultiSelect(prefix) {
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            if (dropdown) {
                dropdown.querySelectorAll('input:checked').forEach(cb => cb.checked = false);
                dropdown.querySelectorAll('.multiselect-option.selected').forEach(el => el.classList.remove('selected'));
            }
            updateSelectedTags(prefix);
        }

        function updateCharCounter(elementId, counterId) {
            const element = document.getElementById(elementId);
            const counter = document.getElementById(counterId);
            if (!element || !counter) return;

            const len = element.value.length;
            const max = element.maxLength;
            if (max <= 0) return; // Don't show for elements without maxlength
            counter.textContent = `${len}/${max} characters`;
            counter.style.color = len >= max ? 'var(--danger-start)' : '#6c757d';
        }

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const [datePart] = dateStr.split(' ');
            const [year, month, day] = datePart.split('-');
            if (!year || !month || !day) return 'Invalid Date';
            // Create date in UTC to avoid timezone issues
            const date = new Date(Date.UTC(year, month-1, day));
            return date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric',
                timeZone: 'UTC'
            });
        }

        function daysUntil(dateStr) {
            if (!dateStr) return 'N/A';
            const [datePart] = dateStr.split(' ');
            const [year, month, day] = datePart.split('-');
            if (!year || !month || !day) return 'N/A';
            // Create date in UTC to avoid timezone issues
            const targetDate = new Date(Date.UTC(year, month-1, day));
            const today = new Date();
            // Create today in UTC
            const todayUTC = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()));
            const diff = targetDate.getTime() - todayUTC.getTime();
            return Math.ceil(diff / (1000 * 60 * 60 * 24));
        }

        function showAlert(message, type = 'success') {
            const container = document.getElementById('alert-container');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span>${message}</span>`;
            container.appendChild(alert);
            alert.classList.add('show');
            setTimeout(() => { alert.remove(); }, 5000);
        }

        function openModal(id) { document.getElementById(id)?.classList.add('show'); }
        window.closeModal = function(id) { document.getElementById(id)?.classList.remove('show'); }
        function escapeJS(str) {
            return String(str || '').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        init();
    });
    </script>
</body>
</html>