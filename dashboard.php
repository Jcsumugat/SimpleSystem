<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// Get user info
$userQuery = $conn->prepare("SELECT fullname, email, role FROM users WHERE id = ?");
$userQuery->bind_param("i", $user_id);
$userQuery->execute();
$result = $userQuery->get_result();
$user = $result->fetch_assoc();

// Initialize session filters if not set
if (!isset($_SESSION['dashboard_filters'])) {
    $_SESSION['dashboard_filters'] = [
        'department' => '',
        'course' => '',
        'year' => '',
        'section' => '',
        'date_from' => date('Y-m-01'),
        'date_to' => date('Y-m-d')
    ];
}

// Update filters from POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_filters'])) {
    $_SESSION['dashboard_filters'] = [
        'department' => $_POST['filter_department'] ?? '',
        'course' => $_POST['filter_course'] ?? '',
        'year' => $_POST['filter_year'] ?? '',
        'section' => $_POST['filter_section'] ?? '',
        'date_from' => $_POST['filter_date_from'] ?? date('Y-m-01'),
        'date_to' => $_POST['filter_date_to'] ?? date('Y-m-d')
    ];
}

// Handle AJAX request for courses
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
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
}

$filters = $_SESSION['dashboard_filters'];

// Build WHERE conditions for statistics
$whereConditions = ["s.is_active = 1"];
$params = [];
$types = "";

if (!empty($filters['department'])) {
    $whereConditions[] = "s.department_id = ?";
    $params[] = $filters['department'];
    $types .= "i";
}

if (!empty($filters['course'])) {
    $whereConditions[] = "s.course_id = ?";
    $params[] = $filters['course'];
    $types .= "i";
}

if (!empty($filters['year'])) {
    $whereConditions[] = "s.year_level = ?";
    $params[] = $filters['year'];
    $types .= "s";
}

if (!empty($filters['section'])) {
    $whereConditions[] = "s.section_id = ?";
    $params[] = $filters['section'];
    $types .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

// Get filtered total students
$studentQuery = "SELECT COUNT(*) as total FROM students s WHERE $whereClause";
if (!empty($params)) {
    $stmt = $conn->prepare($studentQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalStudents = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $totalStudents = $conn->query($studentQuery)->fetch_assoc()['total'];
}

// Get today's attendance with filters
$today = date('Y-m-d');
$todayQuery = "SELECT COUNT(DISTINCT a.student_id) as present 
    FROM attendance a 
    JOIN students s ON a.student_id = s.id 
    WHERE a.attendance_date = ? AND a.status IN ('Present', 'Late') AND $whereClause";

$todayParams = array_merge([$today], $params);
$todayTypes = "s" . $types;

$stmt = $conn->prepare($todayQuery);
if (!empty($todayParams)) {
    $stmt->bind_param($todayTypes, ...$todayParams);
}
$stmt->execute();
$todayPresent = $stmt->get_result()->fetch_assoc()['present'];

$todayAbsent = $totalStudents - $todayPresent;

// Get attendance rate for date range with filters
$attendanceRateQuery = "SELECT 
    COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) as present_count,
    COUNT(*) as total_count
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.attendance_date BETWEEN ? AND ? AND $whereClause";

$rateParams = array_merge([$filters['date_from'], $filters['date_to']], $params);
$rateTypes = "ss" . $types;

$stmt = $conn->prepare($attendanceRateQuery);
$stmt->bind_param($rateTypes, ...$rateParams);
$stmt->execute();
$monthAttendance = $stmt->get_result()->fetch_assoc();

$attendanceRate = $monthAttendance['total_count'] > 0
    ? round(($monthAttendance['present_count'] / $monthAttendance['total_count']) * 100, 1)
    : 0;

// Get recent attendance (last 7 days) with filters
$chartQuery = "SELECT 
    DATE_FORMAT(a.attendance_date, '%b %d') as date_label,
    COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) as present,
    COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND $whereClause
    GROUP BY a.attendance_date
    ORDER BY a.attendance_date ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($chartQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $recentQuery = $stmt->get_result();
} else {
    $recentQuery = $conn->query($chartQuery);
}

