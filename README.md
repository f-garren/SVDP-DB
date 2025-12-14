# SVDP Database Management System

A comprehensive web application for managing a non-profit food distribution and thrift store. Built with PHP, MySQL, HTML, CSS, and minimal JavaScript.

## Features

### Core Functionality
- **Customer Management**: Sign up, search, view, and edit customer information
- **Visit Tracking**: Record food, money, and voucher visits with configurable limits
- **Voucher System**: Create and redeem vouchers with unique codes
- **Reporting**: View statistics and export data to CSV
- **Employee Management**: Role-based access control with permissions
- **Audit Trail**: Complete history of customer data changes
- **Database Backup/Restore**: Built-in backup and restore functionality

### Visit Types
- **Food Visits**: Configurable limits (per month, per year, minimum days between)
- **Money Visits**: Lifetime visit limits and cooldown periods
- **Voucher Visits**: Generate unique voucher codes with optional expiration

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher (or MariaDB 10.3+)
- Nginx (or Apache with mod_rewrite)
- PHP extensions: mysql, mbstring, xml, curl

## Installation

### Quick Setup

1. Clone or download this repository
2. Run the setup script as root:
   ```bash
   sudo ./setup.sh
   ```
3. Follow the prompts to configure:
   - Installation directory (default: `/var/www/svdp`)
   - Database credentials
   - Admin account
   - Domain name (optional)
   - HTTPS setup (optional, recommended)

### Manual Setup

1. **Database Setup**:
   ```bash
   mysql -u root -p < database/schema.sql
   ```

2. **Configure Database**:
   Edit `config.php` and update:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
   - `ADMIN_ACCOUNTS` (comma-separated list of admin usernames)

3. **Web Server Configuration**:
   - Point document root to the application directory
   - Ensure PHP-FPM is configured
   - Set proper file permissions (www-data user)

4. **Create Admin Account**:
   ```sql
   INSERT INTO employees (username, password_hash, password_reset_required)
   VALUES ('admin', '$2y$12$...', TRUE);
   ```
   Generate password hash using:
   ```php
   password_hash('your_password', PASSWORD_BCRYPT, ['cost' => 12]);
   ```

## Configuration

### Config File (`config.php`)

Key settings:
- Database connection details
- Company name and partner store name
- Admin account names
- Session lifetime
- Bcrypt cost factor

### Settings Page

Admin users can configure:
- Visit limits (food and money visits)
- Company information
- Website appearance

## User Permissions

The system supports role-based permissions:
- `customer_creation`: Create and edit customers
- `food_visit_entry`: Record food visits
- `money_visit_entry`: Record money visits
- `voucher_creation`: Create vouchers
- `settings_access`: Access settings page
- `report_access`: View reports

Admin accounts (defined in `config.php`) have all permissions automatically.

## Database Schema

The database includes:
- `customers`: Customer information
- `household_members`: Household member details
- `household_income`: Income tracking
- `visits`: Visit records
- `vouchers`: Voucher information
- `employees`: Employee accounts
- `employee_permissions`: Permission assignments
- `customer_audit`: Change history
- `settings`: System configuration

## Security Features

- Password hashing with bcrypt
- SQL injection prevention (prepared statements)
- XSS protection (output escaping)
- Session management
- Role-based access control
- Password reset requirements
- Soft delete for visits (audit trail)

## Backup and Restore

### Backup
1. Go to Settings → Database Management
2. Click "Download Backup"
3. Backup includes version information for compatibility checking

### Restore
1. Go to Settings → Database Management
2. Select a backup file
3. Confirm restore (WARNING: This replaces all current data)

Backup files are named: `backup_YYYY-MM-DD_HHMMSS_vX.X.sql`

## Default Settings

- Food visits: 2 per month, 12 per year, 14 days minimum between
- Money visits: 3 lifetime, 1 year cooldown
- Company name: "St. Vincent de Paul"
- Partner store: "Partner Store"

## Troubleshooting

### Database Connection Issues
- Verify credentials in `config.php`
- Check MySQL service is running
- Ensure database user has proper permissions

### Permission Errors
- Check file ownership (should be www-data)
- Verify directory permissions (755 for directories, 644 for files)
- Ensure backups directory is writable (775)

### Session Issues
- Check PHP session directory is writable
- Verify session configuration in `config.php`

## Development

### File Structure
```
/
├── api/              # API endpoints
├── css/              # Stylesheets
├── database/         # SQL schema
├── includes/         # PHP includes (auth, db, functions)
├── backups/          # Database backups (created automatically)
├── config.php        # Configuration
├── *.php            # Main application pages
└── setup.sh         # Setup script
```

### Adding New Features

1. Update database schema if needed
2. Add functions to `includes/functions.php`
3. Create page files
4. Update navigation if needed
5. Add permissions if required

## License

This software is provided as-is for non-profit use.

## Support

For issues or questions, please refer to the documentation or contact your system administrator.

