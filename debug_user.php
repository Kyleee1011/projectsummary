<?php
session_start();

// Database connection details
$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];

echo "<h2>Session Debug Information</h2>";
echo "<pre>";
echo "Session Data:\n";
print_r($_SESSION);
echo "</pre>";

// Establish the connection to the SQL Server
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    echo "<p style='color:red'>Database connection failed!</p>";
    echo "<pre>";
    print_r(sqlsrv_errors());
    echo "</pre>";
    exit();
}

echo "<h2>Database Connection: SUCCESS</h2>";

// Check if users table exists
$checkTableQuery = "SELECT COUNT(*) as table_count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'users'";
$checkTableStmt = sqlsrv_query($conn, $checkTableQuery);
if ($checkTableStmt) {
    $tableRow = sqlsrv_fetch_array($checkTableStmt, SQLSRV_FETCH_ASSOC);
    if ($tableRow['table_count'] > 0) {
        echo "<h2>Users Table: EXISTS</h2>";
        
        // Show all users in the table
        $getAllUsersQuery = "SELECT id, username, full_name, role, empcode FROM users";
        $getAllUsersStmt = sqlsrv_query($conn, $getAllUsersQuery);
        
        echo "<h3>All Users in Database:</h3>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Empcode</th></tr>";
        
        $userFound = false;
        while ($userRow = sqlsrv_fetch_array($getAllUsersStmt, SQLSRV_FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . $userRow['id'] . "</td>";
            echo "<td>" . $userRow['username'] . "</td>";
            echo "<td>" . $userRow['full_name'] . "</td>";
            echo "<td>" . $userRow['role'] . "</td>";
            echo "<td>" . $userRow['empcode'] . "</td>";
            echo "</tr>";
            
            // Check if current session user matches
            if (isset($_SESSION['username']) && $userRow['username'] == $_SESSION['username']) {
                $userFound = true;
            }
        }
        echo "</table>";
        
        if (isset($_SESSION['username'])) {
            if ($userFound) {
                echo "<p style='color:green'>✓ Session user '" . $_SESSION['username'] . "' found in database</p>";
            } else {
                echo "<p style='color:red'>✗ Session user '" . $_SESSION['username'] . "' NOT found in database</p>";
            }
            
            // Try the exact query from submit_report.php
            $getUserQuery = "SELECT id FROM users WHERE username = ?";
            $getUserStmt = sqlsrv_prepare($conn, $getUserQuery, [$_SESSION['username']]);
            
            if ($getUserStmt && sqlsrv_execute($getUserStmt)) {
                $userRow = sqlsrv_fetch_array($getUserStmt, SQLSRV_FETCH_ASSOC);
                if ($userRow) {
                    echo "<p style='color:green'>✓ User lookup successful. User ID: " . $userRow['id'] . "</p>";
                } else {
                    echo "<p style='color:red'>✗ User lookup failed - no rows returned</p>";
                }
            } else {
                echo "<p style='color:red'>✗ User lookup query failed</p>";
                echo "<pre>";
                print_r(sqlsrv_errors());
                echo "</pre>";
            }
        } else {
            echo "<p style='color:red'>✗ No username in session</p>";
        }
        
    } else {
        echo "<h2 style='color:red'>Users Table: DOES NOT EXIST</h2>";
    }
}

sqlsrv_close($conn);
?>

<style>
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
</style>