<?php
// Database connection details
$serverName = "10.2.0.9";
$connectionOptions = [
    "UID" => "sa",
    "PWD" => "S3rverDB02lrn25",
    "Database" => "daily_report_db"
];

// Establish the connection to the SQL Server
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    echo "Database connection failed!<br>";
    print_r(sqlsrv_errors());
    exit();
}

echo "Database connected successfully!<br><br>";

// Create users table
$createUsersTable = "
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='users' AND xtype='U')
BEGIN
    CREATE TABLE users (
        id INT IDENTITY(1,1) PRIMARY KEY,
        username NVARCHAR(100) NOT NULL UNIQUE,
        password NVARCHAR(255) NOT NULL,
        full_name NVARCHAR(255),
        role NVARCHAR(50),
        empcode NVARCHAR(50)
    );
END";

$result = sqlsrv_query($conn, $createUsersTable);
if ($result) {
    echo "✓ Users table created successfully!<br><br>";
} else {
    echo "✗ Failed to create users table<br>";
    print_r(sqlsrv_errors());
    exit();
}

// Insert the current user from session data
session_start();

if (isset($_SESSION['dr_username']) && isset($_SESSION['dr_full_name'])) {
    // Use session data to create the user
    $insertUserQuery = "
    IF NOT EXISTS (SELECT 1 FROM users WHERE username = ? OR empcode = ?)
    BEGIN
        INSERT INTO users (username, password, full_name, role, empcode) 
        VALUES (?, ?, ?, ?, ?)
    END";
    
    $username = $_SESSION['dr_username']; // member1
    $empcode = $_SESSION['empcode']; // Kyle Justine Dimla
    $full_name = $_SESSION['dr_full_name']; // Member 1
    $role = $_SESSION['dr_role']; // member
    $password = password_hash('defaultpass123', PASSWORD_DEFAULT); // Default password
    
    $params = [
        $username, $empcode, // for the NOT EXISTS check
        $username, $password, $full_name, $role, $empcode // for the INSERT
    ];
    
    $insertStmt = sqlsrv_prepare($conn, $insertUserQuery, $params);
    
    if ($insertStmt && sqlsrv_execute($insertStmt)) {
        echo "✓ Created user from session data:<br>";
        echo "&nbsp;&nbsp;Username: " . htmlspecialchars($username) . "<br>";
        echo "&nbsp;&nbsp;Full Name: " . htmlspecialchars($full_name) . "<br>";
        echo "&nbsp;&nbsp;Role: " . htmlspecialchars($role) . "<br>";
        echo "&nbsp;&nbsp;Empcode: " . htmlspecialchars($empcode) . "<br>";
        echo "&nbsp;&nbsp;Password: defaultpass123 (default)<br><br>";
    } else {
        echo "✗ Failed to create user from session data<br>";
        print_r(sqlsrv_errors());
    }
}

// Also create some additional test users
$additionalUsers = [
    [
        'username' => 'admin',
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'full_name' => 'Admin User',
        'role' => 'Admin',
        'empcode' => 'ADM001'
    ],
    [
        'username' => 'testuser',
        'password' => password_hash('test123', PASSWORD_DEFAULT),
        'full_name' => 'Test User',
        'role' => 'Employee',
        'empcode' => 'EMP001'
    ]
];

echo "Creating additional test users:<br>";
foreach ($additionalUsers as $user) {
    $insertQuery = "
    IF NOT EXISTS (SELECT 1 FROM users WHERE username = ?)
    BEGIN
        INSERT INTO users (username, password, full_name, role, empcode) VALUES (?, ?, ?, ?, ?)
    END";
    
    $insertStmt = sqlsrv_prepare($conn, $insertQuery, [
        $user['username'], // for NOT EXISTS check
        $user['username'], $user['password'], $user['full_name'], $user['role'], $user['empcode']
    ]);
    
    if ($insertStmt && sqlsrv_execute($insertStmt)) {
        echo "✓ Created user: " . $user['username'] . "<br>";
    } else {
        echo "✗ Failed to create user: " . $user['username'] . "<br>";
    }
}

echo "<br>";

// Show all users
$showUsersQuery = "SELECT id, username, full_name, role, empcode FROM users";
$showUsersStmt = sqlsrv_query($conn, $showUsersQuery);

if ($showUsersStmt) {
    echo "<h3>All Users in Database:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Empcode</th></tr>";
    
    while ($userRow = sqlsrv_fetch_array($showUsersStmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $userRow['id'] . "</td>";
        echo "<td>" . htmlspecialchars($userRow['username']) . "</td>";
        echo "<td>" . htmlspecialchars($userRow['full_name']) . "</td>";
        echo "<td>" . htmlspecialchars($userRow['role']) . "</td>";
        echo "<td>" . htmlspecialchars($userRow['empcode']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

sqlsrv_close($conn);
?>

<style>
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f2f2f2; }
body { font-family: Arial, sans-serif; }
</style>