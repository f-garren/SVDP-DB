<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$auth->requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        if (isset($_POST['update_visit_limits'])) {
            $foodVisitsPerMonth = sanitizeInt($_POST['food_visits_per_month'] ?? 2, 1, 100);
            $foodVisitsPerYear = sanitizeInt($_POST['food_visits_per_year'] ?? 12, 1, 1000);
            $foodMinDaysBetween = sanitizeInt($_POST['food_min_days_between'] ?? 14, 1, 365);
            $moneyMaxLifetime = sanitizeInt($_POST['money_max_lifetime_visits'] ?? 3, 1, 100);
            $moneyCooldownYears = sanitizeFloat($_POST['money_cooldown_years'] ?? 1, 0, 10);
            
            if ($foodVisitsPerMonth && $foodVisitsPerYear && $foodMinDaysBetween && $moneyMaxLifetime && $moneyCooldownYears !== null) {
                setSetting('food_visits_per_month', $foodVisitsPerMonth);
                setSetting('food_visits_per_year', $foodVisitsPerYear);
                setSetting('food_min_days_between', $foodMinDaysBetween);
                setSetting('money_max_lifetime_visits', $moneyMaxLifetime);
                setSetting('money_cooldown_years', $moneyCooldownYears);
                header('Location: /settings.php?success=settings_updated');
                exit;
            } else {
                $error = 'Invalid input values.';
            }
        }
        
        if (isset($_POST['update_appearance'])) {
            $companyName = sanitizeString($_POST['company_name'] ?? '', 255);
            $partnerStoreName = sanitizeString($_POST['partner_store_name'] ?? '', 255);
            
            if (!empty($companyName) && !empty($partnerStoreName)) {
                setSetting('company_name', $companyName);
                setSetting('partner_store_name', $partnerStoreName);
                header('Location: /settings.php?success=settings_updated');
                exit;
            } else {
                $error = 'Company name and partner store name are required.';
            }
        }
    
    if (isset($_POST['backup_database'])) {
        $version = getSetting('db_version', '1.0');
        $backupFile = 'backup_' . date('Y-m-d_His') . '_v' . $version . '.sql';
        $backupPath = __DIR__ . '/backups/' . $backupFile;
        
        // Create backups directory if it doesn't exist
        if (!is_dir(__DIR__ . '/backups')) {
            mkdir(__DIR__ . '/backups', 0755, true);
        }
        
        // Generate backup
        $output = [];
        $returnVar = 0;
        $command = sprintf(
            'mysqldump -h %s -u %s -p%s %s > %s 2>&1',
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASS),
            escapeshellarg(DB_NAME),
            escapeshellarg($backupPath)
        );
        
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0 && file_exists($backupPath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $backupFile . '"');
            readfile($backupPath);
            exit;
        } else {
            $error = 'Database backup failed. Please check server permissions and MySQL credentials.';
        }
    }
    
        if (isset($_POST['restore_database']) && isset($_FILES['backup_file'])) {
            $fileValidation = validateFileUpload($_FILES['backup_file'], ['sql'], 10485760); // 10MB max
            
            if (!$fileValidation['valid']) {
                $error = $fileValidation['error'];
            } else {
                $tmpFile = $_FILES['backup_file']['tmp_name'];
                
                // Read first few lines to check version
                $handle = fopen($tmpFile, 'r');
                $firstLines = '';
                for ($i = 0; $i < 10; $i++) {
                    $line = fgets($handle);
                    if ($line === false) break;
                    $firstLines .= $line;
                }
                fclose($handle);
                
                // Check for version compatibility
                if (strpos($firstLines, 'db_version') !== false || strpos($firstLines, 'SVDP') !== false) {
                    $command = sprintf(
                        'mysql -h %s -u %s -p%s %s < %s 2>&1',
                        escapeshellarg(DB_HOST),
                        escapeshellarg(DB_USER),
                        escapeshellarg(DB_PASS),
                        escapeshellarg(DB_NAME),
                        escapeshellarg($tmpFile)
                    );
                    
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0) {
                        header('Location: /settings.php?success=database_restored');
                        exit;
                    } else {
                        error_log('Database restore error: ' . implode("\n", $output));
                        $error = 'Database restore failed. Please check the backup file.';
                    }
                } else {
                    $error = 'Invalid backup file format.';
                }
            }
        }
    }
}

