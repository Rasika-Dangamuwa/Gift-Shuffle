<?php
// Database connection configuration file

// Define database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'gift_shuffle_system');

// Establish database connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

// Function to execute query and return results in an array
function executeQuery($sql, $params = [], $types = '') {
    $conn = getConnection();
    $result = [];
    
    if ($stmt = $conn->prepare($sql)) {
        // Bind parameters if any
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        // Execute the statement
        if ($stmt->execute()) {
            $query_result = $stmt->get_result();
            
            // If it's a SELECT query
            if ($query_result) {
                while ($row = $query_result->fetch_assoc()) {
                    $result[] = $row;
                }
            } else {
                // For INSERT, UPDATE, DELETE
                $result = ['affected_rows' => $stmt->affected_rows, 'insert_id' => $conn->insert_id];
            }
        } else {
            throw new Exception("Error executing query: " . $stmt->error);
        }
        
        $stmt->close();
    } else {
        throw new Exception("Error preparing statement: " . $conn->error);
    }
    
    $conn->close();
    return $result;
}

/**
 * Function to execute a query using an existing database connection
 * Similar to executeQuery, but accepts a connection object to use in a transaction
 *
 * @param mysqli $conn The database connection to use
 * @param string $sql The SQL query to execute
 * @param array $params Parameters to bind to the query
 * @param string $types Types of parameters (i: integer, d: double, s: string, b: blob)
 * @return array Result of the query
 * @throws Exception If there's an error in the query
 */
function executeQueryWithConnection($conn, $sql, $params = [], $types = '') {
    $result = [];
    
    if ($stmt = $conn->prepare($sql)) {
        // Bind parameters if any
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        // Execute the statement
        if ($stmt->execute()) {
            $query_result = $stmt->get_result();
            
            // If it's a SELECT query
            if ($query_result) {
                while ($row = $query_result->fetch_assoc()) {
                    $result[] = $row;
                }
            } else {
                // For INSERT, UPDATE, DELETE
                $result = ['affected_rows' => $stmt->affected_rows, 'insert_id' => $conn->insert_id];
            }
        } else {
            throw new Exception("Error executing query: " . $stmt->error);
        }
        
        $stmt->close();
    } else {
        throw new Exception("Error preparing statement: " . $conn->error);
    }
    
    return $result;
}

// Function to sanitize user input
function sanitizeInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input);
    return $input;
}

// Function to get a single setting value
function getSetting($setting_name) {
    try {
        $result = executeQuery(
            "SELECT setting_value FROM system_settings WHERE setting_name = ?", 
            [$setting_name], 
            's'
        );
        
        if (count($result) > 0) {
            return $result[0]['setting_value'];
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error getting setting: " . $e->getMessage());
        return null;
    }
}

// Function to update a setting
function updateSetting($setting_name, $setting_value, $user_id) {
    try {
        $result = executeQuery(
            "UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_name = ?", 
            [$setting_value, $user_id, $setting_name], 
            'sis'
        );
        
        return $result['affected_rows'] > 0;
    } catch (Exception $e) {
        error_log("Error updating setting: " . $e->getMessage());
        return false;
    }
}

// Function to log an activity
function logActivity($user_id, $activity_type, $details = '') {
    try {
        $result = executeQuery(
            "INSERT INTO activity_log (user_id, activity_type, details, ip_address) VALUES (?, ?, ?, ?)", 
            [$user_id, $activity_type, $details, $_SERVER['REMOTE_ADDR']], 
            'isss'
        );
        
        return $result['insert_id'];
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
}

// Function to check if user has specific role
function hasRole($required_role) {
    return isLoggedIn() && $_SESSION["role"] === $required_role;
}

// Function to redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("location: index.php");
        exit;
    }
}

// Function to redirect if user doesn't have required role
function requireRole($required_role) {
    requireLogin();
    
    if ($_SESSION["role"] !== $required_role) {
        header("location: access_denied.php");
        exit;
    }
}