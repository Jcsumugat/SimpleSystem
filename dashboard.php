<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bsis4a_jc";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];
$sql = "SELECT fullname, email, role FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$totalQuery = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'staff'");
$total = $totalQuery->fetch_assoc()['total'];

$activeQuery = $conn->query("SELECT COUNT(*) as active FROM users WHERE role = 'staff' AND is_active = 1");
$active = $activeQuery->fetch_assoc()['active'];

$inactive = $total - $active;

$chartData = [];
$chartLabels = [];
$currentYear = 2025;

for ($month = 1; $month <= 10; $month++) {
    $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
    $yearMonth = "$currentYear-$monthStr";
    $monthLabel = date('M', mktime(0, 0, 0, $month, 1));

    $monthQuery = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'staff' AND DATE_FORMAT(created_at, '%Y-%m') = '$yearMonth'");
    $monthCount = $monthQuery->fetch_assoc()['count'];

    $chartLabels[] = $monthLabel;
    $chartData[] = $monthCount;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="loginstyle.css" rel="stylesheet">
    <title>FitZone - Gym Management Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
</head>

<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Catague Fitness Gym</h2>
                <p>Gym Manager</p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item active" onclick="showPage('dashboard')">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="showPage('register')">
                    <i class="fas fa-user-plus"></i>
                    <span>Register Member</span>
                </div>
                <div class="nav-item" onclick="showPage('manage')">
                    <i class="fas fa-users"></i>
                    <span>Manage Members</span>
                </div>
                <div class="nav-item" onclick="showPage('reports')">
                    <i class="fas fa-file-alt"></i>
                    <span>Generate Reports</span>
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
                    <input type="text" class="search-box" id="searchBox" placeholder="Search members..." style="display: none;">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($user['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($user['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Page -->
            <div id="dashboard" class="page active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Total Members</div>
                            <div class="stat-card-icon" style="color: var(--primary-red);"><i class="fas fa-users"></i></div>
                        </div>
                        <div class="stat-card-value"><?php echo $total; ?></div>
                        <div class="stat-card-change"><i class="fas fa-arrow-up"></i> All registered</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Active Members</div>
                            <div class="stat-card-icon" style="color: var(--success);"><i class="fas fa-user-check"></i></div>
                        </div>
                        <div class="stat-card-value"><?php echo $active; ?></div>
                        <div class="stat-card-change"><i class="fas fa-arrow-up"></i> Currently active</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Inactive Members</div>
                            <div class="stat-card-icon" style="color: var(--warning);"><i class="fas fa-user-clock"></i></div>
                        </div>
                        <div class="stat-card-value"><?php echo $inactive; ?></div>
                        <div class="stat-card-change"><i class="fas fa-arrow-down"></i> Not active</div>
                    </div>
                </div>

                <div class="chart-container" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); padding: 30px; border-radius: 16px; margin-top: 25px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h3 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 600; display: flex; align-items: center;">
                            <i class="fas fa-chart-line" style="color: #e74c3c; margin-right: 12px; font-size: 22px;"></i>
                            New Member Registration Trend (2025)
                        </h3>
                        <div style="background: rgba(231, 76, 60, 0.15); padding: 8px 16px; border-radius: 8px; border: 1px solid rgba(231, 76, 60, 0.3);">
                            <span style="color: #e74c3c; font-weight: 600; font-size: 14px;">
                                <i class="fas fa-calendar-alt" style="margin-right: 6px;"></i>
                                Jan - Oct
                            </span>
                        </div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px; backdrop-filter: blur(10px);">
                        <canvas id="memberChart" height="80"></canvas>
                    </div>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; gap: 30px; justify-content: center;">
                        <div style="text-align: center;">
                            <div style="color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 5px;">Total New Members</div>
                            <div style="color: #ffffff; font-size: 24px; font-weight: 700;" id="totalNewMembers">0</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 5px;">Average per Month</div>
                            <div style="color: #e74c3c; font-size: 24px; font-weight: 700;" id="avgPerMonth">0</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="color: rgba(255,255,255,0.6); font-size: 12px; margin-bottom: 5px;">Peak Month</div>
                            <div style="color: #3498db; font-size: 24px; font-weight: 700;" id="peakMonth">N/A</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Register Page -->
            <div id="register" class="page">
                <div class="form-container">
                    <form id="registerForm">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="fullname" placeholder="Enter full name" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" placeholder="email@example.com" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number *</label>
                                <input type="tel" name="phone" placeholder="09171234567" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Date of Birth *</label>
                                <input type="date" name="birthday" required>
                            </div>
                            <div class="form-group">
                                <label>Gender *</label>
                                <select name="sex" required>
                                    <option value="">Select gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">Register Member</button>
                    </form>
                </div>
            </div>

            <!-- Manage Page -->
            <div id="manage" class="page">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Member ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Birthday</th>
                                <th>Join Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="membersTableBody">
                            <tr>
                                <td colspan="8" style="text-align: center;">Loading members...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reports Page -->
            <div id="reports" class="page">
                <div class="form-container" style="max-width: 100%;">
                    <h3 style="margin-bottom: 25px; color: var(--text-light); display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-filter" style="color: var(--primary-red);"></i>
                        Filter New Member Reports
                    </h3>
                    
                    <form id="reportFilterForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" id="startDate" name="start_date">
                            </div>
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" id="endDate" name="end_date">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Gender Filter</label>
                                <select id="sexFilter" name="sex">
                                    <option value="All">All Genders</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status Filter</label>
                                <select id="statusFilter" name="status">
                                    <option value="all">All Status</option>
                                    <option value="1">Active Only</option>
                                    <option value="0">Inactive Only</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="button" class="btn-submit" onclick="generateReport()" style="flex: 1;">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                            <button type="button" class="btn-submit" onclick="exportToPDF()" style="flex: 1; background: linear-gradient(135deg, #00D9FF, #0097B2);">
                                <i class="fas fa-file-pdf"></i> Export to PDF
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Report Summary -->
                <div id="reportSummary" class="stats-grid" style="margin-top: 30px; display: none;">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Total New Members</div>
                            <div class="stat-card-icon" style="color: var(--primary-red);"><i class="fas fa-users"></i></div>
                        </div>
                        <div class="stat-card-value" id="reportTotal">0</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Male Members</div>
                            <div class="stat-card-icon" style="color: var(--accent-blue);"><i class="fas fa-male"></i></div>
                        </div>
                        <div class="stat-card-value" id="reportMale">0</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Female Members</div>
                            <div class="stat-card-icon" style="color: var(--accent-purple);"><i class="fas fa-female"></i></div>
                        </div>
                        <div class="stat-card-value" id="reportFemale">0</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-title">Active Members</div>
                            <div class="stat-card-icon" style="color: var(--success);"><i class="fas fa-check-circle"></i></div>
                        </div>
                        <div class="stat-card-value" id="reportActive">0</div>
                    </div>
                </div>

                <!-- Report Table -->
                <div id="reportTableContainer" class="table-container" style="margin-top: 30px; display: none;">
                    <table class="table" id="reportTable">
                        <thead>
                            <tr>
                                <th>Member ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Gender</th>
                                <th>Birthday</th>
                                <th>Registration Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="reportTableBody">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        let allMembers = [];
        let reportData = [];

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
                'register': 'Register Member',
                'manage': 'Manage Members',
                'reports': 'Generate Reports'
            };
            document.getElementById('page-title').textContent = titles[pageName];

            const searchBox = document.getElementById('searchBox');
            if (pageName === 'manage') {
                searchBox.style.display = 'block';
                loadMembers();
            } else {
                searchBox.style.display = 'none';
                searchBox.value = '';
            }
        }

        function generateReport() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const sex = document.getElementById('sexFilter').value;
            const status = document.getElementById('statusFilter').value;

            if (!startDate || !endDate) {
                showMessage('error', 'Please select both start and end dates');
                return;
            }

            const params = new URLSearchParams({
                action: 'get_new_members',
                start_date: startDate,
                end_date: endDate,
                sex: sex,
                status: status
            });

            fetch(`api_reports.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        reportData = data.data;
                        displayReportData(data.data, data.summary);
                    } else {
                        showMessage('error', 'Failed to generate report');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'An error occurred while generating report');
                });
        }

        function displayReportData(members, summary) {
            // Update summary cards
            document.getElementById('reportTotal').textContent = summary.total;
            document.getElementById('reportMale').textContent = summary.male;
            document.getElementById('reportFemale').textContent = summary.female;
            document.getElementById('reportActive').textContent = summary.active;
            
            document.getElementById('reportSummary').style.display = 'grid';
            document.getElementById('reportTableContainer').style.display = 'block';

            // Populate table
            const tbody = document.getElementById('reportTableBody');
            
            if (members.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No members found for selected criteria</td></tr>';
                return;
            }

            tbody.innerHTML = members.map(member => `
                <tr>
                    <td>#MEM-${String(member.id).padStart(3, '0')}</td>
                    <td>${member.fullname}</td>
                    <td>${member.email}</td>
                    <td>${member.mobile_number || 'N/A'}</td>
                    <td>${member.sex}</td>
                    <td>${formatDate(member.birthday)}</td>
                    <td>${formatDate(member.created_at)}</td>
                    <td><span style="color: ${member.is_active ? 'var(--success)' : 'var(--warning)'};">●</span> ${member.is_active ? 'Active' : 'Inactive'}</td>
                </tr>
            `).join('');

            showMessage('success', `Report generated successfully! Found ${members.length} member(s)`);
        }

        function exportToPDF() {
            if (reportData.length === 0) {
                showMessage('error', 'Please generate a report first before exporting');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();

            // Add title
            doc.setFontSize(20);
            doc.setTextColor(231, 76, 60);
            doc.text('Catague Fitness Gym', 105, 20, { align: 'center' });
            
            doc.setFontSize(14);
            doc.setTextColor(0, 0, 0);
            doc.text('New Member Registration Report', 105, 30, { align: 'center' });

            // Add report details
            doc.setFontSize(10);
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            doc.text(`Report Period: ${startDate} to ${endDate}`, 14, 40);
            doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 46);

            // Add summary
            doc.setFontSize(12);
            doc.setTextColor(231, 76, 60);
            doc.text('Summary', 14, 56);
            
            doc.setFontSize(10);
            doc.setTextColor(0, 0, 0);
            doc.text(`Total Members: ${document.getElementById('reportTotal').textContent}`, 14, 64);
            doc.text(`Male: ${document.getElementById('reportMale').textContent}`, 70, 64);
            doc.text(`Female: ${document.getElementById('reportFemale').textContent}`, 110, 64);
            doc.text(`Active: ${document.getElementById('reportActive').textContent}`, 150, 64);

            // Prepare table data
            const tableData = reportData.map(member => [
                `#MEM-${String(member.id).padStart(3, '0')}`,
                member.fullname,
                member.email,
                member.mobile_number || 'N/A',
                member.sex,
                formatDate(member.birthday),
                formatDate(member.created_at),
                member.is_active ? 'Active' : 'Inactive'
            ]);

            // Add table
            doc.autoTable({
                startY: 72,
                head: [['ID', 'Name', 'Email', 'Phone', 'Gender', 'Birthday', 'Reg. Date', 'Status']],
                body: tableData,
                theme: 'grid',
                headStyles: { 
                    fillColor: [231, 76, 60],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold'
                },
                styles: {
                    fontSize: 8,
                    cellPadding: 3
                },
                alternateRowStyles: {
                    fillColor: [245, 245, 245]
                }
            });

            // Save PDF
            const filename = `new_members_report_${startDate}_to_${endDate}.pdf`;
            doc.save(filename);
            
            showMessage('success', 'Report exported to PDF successfully!');
        }

        function loadMembers() {
            fetch('api_members.php?action=get_all')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allMembers = data.data;
                        displayMembers(allMembers);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function displayMembers(members) {
            const tbody = document.getElementById('membersTableBody');

            if (members.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No members found</td></tr>';
                return;
            }

            tbody.innerHTML = members.map(member => `
            <tr data-id="${member.id}">
                <td>#MEM-${String(member.id).padStart(3, '0')}</td>
                <td>${member.fullname}</td>
                <td>${member.email}</td>
                <td>${member.mobile_number || 'N/A'}</td>
                <td>${formatDate(member.birthday)}</td>
                <td>${formatDate(member.created_at)}</td>
                <td><span style="color: ${member.is_active ? 'var(--success)' : 'var(--warning)'};">●</span> ${member.is_active ? 'Active' : 'Inactive'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-small" onclick="toggleStatus(${member.id}, ${member.is_active})">${member.is_active ? 'Deactivate' : 'Activate'}</button>
                        <button class="btn-small delete" onclick="deleteMember(${member.id})">Delete</button>
                    </div>
                </td>
            </tr>
        `).join('');
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'register');

            fetch('api_members.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', data.message);
                        this.reset();
                        setTimeout(() => {
                            showPage('manage');
                        }, 1500);
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'An error occurred while registering member');
                });
        });

        function toggleStatus(id, currentStatus) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('id', id);
            formData.append('status', currentStatus ? 0 : 1);

            fetch('api_members.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', data.message);
                        loadMembers();
                        location.reload();
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'An error occurred');
                });
        }

        function deleteMember(id) {
            if (!confirm('Are you sure you want to delete this member?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('api_members.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', data.message);
                        loadMembers();
                        location.reload();
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'An error occurred while deleting member');
                });
        }

        document.getElementById('searchBox').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const filteredMembers = allMembers.filter(member =>
                member.fullname.toLowerCase().includes(searchTerm) ||
                member.email.toLowerCase().includes(searchTerm) ||
                (member.username && member.username.toLowerCase().includes(searchTerm))
            );
            displayMembers(filteredMembers);
        });

        function showMessage(type, message) {
            const messageBox = document.createElement('div');
            messageBox.className = `message-box ${type}`;
            messageBox.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
        `;

            const mainContent = document.querySelector('.main-content');
            const header = mainContent.querySelector('.header');
            mainContent.insertBefore(messageBox, header.nextSibling);

            setTimeout(() => {
                messageBox.style.opacity = '0';
                messageBox.style.transform = 'translateY(-10px)';
                setTimeout(() => messageBox.remove(), 300);
            }, 3000);
        }

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        // Initialize chart and load members on page load
        window.addEventListener('DOMContentLoaded', function() {
            // Set default dates for report (current month)
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            
            document.getElementById('startDate').valueAsDate = firstDay;
            document.getElementById('endDate').valueAsDate = lastDay;

            // Initialize the chart
            const chartLabels = <?php echo json_encode($chartLabels); ?>;
            const chartData = <?php echo json_encode($chartData); ?>;

            // Calculate statistics
            const totalNew = chartData.reduce((a, b) => parseInt(a) + parseInt(b), 0);
            const avgNew = chartData.length > 0 ? (totalNew / chartData.length).toFixed(1) : 0;
            const maxValue = Math.max(...chartData);
            const peakMonthIndex = chartData.indexOf(maxValue);
            const peakMonth = maxValue > 0 ? chartLabels[peakMonthIndex] : 'N/A';

            // Update statistics display
            document.getElementById('totalNewMembers').textContent = totalNew;
            document.getElementById('avgPerMonth').textContent = avgNew;
            document.getElementById('peakMonth').textContent = peakMonth;

            const ctx = document.getElementById('memberChart').getContext('2d');

            // Create gradient
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(231, 76, 60, 0.4)');
            gradient.addColorStop(1, 'rgba(231, 76, 60, 0.05)');

            const memberChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'New Members',
                        data: chartData,
                        backgroundColor: gradient,
                        borderColor: '#e74c3c',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#e74c3c',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 3,
                        pointRadius: 6,
                        pointHoverRadius: 9,
                        pointHoverBackgroundColor: '#e74c3c',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 46, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            padding: 15,
                            cornerRadius: 10,
                            titleFont: {
                                size: 15,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 14
                            },
                            borderColor: 'rgba(231, 76, 60, 0.5)',
                            borderWidth: 1,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return 'New Members: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: 13,
                                    weight: '500'
                                },
                                color: 'rgba(255, 255, 255, 0.8)',
                                padding: 10
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.08)',
                                drawBorder: false
                            },
                            border: {
                                display: false
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 13,
                                    weight: '500'
                                },
                                color: 'rgba(255, 255, 255, 0.8)',
                                padding: 10
                            },
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });

            loadMembers();
        });
    </script>
</body>

</html>