<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$serverName = "10.2.0.9";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    $errors = sqlsrv_errors();
    echo json_encode(['error' => 'Database connection failed', 'details' => $errors]);
    exit();
}

// Include department in the query
$query = "SELECT empcode, username, ISNULL(department, 'Unknown Department') as department 
          FROM daily_report_db.dbo.users 
          ORDER BY department, username";

$result = sqlsrv_query($conn, $query);
if ($result === false) {
    $errors = sqlsrv_errors();
    echo json_encode(['error' => 'Query failed', 'details' => $errors]);
    sqlsrv_close($conn);
    exit();
}

$users = [];
while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $users[] = [
        'empcode' => $row['empcode'],
        'username' => $row['username'],
        'department' => $row['department']
    ];
}

sqlsrv_free_stmt($result);
sqlsrv_close($conn);

if (empty($users)) {
    echo json_encode(['error' => 'No users found']);
} else {
    echo json_encode($users);
}
?>