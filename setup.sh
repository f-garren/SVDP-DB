#!/bin/bash

# SVDP Database Setup Script
# This script sets up the web server, database, and application

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

# Get installation directory
INSTALL_DIR="/var/www/svdp"
read -p "Installation directory [$INSTALL_DIR]: " input
INSTALL_DIR=${input:-$INSTALL_DIR}

# Create installation directory
mkdir -p "$INSTALL_DIR"
echo "Installation directory: $INSTALL_DIR"

# Update system
echo ""
echo "Updating system packages..."
apt-get update
apt-get upgrade -y

# Install required packages
echo ""
echo "Installing required packages..."
apt-get install -y nginx mysql-server php-fpm php-mysql php-mbstring php-xml php-curl unzip

# Get database credentials
echo ""
echo "Database Configuration:"
read -p "MySQL root password: " -s MYSQL_ROOT_PASS
echo ""
read -p "Database name [svdp_db]: " DB_NAME
DB_NAME=${DB_NAME:-svdp_db}
read -p "Database user [svdp_user]: " DB_USER
DB_USER=${DB_USER:-svdp_user}

# Generate secure random password for database user
echo ""
echo "Generating secure database password..."
if command -v openssl &> /dev/null; then
    # Use openssl if available (preferred method)
    DB_PASS=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-25)
elif [ -c /dev/urandom ]; then
    # Fallback to /dev/urandom
    DB_PASS=$(head -c 32 /dev/urandom | base64 | tr -d "=+/" | cut -c1-25)
else
    # Last resort: use date + random
    DB_PASS="SVDP$(date +%s)$(shuf -i 1000-9999 -n 1)$(shuf -i 1000-9999 -n 1)"
fi