// Get current settings
$foodVisitsPerMonth = getSetting('food_visits_per_month', 2);
$foodVisitsPerYear = getSetting('food_visits_per_year', 12);
$foodMinDaysBetween = getSetting('food_min_days_between', 14);
$moneyMaxLifetime = getSetting('money_max_lifetime_visits', 3);
$moneyCooldownYears = getSetting('money_cooldown_years', 1);
$companyName = getSetting('company_name', COMPANY_NAME);
$partnerStoreName = getSetting('partner_store_name', PARTNER_STORE_NAME);
?>
<div class="page-header">
    <h2>Settings</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo h($error); ?></div>
<?php endif; ?>

<div class="settings">
    <div class="settings-section">
        <h3>Visit Limits</h3>
        <form method="POST" class="form">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <fieldset>
                <legend>Food Visit Limits</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="food_visits_per_month">Visits Per Month</label>
                        <input type="number" id="food_visits_per_month" name="food_visits_per_month" 
                               value="<?php echo h($foodVisitsPerMonth); ?>" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="food_visits_per_year">Visits Per Year</label>
                        <input type="number" id="food_visits_per_year" name="food_visits_per_year" 
                               value="<?php echo h($foodVisitsPerYear); ?>" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="food_min_days_between">Minimum Days Between Visits</label>
                        <input type="number" id="food_min_days_between" name="food_min_days_between" 
                               value="<?php echo h($foodMinDaysBetween); ?>" min="1" required>
                    </div>
                </div>
            </fieldset>
            
            <fieldset>
                <legend>Money Visit Limits</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="money_max_lifetime_visits">Maximum Lifetime Visits</label>
                        <input type="number" id="money_max_lifetime_visits" name="money_max_lifetime_visits" 
                               value="<?php echo h($moneyMaxLifetime); ?>" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="money_cooldown_years">Cooldown Period (Years)</label>
                        <input type="number" id="money_cooldown_years" name="money_cooldown_years" 
                               value="<?php echo h($moneyCooldownYears); ?>" min="0" step="0.5" required>
                    </div>
                </div>
            </fieldset>
            
            <div class="form-actions">
                <button type="submit" name="update_visit_limits" class="btn btn-primary">Update Visit Limits</button>
            </div>
        </form>
    </div>
    
    <div class="settings-section">
        <h3>Website Appearance</h3>
        <form method="POST" class="form">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="company_name">Company Name</label>
                    <input type="text" id="company_name" name="company_name" 
                           value="<?php echo h($companyName); ?>" required>
                </div>
                <div class="form-group">
                    <label for="partner_store_name">Partner Store Name</label>
                    <input type="text" id="partner_store_name" name="partner_store_name" 
                           value="<?php echo h($partnerStoreName); ?>" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="update_appearance" class="btn btn-primary">Update Appearance</button>
            </div>
        </form>
    </div>
    
    <div class="settings-section">
        <h3>Database Management</h3>
        <div class="database-actions">
            <div class="action-item">
                <h4>Backup Database</h4>
                <p>Download a complete backup of the database. Backups include version information for compatibility checking.</p>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="backup_database" class="btn btn-primary">Download Backup</button>
                </form>
            </div>
            
            <div class="action-item">
                <h4>Restore Database</h4>
                <p>Restore the database from a backup file. Only compatible backup files can be restored.</p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label for="backup_file">Select Backup File</label>
                        <input type="file" id="backup_file" name="backup_file" accept=".sql" required>
                    </div>
                    <button type="submit" name="restore_database" class="btn btn-warning" 
                            onclick="return confirm('WARNING: This will replace all current data. Are you sure?');">
                        Restore Database
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

