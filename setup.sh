#!/bin/bash

# SVDP Database Setup Script
# Fully automated setup with no user input required for MySQL or admin accounts

set -e

echo "========================================="
echo "SVDP Database Setup Script"
echo "========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root (use sudo)"
    exit 1
fi

# Generate secure random string function
generate_password() {
    local length=${1:-24}
    if command -v openssl &> /dev/null; then
        openssl rand -base64 32 | tr -d "=+/'\"\\\`\$" | tr '[:upper:]' '[:lower:]' | head -c "$length"
    elif [ -c /dev/urandom ]; then
        cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w "$length" | head -n 1
    else
        echo "SVDP$(date +%s | sha256sum | head -c $((length-4)))$(shuf -i 1000-9999 -n 1)"
    fi
}

# Configuration - all automatic
INSTALL_DIR="/var/www/svdp"
DB_NAME="svdp_db"
DB_USER="svdp_user"
DB_PASS=$(generate_password 32)
ADMIN_USER="admin"
ADMIN_PASS=$(generate_password 24)

# Optional configuration (can be overridden)
DOMAIN=""
SETUP_HTTPS="n"

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --install-dir)
            INSTALL_DIR="$2"
            shift 2
            ;;
        --domain)
            DOMAIN="$2"
            shift 2
            ;;
        --https)
            SETUP_HTTPS="y"
            shift
            ;;
        --help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --install-dir DIR    Installation directory (default: /var/www/svdp)"
            echo "  --domain DOMAIN      Domain name for web server"
            echo "  --https              Set up HTTPS with Let's Encrypt"
            echo "  --help               Show this help message"
            echo ""
            echo "All MySQL and admin credentials are automatically generated."
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

echo "Configuration:"
echo "  Installation directory: $INSTALL_DIR"
echo "  Database name: $DB_NAME"
echo "  Database user: $DB_USER"
echo "  Admin username: $ADMIN_USER"
echo ""

# Create installation directory
mkdir -p "$INSTALL_DIR"
echo "Created installation directory: $INSTALL_DIR"

# Update system
echo ""
echo "Updating system packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

# Install required packages
echo ""
echo "Installing required packages..."
# Detect PHP version and install appropriate packages
PHP_VERSION=$(apt-cache search --names-only '^php[0-9]' | grep -oP 'php\d+\.\d+' | sort -V | tail -1)
if [ -z "$PHP_VERSION" ]; then
    PHP_VERSION="php"
fi

apt-get install -y -qq nginx mysql-server ${PHP_VERSION}-fpm ${PHP_VERSION}-mysql ${PHP_VERSION}-mbstring ${PHP_VERSION}-xml ${PHP_VERSION}-curl unzip openssl

# Configure MySQL automatically
echo ""
echo "Configuring MySQL..."

# Check if MySQL is already configured
if ! mysql -u root -e "SELECT 1" &>/dev/null; then
    # MySQL not configured, set up root password
    MYSQL_ROOT_PASS=$(generate_password 32)
    
    # Secure MySQL installation
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASS';" 2>/dev/null || \
    mysql -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('$MYSQL_ROOT_PASS');" 2>/dev/null || \
    mysqladmin -u root password "$MYSQL_ROOT_PASS" 2>/dev/null || true
    
    echo "MySQL root password has been set."
else
    # Try to connect without password first
    if mysql -u root -e "SELECT 1" &>/dev/null 2>&1; then
        MYSQL_ROOT_PASS=""
    else
        # MySQL requires password - we'll need to handle this
        echo "MySQL root password is required but not provided."
        echo "Attempting to proceed with default authentication..."
        MYSQL_ROOT_PASS=""
    fi
fi

# Function to run MySQL commands
run_mysql() {
    if [ -z "$MYSQL_ROOT_PASS" ]; then
        mysql -u root "$@"
    else
        mysql -u root -p"$MYSQL_ROOT_PASS" "$@"
    fi
}

# Setup database and user
echo "Creating database and user..."
run_mysql <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import database schema
echo "Importing database schema..."
if [ -f "database/schema.sql" ]; then
    run_mysql "$DB_NAME" < database/schema.sql
    echo "Database schema imported successfully."