# Ensure password meets MySQL requirements (at least 8 chars, has letters and numbers)
if [ ${#DB_PASS} -lt 8 ]; then
    DB_PASS="${DB_PASS}$(shuf -i 1000-9999 -n 1)"
fi

echo "Database password generated successfully (25 characters)."
echo ""

# Setup MySQL
echo ""
echo "Setting up MySQL database..."
mysql -u root -p"$MYSQL_ROOT_PASS" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import database schema
echo ""
echo "Importing database schema..."
if [ -f "database/schema.sql" ]; then
    mysql -u root -p"$MYSQL_ROOT_PASS" "$DB_NAME" < database/schema.sql
    echo "Database schema imported successfully."
else
    echo "Warning: database/schema.sql not found. Please import manually."
fi

# Copy application files
echo ""
echo "Copying application files..."
cp -r . "$INSTALL_DIR/"
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR/backups" 2>/dev/null || mkdir -p "$INSTALL_DIR/backups" && chmod -R 775 "$INSTALL_DIR/backups"

# Update config.php with database credentials
echo ""
echo "Updating configuration..."
sed -i "s/define('DB_NAME', '.*');/define('DB_NAME', '$DB_NAME');/" "$INSTALL_DIR/config.php"
sed -i "s/define('DB_USER', '.*');/define('DB_USER', '$DB_USER');/" "$INSTALL_DIR/config.php"
sed -i "s/define('DB_PASS', '.*');/define('DB_PASS', '$DB_PASS');/" "$INSTALL_DIR/config.php"

# Create admin account
echo ""
read -p "Admin username [admin]: " ADMIN_USER
ADMIN_USER=${ADMIN_USER:-admin}
read -p "Admin password: " -s ADMIN_PASS
echo ""

# Create PHP script to hash password
cat > /tmp/hash_password.php <<'PHPEOF'
<?php
echo password_hash($argv[1], PASSWORD_BCRYPT, ['cost' => 12]);
PHPEOF

ADMIN_HASH=$(php /tmp/hash_password.php "$ADMIN_PASS")

mysql -u root -p"$MYSQL_ROOT_PASS" "$DB_NAME" <<EOF
INSERT INTO employees (username, password_hash, password_reset_required) 
VALUES ('$ADMIN_USER', '$ADMIN_HASH', TRUE)
ON DUPLICATE KEY UPDATE password_hash = '$ADMIN_HASH', password_reset_required = TRUE;
EOF

rm -f /tmp/hash_password.php

# Configure Nginx
echo ""
echo "Configuring Nginx..."
DOMAIN=""
read -p "Domain name (or press Enter for IP-based access): " DOMAIN

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
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
    
    # Protect credentials file
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
echo ""
echo "Restarting services..."
systemctl restart nginx
systemctl restart php*-fpm
systemctl enable nginx
systemctl enable php*-fpm

# SSL/HTTPS Setup
echo ""
read -p "Do you want to set up HTTPS with Let's Encrypt? (y/n): " SETUP_HTTPS

if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "Y" ]; then
    if [ -z "$DOMAIN" ]; then
        read -p "Please enter domain name for SSL certificate: " DOMAIN
    fi
    
    # Install certbot
    apt-get install -y certbot python3-certbot-nginx
    
    # Get email for Let's Encrypt
    read -p "Email address for Let's Encrypt: " EMAIL
    
    # Ask about challenge method
    echo ""
    echo "SSL Certificate Challenge Method:"
    echo "1. HTTP challenge (default, recommended)"
    echo "2. DNS challenge (for servers behind firewall)"
    read -p "Choose method (1 or 2): " CHALLENGE_METHOD
    
    if [ "$CHALLENGE_METHOD" = "2" ]; then
        echo "You will need to manually add DNS TXT records when prompted."
        certbot --nginx -d "$DOMAIN" --email "$EMAIL" --agree-tos --preferred-challenges dns
    else
        certbot --nginx -d "$DOMAIN" --email "$EMAIL" --agree-tos
    fi
    
    # Setup auto-renewal
    systemctl enable certbot.timer
    echo "HTTPS setup complete. Certificate will auto-renew."
else
    echo "HTTPS setup skipped. You can set it up later with: certbot --nginx -d $DOMAIN"
fi

# Create index.php redirect to login
cat > "$INSTALL_DIR/index.php" <<'EOF'
<?php
header('Location: /login.php');
exit;
EOF

# Save credentials to file
CREDENTIALS_FILE="$INSTALL_DIR/.svdp_credentials"
cat > "$CREDENTIALS_FILE" <<EOF
# SVDP Database Credentials
# Generated on: $(date)
# KEEP THIS FILE SECURE - Contains sensitive information

Database Name: $DB_NAME
Database User: $DB_USER
Database Password: $DB_PASS

Admin Username: $ADMIN_USER
(Admin password was set during setup - reset required on first login)
EOF
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
echo "Database password: $DB_PASS"
echo ""
echo "IMPORTANT: Credentials have been saved to: $CREDENTIALS_FILE"
echo "          (File permissions: 600 - readable only by owner)"
echo ""
if [ -n "$DOMAIN" ]; then
    echo "Access the application at: http://$DOMAIN"
    if [ "$SETUP_HTTPS" = "y" ] || [ "$SETUP_HTTPS" = "Y" ]; then
        echo "Or via HTTPS: https://$DOMAIN"
    fi
else
    echo "Access the application at: http://$(hostname -I | awk '{print $1}')"
fi
echo ""
echo "Admin username: $ADMIN_USER"
echo "You will be required to reset the admin password on first login."
echo ""
echo "SECURITY NOTE:"
echo "- Database password has been automatically generated"
echo "- Credentials saved to: $CREDENTIALS_FILE"
echo "- Keep the credentials file secure and backed up"
echo "- Consider changing the MySQL root password if this is a production server"
echo ""
echo "Next steps:"
echo "1. Log in with the admin account"
echo "2. Reset your password"
echo "3. Configure settings in the Settings page"
echo "4. Create additional employee accounts as needed"
echo ""

