<?php
require_once 'config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['userID'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Department options
$departments = [
    'Faculty of Civil Engineering and Built Environment (FKAAB)',
    'Faculty of Electric and Electronic Engineering (FKEE)',
    'Faculty of Mechanical and Manufacturing Engineering (FKMP)',
    'Faculty of Technical and Vocational Education (FPTV)',
    'Faculty of Technology Management and Business (FPTP)',
    'Faculty of Applied Science and Technology (FAST)',
    'Faculty of Science Computer and Information Technology (FSKTM)',
    'Faculty of Engineering Technology (FTK)'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $student_staff_id = $_POST['student_staff_id'] ?? '';
    $department = $_POST['department'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $role = $_POST['role'] ?? 'student'; // Default to student
    
    // Validation
    if (empty($name) || empty($email) || empty($username) || empty($password)) {
        $error = 'Please fill in all required fields';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } elseif (empty($department)) {
        $error = 'Please select your department';
    } else {
        $db = Database::getInstance()->getConnection();
        
        // Check if email exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Email already registered';
        } else {
            // Check if username exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already taken';
            } else {
                // Create user
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO users (name, email, username, password, role, student_staff_id, department, phone, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                
                if ($stmt->execute([$name, $email, $username, $password_hash, $role, $student_staff_id, $department, $phone])) {
                    $success = 'Registration successful! You can now login.';
                    // Clear form
                    $_POST = [];
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .role-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            height: 100%;
        }
        .role-card:hover {
            border-color: #FF8C00;
            background: #fff8f0;
        }
        .role-card.selected {
            border-color: #FF8C00;
            background: linear-gradient(135deg, #fff8f0, #fff0e0);
            box-shadow: 0 5px 15px rgba(255, 140, 0, 0.2);
        }
        .role-card i {
            font-size: 48px;
            margin-bottom: 10px;
            color: #FF8C00;
        }
        .role-card h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        .role-card p {
            font-size: 12px;
            color: #666;
            margin: 0;
        }
        .role-input {
            display: none;
        }
        .required-field::after {
            content: '*';
            color: red;
            margin-left: 4px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-4">
            <div class="col-md-8">
                <div class="card fade-in">
                    <div class="card-header text-center">
                        <h3><i class="fas fa-user-plus"></i> Create Account</h3>
                        <p>Join Reclaim System today</p>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <?php if($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="registerForm">
                            <!-- Role Selection Section -->
                            <div class="mb-4">
                                <label class="form-label required-field">I am a:</label>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="role-card" data-role="student" onclick="selectRole('student')">
                                            <i class="fas fa-user-graduate"></i>
                                            <h6>Student</h6>
                                            <p>Currently enrolled student</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="role-card" data-role="staff" onclick="selectRole('staff')">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                            <h6>Staff</h6>
                                            <p>Faculty or administrative staff</p>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($_POST['role'] ?? 'student') ?>">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="name" class="form-label required-field">Full Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label required-field">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label required-field">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label required-field">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label required-field">Confirm Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="student_staff_id" class="form-label required-field" id="idLabel">Student ID</label>
                                    <input type="text" class="form-control" id="student_staff_id" name="student_staff_id" 
                                           value="<?= htmlspecialchars($_POST['student_staff_id'] ?? '') ?>"
                                           placeholder="e.g., A123456">
                                    <small class="text-muted" id="idHelpText">Enter your matriculation/student ID number</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="department" class="form-label required-field">Faculty / Department</label>
                                    <select class="form-control" id="department" name="department" required>
                                        <option value="">-- Select Faculty --</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>" <?= (($_POST['department'] ?? '') == $dept) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($dept) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus"></i> Register
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-3">
                            <p>Already have an account? <a href="login.php">Login here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Role selection function
        function selectRole(role) {
            // Update hidden input
            document.getElementById('roleInput').value = role;
            
            // Update UI for role cards
            document.querySelectorAll('.role-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.role-card[data-role="${role}"]`).classList.add('selected');
            
            // Update labels based on role
            const idLabel = document.getElementById('idLabel');
            const idHelpText = document.getElementById('idHelpText');
            const studentStaffId = document.getElementById('student_staff_id');
            
            if (role === 'student') {
                idLabel.innerHTML = 'Student ID *';
                idHelpText.innerHTML = 'Enter your matriculation/student ID number';
                studentStaffId.placeholder = 'e.g., A123456 or D202312345';
            } else {
                idLabel.innerHTML = 'Staff ID *';
                idHelpText.innerHTML = 'Enter your staff ID number';
                studentStaffId.placeholder = 'e.g., STF001 or EMP12345';
            }
        }
        
        // Set initial selected role based on POST data or default
        const initialRole = '<?= $_POST['role'] ?? 'student' ?>';
        selectRole(initialRole);
        
        // Password confirmation validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function validatePassword() {
            if (password.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
        
        password.addEventListener('change', validatePassword);
        confirmPassword.addEventListener('keyup', validatePassword);
        
        // Form validation before submit
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const role = document.getElementById('roleInput').value;
            const studentStaffId = document.getElementById('student_staff_id').value;
            
            if (!studentStaffId.trim()) {
                e.preventDefault();
                alert(role === 'student' ? 'Please enter your Student ID' : 'Please enter your Staff ID');
                return false;
            }
        });
    </script>
</body>
</html>