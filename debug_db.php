<?php
// Create this as a separate file (debug_db.php) to test your database connection

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test basic SQL Server connection first
echo "<h2>Testing SQL Server Connection</h2>";

$serverName = "172.16.2.8";
$connectionOptions = [
    "Database" => "it_project_db",
    "Uid" => "sa",
    "PWD" => "i2t400",
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    echo "<p style='color: red;'>Connection failed!</p>";
    $errors = sqlsrv_errors();
    foreach ($errors as $error) {
        echo "<p>Error: " . $error['message'] . "</p>";
    }
    exit;
} else {
    echo "<p style='color: green;'>Connection successful!</p>";
}

// Test if the projects table exists
echo "<h2>Testing Table Existence</h2>";
$sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'projects'";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    echo "<p style='color: red;'>Query failed!</p>";
    $errors = sqlsrv_errors();
    foreach ($errors as $error) {
        echo "<p>Error: " . $error['message'] . "</p>";
    }
} else {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        echo "<p style='color: green;'>Projects table exists!</p>";
    } else {
        echo "<p style='color: red;'>Projects table does not exist!</p>";
    }
}

// Test table structure
echo "<h2>Testing Table Structure</h2>";
$sql = "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'projects' ORDER BY ORDINAL_POSITION";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    echo "<p style='color: red;'>Query failed!</p>";
} else {
    echo "<table border='1'><tr><th>Column Name</th><th>Data Type</th></tr>";
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr><td>" . $row['COLUMN_NAME'] . "</td><td>" . $row['DATA_TYPE'] . "</td></tr>";
    }
    echo "</table>";
}

// Test data count
echo "<h2>Testing Data Count</h2>";
$sql = "SELECT COUNT(*) as total FROM projects";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    echo "<p style='color: red;'>Count query failed!</p>";
    $errors = sqlsrv_errors();
    foreach ($errors as $error) {
        echo "<p>Error: " . $error['message'] . "</p>";
    }
} else {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    echo "<p>Total records in projects table: " . $row['total'] . "</p>";
}

// Test sample data fetch
echo "<h2>Testing Sample Data Fetch</h2>";
$sql = "SELECT TOP 5 * FROM projects";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    echo "<p style='color: red;'>Sample data query failed!</p>";
    $errors = sqlsrv_errors();
    foreach ($errors as $error) {
        echo "<p>Error: " . $error['message'] . "</p>";
    }
} else {
    echo "<table border='1'>";
    $first_row = true;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if ($first_row) {
            echo "<tr>";
            foreach (array_keys($row) as $column) {
                echo "<th>" . htmlspecialchars($column) . "</th>";
            }
            echo "</tr>";
            $first_row = false;
        }
        echo "<tr>";
        foreach ($row as $value) {
            if ($value instanceof DateTime) {
                $value = $value->format('Y-m-d H:i:s');
            }
            echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

// Test your Database class
echo "<h2>Testing Database Class</h2>";
try {
    require_once 'config/config.php';
    $db = new Database();
    echo "<p style='color: green;'>Database class instantiated successfully!</p>";
    
    // Test a simple query
    $db->query("SELECT COUNT(*) as total FROM projects");
    $db->execute();
    $result = $db->single();
    
    if ($result) {
        echo "<p>Database class query result: " . $result['total'] . " records found</p>";
    } else {
        echo "<p style='color: red;'>Database class query returned null</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Database class error: " . $e->getMessage() . "</p>";
}

sqlsrv_close($conn);
?>