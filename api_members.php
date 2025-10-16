<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bsis4a_jc";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'register') {
        $fullname = $conn->real_escape_string(trim($_POST['fullname']));
        $email = $conn->real_escape_string(trim($_POST['email']));
        $phone = $conn->real_escape_string(trim($_POST['phone']));
        $birthday = $_POST['birthday'];
        $sex = $_POST['sex'];
        
        // Check if email already exists
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        $checkEmail->store_result();
        
        if ($checkEmail->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit();
        }
        
        $username = strtolower(str_replace(' ', '', $fullname)) . rand(100, 999);
        $default_password = password_hash('member123', PASSWORD_DEFAULT);
        $role = 'staff';
        
        $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, email, birthday, sex, mobile_number, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssssss", $username, $default_password, $fullname, $email, $birthday, $sex, $phone, $role);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Member registered successfully! Username: ' . $username . ', Password: member123']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to register member']);
        }
        
        $stmt->close();
    }
    elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Member deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete member']);
        }
        
        $stmt->close();
    }
    elseif ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $status = intval($_POST['status']);
        
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'staff'");
        $stmt->bind_param("ii", $status, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Member status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
        
        $stmt->close();
    }
} 
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get_all') {
        $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
        
        if (!empty($search)) {
            $searchParam = "%{$search}%";
            $stmt = $conn->prepare("SELECT id, username, fullname, email, mobile_number, birthday, sex, role, is_active, created_at 
                                    FROM users 
                                    WHERE (fullname LIKE ? OR email LIKE ? OR username LIKE ?) 
                                    AND role = 'staff' 
                                    ORDER BY created_at DESC");
            $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
        } else {
            $stmt = $conn->prepare("SELECT id, username, fullname, email, mobile_number, birthday, sex, role, is_active, created_at 
                                    FROM users 
                                    WHERE role = 'staff' 
                                    ORDER BY created_at DESC");
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $members = [];

        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }

        echo json_encode(['success' => true, 'data' => $members]);
        $stmt->close();
    }
}

$conn->close();
?>