else
    echo "Warning: database/schema.sql not found. Please import manually."
fi

# Create admin account
echo "Creating admin account..."
# Create PHP script to hash password
cat > /tmp/hash_password.php <<'PHPEOF'
<?php
echo password_hash($argv[1], PASSWORD_BCRYPT, ['cost' => 12]);
PHPEOF

ADMIN_HASH=$(php /tmp/hash_password.php "$ADMIN_PASS")

run_mysql "$DB_NAME" <<EOF
INSERT INTO employees (username, password_hash, password_reset_required) 
VALUES ('$ADMIN_USER', '$ADMIN_HASH', TRUE)
ON DUPLICATE KEY UPDATE password_hash = '$ADMIN_HASH', password_reset_required = TRUE;
EOF

rm -f /tmp/hash_password.php

# Copy application files
echo ""
echo "Copying application files..."
cp -r . "$INSTALL_DIR/" 2>/dev/null || {
    # If cp -r fails (e.g., .git directory), copy files individually
    find . -maxdepth 1 -not -name '.' -not -name '..' -not -name '.git' -exec cp -r {} "$INSTALL_DIR/" \;
}
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
mkdir -p "$INSTALL_DIR/backups"
chmod 775 "$INSTALL_DIR/backups"

# Update config.php with database credentials
echo "Updating configuration..."
# Escape special characters for sed
DB_PASS_SED=$(echo "$DB_PASS" | sed 's/[[\.*^$()+?{|]/\\&/g' | sed 's/\\/\\\\/g' | sed "s/'/\\\'/g")
sed -i "s/define('DB_NAME', '.*');/define('DB_NAME', '$DB_NAME');/" "$INSTALL_DIR/config.php"
sed -i "s/define('DB_USER', '.*');/define('DB_USER', '$DB_USER');/" "$INSTALL_DIR/config.php"
sed -i "s|define('DB_PASS', '.*');|define('DB_PASS', '$DB_PASS_SED');|" "$INSTALL_DIR/config.php"

# Configure Nginx
echo ""
echo "Configuring Nginx..."
if [ -z "$DOMAIN" ]; then
    SERVER_NAME="_"
else
    SERVER_NAME="$DOMAIN"
fi

cat > /etc/nginx/sites-available/svdp <<EOF
server {
    listen 80;
    server_name $SERVER_NAME;
    root $INSTALL_DIR;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # Try to find PHP-FPM socket automatically
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
    
    location ~ /\.svdp_credentials {
        deny all;
    }
    
    location ~ /.*\.credentials {
        deny all;
    }
}
EOF

ln -sf /etc/nginx/sites-available/svdp /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Test Nginx configuration
nginx -t

# Restart services
echo "Restarting services..."
systemctl restart nginx
systemctl enable nginx

# Find and restart PHP-FPM service
PHP_FPM_SERVICE=""

# Method 1: Check installed PHP packages to determine version
INSTALLED_PHP=$(dpkg -l | grep -E '^ii\s+php[0-9]' | grep fpm | head -n 1 | awk '{print $2}' | sed 's/-fpm.*//')
if [ -n "$INSTALLED_PHP" ]; then
    PHP_FPM_SERVICE="${INSTALLED_PHP}-fpm"
fi

# Method 2: Try common service names
if [ -z "$PHP_FPM_SERVICE" ]; then
    for service in php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php7.4-fpm php7.3-fpm php-fpm; do
        if systemctl list-unit-files 2>/dev/null | grep -q "^${service}.service"; then
            PHP_FPM_SERVICE="$service"
            break
        fi
    done
fi

# Method 3: Search all unit files
if [ -z "$PHP_FPM_SERVICE" ]; then
    PHP_FPM_SERVICE=$(systemctl list-unit-files 2>/dev/null | grep -E 'php.*fpm\.service' | head -n 1 | awk '{print $1}' | sed 's/\.service$//')
fi

