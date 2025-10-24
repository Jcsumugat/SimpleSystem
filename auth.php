<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(false, 'Username and password are required');
        }
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id, username, password, fullname, email, role FROM users WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            
            $updateStmt = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $user['id']);
            $updateStmt->execute();
            
            jsonResponse(true, 'Login successful', ['redirect' => 'dashboard.php']);
        } else {
            jsonResponse(false, 'Invalid username or password');
        }
        
        $stmt->close();
        $conn->close();
    } 
    elseif ($action === 'logout') {
        session_destroy();
        jsonResponse(true, 'Logged out successfully', ['redirect' => 'login.php']);
    }
}
?>