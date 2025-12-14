<?php
/**
 * Security Functions
 * Input validation, sanitization, CSRF protection, etc.
 */

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate and sanitize string input
 */
function sanitizeString($input, $maxLength = null, $allowHtml = false) {
    if (!is_string($input)) {
        return '';
    }
    
    $input = trim($input);
    
    if ($maxLength !== null && strlen($input) > $maxLength) {
        $input = substr($input, 0, $maxLength);
    }
    
    if (!$allowHtml) {
        $input = strip_tags($input);
    }
    
    return $input;
}

/**
 * Validate email address
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number format
 */
function validatePhoneNumber($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) >= 10 && strlen($digits) <= 15;
}

/**
 * Validate ZIP code (US format)
 */
function validateZipCode($zip) {
    return preg_match('/^\d{5}(-\d{4})?$/', $zip) === 1;
}

/**
 * Validate state code (2 letters)
 */
function validateStateCode($state) {
    return preg_match('/^[A-Z]{2}$/i', $state) === 1 && strlen($state) === 2;
}

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate datetime format
 */
function validateDateTime($datetime, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $datetime);
    return $d && $d->format($format) === $datetime;
}

/**
 * Sanitize integer input
 */
function sanitizeInt($input, $min = null, $max = null) {
    $int = filter_var($input, FILTER_VALIDATE_INT);
    if ($int === false) {
        return null;
    }
    if ($min !== null && $int < $min) {
        return $min;
    }
    if ($max !== null && $int > $max) {
        return $max;
    }
    return $int;
}

/**
 * Sanitize float input
 */
function sanitizeFloat($input, $min = null, $max = null) {
    $float = filter_var($input, FILTER_VALIDATE_FLOAT);
    if ($float === false) {
        return null;
    }
    if ($min !== null && $float < $min) {
        return $min;
    }
    if ($max !== null && $float > $max) {
        return $max;
    }
    return $float;
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowedTypes = [], $maxSize = 10485760) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'File upload error'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'File size exceeds maximum allowed'];
    }
    
    if (!empty($allowedTypes)) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }
    }
    
    // Check for PHP code in file (basic check)
    $content = file_get_contents($file['tmp_name']);
    if (preg_match('/<\?php/i', $content) || preg_match('/<script/i', $content)) {
        return ['valid' => false, 'error' => 'File contains potentially dangerous content'];
    }
    
    return ['valid' => true];
}

/**
 * Rate limiting check
 */
function checkRateLimit($key, $maxAttempts = 5, $timeWindow = 300) {
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    
    $now = time();
    $key = 'rate_limit_' . $key;
    
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = ['attempts' => 0, 'reset_time' => $now + $timeWindow];
    }
    
    $limit = &$_SESSION['rate_limits'][$key];
    
    if ($now > $limit['reset_time']) {
        $limit = ['attempts' => 0, 'reset_time' => $now + $timeWindow];
    }
    
    $limit['attempts']++;
    
    if ($limit['attempts'] > $maxAttempts) {
        return ['allowed' => false, 'reset_time' => $limit['reset_time']];
    }
    
    return ['allowed' => true, 'attempts' => $limit['attempts']];
}

/**
 * Secure session configuration
 */
function secureSession() {
    // Prevent session fixation
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }
    
    // Set secure session parameters
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
}

/**
 * Validate customer name
 */
function validateCustomerName($name) {
    $name = sanitizeString($name, 255);
    if (empty($name) || strlen($name) < 2) {
        return ['valid' => false, 'error' => 'Name must be at least 2 characters'];
    }
    if (strlen($name) > 255) {
        return ['valid' => false, 'error' => 'Name is too long'];
    }
    // Check for potentially malicious patterns
    if (preg_match('/[<>"\']/', $name)) {
        return ['valid' => false, 'error' => 'Name contains invalid characters'];
    }
    return ['valid' => true, 'value' => $name];
}

/**
 * Validate address
 */
function validateAddress($address) {
    $address = sanitizeString($address, 255);
    if (empty($address) || strlen($address) < 5) {
        return ['valid' => false, 'error' => 'Address must be at least 5 characters'];
    }
    return ['valid' => true, 'value' => $address];
}

/**
 * Validate city
 */
function validateCity($city) {
    $city = sanitizeString($city, 100);
    if (empty($city) || strlen($city) < 2) {
        return ['valid' => false, 'error' => 'City must be at least 2 characters'];
    }
    return ['valid' => true, 'value' => $city];
}

/**
 * Escape output for JavaScript (prevent XSS in JS context)
 */
function escapeJS($string) {
    return json_encode($string, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Generate secure random string
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Check if request is from same origin
 */
function isSameOrigin() {
    if (!isset($_SERVER['HTTP_REFERER'])) {
        return false;
    }
    
    $referer = parse_url($_SERVER['HTTP_REFERER']);
    $current = parse_url($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME']);
    
    return isset($referer['host']) && 
           isset($current['host']) && 
           $referer['host'] === $current['host'];
}

