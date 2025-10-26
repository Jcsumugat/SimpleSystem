<?php
// Prevent any output before JSON response
ob_start();

require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle file upload for excuse letters
function handleExcuseUpload($file, $student_id, $attendance_date)
{
    $upload_dir = 'uploads/excuse_letters/';

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and PDF are allowed.'];
    }

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large. Maximum size is 5MB.'];
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'excuse_' . $student_id . '_' . date('Ymd_His', strtotime($attendance_date)) . '_' . uniqid() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filepath' => $filepath];
    }

    return ['success' => false, 'message' => 'Failed to upload file.'];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Clear any output buffers to ensure clean JSON response
    ob_clean();

    $action = $_POST['action'] ?? '';

    if ($action === 'get_courses') {
        $department_id = (int)$_POST['department_id'];
        $stmt = $conn->prepare("SELECT id, code, name FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY name");
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
        jsonResponse(true, 'Courses found', $courses);
        exit;
    }

    if ($action === 'get_students') {
        $department_id = (int)$_POST['department_id'];
        $course_id = (int)$_POST['course_id'];
        $year_level = sanitizeInput($_POST['year_level']);
        $section_id = (int)$_POST['section_id'];
        $attendance_date = sanitizeInput($_POST['attendance_date']);

        $query = "SELECT 
            s.id,
            s.student_id,
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as full_name,
            s.email,
            a.id as attendance_id,
            a.status,
            a.time_in,
            a.remarks,
            a.excuse_file
            FROM students s
            LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date = ?
            WHERE s.is_active = 1 
            AND s.department_id = ?
            AND s.course_id = ?
            AND s.year_level = ?
            AND s.section_id = ?
            ORDER BY s.lastname, s.firstname";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("siisi", $attendance_date, $department_id, $course_id, $year_level, $section_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        jsonResponse(true, 'Students loaded', $students);
        exit;
    }

    if ($action === 'save_attendance') {
        try {
            $attendance_date = sanitizeInput($_POST['attendance_date']);
            $attendanceData = json_decode($_POST['attendance_data'], true);

            if (empty($attendanceData)) {
                jsonResponse(false, 'No attendance data provided');
                exit;
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                jsonResponse(false, 'Invalid attendance data format');
                exit;
            }

            $conn->begin_transaction();

            $success_count = 0;

            foreach ($attendanceData as $record) {
                $student_id = (int)$record['student_id'];
                $status = sanitizeInput($record['status']);
                $time_in = sanitizeInput($record['time_in']);
                $remarks = sanitizeInput($record['remarks'] ?? '');
                $excuse_file = $record['excuse_file'] ?? null;

                // Validate status
                if (!in_array($status, ['Present', 'Late', 'Absent', 'Excused'])) {
                    continue;
                }

                // If absent, clear time_in
                if ($status === 'Absent') {
                    $time_in = null;
                } elseif (empty($time_in)) {
                    $time_in = date('H:i:s');
                } else {
                    // Ensure time format is correct
                    $time_in = date('H:i:s', strtotime($time_in));
                }

                // Check if attendance already exists
                $checkStmt = $conn->prepare("SELECT id, excuse_file FROM attendance WHERE student_id = ? AND attendance_date = ?");
                $checkStmt->bind_param("is", $student_id, $attendance_date);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();

                if ($existing) {
                    // Update existing record
                    if ($excuse_file) {
                        // Delete old file if exists and new file is uploaded
                        if ($existing['excuse_file'] && file_exists($existing['excuse_file'])) {
                            unlink($existing['excuse_file']);
                        }

                        if ($time_in !== null) {
                            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, remarks = ?, excuse_file = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("ssssi", $status, $time_in, $remarks, $excuse_file, $existing['id']);
                        } else {
                            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = NULL, remarks = ?, excuse_file = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("sssi", $status, $remarks, $excuse_file, $existing['id']);
                        }
                    } else {
                        if ($time_in !== null) {
                            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("sssi", $status, $time_in, $remarks, $existing['id']);
                        } else {
                            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = NULL, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("ssi", $status, $remarks, $existing['id']);
                        }
                    }
                } else {
                    // Insert new record
                    if ($excuse_file) {
                        if ($time_in !== null) {
                            $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks, excuse_file) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("isssss", $student_id, $attendance_date, $status, $time_in, $remarks, $excuse_file);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks, excuse_file) VALUES (?, ?, ?, NULL, ?, ?)");
                            $stmt->bind_param("issss", $student_id, $attendance_date, $status, $remarks, $excuse_file);
                        }
                    } else {
                        if ($time_in !== null) {
                            $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param("issss", $student_id, $attendance_date, $status, $time_in, $remarks);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks) VALUES (?, ?, ?, NULL, ?)");
                            $stmt->bind_param("isss", $student_id, $attendance_date, $status, $remarks);
                        }
                    }
                }

                if ($stmt->execute()) {
                    $success_count++;
                }
            }

            $conn->commit();
            jsonResponse(true, "Attendance saved successfully ({$success_count} records)");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Attendance save error: " . $e->getMessage());
            jsonResponse(false, 'Failed to save attendance. Please try again.');
            exit;
        }
    }

    if ($action === 'upload_excuse') {
        $student_id = (int)$_POST['student_id'];
        $attendance_date = sanitizeInput($_POST['attendance_date']);

        if (!isset($_FILES['excuse_file']) || $_FILES['excuse_file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'Please select a valid file');
            exit;
        }

        $upload_result = handleExcuseUpload($_FILES['excuse_file'], $student_id, $attendance_date);

        if ($upload_result['success']) {
            jsonResponse(true, 'File uploaded successfully', ['filepath' => $upload_result['filepath']]);
        } else {
            jsonResponse(false, $upload_result['message']);
        }
        exit;
    }

    if ($action === 'delete_excuse') {
        $student_id = (int)$_POST['student_id'];
        $attendance_date = sanitizeInput($_POST['attendance_date']);

        // Get the file path
        $stmt = $conn->prepare("SELECT excuse_file FROM attendance WHERE student_id = ? AND attendance_date = ?");
        $stmt->bind_param("is", $student_id, $attendance_date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result && $result['excuse_file']) {
            // Delete the file
            if (file_exists($result['excuse_file'])) {
                unlink($result['excuse_file']);
            }

            // Update database
            $updateStmt = $conn->prepare("UPDATE attendance SET excuse_file = NULL WHERE student_id = ? AND attendance_date = ?");
            $updateStmt->bind_param("is", $student_id, $attendance_date);

            if ($updateStmt->execute()) {
                jsonResponse(true, 'Excuse letter removed successfully');
            } else {
                jsonResponse(false, 'Failed to remove excuse letter');
            }
        } else {
            jsonResponse(false, 'No excuse letter found');
        }
        exit;
    }

    // If action not recognized
    jsonResponse(false, 'Invalid action');
    exit;
}

