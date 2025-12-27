<?php
// auth.php
require_once __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

session_name($config['session_name']);
session_start();

/* ============================================================
   ADMIN AUTH
   ============================================================ */

if (!function_exists('require_admin')) {
    function require_admin() {
        // If reseller tries to access admin pages → redirect
        if (isset($_SESSION['reseller_id']) && !isset($_SESSION['admin_id'])) {
            header("Location: reseller_dashboard.php");
            exit;
        }

        if (empty($_SESSION['admin_id'])) {
            header("Location: signin.php");
            exit;
        }
    }
}

if (!function_exists('admin_login')) {
    function admin_login($username, $password) {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) return false;

        if (password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            return true;
        }
        return false;
    }
}

if (!function_exists('admin_logout')) {
    function admin_logout() {
        $_SESSION = [];
        session_destroy();
    }
}

/* ============================================================
   USER AUTH
   ============================================================ */

if (!function_exists('require_login')) {
    function require_login() {
        if (empty($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }
    }
}

/* ============================================================
   RESELLER AUTH
   ============================================================ */

if (!function_exists('require_reseller')) {
    function require_reseller() {
        if (empty($_SESSION['reseller_id'])) {
            header("Location: signin.php?role=reseller");
            exit;
        }
    }
}

if (!function_exists('reseller_login')) {
    function reseller_login($username, $password) {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM resellers WHERE username = ? AND status='active' LIMIT 1");
        $stmt->execute([$username]);
        $reseller = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reseller) return false;
        if (!password_verify($password, $reseller['password_hash'])) return false;

        $_SESSION['reseller_id'] = $reseller['id'];
        $_SESSION['reseller_username'] = $reseller['username'];
        return true;
    }
}

if (!function_exists('reseller_logout')) {
    function reseller_logout() {
        unset($_SESSION['reseller_id']);
        unset($_SESSION['reseller_username']);
    }
}
