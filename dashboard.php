<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// Get user info
$userQuery = $conn->prepare("SELECT fullname, email, role FROM users WHERE id = ?");
$userQuery->bind_param("i", $user_id);
$userQuery->execute();
$user = $userQuery->fetch_assoc();

// Get statistics
$totalStudents = $conn->query("SELECT COUNT(*) as total FROM students WHERE is_active = 1")->fetch_assoc()['total'];

$today = date('Y-m-d');
$todayPresent = $conn->query("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = '$today' AND status IN ('Present', 'Late')")->fetch_assoc()['present'];

$todayAbsent = $totalStudents - $todayPresent;

// Get attendance rate for current month
$currentMonth = date('Y-m');
$monthAttendance = $conn->query("SELECT 
    COUNT(CASE WHEN status IN ('Present', 'Late') THEN 1 END) as present_count,
    COUNT(*) as total_count
    FROM attendance 
    WHERE DATE_FORMAT(attendance_date, '%Y-%m') = '$currentMonth'")->fetch_assoc();

$attendanceRate = $monthAttendance['total_count'] > 0 
    ? round(($monthAttendance['present_count'] / $monthAttendance['total_count']) * 100, 1) 
    : 0;

// Get recent attendance (last 7 days)
$recentQuery = $conn->query("SELECT 
    DATE_FORMAT(attendance_date, '%b %d') as date_label,
    COUNT(CASE WHEN status IN ('Present', 'Late') THEN 1 END) as present,
    COUNT(CASE WHEN status = 'Absent' THEN 1 END) as absent
    FROM attendance 
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY attendance_date
    ORDER BY attendance_date ASC");

$chartLabels = [];
$presentData = [];
$absentData = [];

while ($row = $recentQuery->fetch_assoc()) {
    $chartLabels[] = $row['date_label'];
    $presentData[] = $row['present'];
    $absentData[] = $row['absent'];
}

// Get latest attendance records
$latestRecords = $conn->query("SELECT 
    a.id,
    s.student_id,
    CONCAT(s.firstname, ' ', s.lastname) as student_name,
    s.course,
    s.year_level,
    a.attendance_date,
    a.time_in,
    a.status
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    ORDER BY a.created_at DESC
    LIMIT 10");

$conn->close();
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
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Attendance System</h2>
                <p><?php echo htmlspecialchars($user['role']); ?></p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item active" onclick="showPage('dashboard')">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="showPage('students')">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                </div>
                <div class="nav-item" onclick="showPage('attendance')">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Mark Attendance</span>
                </div>
                <div class="nav-item" onclick="showPage('records')">
                    <i class="fas fa-history"></i>
                    <span>Attendance Records</span>
                </div>
                <div class="nav-item" onclick="showPage('reports')">
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
                <h1 class="page-title" id="page-title">Dashboard</h1>
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

            <!-- Dashboard Page -->
            <div id="dashboard" class="page active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Total Students</div>
                            <div class="stat-card-icon" style="color: var(--primary-blue);">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                        </div>
                        <div class="stat-card-value"><?php echo $totalStudents; ?></div>
                        <div class="stat-card-change">
                            <i class="fas fa-users"></i> Active students
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Present Today</div>
                            <div class="stat-card-icon" style="color: var(--success);">
                                <i class="fas fa-user-check"></i>
                            </div>
                        </div>
                        <div class="stat-card-value"><?php echo $todayPresent; ?></div>
                        <div class="stat-card-change">
                            <i class="fas fa-arrow-up"></i> <?php echo date('F d, Y'); ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Absent Today</div>
                            <div class="stat-card-icon" style="color: var(--error);">
                                <i class="fas fa-user-times"></i>
                            </div>
                        </div>
                        <div class="stat-card-value"><?php echo $todayAbsent; ?></div>
                        <div class="stat-card-change">
                            <i class="fas fa-exclamation-triangle"></i> Needs attention
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
                            <i class="fas fa-calendar-alt"></i> This month
                        </div>
                    </div>
                </div>

                <div class="chart-container">
                    <h3 style="margin-bottom: 20px; color: var(--text-light); display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-chart-area" style="color: var(--primary-blue);"></i>
                        7-Day Attendance Trend
                    </h3>
                    <canvas id="attendanceChart" height="80"></canvas>
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
                                        <td><?php echo htmlspecialchars($record['course']); ?></td>
                                        <td><?php echo htmlspecialchars($record['year_level']); ?></td>
                                        <td><?php echo formatDate($record['attendance_date']); ?></td>
                                        <td><?php echo formatTime($record['time_in']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center;">No attendance records yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Students Page -->
            <div id="students" class="page">
                <div style="text-align: center; padding: 50px; color: var(--text-secondary);">
                    <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 20px;"></i>
                    <h3>Students Management</h3>
                    <p>This page will contain student list and management features</p>
                </div>
            </div>

            <!-- Attendance Page -->
            <div id="attendance" class="page">
                <div style="text-align: center; padding: 50px; color: var(--text-secondary);">
                    <i class="fas fa-clipboard-check" style="font-size: 3rem; margin-bottom: 20px;"></i>
                    <h3>Mark Attendance</h3>
                    <p>This page will contain attendance marking interface</p>
                </div>
            </div>

            <!-- Records Page -->
            <div id="records" class="page">
                <div style="text-align: center; padding: 50px; color: var(--text-secondary);">
                    <i class="fas fa-history" style="font-size: 3rem; margin-bottom: 20px;"></i>
                    <h3>Attendance Records</h3>
                    <p>This page will contain all attendance records</p>
                </div>
            </div>

            <!-- Reports Page -->
            <div id="reports" class="page">
                <div style="text-align: center; padding: 50px; color: var(--text-secondary);">
                    <i class="fas fa-file-alt" style="font-size: 3rem; margin-bottom: 20px;"></i>
                    <h3>Reports</h3>
                    <p>This page will contain reports and analytics</p>
                </div>
            </div>
        </main>
    </div>

    <script>
        function showPage(pageName) {
            document.querySelectorAll('.page').forEach(page => {
                page.classList.remove('active');
            });

            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            document.getElementById(pageName).classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => {
                if (item.getAttribute('onclick') && item.getAttribute('onclick').includes(pageName)) {
                    item.classList.add('active');
                }
            });

            const titles = {
                'dashboard': 'Dashboard',
                'students': 'Students Management',
                'attendance': 'Mark Attendance',
                'records': 'Attendance Records',
                'reports': 'Reports'
            };
            document.getElementById('page-title').textContent = titles[pageName];
        }

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

        // Initialize chart
        window.addEventListener('DOMContentLoaded', function() {
            const chartLabels = <?php echo json_encode($chartLabels); ?>;
            const presentData = <?php echo json_encode($presentData); ?>;
            const absentData = <?php echo json_encode($absentData); ?>;

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
                        pointBackgroundColor: '#00FF88'
                    }, {
                        label: 'Absent',
                        data: absentData,
                        backgroundColor: 'rgba(255, 71, 87, 0.2)',
                        borderColor: '#FF4757',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#FF4757'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                color: '#E0E0E0',
                                font: {
                                    size: 13
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: 'rgba(255, 255, 255, 0.8)'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.8)'
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>