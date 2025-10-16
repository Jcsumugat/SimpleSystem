<?php
require_once 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'register':
            registerMember();
            break;
        case 'update':
            updateMember();
            break;
        case 'delete':
            deleteMember();
            break;
        case 'get_all':
            getAllMembers();
            break;
        case 'get_stats':
            getMemberStats();
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get_all') {
        getAllMembers();
    } elseif ($action === 'get_stats') {
        getMemberStats();
    }
}

function registerMember() {
    $fullname = sanitizeInput($_POST['fullname'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $birthday = $_POST['birthday'] ?? '';
    $sex = $_POST['sex'] ?? '';
    
    if (empty($fullname) || empty($email) || empty($birthday) || empty($sex)) {
        jsonResponse(false, 'All required fields must be filled');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Invalid email format');
    }
    
    $conn = getDBConnection();
    
    $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    
    if ($checkEmail->num_rows > 0) {
        jsonResponse(false, 'Email already exists');
    }
    
    $username = strtolower(str_replace(' ', '', $fullname)) . rand(100, 999);
    $default_password = password_hash('password123', PASSWORD_DEFAULT);
    $mobile_number = $phone;
    $role = 'staff';
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, email, birthday, sex, mobile_number, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("ssssssss", $username, $default_password, $fullname, $email, $birthday, $sex, $mobile_number, $role);
    
    if ($stmt->execute()) {
        jsonResponse(true, 'Member registered successfully', [
            'member_id' => $conn->insert_id,
            'username' => $username
        ]);
    } else {
        jsonResponse(false, 'Failed to register member: ' . $conn->error);
    }
    
    $stmt->close();
    $conn->close();
}

function updateMember() {
    $id = intval($_POST['id'] ?? 0);
    $fullname = sanitizeInput($_POST['fullname'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $birthday = $_POST['birthday'] ?? '';
    $sex = $_POST['sex'] ?? '';
    
    if ($id <= 0) {
        jsonResponse(false, 'Invalid member ID');
    }
    
    if (empty($fullname) || empty($email) || empty($birthday) || empty($sex)) {
        jsonResponse(false, 'All required fields must be filled');
    }
    
    $conn = getDBConnection();
    
    $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $checkEmail->bind_param("si", $email, $id);
    $checkEmail->execute();
    $checkEmail->store_result();
    
    if ($checkEmail->num_rows > 0) {
        jsonResponse(false, 'Email already exists for another member');
    }
    
    $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, mobile_number = ?, birthday = ?, sex = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssssi", $fullname, $email, $phone, $birthday, $sex, $id);
    
    if ($stmt->execute()) {
        jsonResponse(true, 'Member updated successfully');
    } else {
        jsonResponse(false, 'Failed to update member');
    }
    
    $stmt->close();
    $conn->close();
}

function deleteMember() {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse(false, 'Invalid member ID');
    }
    
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        jsonResponse(true, 'Member deleted successfully');
    } else {
        jsonResponse(false, 'Failed to delete member or member not found');
    }
    
    $stmt->close();
    $conn->close();
}

function getAllMembers() {
    $conn = getDBConnection();
    
    $search = sanitizeInput($_GET['search'] ?? '');
    
    if (!empty($search)) {
        $searchParam = "%{$search}%";
        $stmt = $conn->prepare("SELECT id, username, fullname, email, mobile_number, birthday, sex, role, is_active, created_at FROM users WHERE (fullname LIKE ? OR email LIKE ? OR username LIKE ?) AND role = 'staff' ORDER BY created_at DESC");
        $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
    } else {
        $stmt = $conn->prepare("SELECT id, username, fullname, email, mobile_number, birthday, sex, role, is_active, created_at FROM users WHERE role = 'staff' ORDER BY created_at DESC");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    
    jsonResponse(true, 'Members retrieved successfully', $members);
    
    $stmt->close();
    $conn->close();
}

function getMemberStats() {
    $conn = getDBConnection();
    
    $totalQuery = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'staff'");
    $total = $totalQuery->fetch_assoc()['total'];
    
    $activeQuery = $conn->query("SELECT COUNT(*) as active FROM users WHERE role = 'staff' AND is_active = 1");
    $active = $activeQuery->fetch_assoc()['active'];
    
    $inactive = $total - $active;
    
    jsonResponse(true, 'Stats retrieved successfully', [
        'total' => $total,
        'active' => $active,
        'inactive' => $inactive
    ]);
    
    $conn->close();
}
?>