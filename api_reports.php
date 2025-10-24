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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get_new_members') {
        $startDate = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : '';
        $endDate = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : '';
        $sex = isset($_GET['sex']) ? $conn->real_escape_string($_GET['sex']) : '';
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        
        // Build query with filters
        $whereConditions = ["role = 'staff'"];
        
        if (!empty($startDate) && !empty($endDate)) {
            $whereConditions[] = "DATE(created_at) BETWEEN '$startDate' AND '$endDate'";
        }
        
        if (!empty($sex) && $sex !== 'All') {
            $whereConditions[] = "sex = '$sex'";
        }
        
        if ($status !== '' && $status !== 'all') {
            $whereConditions[] = "is_active = " . intval($status);
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $sql = "SELECT id, username, fullname, email, mobile_number, birthday, sex, is_active, created_at 
                FROM users 
                WHERE $whereClause 
                ORDER BY created_at DESC";
        
        $result = $conn->query($sql);
        $members = [];
        
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
        
        // Get summary statistics
        $totalQuery = $conn->query("SELECT COUNT(*) as total FROM users WHERE $whereClause");
        $total = $totalQuery->fetch_assoc()['total'];
        
        $maleQuery = $conn->query("SELECT COUNT(*) as male FROM users WHERE $whereClause AND sex = 'Male'");
        $male = $maleQuery->fetch_assoc()['male'];
        
        $femaleQuery = $conn->query("SELECT COUNT(*) as female FROM users WHERE $whereClause AND sex = 'Female'");
        $female = $femaleQuery->fetch_assoc()['female'];
        
        $activeQuery = $conn->query("SELECT COUNT(*) as active FROM users WHERE $whereClause AND is_active = 1");
        $active = $activeQuery->fetch_assoc()['active'];
        
        echo json_encode([
            'success' => true, 
            'data' => $members,
            'summary' => [
                'total' => $total,
                'male' => $male,
                'female' => $female,
                'active' => $active,
                'inactive' => $total - $active
            ]
        ]);
    }
}

$conn->close();
?>