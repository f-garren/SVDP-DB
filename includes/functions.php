<?php
/**
 * Utility Functions
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';

/**
 * Format phone number
 */
function formatPhoneNumber($countryCode, $localNumber) {
    $fullNumber = $countryCode . $localNumber;
    if (strlen($fullNumber) == 11 && substr($fullNumber, 0, 1) == '1') {
        $localNumber = substr($fullNumber, 1);
    }
    
    if (strlen($localNumber) == 10) {
        return '(' . substr($localNumber, 0, 3) . ') ' . 
               substr($localNumber, 3, 3) . '-' . 
               substr($localNumber, 6, 4);
    }
    return $countryCode . ' ' . $localNumber;
}

/**
 * Parse phone number (extract last 10 digits as local, rest as country code)
 * Optimized version
 */
function parsePhoneNumber($phone) {
    // Remove all non-digit characters (more efficient than multiple regex)
    $digits = preg_replace('/\D/', '', $phone);
    $len = strlen($digits);
    
    if ($len == 10) {
        return ['country_code' => '1', 'local_number' => $digits];
    } elseif ($len > 10) {
        return [
            'country_code' => substr($digits, 0, $len - 10),
            'local_number' => substr($digits, -10)
        ];
    }
    
    return ['country_code' => '1', 'local_number' => $digits];
}

/**
 * Generate unique customer ID (optimized with max attempts)
 */
function generateCustomerID() {
    $db = Database::getInstance();
    $date = date('Ymd');
    $maxAttempts = 100;
    $attempt = 0;
    
    do {
        // Format: SVDP-YYYYMMDD-XXXX (4 random digits)
        $random = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $customerId = 'SVDP-' . $date . '-' . $random;
        
        $stmt = $db->query("SELECT COUNT(*) as count FROM customers WHERE customer_id = ?", [$customerId]);
        $result = $stmt->fetch();
        $attempt++;
    } while ($result['count'] > 0 && $attempt < $maxAttempts);
    
    // If still not unique after max attempts, add timestamp
    if ($result['count'] > 0) {
        $customerId = 'SVDP-' . $date . '-' . time() . '-' . mt_rand(100, 999);
    }
    
    return $customerId;
}

/**
 * Generate unique voucher code (optimized with max attempts)
 */
function generateVoucherCode() {
    $db = Database::getInstance();
    $maxAttempts = 50;
    $attempt = 0;
    
    do {
        // Format: V-XXXXXXXX (8 random alphanumeric)
        $code = 'V-' . strtoupper(bin2hex(random_bytes(4)));
        
        $stmt = $db->query("SELECT COUNT(*) as count FROM vouchers WHERE voucher_code = ?", [$code]);
        $result = $stmt->fetch();
        $attempt++;
    } while ($result['count'] > 0 && $attempt < $maxAttempts);
    
    // If still not unique, add timestamp
    if ($result['count'] > 0) {
        $code = 'V-' . strtoupper(bin2hex(random_bytes(4))) . '-' . substr(time(), -4);
    }
    
    return $code;
}

/**
 * Search for duplicate customers (optimized with single query using UNION)
 */
function findDuplicateCustomers($name, $address, $phoneLocal, $householdMembers = []) {
    $db = Database::getInstance();
    
    $queries = [];
    $params = [];
    
    // Search by name, address, or phone
    $queries[] = "SELECT DISTINCT c.* FROM customers c
                  WHERE c.name LIKE ? OR c.address LIKE ? OR c.phone_local_number = ?";
    $params = array_merge($params, ['%' . $name . '%', '%' . $address . '%', $phoneLocal]);
    
    // Also check household members if provided
    if (!empty($householdMembers)) {
        $memberNames = array_column($householdMembers, 'name');
        if (!empty($memberNames)) {
            $placeholders = str_repeat('?,', count($memberNames) - 1) . '?';
            $queries[] = "SELECT DISTINCT c.* FROM customers c
                         INNER JOIN household_members hm ON c.id = hm.customer_id
                         WHERE hm.name IN ($placeholders)";
            $params = array_merge($params, $memberNames);
        }
    }
    
    // Combine queries with UNION and get unique results
    $sql = implode(' UNION ', $queries);
    $stmt = $db->query($sql, $params);
    
    // Use array key to automatically deduplicate by id
    $unique = [];
    while ($row = $stmt->fetch()) {
        $unique[$row['id']] = $row;
    }
    
    return array_values($unique);
}

/**
 * Get setting value (with caching)
 */
