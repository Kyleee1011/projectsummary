<?php
// --- config/config.php ---
// This file contains the database connection settings for the Project Summary data.
// It connects to the 'it_project_db' database.

// Ensure the class is not declared more than once.
if (!class_exists('Database')) {
    class Database {
        private $serverName = "172.16.2.8";
        private $connectionOptions = [
            "Database" => "it_project_db",
            "Uid" => "sa",
            "PWD" => "i2t400"
        ];
        private $conn;
        private $stmt;
        private $sql;
        private $params = [];

        public function __construct() {
            // Use UTF-8 for character set
            $this->conn = sqlsrv_connect($this->serverName, array_merge($this->connectionOptions, ["CharacterSet" => "UTF-8"]));

            if ($this->conn === false) {
                $errors = sqlsrv_errors();
                // Log the detailed error to the server's error log for security.
                error_log("Database connection failed: " . print_r($errors, true));
                // Provide a generic error to the user.
                throw new Exception("Database connection could not be established.");
            }
        }

        // Resets the query and parameters for a new query
        public function query(string $sql): self {
            $this->sql = $sql;
            $this->params = []; // Reset parameters for the new query
            if ($this->stmt) {
                sqlsrv_free_stmt($this->stmt);
                $this->stmt = null;
            }
            return $this;
        }

        // Binds a value to a corresponding named placeholder in the SQL query
        public function bind(string $param, $value): self {
            $this->params[$param] = $value;
            return $this;
        }

        // Executes the prepared statement
        public function execute() {
            // Find all named parameters in the query (e.g., :name, :id)
            preg_match_all('/:([a-zA-Z0-9_]+)/', $this->sql, $matches);
            $param_names = $matches[0]; 
            
            $ordered_params = [];
            foreach ($param_names as $placeholder) {
                if (array_key_exists($placeholder, $this->params)) {
                    // Add the corresponding value to an ordered array for sqlsrv
                    $ordered_params[] = $this->params[$placeholder];
                } else {
                    throw new Exception("Missing parameter for placeholder '$placeholder'");
                }
            }

            // Replace named placeholders with question marks for SQLSRV compatibility
            $sql_with_placeholders = preg_replace('/:[a-zA-Z0-9_]+/', '?', $this->sql);

            // Prepare the statement with the ordered parameters
            $this->stmt = sqlsrv_prepare($this->conn, $sql_with_placeholders, $ordered_params);

            if ($this->stmt === false) {
                $errors = sqlsrv_errors();
                $errorMessage = "SQL Server Prepare Failed: " . ($errors[0]['message'] ?? 'Unknown error');
                error_log($errorMessage . " | SQL: " . $sql_with_placeholders . " | Params: " . print_r($ordered_params, true));
                throw new Exception($errorMessage);
            }

            // Execute the prepared statement
            $result = sqlsrv_execute($this->stmt);

            if ($result === false) {
                $errors = sqlsrv_errors();
                $errorMessage = "SQL Server Execute Error: " . ($errors[0]['message'] ?? 'Unknown error');
                error_log($errorMessage . " | SQL: " . $sql_with_placeholders . " | Params: " . print_r($ordered_params, true));
                throw new Exception($errorMessage);
            }

            return $result;
        }

        // Fetches all rows from the result set as an array of associative arrays
        public function resultSet(): array {
            $results = [];
            if ($this->stmt) {
                while ($row = sqlsrv_fetch_array($this->stmt, SQLSRV_FETCH_ASSOC)) {
                    // Format DateTime objects into a consistent string format
                    foreach ($row as $key => $value) {
                        if ($value instanceof DateTime) {
                            $row[$key] = $value->format('Y-m-d H:i:s');
                        }
                    }
                    $results[] = $row;
                }
            }
            return $results;
        }

        // Fetches a single row from the result set
        public function single(): ?array {
            if ($this->stmt) {
                $row = sqlsrv_fetch_array($this->stmt, SQLSRV_FETCH_ASSOC);
                if ($row) {
                    // Format DateTime objects
                    foreach ($row as $key => $value) {
                        if ($value instanceof DateTime) {
                            $row[$key] = $value->format('Y-m-d H:i:s');
                        }
                    }
                    return $row;
                }
            }
            return null;
        }

        // Returns the number of rows affected by the last SQL statement
        public function rowCount(): ?int {
            if ($this->stmt) {
                return sqlsrv_rows_affected($this->stmt);
            }
            return null;
        }

        // Retrieves the last ID inserted into the database
        public function lastInsertId() {
            $this->query("SELECT SCOPE_IDENTITY() AS lastid");
            $this->execute();
            $row = $this->single();
            return $row ? $row['lastid'] : null;
        }

        // Closes the database connection when the object is destroyed
        public function __destruct() {
            if ($this->stmt) {
                sqlsrv_free_stmt($this->stmt);
            }
            if ($this->conn) {
                sqlsrv_close($this->conn);
            }
        }
    }
}
?>