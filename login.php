<?php
session_start(); // Start the session for the user

// Initialize variables for the form data
$username = $password = "";
$usernameErr = $passwordErr = "";
$loginErr = "";

// Database connection details
$serverName = "172.16.2.8"; // Database server IP or hostname
$connectionOptions = [
    "UID" => "sa",              // Database username
    "PWD" => "i2t400",          // Database password
    "Database" => "it_project_db" // Changed to it_project_db
];

// Establish the connection to the SQL Server
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Check if the connection was successful
if (!$conn) {
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
}

// Handle POST request for login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate input
    if (empty($_POST["username"])) {
        $usernameErr = "Username is required";
    } else {
        $username = $_POST["username"];
    }

    if (empty($_POST["password"])) {
        $passwordErr = "Password is required";
    } else {
        $password = $_POST["password"];
    }

    // If no errors, proceed with the login attempt
    if (empty($usernameErr) && empty($passwordErr)) {
        // Updated SQL query to use the new table structure
        $query = "SELECT username, password, role, empcode FROM it_project_db.dbo.users WHERE username = ?";
        $params = array($username);

        // Execute the query
        $stmt = sqlsrv_query($conn, $query, $params);

        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        // Check if user exists
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Verify the password
            if (password_verify($password, $row['password'])) {
                // Password is correct, start session and store user data
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['empcode'] = $row['empcode']; // Store empcode in the session

                // Check if "Remember Me" is selected
                if (isset($_POST['remember'])) {
                    // Generate a unique token for the user
                    $token = bin2hex(random_bytes(32));
                    $hashedToken = password_hash($token, PASSWORD_DEFAULT);

                    // First, add the login_token column if it doesn't exist
                    $alterQuery = "
                    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                                   WHERE TABLE_NAME = 'users' 
                                   AND COLUMN_NAME = 'login_token' 
                                   AND TABLE_SCHEMA = 'dbo')
                    BEGIN
                        ALTER TABLE it_project_db.dbo.users ADD login_token NVARCHAR(255) NULL
                    END";
                    sqlsrv_query($conn, $alterQuery);

                    // Store the token in the database
                    $updateQuery = "UPDATE it_project_db.dbo.users SET login_token = ? WHERE username = ?";
                    $updateParams = array($hashedToken, $username);
                    sqlsrv_query($conn, $updateQuery, $updateParams);

                    // Set the token in a cookie that expires in 1 year
                    setcookie("login_token", $token, time() + (365 * 24 * 60 * 60), "/", "", false, true);
                }

                if (isset($_SESSION['redirect_after_login'])) {
                    $redirect_url = $_SESSION['redirect_after_login'];
                    unset($_SESSION['redirect_after_login']);
                    header("Location: $redirect_url");
                    exit();
                } else {
                    header("Location: http://172.16.2.8/projectsummary/projectsummary.php");
                    exit();
                }
            } else {
                $loginErr = "Incorrect password.";
            }
        } else {
            $loginErr = "User not found.";
        }

        // Close the statement
        sqlsrv_free_stmt($stmt);
    }
}

