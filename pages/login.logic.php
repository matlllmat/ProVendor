<?php
// pages/login.logic.php
// Handles login, signup, and password reset POST actions.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Global autoloader paths
require_once dirname(__DIR__) . '/vendor/autoload.php'; 
require_once __DIR__ . '/../config/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error     = null;
$success   = null;
$activeTab = 'login'; // States: 'login', 'signup', 'forgot', 'verify', 'reset'

if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = "Password successfully reset. Please log in.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../queries/user.query.php';

    $action = $_POST['action'] ?? '';

    // Helper function for password constraints
    function isValidPassword($pwd) {
        // At least 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special char
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $pwd);
    }

    // ── Login ──────────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Please fill in all fields.';
        } else {
            $user = getUserByEmail($pdo, $email);

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                
                // Assumes userHasSales is defined in user.query.php
                $hasSales = function_exists('userHasSales') ? userHasSales($pdo, $user['id']) : false;
                
                header('Location: ' . BASE_URL . ($hasSales ? '/pages/forecast.view.php' : '/pages/landing.view.php'));
                exit;
            } else {
                $error = 'Incorrect email or password.';
            }
        }

    // ── Signup ─────────────────────────────────────────────────────────────
    } elseif ($action === 'signup') {
        $activeTab       = 'signup';
        $name            = trim($_POST['name']             ?? '');
        $storeName       = trim($_POST['store_name']       ?? '');
        $email           = trim($_POST['email']            ?? '');
        $password        = trim($_POST['password']         ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($name === '' || $storeName === '' || $email === '' || $password === '' || $confirmPassword === '') {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (!isValidPassword($password)) {
            $error = 'Password must be at least 8 characters, include 1 uppercase, 1 lowercase, 1 number, and 1 special character.';
        } else {
            $existing = getUserByEmail($pdo, $email);

            if ($existing) {
                $error = 'An account with that email already exists.';
            } else {
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $newId = createUser($pdo, $name, $storeName, $email, $hash);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newId;
                header('Location: ' . BASE_URL . '/pages/landing.view.php');
                exit;
            }
        }
    
    // ── Forgot Password (Send Code) ─────────────────────────────────────────
    } elseif ($action === 'send_code') {
        $activeTab = 'forgot';
        $email     = trim($_POST['email'] ?? '');
        $user      = getUserByEmail($pdo, $email);

        if ($user) {
            $code = sprintf("%06d", mt_rand(1, 999999));
            $_SESSION['reset_code']  = $code;
            $_SESSION['reset_email'] = $email;

            // Send email using PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'Windoges7@gmail.com';
                $mail->Password   = 'bblqyhtketrtoyuy';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('Windoges7@gmail.com', 'ProVendor Security');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Your ProVendor Password Reset Code';
                $mail->Body    = "Your password reset code is: <b style='font-size:1.5em;'>$code</b><br><br>If you did not request this, please ignore this email.";

                $mail->send();
                
                $activeTab = 'verify';
                $success   = "Reset code sent to your email.";
            } catch (Exception $e) {
                $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        } else {
            $error = "No account found with that email address.";
        }

    // ── Verify Reset Code ───────────────────────────────────────────────────
    } elseif ($action === 'verify_code') {
        $activeTab = 'verify';
        $code = trim($_POST['code'] ?? '');
        
        if (isset($_SESSION['reset_code']) && $code === (string)$_SESSION['reset_code']) {
            $activeTab = 'reset';
        } else {
            $error = "Invalid or expired reset code.";
        }

    // ── Reset Password ──────────────────────────────────────────────────────
    } elseif ($action === 'reset_password') {
        $activeTab       = 'reset';
        $password        = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (!isValidPassword($password)) {
            $error = 'Password must be at least 8 characters, include 1 uppercase, 1 lowercase, 1 number, and 1 special character.';
        } elseif (isset($_SESSION['reset_email'])) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->execute([$hash, $_SESSION['reset_email']]);

            unset($_SESSION['reset_code']);
            unset($_SESSION['reset_email']);

            header('Location: ' . BASE_URL . '/pages/login.view.php?reset=success');
            exit;
        } else {
            $error     = "Session expired. Please request a new code.";
            $activeTab = 'forgot';
        }
    }
}