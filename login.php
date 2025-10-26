<?php
session_start();

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Attendance Monitoring System - Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Form Toggle */
        .form-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: rgba(255, 255, 255, 0.05);
            padding: 5px;
            border-radius: 12px;
        }

        .toggle-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.6);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .toggle-btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.9);
        }

        /* Form Content */
        .form-content {
            display: none;
        }

        .form-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Input Row */
        .input-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        /* Password Toggle Icon Styling */
        .input-wrapper {
            position: relative;
        }

        .input-group input {
            padding-right: 50px !important;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #8B8B9A;
            cursor: pointer;
            font-size: 1.1rem;
            width: 36px;
            height: 36px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 10;
            border-radius: 6px;
            opacity: 0.7;
        }

        .toggle-password:hover {
            color: #00D084;
            background: rgba(0, 208, 132, 0.08);
            opacity: 1;
        }

        .toggle-password:active {
            transform: translateY(-50%) scale(0.92);
        }

        .toggle-password i {
            pointer-events: none;
            line-height: 1;
        }

        /* Password Strength */
        .password-strength {
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .password-strength-bar.weak {
            width: 33%;
            background: #ff4757;
        }

        .password-strength-bar.medium {
            width: 66%;
            background: #ffa502;
        }

        .password-strength-bar.strong {
            width: 100%;
            background: #00d2d3;
        }

        .password-hint {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 5px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .input-row {
                grid-template-columns: 1fr;
            }

            .toggle-password {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="orb orb1"></div>
        <div class="orb orb2"></div>
        <div class="orb orb3"></div>
    </div>

    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-user-check"></i>
                <h1>Student Attendance</h1>
            </div>
            <p class="tagline">Monitoring System</p>
        </div>

        <div id="message-container"></div>

        <div class="form-section">
            <div class="form-toggle">
                <button class="toggle-btn active" onclick="switchForm('login')">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
                <button class="toggle-btn" onclick="switchForm('register')">
                    <i class="fas fa-user-plus"></i> Register
                </button>
            </div>

            <!-- Login Form -->
            <div id="loginForm" class="form-content active">
                <h2 class="form-title">
                    <i class="fas fa-sign-in-alt"></i> System Login
                </h2>
                <form onsubmit="handleLogin(event)">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="input-group">
                        <label>Username</label>
                        <div class="input-wrapper">
                            <input type="text" name="username" placeholder="Enter your username" required>
                            <i class="fas fa-user"></i>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="loginPassword" name="password" placeholder="Enter your password" required>
                            <i class="fas fa-lock"></i>
                            <button type="button" class="toggle-password" onclick="togglePassword('loginPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn">
                        Sign In <i class="fas fa-arrow-right btn-icon"></i>
                    </button>
                </form>
            </div>

            <!-- Registration Form -->
            <div id="registerForm" class="form-content">
                <h2 class="form-title">
                    <i class="fas fa-user-plus"></i> Create Admin Account
                </h2>
                <form onsubmit="handleRegister(event)">
                    <input type="hidden" name="action" value="register">
                    
                    <div class="input-group">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <input type="text" name="fullname" placeholder="Enter your full name" required>
                            <i class="fas fa-id-card"></i>
                        </div>
                    </div>

                    <div class="input-row">
                        <div class="input-group">
                            <label>Username</label>
                            <div class="input-wrapper">
                                <input type="text" name="username" id="regUsername" placeholder="Choose a username" required minlength="4">
                                <i class="fas fa-user"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Email</label>
                            <div class="input-wrapper">
                                <input type="email" name="email" placeholder="your.email@example.com" required>
                                <i class="fas fa-envelope"></i>
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="registerPassword" name="password" placeholder="Create a strong password" required minlength="6" oninput="checkPasswordStrength()">
                            <i class="fas fa-lock"></i>
                            <button type="button" class="toggle-password" onclick="togglePassword('registerPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div id="passwordStrengthBar" class="password-strength-bar"></div>
                        </div>
                        <div class="password-hint">Use at least 6 characters with letters and numbers</div>
                    </div>

                    <div class="input-group">
                        <label>Confirm Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="confirmPassword" name="confirm_password" placeholder="Re-enter your password" required>
                            <i class="fas fa-lock"></i>
                            <button type="button" class="toggle-password" onclick="togglePassword('confirmPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn">
                        Create Account <i class="fas fa-arrow-right btn-icon"></i>
                    </button>
                </form>
            </div>
        </div>

        <div class="footer">
            <div class="footer-links">
                <a href="#">Help</a>
                <a href="#">Privacy</a>
                <a href="#">Contact</a>
            </div>
            <p>&copy; 2025 Student Attendance System. All rights reserved.</p>
        </div>
    </div>

    <script>
        function switchForm(formType) {
            const loginForm = document.getElementById('loginForm');
            const registerForm = document.getElementById('registerForm');
            const toggleBtns = document.querySelectorAll('.toggle-btn');

            toggleBtns.forEach(btn => btn.classList.remove('active'));

            if (formType === 'login') {
                loginForm.classList.add('active');
                registerForm.classList.remove('active');
                toggleBtns[0].classList.add('active');
            } else {
                registerForm.classList.add('active');
                loginForm.classList.remove('active');
                toggleBtns[1].classList.add('active');
            }
        }

        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = event.target.closest('.toggle-password').querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function checkPasswordStrength() {
            const password = document.getElementById('registerPassword').value;
            const strengthBar = document.getElementById('passwordStrengthBar');
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;

            strengthBar.className = 'password-strength-bar';
            
            if (strength <= 2) {
                strengthBar.classList.add('weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('medium');
            } else {
                strengthBar.classList.add('strong');
            }
        }

        function handleLogin(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            
            fetch('auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('success', data.message);
                    setTimeout(() => {
                        window.location.href = data.data.redirect;
                    }, 1000);
                } else {
                    showMessage('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'An error occurred. Please try again.');
            });
        }

        function handleRegister(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');

            // Validate passwords match
            if (password !== confirmPassword) {
                showMessage('error', 'Passwords do not match!');
                return;
            }

            // Validate password strength
            if (password.length < 6) {
                showMessage('error', 'Password must be at least 6 characters long!');
                return;
            }

            fetch('auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('success', data.message);
                    setTimeout(() => {
                        switchForm('login');
                        e.target.reset();
                        document.getElementById('passwordStrengthBar').className = 'password-strength-bar';
                    }, 2000);
                } else {
                    showMessage('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'An error occurred. Please try again.');
            });
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
    </script>
</body>
</html>