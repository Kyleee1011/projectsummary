<?php
// delete_tasks.php
require_once 'config.php'; // Load DB credentials

try {
    // Connect to SQL Server using PDO
    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Start transaction
    $conn->beginTransaction();

    // Delete from child table first if FK exists
    $conn->exec("DELETE FROM dbo.minor_tasks");
    $conn->exec("DELETE FROM dbo.major_tasks");

    // Commit
    $conn->commit();

    echo "All tasks deleted successfully.";

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    die("Error deleting tasks: " . $e->getMessage());
}
?>
