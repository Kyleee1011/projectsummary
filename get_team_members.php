<?php
session_start();
if (!isset($_SESSION['username']) || !isset($_SESSION['empcode'])) {
    echo json_encode([]);
    exit();
}

$serverName = "172.16.2.8";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "i2t400",
    "Database" => "daily_report_db"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    echo json_encode([]);
    exit();
}

$query = "SELECT empcode, username FROM daily_report_db.dbo.users ORDER BY username";
$result = sqlsrv_query($conn, $query);
$users = [];
while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $users[] = $row;
}

sqlsrv_free_stmt($result);
sqlsrv_close($conn);
echo json_encode($users);
?>