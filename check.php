<?php
$serverName = "10.2.0.9";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];

// Connect to SQL Server
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
}

// Query to get all tables and their columns
$sql = "
SELECT 
    t.name AS table_name,
    c.name AS column_name,
    ty.name AS data_type,
    c.max_length,
    c.is_nullable
FROM 
    sys.tables t
INNER JOIN 
    sys.columns c ON t.object_id = c.object_id
INNER JOIN 
    sys.types ty ON c.user_type_id = ty.user_type_id
ORDER BY 
    t.name, c.column_id;
";

$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die("Query failed: " . print_r(sqlsrv_errors(), true));
}

// Display the table structure
$currentTable = null;

echo "<pre>";
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if ($currentTable !== $row['table_name']) {
        if ($currentTable !== null) {
            echo "\n";
        }
        $currentTable = $row['table_name'];
        echo "Table: {$currentTable}\n";
        echo str_repeat('-', 40) . "\n";
        echo str_pad("Column", 20) . str_pad("Type", 12) . "Nullable\n";
        echo str_repeat('-', 40) . "\n";
    }

    echo str_pad($row['column_name'], 20);
    echo str_pad($row['data_type'], 12);
    echo ($row['is_nullable'] ? "YES" : "NO") . "\n";
}
echo "</pre>";

// Cleanup
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