// Close the connection
sqlsrv_close($conn);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Rose Noire | Login</title>
    <style>
    /* General reset and styling */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Arial', sans-serif;
    }

    body {
        background: #000000;
        color: #f4f4f4;
        min-height: 100vh;
        overflow-x: hidden;
    }

    .container {
        display: flex;
        min-height: 100vh;
    }

    /* Left side with brand imagery - now 75% */
    .brand-section {
        flex: 3;
        /* 3 parts out of 4 total parts (75%) */
        background: linear-gradient(135deg, #000000 0%, #2d1a20 100%);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 40px;
        position: relative;
        overflow: hidden;
    }

    .brand-content {
        z-index: 2;
        text-align: center;
        animation: fadeIn 1.5s ease-in-out;
    }

    .logo {
        font-size: 4em;
        font-weight: bold;
        color: #f48fb1;
        margin-bottom: 30px;
        text-shadow: 0 0 15px rgba(255, 105, 180, 0.7);
    }

    .tagline {
        font-size: 1.4em;
        color: #f4f4f4;
        font-style: italic;
        margin-bottom: 40px;
        margin-left: auto;
        margin-right: auto;
    }

    /* Decorative elements */
    .rose-overlay {
        position: absolute;
        bottom: -50px;
        left: -50px;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(255, 105, 180, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
        border-radius: 50%;
        z-index: 1;
    }

    .rose-overlay:nth-child(2) {
        top: -50px;
        right: -50px;
        left: auto;
        bottom: auto;
    }

    /* Right side with login form - now 25% */
    .login-section {
        flex: 1;
        /* 1 part out of 4 total parts (25%) */
        background: linear-gradient(135deg, #1a1a1a 0%, #000000 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 40px 20px;
    }

    .login-container {
        width: 100%;
        max-width: 350px;
        animation: slideInRight 1s ease-out;
    }

    .form-header {
        text-align: center;
        margin-bottom: 30px;
    }

    .form-header h2 {
        font-size: 1.8em;
        color: #f48fb1;
        margin-bottom: 10px;
    }

    .form-header p {
        color: #999;
        font-size: 0.9em;
    }

    .form-group {
        margin-bottom: 25px;
        position: relative;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #f48fb1;
        font-size: 0.9em;
        font-weight: bold;
    }

    .form-control {
        width: 100%;
        padding: 15px;
        background: rgba(44, 44, 44, 0.8);
        border: 1px solid #444;
        border-radius: 8px;
        color: #f4f4f4;
        font-size: 1em;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        border-color: #f48fb1;
        box-shadow: 0 0 12px rgba(255, 105, 180, 0.3);
        outline: none;
        background: rgba(44, 44, 44, 1);
    }

    .remember-me {
        display: flex;
        align-items: center;
        margin-bottom: 25px;
    }

    .remember-me input {
        appearance: none;
        width: 20px;
        height: 20px;
        border: 2px solid #f48fb1;
        border-radius: 4px;
        outline: none;
        background: rgba(44, 44, 44, 0.8);
        cursor: pointer;
        position: relative;
        margin-right: 10px;
        transition: all 0.3s ease;
    }

    .remember-me input:checked {
        background: #f48fb1;
        box-shadow: 0 0 8px rgba(255, 105, 180, 0.5);
    }

    .remember-me input:checked::before {
        content: '✔';
        font-size: 14px;
        color: #1a1a1a;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }

    .remember-me label {
        color: #ccc;
        font-size: 0.9em;
    }

    .btn-login {
        width: 100%;
        padding: 15px;
        background: linear-gradient(to right, #f48fb1, #ff80ab);
        border: none;
        border-radius: 8px;
        color: white;
        font-size: 1em;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .btn-login:hover {
        background: linear-gradient(to right, #ff80ab, #f48fb1);
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(255, 105, 180, 0.4);
    }

    .error-message {
        color: #ff6f91;
        font-size: 0.85em;
        text-align: center;
        margin-top: 15px;
        padding: 10px;
        background: rgba(255, 0, 0, 0.1);
        border-radius: 8px;
        display: none;
    }

    .error-message.show {
        display: block;
        animation: shake 0.5s ease-in-out;
    }

    /* Animations */
    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes slideInRight {
        from {
            transform: translateX(50px);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes shake {

        0%,
        100% {
            transform: translateX(0);
        }

        10%,
        30%,
        50%,
        70%,
        90% {
            transform: translateX(-5px);
        }

        20%,
        40%,
        60%,
        80% {
            transform: translateX(5px);
        }
    }

    /* Responsive design */
    @media (max-width: 1200px) {

        /* Adjust the flex ratio on medium screens */
        .brand-section {
            flex: 2;
            /* 2/3 of screen (66.6%) */
        }

        .login-section {
            flex: 1;
            /* 1/3 of screen (33.3%) */
        }
    }

    @media (max-width: 992px) {
        .container {
            flex-direction: column;
        }

        .brand-section,
        .login-section {
            flex: none;
            width: 100%;
            padding: 40px 20px;
        }

        .brand-section {
            min-height: 250px;
        }

        .logo {
            font-size: 2.5em;
        }

        .tagline {
            font-size: 1.2em;
        }
    }

    @media (max-width: 600px) {
        .login-container {
            max-width: 100%;
        }

        .form-header h2 {
            font-size: 1.7em;
        }

        .btn-login {
            padding: 12px;
        }

        .brand-section {
            min-height: 200px;
            padding: 30px 15px;
        }

        .logo {
            font-size: 2em;
            margin-bottom: 15px;
        }

        .tagline {
            font-size: 0.9em;
            margin-bottom: 20px;
        }
    }

    /* Add link styles for register page */
    .register-link {
        text-align: center;
        margin-top: 20px;
    }

    .register-link a {
        color: #f48fb1;
        text-decoration: none;
        font-size: 0.9em;
        transition: color 0.3s ease;
    }

    .register-link a:hover {
        color: #ff80ab;
        text-decoration: underline;
    }
    </style>
</head>

<body>
    <div class="container">
        <!-- Left side with brand imagery - now 75% -->
        <div class="brand-section">
            <div class="rose-overlay"></div>
            <div class="rose-overlay"></div>
            <div class="brand-content">
                <div class="logo">LA ROSE NOIRE</div>
            </div>
        </div>

        <!-- Right side with login form - now 25% -->
        <div class="login-section">
            <div class="login-container">
                <div class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Please sign in to continue</p>
                </div>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control"
                            value="<?php echo htmlspecialchars($username); ?>" required>
                        <?php if (!empty($usernameErr)): ?>
                        <span style="color:#ff6f91; font-size:0.85em; display:block; margin-top:5px;">
                            <?php echo $usernameErr; ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                        <?php if (!empty($passwordErr)): ?>
                        <span style="color:#ff6f91; font-size:0.85em; display:block; margin-top:5px;">
                            <?php echo $passwordErr; ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>

                    <button type="submit" class="btn-login">Sign In</button>

                    <?php if (!empty($loginErr)): ?>
                    <div class="error-message show">
                        <?php echo $loginErr; ?>
                    </div>
                    <?php endif; ?>
                </form>

            </div>
        </div>
    </div>

    <script>
    // Simple validation script
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        const username = document.getElementById('username');
        const password = document.getElementById('password');

        form.addEventListener('submit', function(event) {
            let isValid = true;

            if (username.value.trim() === '') {
                showError(username, 'Username is required');
                isValid = false;
            } else {
                clearError(username);
            }

            if (password.value.trim() === '') {
                showError(password, 'Password is required');
                isValid = false;
            } else {
                clearError(password);
            }

            if (!isValid) {
                event.preventDefault();
            }
        });

        function showError(input, message) {
            const formGroup = input.parentElement;
            let errorElement = formGroup.querySelector('.error-message');

            if (!errorElement) {
                errorElement = document.createElement('span');
                errorElement.className = 'error-message show';
                errorElement.style.color = '#ff6f91';
                errorElement.style.fontSize = '0.85em';
                errorElement.style.display = 'block';
                errorElement.style.marginTop = '5px';
                formGroup.appendChild(errorElement);
            }

            errorElement.textContent = message;
            input.style.borderColor = '#ff6f91';
        }

        function clearError(input) {
            const formGroup = input.parentElement;
            const errorElement = formGroup.querySelector('.error-message');

            if (errorElement) {
                errorElement.textContent = '';
            }

            input.style.borderColor = '#444';
        }
    });
    </script>
</body>

</html>