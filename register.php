<?php
session_start();
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // Redirect non-admins or logged-out users to the login page.
    header('Location: login.php');
    exit(); // Stop further script execution.
}

require_once 'config/config.php';

// Database connection for daily_report_db
$dailyReportServer = "10.2.0.9";
$dailyReportConnOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];

$message = '';

// Function to create the users table in daily_report_db if it doesn't exist
function createDailyReportUsersTable($conn) {
    $createTableSQL = "
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'users')
    CREATE TABLE daily_report_db.dbo.users (
        id INT IDENTITY(1,1) PRIMARY KEY,
        username NVARCHAR(50) NOT NULL UNIQUE,
        password NVARCHAR(255) NOT NULL,
        role NVARCHAR(20) NOT NULL DEFAULT 'viewer',
        empcode NVARCHAR(50) NOT NULL,
        full_name NVARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT GETDATE(),
        updated_at DATETIME DEFAULT GETDATE()
    )";
    
    $stmt = sqlsrv_query($conn, $createTableSQL);
    if ($stmt === false) {
        throw new Exception("Failed to create users table in daily_report_db: " . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);
}

// Function to create the users table in it_project_db if it doesn't exist
function createProjectUsersTable($db) {
    $createTableSQL = "
    IF NOT EXISTS (SELECT * FROM it_project_db.INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'users')
    CREATE TABLE it_project_db.dbo.users (
        id INT IDENTITY(1,1) PRIMARY KEY,
        username NVARCHAR(50) NOT NULL UNIQUE,
        password NVARCHAR(255) NOT NULL,
        role NVARCHAR(20) NOT NULL DEFAULT 'viewer',
        empcode NVARCHAR(50) NOT NULL,
        created_at DATETIME DEFAULT GETDATE(),
        updated_at DATETIME DEFAULT GETDATE()
    )";
    
    $db->query($createTableSQL);
    return $db->execute();
}

// Function to get existing user details from both databases
function getExistingUser($db, $dailyReportConn, $username) {
    // Check it_project_db
    $db->query("SELECT id, username, role, empcode FROM it_project_db.dbo.users WHERE username = :username");
    $db->bind(':username', $username);
    $db->execute();
    $projectUser = $db->single();

    // Check daily_report_db
    $query = "SELECT id, username, role, empcode, full_name FROM daily_report_db.dbo.users WHERE username = ?";
    $stmt = sqlsrv_query($dailyReportConn, $query, [$username]);
    if ($stmt === false) {
        throw new Exception("Error checking user in daily_report_db: " . print_r(sqlsrv_errors(), true));
    }
    $dailyReportUser = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return ($projectUser || $dailyReportUser) ? ['project' => $projectUser, 'daily_report' => $dailyReportUser] : null;
}

// Function to update existing user in both databases
function updateUser($db, $dailyReportConn, $username, $password, $role, $empcode, $full_name) {
    $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

    // Update it_project_db
    if ($hashedPassword) {
        $db->query("UPDATE it_project_db.dbo.users SET password = :password, role = :role, empcode = :empcode, updated_at = GETDATE() WHERE username = :username");
        $db->bind(':password', $hashedPassword);
    } else {
        $db->query("UPDATE it_project_db.dbo.users SET role = :role, empcode = :empcode, updated_at = GETDATE() WHERE username = :username");
    }
    $db->bind(':username', $username);
    $db->bind(':role', $role);
    $db->bind(':empcode', $empcode);
    $projectSuccess = $db->execute();

    // Update daily_report_db
    $query = $hashedPassword
        ? "UPDATE daily_report_db.dbo.users SET password = ?, role = ?, empcode = ?, full_name = ?, updated_at = GETDATE() WHERE username = ?"
        : "UPDATE daily_report_db.dbo.users SET role = ?, empcode = ?, full_name = ?, updated_at = GETDATE() WHERE username = ?";
    $params = $hashedPassword ? [$hashedPassword, $role, $empcode, $full_name, $username] : [$role, $empcode, $full_name, $username];
    $stmt = sqlsrv_query($dailyReportConn, $query, $params);
    if ($stmt === false) {
        throw new Exception("Failed to update user in daily_report_db: " . print_r(sqlsrv_errors(), true));
    }
    $dailyReportSuccess = sqlsrv_rows_affected($stmt) > 0;
    sqlsrv_free_stmt($stmt);

    return $projectSuccess && $dailyReportSuccess;
}