# Method 4: Check running services
if [ -z "$PHP_FPM_SERVICE" ]; then
    PHP_FPM_SERVICE=$(systemctl list-units --type=service 2>/dev/null | grep -E 'php.*fpm' | head -n 1 | awk '{print $1}' | sed 's/\.service$//')
fi

if [ -n "$PHP_FPM_SERVICE" ]; then
    echo "Found PHP-FPM service: $PHP_FPM_SERVICE"
    if systemctl restart "$PHP_FPM_SERVICE" 2>/dev/null; then
        echo "PHP-FPM restarted successfully"
    else
        service "$PHP_FPM_SERVICE" restart 2>/dev/null || true
    fi
    # Try to enable, but don't fail if it doesn't work
    systemctl enable "$PHP_FPM_SERVICE" 2>/dev/null || true
else
    echo "Warning: Could not automatically detect PHP-FPM service name."
    echo "PHP-FPM may need to be restarted manually."
    echo "Common service names: php8.2-fpm, php8.1-fpm, php8.0-fpm, php-fpm"
fi

# SSL/HTTPS Setup
if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "Y" ]; then
    if [ -z "$DOMAIN" ]; then
        echo ""
        echo "HTTPS setup requires a domain name."
        echo "Skipping HTTPS setup. You can set it up later with:"
        echo "  certbot --nginx -d your-domain.com"
    else
        echo ""
        echo "Setting up HTTPS with Let's Encrypt..."
        apt-get install -y -qq certbot python3-certbot-nginx
        
        # Use non-interactive mode for certbot
        certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "admin@$DOMAIN" --redirect || {
            echo "HTTPS setup failed. You can set it up later with:"
            echo "  certbot --nginx -d $DOMAIN"
        }
        
        # Setup auto-renewal
        systemctl enable certbot.timer
        echo "HTTPS setup complete. Certificate will auto-renew."
    fi
fi

# Create index.php redirect to login
cat > "$INSTALL_DIR/index.php" <<'EOF'
<?php
header('Location: /login.php');
exit;
EOF

# Save credentials to file
CREDENTIALS_FILE="$INSTALL_DIR/.svdp_credentials"
{
    echo "# SVDP Database Credentials"
    echo "# Generated on: $(date)"
    echo "# KEEP THIS FILE SECURE - Contains sensitive information"
    echo ""
    echo "Database Name: $DB_NAME"
    echo "Database User: $DB_USER"
    echo "Database Password: $DB_PASS"
    echo ""
    echo "Admin Username: $ADMIN_USER"
    echo "Admin Password: $ADMIN_PASS"
    echo ""
    echo "NOTE: Admin password reset is required on first login."
    if [ -n "$MYSQL_ROOT_PASS" ]; then
        echo ""
        echo "MySQL Root Password: $MYSQL_ROOT_PASS"
    fi
} > "$CREDENTIALS_FILE"
chmod 600 "$CREDENTIALS_FILE"
chown www-data:www-data "$CREDENTIALS_FILE"

# Final instructions
echo ""
echo "========================================="
echo "Setup Complete!"
echo "========================================="
echo ""
echo "Installation directory: $INSTALL_DIR"
echo "Database: $DB_NAME"
echo "Database user: $DB_USER"
echo "Admin username: $ADMIN_USER"
echo ""
echo "IMPORTANT: All credentials have been saved to:"
echo "  $CREDENTIALS_FILE"
echo "  (File permissions: 600 - readable only by owner)"
echo ""
if [ -n "$DOMAIN" ]; then
    if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "Y" ]; then
        echo "Access the application at: https://$DOMAIN"
    else
        echo "Access the application at: http://$DOMAIN"
    fi
else
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo "Access the application at: http://$SERVER_IP"
fi
echo ""
echo "First Login:"
echo "  1. Log in with username: $ADMIN_USER"
echo "  2. You will be prompted to reset your password"
echo "  3. Configure settings in the Settings page"
echo "  4. Create additional employee accounts as needed"
echo ""
echo "To view credentials later, run:"
echo "  sudo cat $CREDENTIALS_FILE"
echo ""
