<?php
session_start();
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bsis4a_jc";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = '';
$message_class = '';

// Login logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            header('Location: dashboard.php');
            exit();
        } else {
            $message = "Incorrect username/email or password.";
            $message_class = "error";
        }
    } else {
        $message = "Incorrect username/email or password.";
        $message_class = "error";
    }
    $stmt->close();
}

// Signup logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signup'])) {
    $new_username = $conn->real_escape_string(trim($_POST['new_username']));
    $new_password = $_POST['new_password'];
    $fullname = $conn->real_escape_string(trim($_POST['fullname']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $birthday = $_POST['birthday'];
    $sex = $_POST['sex'];
    $mobile_number = $conn->real_escape_string(trim($_POST['mobile_number']));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $message_class = "error";
    } elseif (strlen($new_password) < 8) {
        $message = "Password must be at least 8 characters.";
        $message_class = "error";
    } else {
        $check_sql = "SELECT * FROM users WHERE username=? OR email=?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $new_username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = "Username or email already exists. Please choose a different one.";
            $message_class = "error";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $role = 'staff';
            
            $signup_sql = "INSERT INTO users (username, password, fullname, email, birthday, sex, mobile_number, role, is_active) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
            $signup_stmt = $conn->prepare($signup_sql);
            $signup_stmt->bind_param("ssssssss", $new_username, $hashed_password, $fullname, $email, $birthday, $sex, $mobile_number, $role);
            
            if ($signup_stmt->execute()) {
                $message = "Signup successful! You can now log in.";
                $message_class = "success";
            } else {
                $message = "Error: " . $conn->error;
                $message_class = "error";
            }
            $signup_stmt->close();
        }
        $check_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FitZone - Gym Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
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
                <i class="fas fa-dumbbell"></i>
                <h1>Catague Fitness Gym</h1>
            </div>
            <p class="tagline">Digital CRM Solution</p>
        </div>

        <?php if ($message): ?>
            <div class="message-box <?php echo $message_class; ?>">
                <i class="fas <?php echo $message_class === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <div id="login-form" class="form-section">
            <h2 class="form-title">
                <i class="fas fa-sign-in-alt"></i> Member Login
            </h2>
            <form method="POST" action="">
                <input type="hidden" name="login" value="1">
                <div class="input-group">
                    <label>Email or Username</label>
                    <div class="input-wrapper">
                        <input type="text" name="username" placeholder="your@email.com or username" required>
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>

                <div class="input-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="login-password" name="password" placeholder="Enter your password" required>
                        <i class="fas fa-lock"></i>
                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility('login-password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn">
                    Sign In <i class="fas fa-arrow-right btn-icon"></i>
                </button>
            </form>
        </div>

        <div id="signup-form" class="form-section hidden">
            <h2 class="form-title">
                <i class="fas fa-user-plus"></i> Create Account
            </h2>
            <form method="POST" action="">
                <input type="hidden" name="signup" value="1">
                
                <div class="input-group">
                    <label>Full Name</label>
                    <div class="input-wrapper">
                        <input type="text" name="fullname" placeholder="Your Full Name" required>
                        <i class="fas fa-user"></i>
                    </div>
                </div>

                <div class="input-group">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <input type="text" name="new_username" placeholder="Choose a username" required>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>

                <div class="input-group">
                    <label>Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" name="email" placeholder="your@email.com" required>
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>

                <div class="form-row">
                    <div class="input-group">
                        <label>Birthday</label>
                        <div class="input-wrapper">
                            <input type="date" name="birthday" required>
                            <i class="fas fa-calendar"></i>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Sex</label>
                        <div class="input-wrapper">
                            <select name="sex" required>
                                <option value="" disabled selected>Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                            <i class="fas fa-venus-mars"></i>
                        </div>
                    </div>
                </div>

                <div class="input-group">
                    <label>Mobile Number</label>
                    <div class="input-wrapper">
                        <input type="tel" name="mobile_number" placeholder="09171234567" pattern="[0-9]{11}" required>
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                </div>

                <div class="input-group">
                    <label>Create Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="signup-password" name="new_password" placeholder="Minimum 8 characters" minlength="8" required>
                        <i class="fas fa-lock"></i>
                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility('signup-password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn">
                    Create Account <i class="fas fa-check btn-icon"></i>
                </button>
            </form>
        </div>

        <div class="toggle-section">
            <p class="toggle-text" id="toggle-text">New to CFG?</p>
            <button type="button" class="toggle-btn" onclick="toggleForms()">
                <span id="toggle-btn-text">Create Account</span>
            </button>
        </div>

        <div class="footer">
            <div class="footer-links">
                <a href="#">Help</a>
                <a href="#">Privacy</a>
                <a href="#">Contact</a>
            </div>
            <p>&copy; 2025 CFG. Transform Your Body, Transform Your Life</p>
        </div>
    </div>

    <script>
        function toggleForms() {
            const loginForm = document.getElementById('login-form');
            const signupForm = document.getElementById('signup-form');
            const toggleText = document.getElementById('toggle-text');
            const toggleBtnText = document.getElementById('toggle-btn-text');

            if (loginForm.classList.contains('hidden')) {
                loginForm.classList.remove('hidden');
                signupForm.classList.add('hidden');
                toggleText.textContent = 'New to CFG?';
                toggleBtnText.textContent = 'Create Account';
            } else {
                loginForm.classList.add('hidden');
                signupForm.classList.remove('hidden');
                toggleText.textContent = 'Already have an account?';
                toggleBtnText.textContent = 'Sign In';
            }
        }

        function togglePasswordVisibility(inputId) {
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
    </script>
</body>

</html>