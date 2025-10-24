<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendance_system');

// Get database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Require login - redirect to login page if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// JSON response helper
function jsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Format date for display
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    if (empty($time)) return 'N/A';
    return date('h:i A', strtotime($time));
}

// Get current academic year
function getCurrentAcademicYear() {
    $currentYear = date('Y');
    $currentMonth = date('n');
    
    // Academic year starts in August
    if ($currentMonth >= 8) {
        return $currentYear . '-' . ($currentYear + 1);
    } else {
        return ($currentYear - 1) . '-' . $currentYear;
    }
}

// Calculate attendance percentage
function calculateAttendancePercentage($present, $total) {
    if ($total == 0) return 0;
    return round(($present / $total) * 100, 2);
}
?>