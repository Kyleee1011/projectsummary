<?php
// debug_users.php - Use this to check what's happening with your user data

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connections
$dailyReportServer = "10.2.0.9";
$dailyReportConnOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25", 
    "Database" => "daily_report_db"
];

// For project summary database (assuming same server)
require_once 'config/config.php';

echo "<h2>Database User Debug Information</h2>";

// 1. Check daily_report_db connection and users table
echo "<h3>1. Daily Report Database Check</h3>";
$dailyReportConn = sqlsrv_connect($dailyReportServer, $dailyReportConnOptions);

if ($dailyReportConn === false) {
    echo "<p style='color: red;'>❌ Failed to connect to daily_report_db: " . print_r(sqlsrv_errors(), true) . "</p>";
} else {
    echo "<p style='color: green;'>✅ Successfully connected to daily_report_db</p>";
    
    // Check if users table exists
    $checkTableQuery = "SELECT COUNT(*) as table_exists FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'users' AND TABLE_SCHEMA = 'dbo'";
    $result = sqlsrv_query($dailyReportConn, $checkTableQuery);
    
    if ($result) {
        $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
        if ($row['table_exists'] > 0) {
            echo "<p style='color: green;'>✅ Users table exists in daily_report_db</p>";
            
            // Get table structure
            $structureQuery = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION";
            $structResult = sqlsrv_query($dailyReportConn, $structureQuery);
            
            echo "<h4>Table Structure:</h4>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>Column Name</th><th>Data Type</th><th>Nullable</th></tr>";
            
            while ($col = sqlsrv_fetch_array($structResult, SQLSRV_FETCH_ASSOC)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($col['COLUMN_NAME']) . "</td>";
                echo "<td>" . htmlspecialchars($col['DATA_TYPE']) . "</td>";
                echo "<td>" . htmlspecialchars($col['IS_NULLABLE']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Count users
            $countQuery = "SELECT COUNT(*) as user_count FROM daily_report_db.dbo.users";
            $countResult = sqlsrv_query($dailyReportConn, $countQuery);
            $countRow = sqlsrv_fetch_array($countResult, SQLSRV_FETCH_ASSOC);
            echo "<p><strong>Total users in daily_report_db: " . $countRow['user_count'] . "</strong></p>";
            
            // Show all users
            if ($countRow['user_count'] > 0) {
                $usersQuery = "SELECT id, username, role, empcode, full_name, department, created_at FROM daily_report_db.dbo.users ORDER BY username";
                $usersResult = sqlsrv_query($dailyReportConn, $usersQuery);
                
                echo "<h4>Users in daily_report_db:</h4>";
                echo "<table border='1' cellpadding='5' cellspacing='0'>";
                echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Emp Code</th><th>Full Name</th><th>Department</th><th>Created At</th></tr>";
                
                while ($user = sqlsrv_fetch_array($usersResult, SQLSRV_FETCH_ASSOC)) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($user['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['role']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['empcode']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['full_name'] ?? 'NULL') . "</td>";
                    echo "<td>" . htmlspecialchars($user['department'] ?? 'NULL') . "</td>";
                    echo "<td>" . (is_object($user['created_at']) ? $user['created_at']->format('Y-m-d H:i:s') : htmlspecialchars($user['created_at'])) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Users table does NOT exist in daily_report_db</p>";
        }
    }
    sqlsrv_close($dailyReportConn);
}

// 2. Check project summary database 
echo "<h3>2. Project Summary Database Check</h3>";
try {
    $db = new Database();
    echo "<p style='color: green;'>✅ Successfully connected to it_project_db</p>";
    
    // Check if users table exists
    $db->query("SELECT COUNT(*) as table_exists FROM it_project_db.INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'users'");
    $db->execute();
    $tableCheck = $db->single();
    
    if ($tableCheck && $tableCheck['table_exists'] > 0) {
        echo "<p style='color: green;'>✅ Users table exists in it_project_db</p>";
        
        // Count users
        $db->query("SELECT COUNT(*) as user_count FROM it_project_db.dbo.users");
        $db->execute();
        $countResult = $db->single();
        echo "<p><strong>Total users in it_project_db: " . $countResult['user_count'] . "</strong></p>";
        
        // Show all users
        if ($countResult['user_count'] > 0) {
            $db->query("SELECT id, username, role, empcode, created_at FROM it_project_db.dbo.users ORDER BY username");
            $db->execute();
            $users = $db->resultSet();
            
            echo "<h4>Users in it_project_db:</h4>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Emp Code</th><th>Created At</th></tr>";
            
            foreach ($users as $user) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($user['id']) . "</td>";
                echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                echo "<td>" . htmlspecialchars($user['role']) . "</td>";
                echo "<td>" . htmlspecialchars($user['empcode']) . "</td>";
                echo "<td>" . (is_object($user['created_at']) ? $user['created_at']->format('Y-m-d H:i:s') : htmlspecialchars($user['created_at'])) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p style='color: red;'>❌ Users table does NOT exist in it_project_db</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error connecting to it_project_db: " . $e->getMessage() . "</p>";
}

// 3. Check what dailyreport.php is trying to fetch
echo "<h3>3. Check Team Members Query (from dailyreport.php)</h3>";

$dailyReportConn = sqlsrv_connect($dailyReportServer, $dailyReportConnOptions);
if ($dailyReportConn !== false) {
    // This is the exact query from your dailyreport.php
    $query = "SELECT empcode, username, department FROM users ORDER BY username";
    $result = sqlsrv_query($dailyReportConn, $query);
    
    if ($result === false) {
        echo "<p style='color: red;'>❌ Query failed: " . print_r(sqlsrv_errors(), true) . "</p>";
    } else {
        $teamMembers = [];
        $count = 0;
        while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
            $teamMembers[] = $row;
            $count++;
        }
        
        echo "<p><strong>Team members found by dailyreport.php query: " . $count . "</strong></p>";
        
        if ($count > 0) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>Emp Code</th><th>Username</th><th>Department</th></tr>";
            foreach ($teamMembers as $member) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($member['empcode']) . "</td>";
                echo "<td>" . htmlspecialchars($member['username']) . "</td>";
                echo "<td>" . htmlspecialchars($member['department'] ?? 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        sqlsrv_free_stmt($result);
    }
    sqlsrv_close($dailyReportConn);
}

echo "<h3>4. Recommendations</h3>";
echo "<div style='background-color: #f0f8ff; padding: 15px; border-left: 4px solid #0066cc;'>";
echo "<p><strong>If no users are showing in dailyreport.php, check:</strong></p>";
echo "<ol>";
echo "<li>Make sure the 'users' table exists in daily_report_db (check section 1 above)</li>";
echo "<li>Ensure users have been properly inserted into daily_report_db (not just it_project_db)</li>";
echo "<li>Check if the 'department' column exists and has values (it might be NULL)</li>";
echo "<li>Verify the database connection in dailyreport.php is working</li>";
echo "<li>Make sure the query in dailyreport.php matches the actual table structure</li>";
echo "</ol>";
echo "</div>";

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
h2 { color: #333; border-bottom: 2px solid #0066cc; }
h3 { color: #0066cc; margin-top: 30px; }
h4 { color: #666; }
</style>