// Function to delete user from both databases
function deleteUser($db, $dailyReportConn, $username) {
    // Delete from it_project_db
    $db->query("DELETE FROM it_project_db.dbo.users WHERE username = :username");
    $db->bind(':username', $username);
    $projectSuccess = $db->execute();

    // Delete from daily_report_db
    $query = "DELETE FROM daily_report_db.dbo.users WHERE username = ?";
    $stmt = sqlsrv_query($dailyReportConn, $query, [$username]);
    if ($stmt === false) {
        throw new Exception("Failed to delete user from daily_report_db: " . print_r(sqlsrv_errors(), true));
    }
    $dailyReportSuccess = sqlsrv_rows_affected($stmt) > 0;
    sqlsrv_free_stmt($stmt);

    return $projectSuccess || $dailyReportSuccess;
}

// Get all existing users for the dropdown (from both databases, union to avoid duplicates)
function getAllUsers($db, $dailyReportConn) {
    try {
        // Get users from it_project_db
        $db->query("SELECT username, role, empcode FROM it_project_db.dbo.users ORDER BY username");
        $db->execute();
        $projectUsers = $db->resultSet();

        // Get users from daily_report_db
        $query = "SELECT username, role, empcode, full_name FROM daily_report_db.dbo.users ORDER BY username";
        $stmt = sqlsrv_query($dailyReportConn, $query);
        if ($stmt === false) {
            throw new Exception("Error fetching users from daily_report_db: " . print_r(sqlsrv_errors(), true));
        }
        $dailyReportUsers = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $dailyReportUsers[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        // Combine users, removing duplicates by username
        $allUsers = [];
        $usernames = [];
        foreach ($projectUsers as $user) {
            $user['full_name'] = ''; // it_project_db doesn't have full_name
            $allUsers[] = $user;
            $usernames[] = $user['username'];
        }
        foreach ($dailyReportUsers as $user) {
            if (!in_array($user['username'], $usernames)) {
                $allUsers[] = $user;
                $usernames[] = $user['username'];
            } else { // If user exists in both, make sure the full_name from daily_report is used
                foreach ($allUsers as $key => $existing) {
                    if ($existing['username'] === $user['username']) {
                        $allUsers[$key]['full_name'] = $user['full_name'];
                        break;
                    }
                }
            }
        }
        usort($allUsers, function($a, $b) { return strcmp($a['username'], $b['username']); });
        return $allUsers;
    } catch (Exception $e) {
        return [];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'register';
    $username = trim($_POST['username']);
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : 'viewer';
    $empcode = isset($_POST['empcode']) ? trim($_POST['empcode']) : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';

    if (empty($username) || ($action !== 'delete' && (empty($role) || empty($empcode) || empty($full_name)))) {
        $message = "Username" . ($action !== 'delete' ? ", role, employee code, and full name" : "") . " are required.";
    } else {
        try {
            // Initialize connections
            $db = new Database(); // For it_project_db
            $dailyReportConn = sqlsrv_connect($dailyReportServer, $dailyReportConnOptions);
            if ($dailyReportConn === false) {
                throw new Exception("Failed to connect to daily_report_db: " . print_r(sqlsrv_errors(), true));
            }

            // Create tables if they don't exist
            createProjectUsersTable($db);
            createDailyReportUsersTable($dailyReportConn);

            if ($action === 'register') {
                if (empty($password)) {
                    $message = "Password is required for new users.";
                } else {
                    // Check if username already exists in either database
                    $existingUser = getExistingUser($db, $dailyReportConn, $username);
                    
                    if ($existingUser) {
                        $message = "Username already exists in " . ($existingUser['project'] ? 'Project Summary' : '') . 
                                   ($existingUser['project'] && $existingUser['daily_report'] ? ' and ' : '') . 
                                   ($existingUser['daily_report'] ? 'Daily Report' : '') . 
                                   " system. Use 'Update User' option to modify existing users.";
                    } else {
                        // Hash the password
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        // Insert into it_project_db
                        $db->query("INSERT INTO it_project_db.dbo.users (username, password, role, empcode) VALUES (:username, :password, :role, :empcode)");
                        $db->bind(':username', $username);
                        $db->bind(':password', $hashedPassword);
                        $db->bind(':role', $role);
                        $db->bind(':empcode', $empcode);
                        $projectSuccess = $db->execute();

                        // Insert into daily_report_db
                        $query = "INSERT INTO daily_report_db.dbo.users (username, password, role, empcode, full_name) VALUES (?, ?, ?, ?, ?)";
                        $params = [$username, $hashedPassword, $role, $empcode, $full_name];
                        $stmt = sqlsrv_query($dailyReportConn, $query, $params);
                        if ($stmt === false) {
                            throw new Exception("Failed to register user in daily_report_db: " . print_r(sqlsrv_errors(), true));
                        }
                        $dailyReportSuccess = sqlsrv_rows_affected($stmt) > 0;
                        sqlsrv_free_stmt($stmt);

                        if ($projectSuccess && $dailyReportSuccess) {
                            $message = "User '{$username}' registered successfully as '{$role}' in both Project Summary and Daily Report systems.";
                        } else {
                            $message = "Failed to register user in " . ($projectSuccess ? 'Daily Report' : 'Project Summary') . " system.";
                        }
                    }
                }
            } elseif ($action === 'update') {
                // Check if user exists in either database
                $existingUser = getExistingUser($db, $dailyReportConn, $username);
                
                if (!$existingUser) {
                    $message = "User does not exist in either system. Use 'Register User' option to create new users.";
                } else {
                    if (updateUser($db, $dailyReportConn, $username, $password, $role, $empcode, $full_name)) {
                        $message = "User '{$username}' updated successfully. Role: '{$role}'" . (!empty($password) ? " (password changed)" : "");
                    } else {
                        $message = "Failed to update user in one or both systems.";
                    }
                }
            } elseif ($action === 'delete') {
                // Check if user exists in either database
                $existingUser = getExistingUser($db, $dailyReportConn, $username);
                
                if (!$existingUser) {
                    $message = "User does not exist in either system.";
                } else {
                    if (deleteUser($db, $dailyReportConn, $username)) {
                        $message = "User '{$username}' deleted successfully from both systems.";
                    } else {
                        $message = "Failed to delete user from one or both systems.";
                    }
                }
            }

            sqlsrv_close($dailyReportConn);
        } catch (Exception $e) {
            $message = "Database error: " . $e->getMessage();
            if (isset($dailyReportConn)) {
                sqlsrv_close($dailyReportConn);
            }
        }
    }
}

// Get existing users for dropdown
try {
    $db = new Database();
    $dailyReportConn = sqlsrv_connect($dailyReportServer, $dailyReportConnOptions);
    if ($dailyReportConn === false) {
        throw new Exception("Failed to connect to daily_report_db: " . print_r(sqlsrv_errors(), true));
    }
    createProjectUsersTable($db);
    createDailyReportUsersTable($dailyReportConn);
    $existingUsers = getAllUsers($db, $dailyReportConn);
    sqlsrv_close($dailyReportConn);
} catch (Exception $e) {
    $existingUsers = [];
    $message = "Error fetching users: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <style>
        :root {
            --primary-start: #667eea;
            --primary-end: #764ba2;
            --secondary-start: #56ab2f;
            --secondary-end: #a8e6cf;
            --danger-start: #ff4757;
            --danger-end: #ff6b7a;
            --light-grey: #f4f7f6;
            --medium-grey: #ddd;
            --dark-grey: #555;
            --text-color: #333;
            --white: #fff;
            --shadow: 0 8px 24px rgba(0,0,0,0.1);
            --radius: 12px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--light-grey);
            margin: 0;
            padding: 40px 20px;
            color: var(--text-color);
        }

        .main-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            max-width: 1300px;
            margin: 0 auto;
        }

        .panel {
            background: var(--white);
            padding: 40px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .user-list-panel {
            max-height: calc(100vh - 80px);
            overflow-y: auto;
        }
        
        .user-list-panel::-webkit-scrollbar { width: 6px; }
        .user-list-panel::-webkit-scrollbar-track { background: #f1f1f1; }
        .user-list-panel::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
        .user-list-panel::-webkit-scrollbar-thumb:hover { background: #aaa; }

        h2, h3 {
            text-align: center;
            color: var(--text-color);
            margin-top: 0;
            margin-bottom: 30px;
        }

        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark-grey); }
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--medium-grey);
            border-radius: 8px;
            font-size: 1rem;
        }
        input:focus, select:focus {
            border-color: var(--primary-end);
            outline: none;
            box-shadow: 0 0 0 3px rgba(118, 75, 162, 0.1);
        }

        .btn {
            width: 100%;
            padding: 15px;
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            background: linear-gradient(45deg, var(--primary-start), var(--primary-end));
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn.update { background: linear-gradient(45deg, var(--secondary-start), var(--secondary-end)); }
        .btn.update:hover { box-shadow: 0 10px 20px rgba(86, 171, 47, 0.3); }
        .btn.delete { background: linear-gradient(45deg, var(--danger-start), var(--danger-end)); }
        .btn.delete:hover { box-shadow: 0 10px 20px rgba(255, 71, 87, 0.3); }
        
        .message { text-align: center; margin-bottom: 20px; padding: 12px; border-radius: 8px; font-weight: 500; }
        .message.success { background-color: #d4edda; color: #155724; }
        .message.error { background-color: #f8d7da; color: #721c24; }
        
        .page-links { text-align: center; margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; }
        .page-links a { color: var(--primary-start); text-decoration: none; font-weight: 600; margin: 0 10px; }
        
        .action-tabs { display: flex; margin-bottom: 25px; border-bottom: 2px solid #eee; }
        .tab { flex: 1; padding: 12px; text-align: center; cursor: pointer; border: none; background: none; font-weight: 600; color: #666; transition: color 0.2s, border-color 0.2s; }
        .tab.active { color: var(--primary-start); border-bottom: 2px solid var(--primary-start); }
        
        .form-section { display: none; }
        .form-section.active { display: block; }
        
        .user-item { padding: 12px; margin-bottom: 10px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid var(--primary-start); font-size: 0.95em; }
        .user-item strong { color: var(--primary-end); }
        
        .password-note { font-size: 0.9em; color: #666; margin-top: 5px; }
        
        .delete-warning { background: #fff3cd; border-left: 5px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .delete-warning h4 { margin-top: 0; color: #dc3545; }
        
        .confirmation-checkbox { margin: 15px 0; }
        .confirmation-checkbox input { width: auto; margin-right: 10px; }
        .confirmation-checkbox label { display: inline; font-weight: normal; }
        
        @media (max-width: 1024px) {
            body { padding: 20px 10px; }
            .main-container { grid-template-columns: 1fr; }
            .panel { padding: 25px; }
            .user-list-panel { max-height: 450px; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="management-panel panel">
            <h2>User Management</h2>
            
            <?php if (!empty($message)): ?>
                <p class="message <?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>
            
            <div class="action-tabs">
                <button class="tab active" onclick="showTab('register')">Register User</button>
                <button class="tab" onclick="showTab('update')">Update User</button>
                <button class="tab" onclick="showTab('delete')">Delete User</button>
            </div>
            
            <div id="register-section" class="form-section active">
                <form action="register.php" method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="empcode">Employee Code</label>
                        <input type="text" id="empcode" name="empcode" required>
                    </div>
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="viewer">Viewer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Register User</button>
                </form>
            </div>
            
            <div id="update-section" class="form-section">
                <form action="register.php" method="POST">
                    <input type="hidden" name="action" value="update">
                    <div class="form-group">
                        <label for="update-username">Select User to Update</label>
                        <select id="update-username" name="username" required onchange="fillUserDetails(this)">
                            <option value="">Select a user...</option>
                            <?php foreach ($existingUsers as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['username']); ?>" 
                                        data-role="<?php echo htmlspecialchars($user['role']); ?>"
                                        data-empcode="<?php echo htmlspecialchars($user['empcode']); ?>"
                                        data-full_name="<?php echo htmlspecialchars($user['full_name']); ?>">
                                    <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="update-password">New Password</label>
                        <input type="password" id="update-password" name="password">
                        <div class="password-note">Leave blank to keep current password</div>
                    </div>
                    <div class="form-group">
                        <label for="update-empcode">Employee Code</label>
                        <input type="text" id="update-empcode" name="empcode" required>
                    </div>
                    <div class="form-group">
                        <label for="update-full_name">Full Name</label>
                        <input type="text" id="update-full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="update-role">Role</label>
                        <select id="update-role" name="role" required>
                            <option value="viewer">Viewer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn update">Update User</button>
                </form>
            </div>
            
            <div id="delete-section" class="form-section">
                <div class="delete-warning">
                    <h4>⚠️ Warning</h4>
                    <p>Deleting a user is permanent and cannot be undone. The user will lose access to both Project Summary and Daily Report systems immediately.</p>
                </div>
                <form action="register.php" method="POST" onsubmit="return confirmDelete()">
                    <input type="hidden" name="action" value="delete">
                    <div class="form-group">
                        <label for="delete-username">Select User to Delete</label>
                        <select id="delete-username" name="username" required onchange="showDeleteUserDetails(this)">
                            <option value="">Select a user...</option>
                            <?php foreach ($existingUsers as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['username']); ?>" 
                                        data-role="<?php echo htmlspecialchars($user['role']); ?>"
                                        data-empcode="<?php echo htmlspecialchars($user['empcode']); ?>"
                                        data-full_name="<?php echo htmlspecialchars($user['full_name']); ?>">
                                    <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="delete-user-details" style="display: none; padding: 15px; background: #f8f9fa; border-radius: 8px; margin: 15px 0;">
                        <h4>User Details:</h4>
                        <p><strong>Username:</strong> <span id="delete-user-username"></span></p>
                        <p><strong>Role:</strong> <span id="delete-user-role"></span></p>
                        <p><strong>Employee Code:</strong> <span id="delete-user-empcode"></span></p>
                        <p><strong>Full Name:</strong> <span id="delete-user-full_name"></span></p>
                    </div>
                    <div class="confirmation-checkbox">
                        <input type="checkbox" id="delete-confirm" required>
                        <label for="delete-confirm">I understand this action is permanent and want to delete this user.</label>
                    </div>
                    <button type="submit" class="btn delete">Delete User</button>
                </form>
            </div>
            
            <div class="page-links">
                <a href="projectsummary.php">Back to Project Summary</a> | 
                <a href="dailyreport.php">Go to Daily Report</a>
            </div>
        </div>
        
        <?php if (!empty($existingUsers)): ?>
        <div class="user-list-panel panel">
            <h3>Existing Users</h3>
            <div class="user-list-content">
                <?php foreach ($existingUsers as $user): ?>
                    <div class="user-item">
                        <strong><?php echo htmlspecialchars($user['username']); ?></strong><br>
                        Role: <?php echo htmlspecialchars($user['role']); ?><br>
                        Emp Code: <?php echo htmlspecialchars($user['empcode']); ?><br>
                        Full Name: <?php echo htmlspecialchars($user['full_name'] ?: 'N/A'); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function showTab(tabName) {
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName + '-section').classList.add('active');
            event.currentTarget.classList.add('active');
        }
        
        function fillUserDetails(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                document.getElementById('update-role').value = selectedOption.dataset.role;
                document.getElementById('update-empcode').value = selectedOption.dataset.empcode;
                document.getElementById('update-full_name').value = selectedOption.dataset.full_name;
            } else {
                document.getElementById('update-role').value = 'viewer';
                document.getElementById('update-empcode').value = '';
                document.getElementById('update-full_name').value = '';
            }
        }
        
        function showDeleteUserDetails(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const detailsDiv = document.getElementById('delete-user-details');
            
            if (selectedOption && selectedOption.value) {
                document.getElementById('delete-user-username').textContent = selectedOption.value;
                document.getElementById('delete-user-role').textContent = selectedOption.dataset.role;
                document.getElementById('delete-user-empcode').textContent = selectedOption.dataset.empcode;
                document.getElementById('delete-user-full_name').textContent = selectedOption.dataset.full_name || 'N/A';
                detailsDiv.style.display = 'block';
            } else {
                detailsDiv.style.display = 'none';
            }
        }
        
        function confirmDelete() {
            const username = document.getElementById('delete-username').value;
            const confirmCheckbox = document.getElementById('delete-confirm');
            
            if (!username) {
                alert('Please select a user to delete.');
                return false;
            }

            if (!confirmCheckbox.checked) {
                alert('Please check the confirmation box to proceed with deletion.');
                return false;
            }
            
            return confirm(`Are you absolutely sure you want to delete user "${username}"?\n\nThis action cannot be undone!`);
        }
    </script>
</body>
</html>