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
if (isset($_POST['action']) && $_POST['action'] === 'export_summary') {
    if (!isset($_SESSION['username'])) {
        http_response_code(403); // Forbidden
        die('Access Denied. Please log in.');
    }
    require_once 'config/config.php';
    try {
        $db = new Database();
        $selected_groups = isset($_POST['groups']) && is_array($_POST['groups']) ? $_POST['groups'] : [];
        exportSummaryAsExcelHtml($db, $selected_groups);
    } catch (Exception $e) {
        error_log("EXPORT ERROR: " . $e->getMessage());
        http_response_code(500);
        die('An unexpected server error occurred during export. Please contact support.');
    }
    exit;
}

require_once 'config/config.php';

// --- SESSION CHECK ---
if (!isset($_SESSION['username'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// --- ROLE & CONFIGURATION ---
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'viewer';
$db = null;
try {
    $db = new Database();
} catch (Exception $e) {
    error_log("DB CONNECTION ERROR: " . $e->getMessage());
    http_response_code(500);
    die('An unexpected server error occurred. Please contact support.');
}

// --- AJAX REQUEST HANDLER ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
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
                $sort_option = isset($_POST['sort_by']) ? $_POST['sort_by'] : 'target_date_asc';
                $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                $per_page = 20;
                $group_filter = isset($_POST['group_filter']) ? $_POST['group_filter'] : '';
                $search_query = isset($_POST['search_query']) ? $_POST['search_query'] : '';
                echo json_encode(getProjects($db, $sort_option, $page, $per_page, $group_filter, $search_query));
                break;
            case 'get_completed_projects':
                $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                $per_page = 20;
                $group_filter = isset($_POST['group_filter']) ? $_POST['group_filter'] : '';
                $search_query = isset($_POST['search_query']) ? $_POST['search_query'] : '';
                $completion_date_filter = isset($_POST['completion_date_filter']) ? $_POST['completion_date_filter'] : '';
                echo json_encode(getCompletedProjects($db, $page, $per_page, $group_filter, $search_query, $completion_date_filter));
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
            case 'get_it_members':
                echo json_encode(getITMembers($db));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action.']);
                break;
        }
    } catch (Exception $e) {
        error_log("AJAX Error in projectsummary.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred. Please contact support.']);
    }
    exit;
}


// --- DATABASE FUNCTIONS ---

function getDashboardStats($db) {
    $stats = ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'not_started' => 0];
    $upcoming = [];
    $recent = [];
    $group_summary = [];

    try {
        $db->query("SELECT * FROM projects");
        $db->execute();
        $projects = $db->resultSet();
    
        foreach ($projects as $project) {
            $stats['total']++;
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

            if ($project['status'] !== 'COMPLETED' && isset($project['target_date'])) {
                if (strtotime($project['target_date']) < strtotime('+30 days') && strtotime($project['target_date']) >= time()) {
                    $upcoming[] = $project;
                }
            }

            $group = $project['group_name'];
            if (!isset($group_summary[$group])) {
                $group_summary[$group] = ['group_name' => $group, 'total_projects' => 0, 'completed' => 0, 'ongoing' => 0, 'not_started' => 0];
            }
            $group_summary[$group]['total_projects']++;
            if ($project['status'] === 'COMPLETED') $group_summary[$group]['completed']++;
            elseif ($project['status'] === 'ONGOING') $group_summary[$group]['ongoing']++;
            elseif ($project['status'] === 'NOT STARTED') $group_summary[$group]['not_started']++;
        }

        usort($upcoming, fn($a, $b) => strtotime($a['target_date']) - strtotime($b['target_date']));
        $upcoming = array_slice($upcoming, 0, 5);
        usort($recent, fn($a, $b) => strtotime($b['completion_date']) - strtotime($a['completion_date']));
        $recent = array_slice($recent, 0, 5);
    
        return ['success' => true, 'data' => ['stats' => $stats, 'upcoming' => $upcoming, 'recent' => $recent, 'group_summary' => array_values($group_summary)]];
    } catch (Exception $e) {
        error_log("Dashboard Stats Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to load dashboard data.'];
    }
}

function exportSummaryAsExcelHtml($db, $groups = []) {
    try {
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
        
        $filename = "project_summary_" . date('Y-m-d_H-i-s') . ".xls";
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        
        echo "\xEF\xBB\xBF";
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;font-size:10pt}table{border-collapse:collapse;width:100%}th{background-color:#4472C4;color:white;font-weight:700;text-align:center;padding:8px;border:2px solid #2F4F4F}td{border:1px solid #ccc;padding:6px;vertical-align:top}.group-header{background-color:#D9E1F2;font-weight:700;text-align:center;font-size:11pt;border:2px solid #4472C4}.title{text-align:center;font-size:14pt;font-weight:700;color:#4472C4;margin:20px 0}</style></head><body><div class="title">PROJECT SUMMARY REPORT - ' . date('F j, Y') . '</div><table><tr><th>Project Name</th><th>Group</th><th>Requestor</th><th>Point of Contact</th><th>Purpose</th><th>Risks & Help Items</th><th>Status</th><th>Progress</th><th>Start Date</th><th>Target Date</th><th>Completion Date</th></tr>';
        
        $currentGroup = '';
        $rowCount = 0;
        
        foreach ($projects as $project) {
            if (!empty($project['group_name']) && $project['group_name'] !== $currentGroup) {
                if ($rowCount > 0) echo '<tr><td colspan="11" style="height:5px;border:none"></td></tr>';
                echo '<tr><td colspan="11" class="group-header">' . htmlspecialchars(strtoupper($project['group_name'])) . '</td></tr>';
                $currentGroup = $project['group_name'];
            }
            
            $progress = floatval($project['progress'] ?? 0);
            if ($progress <= 1) $progress *= 100;
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($project['name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['group_name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['requestor'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['poc'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars(truncateText($project['purpose'] ?? '', 80)) . '</td>';
            echo '<td>' . htmlspecialchars(truncateText($project['risks'] ?? '', 80)) . '</td>';
            echo '<td>' . htmlspecialchars($project['status'] ?? '') . '</td>';
            echo '<td>' . number_format($progress, 1) . '%</td>';
            echo '<td>' . htmlspecialchars(formatDate($project['start_date'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars(formatDate($project['target_date'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars(formatDate($project['completion_date'] ?? '')) . '</td>';
            echo '</tr>';
            
            $rowCount++;
        }
        
        echo '<tr><td colspan="11" style="text-align:center;font-weight:700;background-color:#F2F2F2;padding:10px;border:2px solid #4472C4">Total Projects: ' . $rowCount . ' | Generated: ' . date('Y-m-d H:i:s') . '</td></tr></table></body></html>';
        exit;
    } catch (Exception $e) {
        error_log("Excel HTML Export Error: " . $e->getMessage());
        http_response_code(500);
        die("Export failed: " . $e->getMessage());
    }
}

function truncateText($text, $length) {
    if (strlen($text) > $length) {
        return substr($text, 0, $length - 3) . "...";
    }
    return $text;
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '';
    try {
        return (new DateTime($date))->format('m/d/Y');
    } catch (Exception $e) {
        return $date;
    }
}

function getITMembers($db) {
    $members = ["Ranzel Laroya", "Lemuel Sigua", "Ronell Evaristo", "Jayson Gaon", "Hyacinth Faye Mendez", "Alain Jake Alimurong", "Renniel Ramos", "Justin Luna", "Jairha Cortez", "Ashley Kent San Pedro", "Kyle Justine Dimla", "Stephen Karlle Dimitui", "Russel Pineda"];
    return ['success' => true, 'data' => $members];
}

// UPDATED: Added search functionality to getProjects
function getProjects($db, $sort_option = 'target_date_asc', $page = 1, $per_page = 20, $group_filter = '', $search_query = '') {
    try {
        $sort_columns = [
            'target_date_asc' => 'ORDER BY target_date ASC', 'target_date_desc' => 'ORDER BY target_date DESC',
            'start_date_asc' => 'ORDER BY start_date ASC', 'start_date_desc' => 'ORDER BY start_date DESC',
        ];
        $order_clause = $sort_columns[$sort_option] ?? 'ORDER BY target_date ASC';
        $offset = ($page - 1) * $per_page;
        $where_clause = " WHERE status <> 'COMPLETED' ";
        $params = [];
    
        if (!empty($group_filter)) {
            $where_clause .= " AND group_name = :group_filter";
            $params[':group_filter'] = $group_filter;
        }
    
        if (!empty($search_query)) {
            $where_clause .= " AND (name LIKE :search_query OR requestor LIKE :search_query OR poc LIKE :search_query)";
            $params[':search_query'] = '%' . $search_query . '%';
        }
    
        $db->query("SELECT COUNT(*) as total FROM projects" . $where_clause);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $db->execute();
        $total_items = $db->single()['total'];
    
        $sql = "SELECT * FROM projects" . $where_clause . " " . $order_clause . " OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
        $db->query($sql);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $db->bind(':limit', $per_page);
        $db->bind(':offset', $offset);
        $db->execute();
        
        return [
            'success' => true,
            'data' => $db->resultSet(),
            'pagination' => [
                'total_items' => $total_items,
                'per_page' => $per_page,
                'current_page' => $page,
                'total_pages' => ceil($total_items / $per_page)
            ]
        ];
    } catch (Exception $e) {
        error_log("Get Projects Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to load project list.'];
    }
}

// UPDATED: Filter by a single completion date.
function getCompletedProjects($db, $page = 1, $per_page = 20, $group_filter = '', $search_query = '', $completion_date_filter = '') {
    try {
        $offset = ($page - 1) * $per_page;
        $where_clause = " WHERE status = 'COMPLETED' ";
        $params = [];
    
        if (!empty($group_filter)) {
            $where_clause .= " AND group_name = :group_filter";
            $params[':group_filter'] = $group_filter;
        }
    
        if (!empty($search_query)) {
            $where_clause .= " AND (name LIKE :search_query OR requestor LIKE :search_query OR poc LIKE :search_query)";
            $params[':search_query'] = '%' . $search_query . '%';
        }
        
        if (!empty($completion_date_filter)) {
            $where_clause .= " AND CAST(completion_date AS DATE) = :completion_date_filter";
            $params[':completion_date_filter'] = $completion_date_filter;
        }
    
        $db->query("SELECT COUNT(*) as total FROM projects" . $where_clause);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $db->execute();
        $total_items = $db->single()['total'];
    
        $sql = "SELECT * FROM projects" . $where_clause . " ORDER BY completion_date DESC OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
        $db->query($sql);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $db->bind(':limit', $per_page);
        $db->bind(':offset', $offset);
        $db->execute();
    
        return [
            'success' => true,
            'data' => $db->resultSet(),
            'pagination' => [
                'total_items' => $total_items,
                'per_page' => $per_page,
                'current_page' => $page,
                'total_pages' => ceil($total_items / $per_page)
            ]
        ];
    } catch (Exception $e) {
        error_log("Get Completed Projects Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to load completed project list.'];
    }
}

// MODIFIED: Added more robust validation from projectsummary.php
function addProject($db, $data) {
    try {
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
        if (!empty($data['risks']) && strlen($data['risks']) > 2000) return ['success' => false, 'message' => 'Risks field is too long.'];
        if (!empty($data['remarks']) && strlen($data['remarks']) > 2000) return ['success' => false, 'message' => 'Remarks field is too long.'];

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
        
        $sql = "INSERT INTO projects (name, group_name, requestor, poc, purpose, risks, target_date, start_date, completion_date, status, progress, remarks) VALUES (:name, :group_name, :requestor, :poc, :purpose, :risks, :target_date, :start_date, :completion_date, :status, :progress, :remarks)";
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
    } catch (Exception $e) {
        error_log("Add Project Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add project.'];
    }
}

// MODIFIED: Added more robust validation from projectsummary.php
function updateProject($db, $data) {
    try {
        $required_fields = ['id', 'name', 'group_name', 'poc', 'target_date', 'status', 'requestor', 'purpose'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || (is_array($data[$field]) ? empty($data[$field]) : trim($data[$field]) === '')) {
               return ['success' => false, 'message' => "Validation error. Missing required field: " . ucfirst(str_replace('_', ' ', $field))];
           }
       }
        $id = $data['id'];
        $name = trim($data['name']);
        $requestor = trim($data['requestor']);
        if (strlen($name) > 100) return ['success' => false, 'message' => 'Project Name must be 100 characters or less.'];
        if (strlen($requestor) > 100) return ['success' => false, 'message' => 'Requestor must be 100 characters or less.'];

        $target_date = new DateTime($data['target_date']);
        if (!empty($data['start_date'])) {
            $start_date = new DateTime($data['start_date']);
            if ($start_date > $target_date) return ['success' => false, 'message' => 'Start Date cannot be after the Target Completion Date.'];
        }
    
        $poc = is_array($data['poc']) ? implode(', ', $data['poc']) : ($data['poc'] ?? '');
        
        $status = $data['status'];
        $progress = isset($data['progress']) ? (int)$data['progress'] : 0;
        if ($status === 'COMPLETED') $progress = 100;
        elseif ($status === 'NOT STARTED') $progress = 0;
        elseif ($status === 'ONGOING' && $progress === 0) $progress = 5;
        elseif ($status === 'ONGOING' && $progress === 100) $progress = 99;
    
        $completion_date = ($status === 'COMPLETED') ? date('Y-m-d H:i:s') : null;
    
        $sql = "UPDATE projects SET name = :name, group_name = :group_name, requestor = :requestor, poc = :poc, purpose = :purpose, risks = :risks, target_date = :target_date, start_date = :start_date, completion_date = :completion_date, status = :status, progress = :progress, remarks = :remarks WHERE id = :id";
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
        return ['success' => true, 'message' => 'Project updated successfully.'];
    } catch (Exception $e) {
        error_log("Update Project Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update project.'];
    }
}

function deleteProject($db, $id) {
    try {
        if (empty($id)) return ['success' => false, 'message' => 'No project ID provided.'];
        $db->query("DELETE FROM projects WHERE id = :id");
        $db->bind(':id', $id);
        $db->execute();
        return $db->rowCount() > 0 ? ['success' => true, 'message' => 'Project deleted successfully.'] : ['success' => false, 'message' => 'Project not found.'];
    } catch (Exception $e) {
        error_log("Delete Project Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete project.'];
    }
}

function getProjectDetails($db, $id) {
    try {
        if (empty($id)) return ['success' => false, 'message' => 'No project ID provided.'];
        $db->query("SELECT * FROM projects WHERE id = :id");
        $db->bind(':id', $id);
        $db->execute();
        $project = $db->single();
        return $project ? ['success' => true, 'data' => $project] : ['success' => false, 'message' => 'Project not found.'];
    } catch (Exception $e) {
        error_log("Get Project Details Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to load project details.'];
    }
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
            --primary-start: #667eea; --primary-end: #764ba2; --danger-start: #ff6b6b;
            --danger-end: #ee5a52; --success-start: #38c172; --success-end: #2d995b;
            --light-gray: #f8f9fa; --medium-gray: #e9ecef; --dark-gray: #343a40;
            --text-color: #333; --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        
        body { background: linear-gradient(135deg, #f5f7fa 0%, #e4e7f4 100%); min-height: 10vh; }
        .container { max-width: 95%; margin: 20px auto; background: white; border-radius: 20px; padding: 30px; box-shadow: var(--card-shadow); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid var(--medium-gray); }
        .header h1 { background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: 2.5rem; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-info a { background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); color: white; padding: 8px 20px; border-radius: 50px; text-decoration: none; }
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 30px; flex-wrap: wrap; }
        .nav-tab, .nav-tab-link { padding: 12px 25px; background: transparent; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; transition: var(--transition); }
        .nav-tab.active, .nav-tab-link.active { background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); color: white; }
        .hidden { display: none !important; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .dashboard-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; width: 100%; }
        .dashboard-card { background: white; padding: 25px; border-radius: 15px; box-shadow: var(--card-shadow); border: 1px solid var(--medium-gray); }
        .dashboard-grid { display: grid; gap: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; grid-column: 1 / -1; }
        .stat-card { background: white; padding: 20px; border-radius: 15px; text-align: center; box-shadow: var(--card-shadow); }
        .stat-number { font-size: 2.8rem; font-weight: 700; }
        .stat-label { font-size: 1.1rem; color: #6c757d; }
        .card-title { font-size: 1.3rem; font-weight: 600; color: var(--primary-start); margin-bottom: 15px; }
        .project-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--medium-gray); }
        .project-item:last-child { border-bottom: none; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--medium-gray); }
        th { background: var(--light-gray); }
        .search-filter { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .search-filter input, .search-filter select { padding: 12px; border: 1px solid #ced4da; border-radius: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); color: white; }
        .btn-danger { background: linear-gradient(45deg, var(--danger-start), var(--danger-end)); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .actions { display: flex; justify-content: flex-end; gap: 15px; margin-top: 20px; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px); align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 20px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { color: var(--primary-start); }
        .close-btn { background: none; border: none; font-size: 28px; cursor: pointer; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { margin-bottom: 8px; font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea { padding: 14px; border: 1px solid #ced4da; border-radius: 10px; }
        .required { color: #dc3545; }

        .status-badge { padding: 7px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-ongoing { background: #fff3cd; color: #856404; }
        .status-not-started { background: #f8d7da; color: #721c24; }
        .progress-bar { height: 10px; background: var(--medium-gray); border-radius: 5px; overflow: hidden; margin-top: 5px; }
        .progress-fill { height: 100%; border-radius: 5px; background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); }
        
        .multiselect-container { border: 1px solid #ced4da; border-radius: 10px; background: #f9fafb; position: relative; }
        .selected-items { padding: 6px; min-height: 48px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; cursor: pointer; }
        .selected-tag { background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); color: white; padding: 6px 12px; border-radius: 16px; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .remove-tag { cursor: pointer; font-weight: bold; font-size: 16px; }
        .multiselect-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ced4da; border-top: none; border-radius: 0 0 10px 10px; max-height: 200px; overflow-y: auto; z-index: 1001; display: none; }
        .multiselect-dropdown.open { display: block; }
        .multiselect-option { padding: 12px; cursor: pointer; display: flex; align-items: center; gap: 12px; }
        .multiselect-option:hover { background: #f8f9fa; }
        .multiselect-option.selected { background: #e3f2fd; }
        .multiselect-option input[type="checkbox"] { width: 18px; height: 18px; }
        .word-counter { font-size: 13px; color: #6c757d; text-align: right; margin-top: 6px; }

        /* ADDED: Pagination styles */
        .pagination { display: flex; justify-content: center; align-items: center; margin-top: 20px; gap: 5px; }
        .page-link { padding: 8px 12px; border: 1px solid var(--medium-gray); border-radius: 8px; text-decoration: none; color: var(--text-color); transition: background-color 0.2s ease; }
        .page-link:hover { background-color: var(--light-gray); }
        .page-link.active { background: linear-gradient(45deg, var(--primary-start), var(--primary-end)); color: white; border-color: var(--primary-start); }
        .page-info { padding: 8px 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div id="alert-container" style="position:fixed; top:20px; left:50%; transform:translateX(-50%); z-index:2000; width:90%; max-width:500px;"></div>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-project-diagram"></i> IT Project Management System</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['empcode']); ?>! (<?php echo htmlspecialchars(ucfirst($user_role)); ?>)</span>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</button>
            <button class="nav-tab" data-tab="projects"><i class="fas fa-tasks"></i> Projects</button>
            <button class="nav-tab" data-tab="completed"><i class="fas fa-check-circle"></i> Completed</button>
            <a href="register.php" class="nav-tab-link <?php if ($user_role !== 'admin') echo 'hidden'; ?>"><i class="fas fa-users-cog"></i> User Management</a>
            <a href="dailyreport.php" class="nav-tab-link <?php if ($user_role !== 'admin') echo 'hidden'; ?>"><i class="fas fa-chart-bar"></i> Daily Report</a>
        </div>

        <div id="dashboard" class="tab-content active">
            <div class="dashboard-grid">
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-label">Total Projects</div><div class="stat-number" id="total-projects">0</div></div>
                    <div class="stat-card"><div class="stat-label">Completed</div><div class="stat-number" id="completed-projects">0</div></div>
                    <div class="stat-card"><div class="stat-label">Ongoing</div><div class="stat-number" id="ongoing-projects">0</div></div>
                    <div class="stat-card"><div class="stat-label">Not Started</div><div class="stat-number" id="not-started-projects">0</div></div>
                </div>
                <div class="dashboard-row" style="grid-column: 1 / -1;">
                    <div class="dashboard-card"><div class="card-title"><i class="fas fa-calendar-day"></i> Upcoming Target Dates</div><div id="upcoming-list"></div></div>
                    <div class="dashboard-card"><div class="card-title"><i class="fas fa-history"></i> Recently Completed</div><div id="recent-list"></div></div>
                </div>
                <div class="dashboard-card" style="grid-column: 1 / -1;">
                    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fas fa-layer-group"></i> Group Summary</span>
                        <button id="export-summary-btn" class="btn btn-primary btn-sm"><i class="fas fa-file-excel"></i> Export</button>
                    </div>
                    <div class="table-container"><table id="group-summary-table"><thead><tr><th>Group</th><th>Total</th><th>Completed</th><th>Ongoing</th><th>Not Started</th></tr></thead><tbody id="group-summary-tbody"></tbody></table></div>
                </div>
            </div>
        </div>

        <div id="projects" class="tab-content">
            <div class="search-filter">
                <input type="text" id="search-projects" placeholder="Search active projects...">
                <select id="filter-group"><option value="">All Groups</option><option value="Dev">Development</option><option value="Infra">Infrastructure</option><option value="SA">System Administrator</option><option value="Support">Support</option></select>
                <select id="sort-projects">
                    <option value="target_date_asc">Sort by Target Date (Asc)</option>
                    <option value="target_date_desc">Sort by Target Date (Desc)</option>
                    <option value="start_date_asc">Sort by Start Date (Asc)</option>
                    <option value="start_date_desc">Sort by Start Date (Desc)</option>
                </select>
                <?php if ($user_role === 'admin'): ?>
                <button id="add-project-btn" class="btn btn-primary" style="margin-left: auto;"><i class="fas fa-plus-circle"></i> Add Project</button>
                <?php endif; ?>
            </div>
            <div class="table-container">
                <table id="projects-table">
                    <thead><tr><th>Name</th><th>Group</th><th>Requestor</th><th>POC</th><th>Status</th><th>Progress</th><th>Start Date</th><th>Target Date</th><th>Actions</th></tr></thead>
                    <tbody id="projects-tbody"></tbody>
                </table>
            </div>
            <div id="projects-pagination" class="pagination"></div>
        </div>

        <div id="completed" class="tab-content">
            <div class="search-filter">
                <input type="text" id="search-completed" placeholder="Search completed projects...">
                <select id="filter-completed-group"><option value="">All Groups</option><option value="Dev">Development</option><option value="Infra">Infrastructure</option><option value="SA">System Administration</option><option value="Support">Support</option></select>
                <div class="form-group" style="flex-direction: row; align-items: center; gap: 10px;">
                    <label for="date-completion" style="margin: 0;">Completion Date:</label>
                    <input type="date" id="date-completion">
                </div>
            </div>
            <div class="table-container">
                <table id="completed-table">
                    <thead><tr><th>Name</th><th>Group</th><th>POC</th><th>Completion Date</th><th>Actions</th></tr></thead>
                    <tbody id="completed-tbody"></tbody>
                </table>
            </div>
            <div id="completed-pagination" class="pagination"></div>
        </div>
    </div>

    <div id="project-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title"></h2>
                <button class="close-btn" onclick="window.closeModal('project-modal')">&times;</button>
            </div>
            <div id="modal-body"></div>
        </div>
    </div>
    
    <div id="confirm-modal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
             <div class="modal-header"><h2 id="confirm-title">Are you sure?</h2></div>
             <div id="confirm-body" class="modal-body"></div>
             <div class="actions">
                <button id="confirm-cancel-btn" class="btn btn-secondary">Cancel</button>
                <button id="confirm-ok-btn" class="btn btn-danger">Delete</button>
             </div>
        </div>
    </div>

    <div id="export-modal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header"><h2><i class="fas fa-file-export"></i> Export Project Summary</h2><button class="close-btn" onclick="closeModal('export-modal')">&times;</button></div>
            <div class="modal-body">
                <form id="export-form" action="projectsummary.php" method="POST" target="_blank">
                    <input type="hidden" name="action" value="export_summary">
                    <p>Select groups to include. If none are selected, all projects will be exported.</p><br>
                    <div id="export-groups-container">
                         <div class="multiselect-option"><input type="checkbox" name="groups[]" value="Dev" id="group-dev"><label for="group-dev">Development</label></div>
                         <div class="multiselect-option"><input type="checkbox" name="groups[]" value="Infra" id="group-infra"><label for="group-infra">Infrastructure</label></div>
                         <div class="multiselect-option"><input type="checkbox" name="groups[]" value="SA" id="group-sa"><label for="group-sa">System Administrator</label></div>
                         <div class="multiselect-option"><input type="checkbox" name="groups[]" value="Support" id="group-support"><label for="group-support">Support</label></div>
                    </div>
                    <div class="actions"><button type="button" class="btn btn-secondary" onclick="closeModal('export-modal')">Cancel</button><button type="submit" class="btn btn-primary">Download</button></div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const userRole = '<?php echo $user_role; ?>';
        // MODIFIED: Global arrays to hold all data, client-side filtering
        let allProjects = [], completedProjects = [];
        let itMembers = [];
        let currentProjectId = null;
        
        // ADDED: Pagination state variables
        let projectsCurrentPage = 1;
        let completedCurrentPage = 1;

        async function init() {
            await fetchITMembers();
            // MODIFIED: Initial fetch only gets data for the first page
            fetchProjects();
            fetchCompletedProjects();
            setupEventListeners();
            // MODIFIED: Only render dashboard on page load
            fetchWithAction('get_dashboard_stats').then(res => {
                if (res.success) renderDashboard(res.data);
            });
        }

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
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return await response.json();
            } catch (error) {
                console.error('Fetch error:', error);
                showAlert('Failed to communicate with the server.', 'danger');
                return { success: false, message: 'Server communication error.' };
            }
        }

        async function fetchITMembers() {
            const result = await fetchWithAction('get_it_members');
            if (result.success) itMembers = result.data;
        }

        // MODIFIED: This function now fetches paginated data from the server.
        async function fetchProjects() {
            const sortOption = document.getElementById('sort-projects').value;
            const groupFilter = document.getElementById('filter-group').value;
            const searchQuery = document.getElementById('search-projects').value;
            const result = await fetchWithAction('get_projects', { 
                sort_by: sortOption, 
                page: projectsCurrentPage,
                group_filter: groupFilter,
                search_query: searchQuery
            });
            if (result.success) {
                renderProjectsTable(result.data);
                renderPagination('projects-pagination', result.pagination.total_pages, projectsCurrentPage, 'projects');
            } else {
                showAlert(result.message, 'danger');
            }
        }

        // UPDATED: Now fetches completed projects based on a single completion date filter.
        async function fetchCompletedProjects() {
            const groupFilter = document.getElementById('filter-completed-group').value;
            const searchQuery = document.getElementById('search-completed').value;
            const completionDateFilter = document.getElementById('date-completion').value;
            const result = await fetchWithAction('get_completed_projects', {
                page: completedCurrentPage,
                group_filter: groupFilter,
                search_query: searchQuery,
                completion_date_filter: completionDateFilter
            });
            if (result.success) {
                renderCompletedTable(result.data);
                renderPagination('completed-pagination', result.pagination.total_pages, completedCurrentPage, 'completed');
            } else {
                showAlert(result.message, 'danger');
            }
        }

        function renderDashboard(data) {
            document.getElementById('total-projects').textContent = data.stats.total || 0;
            document.getElementById('completed-projects').textContent = data.stats.completed || 0;
            document.getElementById('ongoing-projects').textContent = data.stats.ongoing || 0;
            document.getElementById('not-started-projects').textContent = data.stats.not_started || 0;
            const upcomingList = document.getElementById('upcoming-list');
            upcomingList.innerHTML = data.upcoming.length ? data.upcoming.map(p => `<div class="project-item"><div>${p.name}</div><div>${formatDate(p.target_date)}</div></div>`).join('') : '<p>No upcoming deadlines.</p>';
            const recentList = document.getElementById('recent-list');
            recentList.innerHTML = data.recent.length ? data.recent.map(p => `<div class="project-item"><div>${p.name}</div><div>${formatDate(p.completion_date)}</div></div>`).join('') : '<p>No recently completed projects.</p>';
            const groupSummaryBody = document.getElementById('group-summary-tbody');
            groupSummaryBody.innerHTML = data.group_summary.map(g => `<tr><td>${g.group_name}</td><td>${g.total_projects}</td><td>${g.completed}</td><td>${g.ongoing}</td><td>${g.not_started}</td></tr>`).join('');
        }
        
        // MODIFIED: Removed the "view" button
        function renderProjectsTable(data) {
            const tbody = document.getElementById('projects-tbody');
            tbody.innerHTML = data.length ? data.map(p => `
                <tr>
                    <td>${p.name}</td><td>${p.group_name}</td><td>${p.requestor}</td><td>${p.poc}</td>
                    <td><span class="status-badge status-${p.status.toLowerCase().replace(/ /g, '-')}">${p.status}</span></td>
                    <td><div>${p.progress}%</div><div class="progress-bar"><div class="progress-fill" style="width:${p.progress}%"></div></div></td>
                    <td>${formatDate(p.start_date)}</td><td>${formatDate(p.target_date)}</td>
                    <td>
                        ${userRole === 'admin' ? `<button class="btn btn-primary" onclick="editProject(${p.id})"><i class="fas fa-edit"></i></button>` : ''}
                    </td>
                </tr>`).join('') : '<tr><td colspan="9" style="text-align:center;">No active projects found.</td></tr>';
        }

        function renderCompletedTable(data) {
            const tbody = document.getElementById('completed-tbody');
            tbody.innerHTML = data.length ? data.map(p => `
                <tr>
                    <td>${p.name}</td><td>${p.group_name}</td><td>${p.poc}</td><td>${formatDate(p.completion_date)}</td>
                    <td><button class="btn btn-secondary" onclick="viewProject(${p.id})"><i class="fas fa-eye"></i></button></td>
                </tr>`).join('') : '<tr><td colspan="5" style="text-align:center;">No completed projects found.</td></tr>';
        }
        
        // REMOVED: Client-side filtering functions, as filtering is now done on the server.
        // function filterProjectsTable() { ... }
        // function filterCompletedTable() { ... }

        // ADDED: Function to render pagination controls
        function renderPagination(containerId, totalPages, currentPage, type) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            if (totalPages <= 1) return;

            // Previous button
            const prevBtn = document.createElement('a');
            prevBtn.href = '#';
            prevBtn.textContent = 'Previous';
            prevBtn.classList.add('page-link');
            if (currentPage === 1) prevBtn.style.visibility = 'hidden';
            prevBtn.onclick = (e) => { e.preventDefault(); navigatePage(currentPage - 1, type); };
            container.appendChild(prevBtn);

            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                const pageLink = document.createElement('a');
                pageLink.href = '#';
                pageLink.textContent = i;
                pageLink.classList.add('page-link');
                if (i === currentPage) pageLink.classList.add('active');
                pageLink.onclick = (e) => { e.preventDefault(); navigatePage(i, type); };
                container.appendChild(pageLink);
            }

            // Next button
            const nextBtn = document.createElement('a');
            nextBtn.href = '#';
            nextBtn.textContent = 'Next';
            nextBtn.classList.add('page-link');
            if (currentPage === totalPages) nextBtn.style.visibility = 'hidden';
            nextBtn.onclick = (e) => { e.preventDefault(); navigatePage(currentPage + 1, type); };
            container.appendChild(nextBtn);
        }

        // ADDED: Function to navigate pages
        function navigatePage(page, type) {
            if (type === 'projects') {
                projectsCurrentPage = page;
                fetchProjects();
            } else if (type === 'completed') {
                completedCurrentPage = page;
                fetchCompletedProjects();
            }
        }

        function setupEventListeners() {
            document.querySelector('.nav-tabs').addEventListener('click', e => {
                if (e.target.matches('.nav-tab')) {
                    document.querySelectorAll('.nav-tab, .tab-content').forEach(el => el.classList.remove('active'));
                    e.target.classList.add('active');
                    document.getElementById(e.target.dataset.tab).classList.add('active');
                }
            });

            document.getElementById('add-project-btn')?.addEventListener('click', openAddProjectModal);
            document.getElementById('export-summary-btn').addEventListener('click', () => openModal('export-modal'));
            
            // UPDATED: Event listeners now trigger a server fetch, resetting the page to 1
            document.getElementById('sort-projects').addEventListener('change', () => { projectsCurrentPage = 1; fetchProjects(); });
            document.getElementById('search-projects').addEventListener('input', () => { projectsCurrentPage = 1; fetchProjects(); });
            document.getElementById('filter-group').addEventListener('change', () => { projectsCurrentPage = 1; fetchProjects(); });
            document.getElementById('search-completed').addEventListener('input', () => { completedCurrentPage = 1; fetchCompletedProjects(); });
            document.getElementById('filter-completed-group').addEventListener('change', () => { completedCurrentPage = 1; fetchCompletedProjects(); });
            // UPDATED: Event listener for single completion date filter
            document.getElementById('date-completion').addEventListener('change', () => { completedCurrentPage = 1; fetchCompletedProjects(); });
            
            document.getElementById('confirm-cancel-btn').addEventListener('click', () => closeModal('confirm-modal'));
            document.getElementById('confirm-ok-btn').addEventListener('click', handleDeleteProject);

            document.body.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-tag')) {
                    const prefix = e.target.closest('form').id.includes('edit') ? 'edit' : 'add';
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
                if (!e.target.closest('.multiselect-container')) {
                    document.querySelectorAll('.multiselect-dropdown.open').forEach(d => d.classList.remove('open'));
                }
            });
        }
        
        function getProjectFormHTML(project = {}) {
            const isEdit = !!project.id;
            const prefix = isEdit ? 'edit' : 'add';
            const groupOptions = ['Dev', 'Infra', 'SA', 'Support'].map(g => `<option value="${g}" ${project.group_name === g ? 'selected' : ''}>${g}</option>`).join('');
            const statusOptions = ['NOT STARTED', 'ONGOING', 'COMPLETED'].map(s => `<option value="${s}" ${project.status === s ? 'selected' : ''}>${s}</option>`).join('');
            let progressOptions = '';
            for (let i=0; i<=100; i+=5) {
                // Ensure project.progress is treated as a number for comparison
                progressOptions += `<option value="${i}" ${Number(project.progress) === i ? 'selected' : ''}>${i}%</option>`;
            }

            return `
            <form id="${prefix}-project-form">
                <input type="hidden" name="id" value="${project.id || ''}">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="${prefix}-name">Name <span class="required">*</span></label>
                        <input type="text" id="${prefix}-name" name="name" value="${project.name || ''}" required maxlength="100">
                        <div class="word-counter" id="${prefix}-name-counter">0/100</div>
                    </div>
                    <div class="form-group"><label for="${prefix}-group_name">Group <span class="required">*</span></label><select id="${prefix}-group_name" name="group_name" required>${groupOptions}</select></div>
                    <div class="form-group">
                        <label for="${prefix}-requestor">Requestor <span class="required">*</span></label>
                        <input type="text" id="${prefix}-requestor" name="requestor" value="${project.requestor || ''}" required maxlength="100">
                        <div class="word-counter" id="${prefix}-requestor-counter">0/100</div>
                    </div>
                    <div class="form-group">
                        <label>POC <span class="required">*</span></label>
                        <div class="multiselect-container" id="${prefix}-poc-multiselect">
                            <div class="selected-items" id="${prefix}-selected-items"></div>
                            <div class="multiselect-dropdown" id="${prefix}-dropdown"></div>
                        </div>
                    </div>
                    <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="${project.start_date ? project.start_date.split(' ')[0] : ''}"></div>
                    <div class="form-group"><label>Target Date <span class="required">*</span></label><input type="date" name="target_date" value="${project.target_date ? project.target_date.split(' ')[0] : ''}" required></div>
                    <div class="form-group"><label>Status <span class="required">*</span></label><select name="status" required>${statusOptions}</select></div>
                    <div class="form-group"><label>Progress (%)</label><select name="progress">${progressOptions}</select></div>
                    <div class="form-group full-width">
                        <label for="${prefix}-purpose">Purpose <span class="required">*</span></label>
                        <textarea id="${prefix}-purpose" name="purpose" required maxlength="2000">${project.purpose || ''}</textarea>
                        <div class="word-counter" id="${prefix}-purpose-counter">0/2000</div>
                    </div>
                    <div class="form-group full-width">
                        <label for="${prefix}-risks">Risks</label>
                        <textarea id="${prefix}-risks" name="risks" maxlength="2000">${project.risks || ''}</textarea>
                        <div class="word-counter" id="${prefix}-risks-counter">0/2000</div>
                    </div>
                </div>
                <div class="actions">
                    ${isEdit ? `<button type="button" class="btn btn-danger" onclick="confirmDelete(${project.id}, '${escapeJS(project.name)}')"><i class="fas fa-trash"></i> Delete</button>` : ''}
                    <div style="margin-left: auto; display: flex; gap: 10px;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('project-modal')"><i class="fas fa-times"></i> Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> ${isEdit ? 'Save Changes' : 'Add Project'}</button>
                    </div>
                </div>
            </form>`;
        }
        
        function setupFormFunctionality(prefix, project = {}) {
            const form = document.getElementById(`${prefix}-project-form`);
            if (!form) return;
            form.addEventListener('submit', handleAddOrUpdateProject);

            initMultiSelect(prefix, project.poc ? project.poc.split(', ') : []);

            ['name', 'requestor', 'purpose', 'risks'].forEach(id => {
                const element = form.querySelector(`#${prefix}-${id}`);
                if (element) {
                    element.addEventListener('input', () => updateCharCounter(element));
                    updateCharCounter(element);
                }
            });
        }

        function openAddProjectModal() {
            document.getElementById('modal-title').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Project';
            document.getElementById('modal-body').innerHTML = getProjectFormHTML();
            setupFormFunctionality('add');
            openModal('project-modal');
        }

        window.editProject = async function(id) {
            const result = await fetchWithAction('get_project_details', { id });
            if (result.success) {
                document.getElementById('modal-title').innerHTML = `<i class="fas fa-edit"></i> Edit Project: ${result.data.name}`;
                document.getElementById('modal-body').innerHTML = getProjectFormHTML(result.data);
                setupFormFunctionality('edit', result.data);
                openModal('project-modal');
            }
        };
        
        window.viewProject = async function(id) {
            const result = await fetchWithAction('get_project_details', { id });
            if (result.success) {
                const p = result.data;
                document.getElementById('modal-title').innerHTML = `<i class="fas fa-eye"></i> View Project: ${p.name}`;
                document.getElementById('modal-body').innerHTML = `<dl class="form-grid">
                    <div><dt>Group</dt><dd>${p.group_name}</dd></div>
                    <div><dt>Status</dt><dd><span class="status-badge status-${p.status.toLowerCase().replace(/ /g, '-')}">${p.status}</span></dd></div>
                    <div><dt>Progress</dt><dd>${p.progress}%</dd></div>
                    <div><dt>Requestor</dt><dd>${p.requestor}</dd></div>
                    <div><dt>POC</dt><dd>${p.poc}</dd></div>
                    <div><dt>Start Date</dt><dd>${formatDate(p.start_date)}</dd></div>
                    <div><dt>Target Date</dt><dd>${formatDate(p.target_date)}</dd></div>
                    ${p.completion_date ? `<div><dt>Completion Date</dt><dd>${formatDate(p.completion_date)}</dd></div>` : ''}
                    <div class="full-width"><dt>Purpose</dt><dd style="white-space:pre-wrap;">${p.purpose || 'N/A'}</dd></div>
                    <div class="full-width"><dt>Risks</dt><dd style="white-space:pre-wrap;">${p.risks || 'None'}</dd></div>
                    <div class="full-width"><dt>Remarks</dt><dd style="white-space:pre-wrap;">${p.remarks || 'None'}</dd></div>
                </dl>`;
                openModal('project-modal');
            }
        };

        async function handleAddOrUpdateProject(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const action = formData.get('id') ? 'update_project' : 'add_project';
            
            // Fix: Manually construct the body object to ensure 'poc' is sent as an array.
            const prefix = action === 'add_project' ? 'add' : 'edit';
            const pocs = Array.from(document.querySelectorAll(`#${prefix}-selected-items .selected-tag span:first-child`)).map(span => span.textContent);
            
            const body = Object.fromEntries(formData);
            body.poc = pocs;

            const result = await fetchWithAction(action, body);
            if (result.success) {
                showAlert(result.message, 'success');
                closeModal('project-modal');
                // MODIFIED: Re-fetch only the relevant table data
                if (action === 'add_project') {
                    projectsCurrentPage = 1;
                    fetchProjects();
                } else {
                    fetchProjects();
                    fetchCompletedProjects();
                }
            } else {
                showAlert(result.message, 'danger');
            }
        }
        
        window.confirmDelete = function(id, name) {
            currentProjectId = id;
            document.getElementById('confirm-body').innerHTML = `<p>Are you sure you want to delete the project: <strong>${name}</strong>? This action cannot be undone.</p>`;
            openModal('confirm-modal');
        }

        async function handleDeleteProject() {
            if (!currentProjectId) return;
            const result = await fetchWithAction('delete_project', { id: currentProjectId });
            if (result.success) {
                showAlert(result.message, 'success');
                closeModal('project-modal');
                closeModal('confirm-modal');
                // MODIFIED: Re-fetch the relevant tables
                projectsCurrentPage = 1;
                completedCurrentPage = 1;
                fetchProjects();
                fetchCompletedProjects();
            } else {
                showAlert(result.message, 'danger');
            }
            currentProjectId = null;
        }

        function initMultiSelect(prefix, selected = []) {
            const container = document.getElementById(`${prefix}-poc-multiselect`);
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            if (!container || !dropdown) return;

            dropdown.innerHTML = itMembers.map(member => {
                const isSelected = selected.includes(member);
                return `<div class="multiselect-option ${isSelected ? 'selected' : ''}" data-value="${escapeJS(member)}">
                    <input type="checkbox" value="${escapeJS(member)}" ${isSelected ? 'checked' : ''}>
                    <label>${member}</label>
                </div>`;
            }).join('');
            
            updateSelectedTags(prefix);

            container.querySelector('.selected-items').addEventListener('click', () => dropdown.classList.toggle('open'));
            dropdown.addEventListener('change', () => updateSelectedTags(prefix));
        }

        function updateSelectedTags(prefix) {
            const selectedItems = document.getElementById(`${prefix}-selected-items`);
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            if (!selectedItems || !dropdown) return;
            const selectedCheckboxes = dropdown.querySelectorAll('input:checked');
            selectedItems.innerHTML = Array.from(selectedCheckboxes).map(cb =>
                `<div class="selected-tag"><span>${cb.value}</span><span class="remove-tag" data-value="${escapeJS(cb.value)}">&times;</span></div>`
            ).join('');
            if (selectedCheckboxes.length === 0) {
                selectedItems.innerHTML = `<span style="color:#6c757d; padding: 6px;">Click to select...</span>`;
            }
        }

        function updateCharCounter(element) {
            const counter = element.nextElementSibling;
            if (!counter || !counter.classList.contains('word-counter')) return;
            const len = element.value.length;
            const max = element.maxLength;
            counter.textContent = `${len}/${max}`;
        }

        function showAlert(message, type = 'success') {
            const container = document.getElementById('alert-container');
            const alertDiv = document.createElement('div');
            alertDiv.style.cssText = `padding: 15px; margin-bottom: 10px; border-radius: 10px; font-weight: 600; color: white; display: flex; align-items: center; gap: 12px;`;
            alertDiv.style.backgroundColor = type === 'success' ? 'var(--success-start)' : 'var(--danger-start)';
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}\"></i><span>${message}</span>`;
            container.appendChild(alertDiv);
            setTimeout(() => { alertDiv.remove(); }, 5000);
        }

        function formatDate(dateStr) {
            if (!dateStr || dateStr.startsWith('0000')) return 'N/A';
            const date = new Date(dateStr);
            return new Intl.DateTimeFormat('en-US').format(date);
        }
        function escapeJS(str) { return String(str || '').replace(/'/g, "\\'").replace(/"/g, '\\"'); }
        function openModal(id) { document.getElementById(id)?.classList.add('show'); }
        window.closeModal = function(id) { document.getElementById(id)?.classList.remove('show'); }

        init();
    });
    </script>
</body>
</html>