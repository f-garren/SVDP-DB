<?php
/**
 * Authentication and Authorization System
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/security.php';

// Start secure session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
secureSession();

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['employee_id']) && isset($_SESSION['username']);
    }
    
    /**
     * Require login, redirect if not logged in
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }
    
    /**
     * Check if user has a specific permission
     */
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        // Admin accounts have all permissions
        $adminAccounts = explode(',', ADMIN_ACCOUNTS);
        if (in_array($_SESSION['username'], $adminAccounts)) {
            return true;
        }
        
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM employee_permissions 
             WHERE employee_id = ? AND permission = ?",
            [$_SESSION['employee_id'], $permission]
        );
        
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Require specific permission
     */
    public function requirePermission($permission) {
        $this->requireLogin();
        if (!$this->hasPermission($permission)) {
            header('Location: /dashboard.php?error=permission_denied');
            exit;
        }
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $adminAccounts = explode(',', ADMIN_ACCOUNTS);
        return in_array($_SESSION['username'], $adminAccounts);
    }
    
    /**
     * Require admin access
     */
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: /dashboard.php?error=admin_required');
            exit;
        }
    }
    
    /**
     * Login user
     */
    public function login($username, $password) {
        $stmt = $this->db->query(
            "SELECT id, username, password_hash, password_reset_required 
             FROM employees WHERE username = ?",
            [$username]
        );
        
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['employee_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['password_reset_required'] = $user['password_reset_required'];
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Logout user
     */
    public function logout() {
        session_destroy();
        header('Location: /login.php');
        exit;
    }
    
    /**
     * Get current user ID
     */
    public function getUserId() {
        return $_SESSION['employee_id'] ?? null;
    }
    
    /**
     * Get current username
     */
    public function getUsername() {
        return $_SESSION['username'] ?? null;
    }
    
    /**
     * Check if password reset is required
     */
    public function isPasswordResetRequired() {
        return $_SESSION['password_reset_required'] ?? false;
    }
}

$auth = new Auth();