$chartLabels = [];
$presentData = [];
$absentData = [];

while ($row = $recentQuery->fetch_assoc()) {
    $chartLabels[] = $row['date_label'];
    $presentData[] = (int)$row['present'];
    $absentData[] = (int)$row['absent'];
}

// Get latest attendance records with filters
$latestQuery = "SELECT 
    a.id,
    s.student_id,
    CONCAT(s.firstname, ' ', IFNULL(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
    c.code as course_code,
    s.year_level,
    a.attendance_date,
    a.time_in,
    a.status
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN courses c ON s.course_id = c.id
    WHERE $whereClause
    ORDER BY a.created_at DESC
    LIMIT 10";

if (!empty($params)) {
    $stmt = $conn->prepare($latestQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $latestRecords = $stmt->get_result();
} else {
    $latestRecords = $conn->query($latestQuery);
}

// Get departments for dropdown
$departments = $conn->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get sections for dropdown
$sections = $conn->query("SELECT id, name FROM sections WHERE is_active = 1 ORDER BY name");

// Get courses for the selected department filter
$filter_courses = null;
if (!empty($filters['department'])) {
    $stmt = $conn->prepare("SELECT id, code, name FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY name");
    $stmt->bind_param("i", $filters['department']);
    $stmt->execute();
    $filter_courses = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Student Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        .filter-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .filter-header h3 {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .filter-toggle {
            background: rgba(157, 78, 221, 0.1);
            border: 1px solid rgba(157, 78, 221, 0.3);
            color: var(--text-light);
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .filter-toggle:hover {
            background: rgba(157, 78, 221, 0.2);
        }

        .filter-content {
            display: none;
        }

        .filter-content.active {
            display: block;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
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
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
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

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        .btn-apply-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-apply-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(74, 144, 226, 0.3);
        }

        .btn-clear-filter {
            padding: 10px 20px;
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 2px solid var(--error);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-clear-filter:hover {
            background: var(--error);
            color: white;
        }

        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: rgba(74, 144, 226, 0.1);
            border: 1px solid var(--primary-blue);
            border-radius: 6px;
            color: var(--primary-blue);
            font-size: 0.85rem;
            font-weight: 500;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Attendance System</h2>
                <p><?php echo htmlspecialchars($user['role']); ?></p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item active">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="location.href='students.php'">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                </div>
                <div class="nav-item" onclick="location.href='attendance.php'">
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
                <h1 class="page-title">Dashboard</h1>
                <div class="header-actions">
                    <div class="date-display">
                        <i class="fas fa-calendar-day"></i>
                        <span><?php echo date('l, F d, Y'); ?></span>
                    </div>
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($user['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($user['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <div id="message-container"></div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>
                        <i class="fas fa-filter"></i>
                        Filters
                    </h3>
                    <button class="filter-toggle" onclick="toggleFilters()">
                        <i class="fas fa-chevron-down" id="filterIcon"></i>
                        <span id="filterToggleText">Show Filters</span>
                    </button>
                </div>

                <?php if (!empty($filters['department']) || !empty($filters['course']) || !empty($filters['year']) || !empty($filters['section'])): ?>
                <div class="active-filters">
                    <span style="color: var(--text-secondary); font-size: 0.85rem;">Active Filters:</span>
                    <?php if (!empty($filters['department'])): 
                        $deptStmt = $conn->prepare("SELECT code, name FROM departments WHERE id = ?");
                        $deptStmt->bind_param("i", $filters['department']);
                        $deptStmt->execute();
                        $deptInfo = $deptStmt->get_result()->fetch_assoc();
                    ?>
                        <span class="filter-badge">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($deptInfo['code']); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($filters['course'])): 
                        $courseStmt = $conn->prepare("SELECT code FROM courses WHERE id = ?");
                        $courseStmt->bind_param("i", $filters['course']);
                        $courseStmt->execute();
                        $courseInfo = $courseStmt->get_result()->fetch_assoc();
                    ?>
                        <span class="filter-badge">
                            <i class="fas fa-book"></i>
                            <?php echo htmlspecialchars($courseInfo['code']); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($filters['year'])): ?>
                        <span class="filter-badge">
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars($filters['year']); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($filters['section'])): 
                        $secStmt = $conn->prepare("SELECT name FROM sections WHERE id = ?");
                        $secStmt->bind_param("i", $filters['section']);
                        $secStmt->execute();
                        $secInfo = $secStmt->get_result()->fetch_assoc();
                    ?>
                        <span class="filter-badge">
                            <i class="fas fa-users"></i>
                            Section <?php echo htmlspecialchars($secInfo['name']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="filterForm" class="filter-content">
                    <input type="hidden" name="update_filters" value="1">
                    
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Date From</label>
                            <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                        </div>

                        <div class="filter-group">
                            <label>Date To</label>
                            <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="filter-group">
                            <label>Department</label>
                            <select id="filter_department" name="filter_department" onchange="loadFilterCourses()">
                                <option value="">All Departments</option>
                                <?php
                                $departments->data_seek(0);
                                while ($dept = $departments->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($filters['department'] == $dept['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Course</label>
                            <select id="filter_course" name="filter_course">
                                <option value="">All Courses</option>
                                <?php
                                if ($filter_courses && $filter_courses->num_rows > 0):
                                    while ($course = $filter_courses->fetch_assoc()):
                                ?>
                                        <option value="<?php echo $course['id']; ?>" <?php echo ($filters['course'] == $course['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name']); ?>
                                        </option>
                                <?php
                                    endwhile;
                                endif;
                                ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Year Level</label>
                            <select id="filter_year" name="filter_year">
                                <option value="">All Years</option>
                                <option value="1st Year" <?php echo ($filters['year'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                                <option value="2nd Year" <?php echo ($filters['year'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                                <option value="3rd Year" <?php echo ($filters['year'] == '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
                                <option value="4th Year" <?php echo ($filters['year'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Section</label>
                            <select id="filter_section" name="filter_section">
                                <option value="">All Sections</option>
                                <?php
                                $sections->data_seek(0);
                                while ($section = $sections->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $section['id']; ?>" <?php echo ($filters['section'] == $section['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-apply-filter">
                            <i class="fas fa-check"></i>
                            Apply Filters
                        </button>
                        <button type="button" class="btn-clear-filter" onclick="clearFilters()">
                            <i class="fas fa-times"></i>
                            Clear Filters
                        </button>
                    </div>
                </form>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Students</div>
                        <div class="stat-card-icon" style="color: var(--primary-blue);">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($totalStudents); ?></div>
                    <div class="stat-card-change">
                        <i class="fas fa-users"></i> <?php echo (!empty($filters['department']) || !empty($filters['course']) || !empty($filters['year']) || !empty($filters['section'])) ? 'Filtered students' : 'Active students'; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Present Today</div>
                        <div class="stat-card-icon" style="color: var(--success);">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($todayPresent); ?></div>
                    <div class="stat-card-change">
                        <i class="fas fa-calendar-day"></i> <?php echo date('F d, Y'); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Absent Today</div>
                        <div class="stat-card-icon" style="color: var(--error);">
                            <i class="fas fa-user-times"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($todayAbsent); ?></div>
                    <div class="stat-card-change">
                        <?php if ($todayAbsent > 0): ?>
                            <i class="fas fa-exclamation-triangle"></i> Needs attention
                        <?php else: ?>
                            <i class="fas fa-check-circle"></i> All present
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Attendance Rate</div>
                        <div class="stat-card-icon" style="color: var(--accent-purple);">
                            <i class="fas fa-percentage"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $attendanceRate; ?>%</div>
                    <div class="stat-card-change">
                        <i class="fas fa-calendar-alt"></i> <?php echo date('M d', strtotime($filters['date_from'])) . ' - ' . date('M d', strtotime($filters['date_to'])); ?>
                    </div>
                </div>
            </div>

            <div class="chart-container">
                <h3 style="margin-bottom: 20px; color: var(--text-light); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-chart-area" style="color: var(--primary-blue);"></i>
                    7-Day Attendance Trend
                </h3>
                <?php if (count($chartLabels) > 0): ?>
                    <canvas id="attendanceChart" height="80"></canvas>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        <i class="fas fa-chart-line" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                        <p>No attendance data available for the last 7 days</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-container" style="margin-top: 30px;">
                <h3 style="margin-bottom: 20px; color: var(--text-light); display: flex; align-items: center; gap: 10px; padding: 0 20px;">
                    <i class="fas fa-clock" style="color: var(--accent-green);"></i>
                    Recent Attendance Records
                </h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Year</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($latestRecords->num_rows > 0): ?>
                            <?php while ($record = $latestRecords->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['course_code'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($record['year_level']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                    <td><?php echo $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : 'N/A'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                            <?php echo htmlspecialchars($record['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h3>No Attendance Records</h3>
                                        <p>No attendance has been recorded yet with the current filters</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
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

function toggleFilters() {
    const filterContent = document.querySelector('.filter-content');
    const filterIcon = document.getElementById('filterIcon');
    const filterToggleText = document.getElementById('filterToggleText');

    filterContent.classList.toggle('active');

    if (filterContent.classList.contains('active')) {
        filterIcon.classList.remove('fa-chevron-down');
        filterIcon.classList.add('fa-chevron-up');
        filterToggleText.textContent = 'Hide Filters';
    } else {
        filterIcon.classList.remove('fa-chevron-up');
        filterIcon.classList.add('fa-chevron-down');
        filterToggleText.textContent = 'Show Filters';
    }
}

function loadFilterCourses() {
    const departmentSelect = document.getElementById('filter_department');
    const courseSelect = document.getElementById('filter_course');
    const departmentId = departmentSelect.value;

    courseSelect.innerHTML = '<option value="">All Courses</option>';

    if (departmentId) {
        courseSelect.disabled = true;

        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'get_courses');
        formData.append('department_id', departmentId);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                data.data.forEach(course => {
                    const option = document.createElement('option');
                    option.value = course.id;
                    option.textContent = course.code + ' - ' + course.name;
                    courseSelect.appendChild(option);
                });
            }
            courseSelect.disabled = false;
        })
        .catch(error => {
            console.error('Error loading courses:', error);
            courseSelect.disabled = false;
            showMessage('error', 'Failed to load courses');
        });
    }
}

function clearFilters() {
    document.getElementById('filter_department').value = '';
    document.getElementById('filter_course').value = '';
    document.getElementById('filter_year').value = '';
    document.getElementById('filter_section').value = '';
    
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    const formattedFirstDay = firstDay.toISOString().split('T')[0];
    const formattedToday = today.toISOString().split('T')[0];
    
    document.getElementById('filter_date_from').value = formattedFirstDay;
    document.getElementById('filter_date_to').value = formattedToday;

    document.getElementById('filterForm').submit();
}

window.addEventListener('DOMContentLoaded', function() {
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const presentData = <?php echo json_encode($presentData); ?>;
    const absentData = <?php echo json_encode($absentData); ?>;

    if (chartLabels.length > 0) {
        const ctx = document.getElementById('attendanceChart').getContext('2d');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Present',
                    data: presentData,
                    backgroundColor: 'rgba(0, 255, 136, 0.2)',
                    borderColor: '#00FF88',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointBackgroundColor: '#00FF88',
                    pointBorderColor: '#00FF88',
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#00FF88',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
                }, {
                    label: 'Absent',
                    data: absentData,
                    backgroundColor: 'rgba(255, 71, 87, 0.2)',
                    borderColor: '#FF4757',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointBackgroundColor: '#FF4757',
                    pointBorderColor: '#FF4757',
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#FF4757',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: '#E0E0E0',
                            font: {
                                size: 13,
                                family: 'Poppins'
                            },
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(30, 30, 40, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: 'rgba(157, 78, 221, 0.5)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + ' student(s)';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: 'rgba(255, 255, 255, 0.7)',
                            font: {
                                size: 12,
                                family: 'Poppins'
                            }
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)',
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)',
                            font: {
                                size: 12,
                                family: 'Poppins'
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
});
    </script>
</body>

</html>
<?php
$conn->close();
?>