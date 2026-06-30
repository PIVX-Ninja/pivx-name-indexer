#!/bin/bash
set -e

echo "Starting PIVX Name Indexer entrypoint script..."

# 1. Ensure required directories and permissions exist
mkdir -p /var/log/indexer /opt/rr-proxy/db /var/run/mysqld /var/lib/mysql /var/log/nginx /var/log/php-fpm /run/php-fpm
chmod 777 /var/log/indexer
chown -R mysql:mysql /var/lib/mysql /var/run/mysqld
chown -R nginx:nginx /opt/rr-proxy /var/log/nginx /var/log/php-fpm /run/php-fpm

# 1.5. Validate required runtime variables
if [ -z "$RPC_HOST" ] || [ -z "$RPC_PORT" ] || [ -z "$RPC_USER" ] || [ -z "$RPC_PASS" ]; then
    echo "========================================================================"
    echo "FATAL ERROR: PIVX Core wallet RPC settings are not configured!"
    echo "Please set RPC_HOST, RPC_PORT, RPC_USER, and RPC_PASS."
    echo "========================================================================"
    exit 1
fi

# 1.6. Determine DB_PASSWORD (write to file after MySQL is initialized)
WRITE_PASSWORD_FILE=false
if [ -z "$DB_PASSWORD" ]; then
    if [ -f "/var/lib/mysql/db_password.txt" ]; then
        DB_PASSWORD=$(cat /var/lib/mysql/db_password.txt)
        echo "Using existing automatically generated database password."
    else
        # Generate a secure 16-character alphanumeric password
        DB_PASSWORD=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 16)
        WRITE_PASSWORD_FILE=true
        echo "Automatically generated database password."
    fi
fi
export DB_PASSWORD

# 2. Run config generator
php /configure.php
echo -n "docker" > /opt/pivx-name-indexer/release_type

# 3. Check if first run
FIRST_RUN=false
if [ ! -d "/var/lib/mysql/mysql" ]; then
    echo "First run detected. Initializing Percona MySQL data directory..."
    mysqld --initialize-insecure --user=mysql
    FIRST_RUN=true
fi

# 4. Start Percona MySQL in the background
echo "Starting Percona MySQL..."
rm -f /var/run/mysqld/mysql.sock /var/run/mysqld/mysql.sock.lock
mysqld --user=mysql &
MYSQL_PID=$!

# 5. Start rr-proxy in the background
echo "Starting rr-proxy..."
/opt/rr-proxy/rr-proxy --config /opt/rr-proxy/config.toml &
RR_PROXY_PID=$!

# 6. Wait for MySQL to become ready
echo "Waiting for MySQL to start..."
until mysqladmin ping --silent; do
    sleep 1
done
echo "MySQL is ready."

# 7. Perform first-run database setup and indexer clean confirm
if [ "$FIRST_RUN" = true ]; then
    if [ "$WRITE_PASSWORD_FILE" = "true" ]; then
        echo "$DB_PASSWORD" > /var/lib/mysql/db_password.txt
        chmod 600 /var/lib/mysql/db_password.txt
        echo "Persisted automatically generated database password."
    fi
    echo "Running first-time database setup..."
    
    DB_USER=${DB_USER:-namedbuser}
    DB_NAME=${DB_NAME:-pivx-name}
    
    # Create DB and user for localhost and 127.0.0.1
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
    mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';"
    mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
    mysql -u root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';"
    mysql -u root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -u root -e "GRANT XA_RECOVER_ADMIN ON *.* TO '$DB_USER'@'127.0.0.1';"
    mysql -u root -e "GRANT XA_RECOVER_ADMIN ON *.* TO '$DB_USER'@'localhost';"
    mysql -u root -e "FLUSH PRIVILEGES;"
    
    # Import schema
    echo "Importing schema..."
    mysql -u root "$DB_NAME" < /opt/pivx-name-indexer/resources/schema.sql
    
    # Insert default EVM RPC URLs if EVM_RPC_URL is set
    if [ -n "$EVM_RPC_URL" ]; then
        for url in $(echo "$EVM_RPC_URL" | tr ',' ' '); do
            echo "Inserting EVM RPC endpoint ($url) for chain 421614 into rpc_list..."
            mysql -u root "$DB_NAME" -e "INSERT IGNORE INTO rpc_list (chain_id, rpc_url, issue_ts) VALUES (421614, '$url', 0);"
        done
    fi
    
    # Run indexer clean confirm
    echo "Initializing indexer databases via clean confirm..."
    /usr/local/bin/indexer clean confirm
    echo "Indexer databases initialized."
fi

# 8. Start PHP-FPM
echo "Starting PHP-FPM..."
php-fpm --daemonize

# 9. Start Cron daemon
echo "Starting Cron daemon..."
crond

# 10. Start Nginx in the foreground
echo "Starting Nginx..."
nginx -g "daemon off;"
