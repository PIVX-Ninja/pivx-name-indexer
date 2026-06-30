FROM quay.io/centos/centos:stream10

# 1. Enable CRB and EPEL repositories
RUN dnf install -y dnf-plugins-core && \
    dnf config-manager --set-enabled crb && \
    dnf install -y https://dl.fedoraproject.org/pub/epel/epel-release-latest-10.noarch.rpm

# 2. Configure Percona Server for MySQL 8.4 repository
RUN echo -e "[percona-ps-84-lts]\n\
name=Percona Server 8.4 LTS\n\
baseurl=https://repo.percona.com/ps-84-lts/yum/release/10/RPMS/x86_64\n\
enabled=1\n\
gpgcheck=1\n\
gpgkey=https://repo.percona.com/yum/PERCONA-PACKAGING-KEY\n\
\n\
[percona-telemetry]\n\
name=Percona Telemetry\n\
baseurl=https://repo.percona.com/telemetry/yum/release/10/RPMS/x86_64\n\
enabled=1\n\
gpgcheck=1\n\
gpgkey=https://repo.percona.com/yum/PERCONA-PACKAGING-KEY" > /etc/yum.repos.d/percona.repo && \
    rpm --import https://repo.percona.com/yum/PERCONA-PACKAGING-KEY

# 3. Install Percona Server 8.4 (disabling weak deps to prevent conflicts with default MariaDB packages)
RUN dnf install -y --setopt=install_weak_deps=False percona-server-server

# 4. Enable Remi Repository for PHP 8.5
RUN dnf install -y https://rpms.remirepo.net/enterprise/remi-release-10.rpm && \
    dnf module reset php -y && \
    dnf module enable php:remi-8.5 -y

# 5. Install PHP 8.5 and extensions (disabling weak deps)
RUN dnf install -y --setopt=install_weak_deps=False \
    php \
    php-cli \
    php-fpm \
    php-mysqlnd \
    php-sodium \
    php-bcmath \
    php-xml \
    php-mbstring \
    php-json \
    php-gmp

# 6. Install system utilities and Nginx (disabling weak deps)
RUN dnf install -y --setopt=install_weak_deps=False \
    nginx \
    cronie \
    logrotate \
    gzip \
    tar \
    unzip \
    curl \
    procps-ng \
    git

# 7. Copy custom config files
COPY resources/docker/nginx.conf /etc/nginx/nginx.conf
COPY resources/docker/php-fpm.conf /etc/php-fpm.d/www.conf
COPY resources/docker/my.cnf /etc/my.cnf
COPY resources/docker/logrotate-indexer /etc/logrotate.d/indexer
COPY resources/docker/indexer-cron /etc/cron.d/indexer-sync

# 8. Configure correct permissions for cron
RUN chmod 0644 /etc/cron.d/indexer-sync && \
    chown root:root /etc/cron.d/indexer-sync

# 9. Download and setup rr-proxy (v0.1.0 release binary + configs)
RUN mkdir -p /opt/rr-proxy && \
    curl -L -o /opt/rr-proxy/rr-proxy.gz https://github.com/PIVX-Ninja/pivx-name-rr-proxy/releases/download/v0.1.0/rr-proxy.gz && \
    gunzip /opt/rr-proxy/rr-proxy.gz && \
    chmod +x /opt/rr-proxy/rr-proxy && \
    curl -L -o /opt/rr-proxy/config.toml https://raw.githubusercontent.com/PIVX-Ninja/pivx-name-rr-proxy/v0.1.0/config.toml && \
    curl -L -o /lib/systemd/system/rr-proxy.service https://raw.githubusercontent.com/PIVX-Ninja/pivx-name-rr-proxy/v0.1.0/contrib/rr-proxy.service && \
    sed -i 's|/run/rr-proxy/rr-proxy.sock|/opt/rr-proxy/indexer.sock|g' /opt/rr-proxy/config.toml && \
    sed -i 's|/opt/rr-proxy/indexer_rocksdb|/opt/rr-proxy/db|g' /opt/rr-proxy/config.toml

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 11. Copy indexer codebase & run composer install
RUN mkdir -p /opt/pivx-name-indexer
COPY . /opt/pivx-name-indexer
RUN rm -rf /opt/pivx-name-indexer/resources/docker
WORKDIR /opt/pivx-name-indexer
RUN chmod +x /opt/pivx-name-indexer/indexer.php && \
    composer install --no-dev --optimize-autoloader --prefer-source

# 12. Create local indexer command wrapper in PATH
RUN echo -e '#!/bin/bash\ncd /opt/pivx-name-indexer && php indexer.php "$@"' > /usr/local/bin/indexer && \
    chmod +x /usr/local/bin/indexer

# 13. Copy entrypoint and configuration scripts
COPY resources/docker/configure.php /configure.php
COPY resources/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# 14. Expose Nginx port
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