function getSetting($key, $default = null) {
    require_once __DIR__ . '/cache.php';
    
    $cacheKey = 'setting_' . $key;
    if (Cache::has($cacheKey)) {
        return Cache::get($cacheKey);
    }
    
    $db = Database::getInstance();
    $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    $result = $stmt->fetch();
    $value = $result ? $result['setting_value'] : $default;
    
    // Cache for 5 minutes
    Cache::set($cacheKey, $value, 300);
    
    return $value;
}

/**
 * Set setting value (with cache invalidation)
 */
function setSetting($key, $value) {
    require_once __DIR__ . '/cache.php';
    
    $db = Database::getInstance();
    $db->query(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?",
        [$key, $value, $value]
    );
    
    // Invalidate cache
    Cache::delete('setting_' . $key);
}

/**
 * Calculate total household income for a customer
 * Note: This function is kept for backward compatibility but consider using direct SUM in queries
 */
function calculateHouseholdIncome($customerId) {
    $db = Database::getInstance();
    $stmt = $db->query(
        "SELECT COALESCE(SUM(amount), 0) as total FROM household_income WHERE customer_id = ?",
        [$customerId]
    );
    $result = $stmt->fetch();
    return (float)($result['total'] ?? 0.00);
}

/**
 * Check food visit limits (optimized with single query)
 */
function checkFoodVisitLimits($customerId, $visitDate) {
    $db = Database::getInstance();
    
    $visitsPerMonth = (int)getSetting('food_visits_per_month', 2);
    $visitsPerYear = (int)getSetting('food_visits_per_year', 12);
    $minDaysBetween = (int)getSetting('food_min_days_between', 14);
    
    $visitDateObj = new DateTime($visitDate);
    $monthStart = $visitDateObj->format('Y-m-01');
    $monthEnd = $visitDateObj->format('Y-m-t 23:59:59');
    $yearStart = $visitDateObj->format('Y-01-01');
    $yearEnd = $visitDateObj->format('Y-12-31 23:59:59');
    
    // Single optimized query to get all needed data
    $stmt = $db->query(
        "SELECT 
            SUM(CASE WHEN visit_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as month_count,
            SUM(CASE WHEN visit_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as year_count,
            MAX(CASE WHEN visit_date < ? THEN visit_date ELSE NULL END) as last_visit
         FROM visits 
         WHERE customer_id = ? AND visit_type = 'Food' AND is_invalid = FALSE",
        [$monthStart, $monthEnd, $yearStart, $yearEnd, $visitDate, $customerId]
    );
    $result = $stmt->fetch();
    
    $monthCount = (int)$result['month_count'];
    $yearCount = (int)$result['year_count'];
    $lastVisit = $result['last_visit'];
    
    $errors = [];
    if ($monthCount >= $visitsPerMonth) {
        $errors[] = "Monthly limit reached ($visitsPerMonth visits per month)";
    }
    if ($yearCount >= $visitsPerYear) {
        $errors[] = "Yearly limit reached ($visitsPerYear visits per year)";
    }
    if ($lastVisit) {
        $lastVisitDate = new DateTime($lastVisit);
        $daysSince = $lastVisitDate->diff($visitDateObj)->days;
        if ($daysSince < $minDaysBetween) {
            $errors[] = "Minimum $minDaysBetween days between visits required (last visit was $daysSince days ago)";
        }
    }
    
    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Check money visit limits
 */
function checkMoneyVisitLimits($customerId, $visitDate) {
    $db = Database::getInstance();
    
    $maxLifetime = (int)getSetting('money_max_lifetime_visits', 3);
    $cooldownYears = (int)getSetting('money_cooldown_years', 1);
    
    // Check lifetime limit
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM visits 
         WHERE customer_id = ? AND visit_type = 'Money' AND is_invalid = FALSE",
        [$customerId]
    );
    $lifetimeCount = $stmt->fetch()['count'];
    
    // Check cooldown period
    $cooldownDate = date('Y-m-d', strtotime("-$cooldownYears years", strtotime($visitDate)));
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM visits 
         WHERE customer_id = ? AND visit_type = 'Money' 
         AND visit_date >= ? AND is_invalid = FALSE",
        [$customerId, $cooldownDate]
    );
    $recentCount = $stmt->fetch()['count'];
    
    $errors = [];
    if ($lifetimeCount >= $maxLifetime) {
        $errors[] = "Lifetime limit reached ($maxLifetime visits)";
    }
    if ($recentCount > 0) {
        $errors[] = "Cooldown period not met ($cooldownYears year(s) required between visits)";
    }
    
    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Sanitize output
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