// Get departments for dropdown
$departments = $conn->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get sections for dropdown
$sections = $conn->query("SELECT id, name FROM sections WHERE is_active = 1 ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance - Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .filter-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .filter-group select option {
            background: var(--card-bg);
            color: var(--text-light);
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .btn-load {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: auto;
        }

        .btn-load:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(74, 144, 226, 0.3);
        }

        .btn-load:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .attendance-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            display: none;
        }

        .attendance-section.active {
            display: block;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(157, 78, 221, 0.2);
        }

        .section-title {
            color: var(--text-light);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-save-all {
            padding: 12px 25px;
            background: linear-gradient(135deg, var(--accent-green), #00a86b);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .btn-save-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 208, 132, 0.3);
        }

        .btn-save-all:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table thead th {
            background: rgba(157, 78, 221, 0.1);
            color: var(--text-secondary);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-table tbody td {
            padding: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-light);
        }

        .attendance-table tbody tr:hover {
            background: rgba(157, 78, 221, 0.05);
        }

        .status-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-status {
            padding: 6px 12px;
            border: 2px solid;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            background: transparent;
        }

        .btn-status.present {
            border-color: var(--success);
            color: var(--success);
        }

        .btn-status.present.active {
            background: var(--success);
            color: white;
        }

        .btn-status.late {
            border-color: var(--warning);
            color: var(--warning);
        }

        .btn-status.late.active {
            background: var(--warning);
            color: white;
        }

        .btn-status.absent {
            border-color: var(--error);
            color: var(--error);
        }

        .btn-status.absent.active {
            background: var(--error);
            color: white;
        }

        .btn-status.excused {
            border-color: #9B59B6;
            color: #9B59B6;
        }

        .btn-status.excused.active {
            background: #9B59B6;
            color: white;
        }

        .time-input {
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            width: 120px;
        }

        .time-input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .remarks-input {
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            width: 100%;
            resize: none;
        }

        .remarks-input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .excuse-upload-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .excuse-upload-btn {
            padding: 8px 12px;
            background: rgba(155, 89, 182, 0.2);
            border: 2px solid #9B59B6;
            border-radius: 8px;
            color: #9B59B6;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .excuse-upload-btn:hover {
            background: rgba(155, 89, 182, 0.3);
        }

        .excuse-file-info {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(155, 89, 182, 0.1);
            border-radius: 8px;
            font-size: 0.85rem;
        }

        .excuse-file-info a {
            color: #9B59B6;
            text-decoration: none;
            font-weight: 600;
        }

        .excuse-file-info a:hover {
            text-decoration: underline;
        }

        .btn-remove-excuse {
            padding: 4px 8px;
            background: rgba(255, 71, 87, 0.2);
            border: 1px solid var(--error);
            border-radius: 6px;
            color: var(--error);
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.3s ease;
        }

        .btn-remove-excuse:hover {
            background: rgba(255, 71, 87, 0.3);
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .bulk-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(157, 78, 221, 0.05);
            border-radius: 10px;
        }

        .btn-bulk {
            padding: 8px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-bulk.all-present {
            background: rgba(0, 208, 132, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .btn-bulk.all-absent {
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 1px solid var(--error);
        }

        .btn-bulk:hover {
            transform: translateY(-2px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            animation: slideIn 0.3s ease;
        }

        .excuse-upload-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: rgba(155, 89, 182, 0.1);
        }

        .excuse-upload-btn:disabled:hover::after {
            content: 'Status must be "Excused" to upload';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--error);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            white-space: nowrap;
            margin-bottom: 5px;
            z-index: 10;
        }

        .excuse-upload-btn:disabled:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: var(--error);
            margin-bottom: -6px;
        }

        .excuse-upload-container {
            position: relative;
        }

        .excuse-file-info.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(157, 78, 221, 0.2);
        }

        .modal-header h3 {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-close-modal {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-close-modal:hover {
            color: var(--error);
        }

        .file-upload-area {
            border: 2px dashed rgba(157, 78, 221, 0.3);
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .file-upload-area:hover {
            border-color: #9B59B6;
            background: rgba(155, 89, 182, 0.05);
        }

        .file-upload-area.dragover {
            border-color: #9B59B6;
            background: rgba(155, 89, 182, 0.1);
        }

        .file-upload-area i {
            font-size: 3rem;
            color: #9B59B6;
            margin-bottom: 15px;
        }

        .file-upload-area p {
            color: var(--text-secondary);
            margin: 5px 0;
        }

        .selected-file {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: rgba(155, 89, 182, 0.1);
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .selected-file i {
            font-size: 1.5rem;
            color: #9B59B6;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-modal {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-modal.cancel {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
        }

        .btn-modal.upload {
            background: linear-gradient(135deg, #9B59B6, #8E44AD);
            color: white;
        }

        .btn-modal:hover {
            transform: translateY(-2px);
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Attendance System</h2>
                <p><?php echo htmlspecialchars($_SESSION['role']); ?></p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item" onclick="location.href='dashboard.php'">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="location.href='students.php'">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                </div>
                <div class="nav-item active">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Mark Attendance</span>
                </div>
                <div class="nav-item" onclick="location.href='records.php'">
                    <i class="fas fa-history"></i>
                    <span>Attendance Records</span>
                </div>
                <div class="nav-item" onclick="location.href='reports.php'">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                </div>
            </nav>

            <div class="sidebar-footer">
                <button class="logout-btn" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </aside>

        <main class="main-content">
            <div class="header">
                <h1 class="page-title">Mark Attendance</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <div id="message-container"></div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 style="color: var(--text-light); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-filter"></i>
                    Select Class
                </h3>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Attendance Date *</label>
                        <input type="date" id="attendance_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Department *</label>
                        <select id="department_id" onchange="loadCourses()">
                            <option value="">Select Department</option>
                            <?php while ($dept = $departments->fetch_assoc()): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Course *</label>
                        <select id="course_id" disabled>
                            <option value="">Select Department First</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year Level *</label>
                        <select id="year_level">
                            <option value="">Select Year</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Section *</label>
                        <select id="section_id">
                            <option value="">Select Section</option>
                            <?php
                            $sections->data_seek(0);
                            while ($section = $sections->fetch_assoc()):
                            ?>
                                <option value="<?php echo $section['id']; ?>">
                                    <?php echo htmlspecialchars($section['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <button class="btn-load" onclick="loadStudents()">
                            <i class="fas fa-download"></i>
                            Load Students
                        </button>
                    </div>
                </div>
            </div>

            <!-- Attendance Section -->
            <div id="attendanceSection" class="attendance-section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-clipboard-list"></i>
                        <span id="classInfo">Students List</span>
                    </div>
                    <button class="btn-save-all" onclick="saveAllAttendance()">
                        <i class="fas fa-save"></i>
                        Save Attendance
                    </button>
                </div>

                <div class="bulk-actions">
                    <button class="btn-bulk all-present" onclick="markAllStatus('Present')">
                        <i class="fas fa-check-circle"></i>
                        Mark All Present
                    </button>
                    <button class="btn-bulk all-absent" onclick="markAllStatus('Absent')">
                        <i class="fas fa-times-circle"></i>
                        Mark All Absent
                    </button>
                </div>

                <div id="attendanceTableContainer"></div>
            </div>
        </main>
    </div>

    <!-- Excuse Letter Upload Modal -->
    <div id="excuseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-upload"></i> Upload Excuse Letter</h3>
                <button class="btn-close-modal" onclick="closeExcuseModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('excuseFileInput').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <p><strong>Click to upload</strong> or drag and drop</p>
                <p style="font-size: 0.85rem;">JPG, PNG or PDF (Max 5MB)</p>
            </div>

            <input type="file" id="excuseFileInput" accept=".jpg,.jpeg,.png,.pdf" style="display: none;" onchange="handleFileSelect(this)">

            <div id="selectedFileInfo" style="display: none;"></div>

            <div class="modal-actions">
                <button class="btn-modal cancel" onclick="closeExcuseModal()">Cancel</button>
                <button class="btn-modal upload" id="uploadBtn" onclick="uploadExcuseLetter()" disabled>
                    <i class="fas fa-upload"></i> Upload
                </button>
            </div>
        </div>
    </div>

    <script>
        let studentsData = [];
        let isSaving = false;
        let currentUploadStudent = null;
        let selectedFile = null;

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        function showMessage(type, message) {
            const container = document.getElementById('message-container');
            const messageBox = document.createElement('div');
            messageBox.className = `message-box ${type}`;
            messageBox.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;

            container.innerHTML = '';
            container.appendChild(messageBox);

            setTimeout(() => {
                messageBox.style.opacity = '0';
                setTimeout(() => messageBox.remove(), 300);
            }, 3000);
        }

        function loadCourses() {
            const departmentId = document.getElementById('department_id').value;
            const courseSelect = document.getElementById('course_id');

            if (!departmentId) {
                courseSelect.innerHTML = '<option value="">Select Department First</option>';
                courseSelect.disabled = true;
                return;
            }

            courseSelect.disabled = false;
            courseSelect.innerHTML = '<option value="">Loading...</option>';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_courses');
            formData.append('department_id', departmentId);

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        courseSelect.innerHTML = '<option value="">Select Course</option>';
                        data.data.forEach(course => {
                            const option = document.createElement('option');
                            option.value = course.id;
                            option.textContent = `${course.code} - ${course.name}`;
                            courseSelect.appendChild(option);
                        });
                    } else {
                        courseSelect.innerHTML = '<option value="">No courses found</option>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    courseSelect.innerHTML = '<option value="">Error loading courses</option>';
                });
        }

        function loadStudents() {
            const attendance_date = document.getElementById('attendance_date').value;
            const department_id = document.getElementById('department_id').value;
            const course_id = document.getElementById('course_id').value;
            const year_level = document.getElementById('year_level').value;
            const section_id = document.getElementById('section_id').value;

            if (!attendance_date || !department_id || !course_id || !year_level || !section_id) {
                showMessage('error', 'Please fill in all required fields');
                return;
            }

            const container = document.getElementById('attendanceTableContainer');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Loading students...</p></div>';

            document.getElementById('attendanceSection').classList.add('active');

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_students');
            formData.append('attendance_date', attendance_date);
            formData.append('department_id', department_id);
            formData.append('course_id', course_id);
            formData.append('year_level', year_level);
            formData.append('section_id', section_id);

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        studentsData = data.data;

                        if (studentsData.length === 0) {
                            container.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-users-slash"></i>
                                <h3>No Students Found</h3>
                                <p>No students found for the selected class</p>
                            </div>
                        `;
                            return;
                        }

                        renderAttendanceTable();

                        const deptText = document.getElementById('department_id').selectedOptions[0].text.split(' - ')[0];
                        const courseText = document.getElementById('course_id').selectedOptions[0].text.split(' - ')[0];
                        document.getElementById('classInfo').textContent =
                            `${deptText} - ${courseText} - ${year_level} - Section ${document.getElementById('section_id').selectedOptions[0].text}`;

                        showMessage('success', `Loaded ${studentsData.length} students`);
                    } else {
                        container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Error</h3>
                            <p>${data.message}</p>
                        </div>
                    `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error</h3>
                        <p>Failed to load students</p>
                    </div>
                `;
                });
        }

        function renderAttendanceTable() {
            const container = document.getElementById('attendanceTableContainer');
            const currentTime = new Date().toTimeString().slice(0, 5);

            let html = `
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th style="width: 4%">#</th>
                            <th style="width: 10%">Student ID</th>
                            <th style="width: 20%">Full Name</th>
                            <th style="width: 25%">Status</th>
                            <th style="width: 11%">Time In</th>
                            <th style="width: 15%">Remarks</th>
                            <th style="width: 15%">Excuse Letter</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            studentsData.forEach((student, index) => {
                const status = student.status || 'Present';
                const time_in = student.time_in || currentTime;
                const remarks = student.remarks || '';
                const excuse_file = student.excuse_file;

                html += `
                    <tr data-student-id="${student.id}">
                        <td>${index + 1}</td>
                        <td>${student.student_id}</td>
                        <td>${student.full_name}</td>
                        <td>
                            <div class="status-buttons">
                                <button class="btn-status present ${status === 'Present' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Present')">
                                    <i class="fas fa-check"></i> Present
                                </button>
                                <button class="btn-status late ${status === 'Late' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Late')">
                                    <i class="fas fa-clock"></i> Late
                                </button>
                                <button class="btn-status absent ${status === 'Absent' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Absent')">
                                    <i class="fas fa-times"></i> Absent
                                </button>
                                <button class="btn-status excused ${status === 'Excused' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Excused')">
                                    <i class="fas fa-file-medical"></i> Excused
                                </button>
                            </div>
                        </td>
                        <td>
                            <input type="time" class="time-input" 
                                   data-student-id="${student.id}"
                                   value="${time_in}"
                                   ${status === 'Absent' ? 'disabled' : ''}>
                        </td>
                        <td>
                            <input type="text" class="remarks-input" 
                                   data-student-id="${student.id}"
                                   value="${remarks}"
                                   placeholder="Optional remarks...">
                        </td>
                        <td>
                            <div class="excuse-upload-container" data-student-id="${student.id}">
                `;

                if (excuse_file) {
                    const fileName = excuse_file.split('/').pop();
                    const isDisabled = status !== 'Excused' ? 'disabled' : '';
                    html += `
                                <div class="excuse-file-info ${isDisabled}">
                                    <i class="fas fa-file-alt"></i>
                                    <a href="${excuse_file}" target="_blank">View File</a>
                                    <button class="btn-remove-excuse" onclick="removeExcuse(${student.id})" ${isDisabled ? 'disabled' : ''}>
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
    `;
                } else {
                    const isDisabled = status !== 'Excused' ? 'disabled' : '';
                    html += `
                                <button class="excuse-upload-btn" onclick="openExcuseModal(${student.id})" ${isDisabled}>
                                    <i class="fas fa-upload"></i> Upload Excuse
                                </button>
    `;
                }

                html += `
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            container.innerHTML = html;
        }

        function setStatus(studentId, status) {
            const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
            const buttons = row.querySelectorAll('.btn-status');
            const timeInput = row.querySelector('.time-input');

            buttons.forEach(btn => btn.classList.remove('active'));
            row.querySelector(`.btn-status.${status.toLowerCase()}`).classList.add('active');

            if (status === 'Absent') {
                timeInput.value = '';
                timeInput.disabled = true;
            } else {
                timeInput.disabled = false;
                if (!timeInput.value) {
                    timeInput.value = new Date().toTimeString().slice(0, 5);
                }
            }

            const studentIndex = studentsData.findIndex(s => s.id == studentId);
            if (studentIndex !== -1) {
                studentsData[studentIndex].status = status;
            }

            // Enable/disable excuse upload based on status
            const excuseContainer = row.querySelector('.excuse-upload-container');
            const excuseBtn = excuseContainer.querySelector('.excuse-upload-btn');
            const excuseInfo = excuseContainer.querySelector('.excuse-file-info');
            const removeBtn = excuseContainer.querySelector('.btn-remove-excuse');

            if (status === 'Excused') {
                if (excuseBtn) excuseBtn.disabled = false;
                if (excuseInfo) excuseInfo.classList.remove('disabled');
                if (removeBtn) removeBtn.disabled = false;
            } else {
                if (excuseBtn) excuseBtn.disabled = true;
                if (excuseInfo) excuseInfo.classList.add('disabled');
                if (removeBtn) removeBtn.disabled = true;
            }
        }

        function markAllStatus(status) {
            studentsData.forEach(student => {
                setStatus(student.id, status);
            });
            showMessage('success', `All students marked as ${status}`);
        }

        function openExcuseModal(studentId) {
            // Check if status is "Excused"
            const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
            const statusBtn = row.querySelector('.btn-status.active');
            const status = statusBtn ? statusBtn.textContent.trim().replace(/\s+/g, ' ').split(' ').pop() : 'Present';

            if (status !== 'Excused') {
                showMessage('error', 'Please set status to "Excused" first before uploading excuse letter');
                return;
            }

            currentUploadStudent = studentId;
            selectedFile = null;
            document.getElementById('excuseFileInput').value = '';
            document.getElementById('selectedFileInfo').style.display = 'none';
            document.getElementById('uploadBtn').disabled = true;
            document.getElementById('excuseModal').classList.add('active');
        }

        function closeExcuseModal() {
            document.getElementById('excuseModal').classList.remove('active');
            currentUploadStudent = null;
            selectedFile = null;
        }

        function handleFileSelect(input) {
            const file = input.files[0];
            if (!file) return;

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                showMessage('error', 'Invalid file type. Only JPG, PNG, and PDF are allowed.');
                return;
            }

            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                showMessage('error', 'File too large. Maximum size is 5MB.');
                return;
            }

            selectedFile = file;

            // Show selected file info
            const fileInfo = document.getElementById('selectedFileInfo');
            const fileIcon = file.type === 'application/pdf' ? 'file-pdf' : 'file-image';
            const fileSize = (file.size / 1024).toFixed(2);

            fileInfo.innerHTML = `
                <div class="selected-file">
                    <i class="fas fa-${fileIcon}"></i>
                    <div style="flex: 1;">
                        <strong>${file.name}</strong>
                        <p style="color: var(--text-secondary); font-size: 0.85rem; margin: 0;">${fileSize} KB</p>
                    </div>
                </div>
            `;
            fileInfo.style.display = 'block';
            document.getElementById('uploadBtn').disabled = false;
        }

        // Drag and drop functionality
        const fileUploadArea = document.getElementById('fileUploadArea');

        fileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUploadArea.classList.add('dragover');
        });

        fileUploadArea.addEventListener('dragleave', () => {
            fileUploadArea.classList.remove('dragover');
        });

        fileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');

            const file = e.dataTransfer.files[0];
            if (file) {
                const input = document.getElementById('excuseFileInput');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                input.files = dataTransfer.files;
                handleFileSelect(input);
            }
        });

        function uploadExcuseLetter() {
            if (!selectedFile || !currentUploadStudent) return;

            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'upload_excuse');
            formData.append('student_id', currentUploadStudent);
            formData.append('attendance_date', document.getElementById('attendance_date').value);
            formData.append('excuse_file', selectedFile);

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', 'Excuse letter uploaded successfully');

                        // Update student data
                        const studentIndex = studentsData.findIndex(s => s.id == currentUploadStudent);
                        if (studentIndex !== -1) {
                            studentsData[studentIndex].excuse_file = data.data.filepath;
                        }

                        // Update UI
                        const container = document.querySelector(`.excuse-upload-container[data-student-id="${currentUploadStudent}"]`);
                        const fileName = data.data.filepath.split('/').pop();
                        container.innerHTML = `
                        <div class="excuse-file-info">
                            <i class="fas fa-file-alt"></i>
                            <a href="${data.data.filepath}" target="_blank">View File</a>
                            <button class="btn-remove-excuse" onclick="removeExcuse(${currentUploadStudent})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;

                        closeExcuseModal();
                    } else {
                        showMessage('error', data.message);
                        uploadBtn.disabled = false;
                        uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to upload excuse letter');
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                });
        }

        function removeExcuse(studentId) {
            if (!confirm('Are you sure you want to remove this excuse letter?')) return;

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'delete_excuse');
            formData.append('student_id', studentId);
            formData.append('attendance_date', document.getElementById('attendance_date').value);

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', 'Excuse letter removed successfully');

                        // Update student data
                        const studentIndex = studentsData.findIndex(s => s.id == studentId);
                        if (studentIndex !== -1) {
                            studentsData[studentIndex].excuse_file = null;
                        }

                        // Update UI
                        const container = document.querySelector(`.excuse-upload-container[data-student-id="${studentId}"]`);
                        container.innerHTML = `
                        <button class="excuse-upload-btn" onclick="openExcuseModal(${studentId})">
                            <i class="fas fa-upload"></i> Upload Excuse
                        </button>
                    `;
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to remove excuse letter');
                });
        }

        function saveAllAttendance() {
            if (isSaving) {
                return;
            }

            const attendance_date = document.getElementById('attendance_date').value;
            const attendanceData = [];

            studentsData.forEach(student => {
                const row = document.querySelector(`tr[data-student-id="${student.id}"]`);
                if (!row) return;

                const statusBtn = row.querySelector('.btn-status.active');
                const status = statusBtn ? statusBtn.textContent.trim().replace(/\s+/g, ' ').split(' ').pop() : 'Present';
                const timeInput = row.querySelector('.time-input');
                const time_in = timeInput.disabled ? '' : timeInput.value;
                const remarks = row.querySelector('.remarks-input').value;

                attendanceData.push({
                    student_id: student.id,
                    status: status,
                    time_in: time_in,
                    remarks: remarks,
                    excuse_file: student.excuse_file || null
                });
            });

            if (attendanceData.length === 0) {
                showMessage('error', 'No attendance data to save');
                return;
            }

            isSaving = true;
            const saveBtn = document.querySelector('.btn-save-all');
            const originalContent = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'save_attendance');
            formData.append('attendance_date', attendance_date);
            formData.append('attendance_data', JSON.stringify(attendanceData));

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    showMessage(data.success ? 'success' : 'error', data.message);
                    if (data.success) {
                        setTimeout(() => loadStudents(), 1500);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to save attendance');
                })
                .finally(() => {
                    isSaving = false;
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalContent;
                });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('excuseModal');
            if (event.target === modal) {
                closeExcuseModal();
            }
        }
    </script>
</body>

</html>