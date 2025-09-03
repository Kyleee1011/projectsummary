<?php
// sync_users_fix.php - Sync users from it_project_db to daily_report_db

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connections
$dailyReportServer = "10.2.0.9";
$dailyReportConnOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];

require_once 'config/config.php';

echo "<h2>Syncing Users from Project DB to Daily Report DB</h2>";

try {
    // Connect to both databases
    $db = new Database(); // it_project_db
    $dailyReportConn = sqlsrv_connect($dailyReportServer, $dailyReportConnOptions);
    
    if ($dailyReportConn === false) {
        throw new Exception("Failed to connect to daily_report_db: " . print_r(sqlsrv_errors(), true));
    }
    
    echo "<p style='color: green;'>✅ Connected to both databases</p>";
    
    // Get all users from it_project_db
    echo "<h3>Step 1: Fetching users from it_project_db</h3>";
    
    $db->query("SELECT username, password, role, empcode FROM it_project_db.dbo.users ORDER BY username");
    $db->execute();
    $projectUsers = $db->resultSet();
    
    echo "<p>Found <strong>" . count($projectUsers) . "</strong> users in it_project_db</p>";
    
    if (count($projectUsers) == 0) {
        echo "<p style='color: red;'>❌ No users found in it_project_db to sync!</p>";
        exit;
    }
    
    // Show users that will be synced
    echo "<table border='1' cellpadding='5' cellspacing='0' style='margin: 10px 0;'>";
    echo "<tr><th>Username</th><th>Role</th><th>Emp Code</th><th>Will Sync</th></tr>";
    
    foreach ($projectUsers as $user) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['username']) . "</td>";
        echo "<td>" . htmlspecialchars($user['role']) . "</td>";
        echo "<td>" . htmlspecialchars($user['empcode']) . "</td>";
        echo "<td style='color: green;'>✅ Yes</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Sync users to daily_report_db
    echo "<h3>Step 2: Syncing users to daily_report_db</h3>";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($projectUsers as $user) {
        // For daily_report_db, we need: username, full_name, role, empcode, password, email, department
        // Map the data we have
        $username = $user['username'];
        $fullName = ucfirst($user['username']); // Use username as full name (capitalize first letter)
        $role = $user['role'];
        $empcode = $user['empcode'];
        $password = $user['password']; // Already hashed from it_project_db
        $email = $username . '@company.com'; // Generate a default email
        $department = 'IT'; // Default department
        $isActive = 1; // Active by default
        
        // Insert into daily_report_db
        $insertQuery = "
            INSERT INTO daily_report_db.dbo.users 
            (username, full_name, role, empcode, password, email, department, is_active, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ";
        
        $insertParams = [
            $username,
            $fullName,
            $role,
            $empcode,
            $password,
            $email,
            $department,
            $isActive
        ];
        
        $insertStmt = sqlsrv_query($dailyReportConn, $insertQuery, $insertParams);
        
        if ($insertStmt === false) {
            $errors = sqlsrv_errors();
            echo "<p style='color: red;'>❌ Failed to sync user <strong>" . htmlspecialchars($username) . "</strong>: " . $errors[0]['message'] . "</p>";
            $errorCount++;
        } else {
            echo "<p style='color: green;'>✅ Successfully synced user: <strong>" . htmlspecialchars($username) . "</strong></p>";
            $successCount++;
            sqlsrv_free_stmt($insertStmt);
        }
    }
    
    echo "<div style='background-color: #e8f5e8; padding: 15px; border-left: 4px solid #4caf50; margin: 20px 0;'>";
    echo "<h3 style='color: #2e7d32; margin-top: 0;'>Sync Summary</h3>";
    echo "<p style='color: #2e7d32;'>";
    echo "✅ <strong>{$successCount}</strong> users synced successfully<br>";
    echo "❌ <strong>{$errorCount}</strong> users failed to sync";
    echo "</p>";
    echo "</div>";
    
    // Verify the sync worked
    echo "<h3>Step 3: Verification</h3>";
    
    $verifyQuery = "SELECT COUNT(*) as total_users FROM daily_report_db.dbo.users";
    $verifyResult = sqlsrv_query($dailyReportConn, $verifyQuery);
    $verifyRow = sqlsrv_fetch_array($verifyResult, SQLSRV_FETCH_ASSOC);
    
    echo "<p>Total users now in daily_report_db: <strong>" . $verifyRow['total_users'] . "</strong></p>";
    
    // Test the exact query that dailyreport.php uses
    $testQuery = "SELECT empcode, username, department FROM daily_report_db.dbo.users ORDER BY username";
    $testResult = sqlsrv_query($dailyReportConn, $testQuery);
    
    if ($testResult === false) {
        echo "<p style='color: red;'>❌ Test query failed: " . print_r(sqlsrv_errors(), true) . "</p>";
    } else {
        $teamMembers = [];
        while ($row = sqlsrv_fetch_array($testResult, SQLSRV_FETCH_ASSOC)) {
            $teamMembers[] = $row;
        }
        
        echo "<p>Team members found by dailyreport.php query: <strong>" . count($teamMembers) . "</strong></p>";
        
        if (count($teamMembers) > 0) {
            echo "<h4>Users that will appear in dailyreport.php:</h4>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>Emp Code</th><th>Username</th><th>Department</th></tr>";
            foreach ($teamMembers as $member) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($member['empcode']) . "</td>";
                echo "<td>" . htmlspecialchars($member['username']) . "</td>";
                echo "<td>" . htmlspecialchars($member['department']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        sqlsrv_free_stmt($testResult);
    }
    
    sqlsrv_free_stmt($verifyResult);
    sqlsrv_close($dailyReportConn);
    
    if ($successCount > 0) {
        echo "<div style='background-color: #d4edda; padding: 20px; border-left: 4px solid #28a745; margin-top: 30px;'>";
        echo "<h2 style='color: #155724; margin-top: 0;'>🎉 Success!</h2>";
        echo "<p style='color: #155724; font-size: 16px;'>";
        echo "Users have been successfully synced! Now go to <strong>dailyreport.php</strong> and check the export section.<br>";
        echo "You should now see all {$successCount} users in the team member selection dropdown.";
        echo "</p>";
        echo "<p style='color: #155724;'><strong>Next Steps:</strong></p>";
        echo "<ol style='color: #155724;'>";
        echo "<li>Go to dailyreport.php</li>";
        echo "<li>Click on the 'Export' tab</li>";
        echo "<li>Look at the 'Select Team Members' dropdown - it should now show all users</li>";
        echo "<li>If you still don't see them, refresh the page or clear your browser cache</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        echo "<div style='background-color: #f8d7da; padding: 20px; border-left: 4px solid #dc3545; margin-top: 30px;'>";
        echo "<h2 style='color: #721c24; margin-top: 0;'>⚠️ Sync Issues</h2>";
        echo "<p style='color: #721c24;'>There were errors syncing the users. Please check the error messages above and try again.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    if (isset($dailyReportConn)) {
        sqlsrv_close($dailyReportConn);
    }
}
?>

<style>
body { 
    font-family: Arial, sans-serif; 
    margin: 20px; 
    background-color: #f5f5f5;
}
.container {
    background: white;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    max-width: 1000px;
    margin: 0 auto;
}
table { 
    border-collapse: collapse; 
    margin: 10px 0; 
    width: 100%;
}
th, td { 
    padding: 10px; 
    text-align: left; 
    border: 1px solid #ddd;
}
th { 
    background-color: #f8f9fa; 
    font-weight: bold;
}
tr:nth-child(even) {
    background-color: #f9f9f9;
}
h2 { 
    color: #333; 
    border-bottom: 3px solid #007bff; 
    padding-bottom: 10px;
}
h3 { 
    color: #007bff; 
    margin-top: 30px; 
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 5px;
}
h4 { 
    color: #495057; 
}
</style>