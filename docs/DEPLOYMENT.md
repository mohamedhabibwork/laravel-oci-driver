# Deployment Guide

This guide provides production deployment strategies for the Laravel OCI Driver.

---

## Production Deployment Strategies

### 1. Blue-Green Deployment

Blue-green deployment allows for zero-downtime updates by maintaining two identical production environments.

```bash
# Deploy to green environment
php artisan migrate --env=green
php artisan config:cache --env=green
php artisan route:cache --env=green

# Test green environment
php artisan oci:status --env=green

# Switch traffic to green
# Update load balancer configuration

# Keep blue environment as rollback option
```

### 2. Rolling Deployment

Rolling deployment updates servers one at a time to minimize downtime.

```bash
#!/bin/bash
# rolling-deploy.sh

SERVERS=("server1" "server2" "server3")
DEPLOY_DIR="/var/www/laravel-app"

for server in "${SERVERS[@]}"; do
    echo "Deploying to $server..."
    
    # Remove from load balancer
    ssh $server "sudo systemctl stop nginx"
    
    # Deploy code
    ssh $server "cd $DEPLOY_DIR && git pull origin main"
    ssh $server "cd $DEPLOY_DIR && composer install --no-dev --optimize-autoloader"
    ssh $server "cd $DEPLOY_DIR && php artisan config:cache"
    ssh $server "cd $DEPLOY_DIR && php artisan route:cache"
    ssh $server "cd $DEPLOY_DIR && php artisan view:cache"
    
    # Test OCI connection
    ssh $server "cd $DEPLOY_DIR && php artisan oci:status"
    
    # Add back to load balancer
    ssh $server "sudo systemctl start nginx"
    
    echo "Deployment to $server completed"
    sleep 30  # Wait before next server
done
```

### 3. Canary Deployment

Canary deployment gradually rolls out changes to a subset of users.

```yaml
# nginx-canary.conf
upstream app_servers {
    server app1.example.com weight=9;
    server app2.example.com weight=1;  # Canary server
}

server {
    listen 80;
    server_name example.com;
    
    location / {
        proxy_pass http://app_servers;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

---

## Environment Configuration

### Production Environment Variables

```env
# .env.production
APP_NAME="Laravel OCI App"
APP_ENV=production
APP_KEY=base64:your-production-key-here
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_PORT=3306
DB_DATABASE=your_production_db
DB_USERNAME=your_db_user
DB_PASSWORD=your_secure_password

# OCI Configuration
OCI_TENANCY_OCID=ocid1.tenancy.oc1..your-tenancy-id
OCI_USER_OCID=ocid1.user.oc1..your-user-id
OCI_FINGERPRINT=your:key:fingerprint
OCI_PRIVATE_KEY_PATH=/secure/oci-keys/api_key.pem
OCI_REGION=us-phoenix-1
OCI_NAMESPACE=your-namespace
OCI_BUCKET=your-production-bucket

# Cache and Session
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=info

# Mail
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Environment-Specific Configuration

```php
// config/oci-production.php
return [
    'connection_pool_size' => 50,
    'timeout' => 120,
    'retry_max' => 5,
    'cache_ttl' => 3600,
    'debug' => false,
    
    'monitoring' => [
        'enabled' => true,
        'log_slow_operations' => true,
        'slow_operation_threshold' => 5.0,
        'metrics_endpoint' => env('METRICS_ENDPOINT'),
    ],
    
    'security' => [
        'ip_whitelist' => explode(',', env('OCI_IP_WHITELIST', '')),
        'rate_limit' => 1000, // requests per minute
        'require_https' => true,
    ],
    
    'performance' => [
        'enable_compression' => true,
        'parallel_uploads' => 8,
        'chunk_size' => 16777216, // 16MB
    ],
];
```

---

## Security Considerations

### SSL/TLS Configuration

```nginx
# nginx-ssl.conf
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    
    ssl_certificate /etc/ssl/certs/your-domain.crt;
    ssl_certificate_key /etc/ssl/private/your-domain.key;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    ssl_prefer_server_ciphers off;
    
    # Security headers
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    
    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Firewall Configuration

```bash
# UFW firewall rules
sudo ufw default deny incoming
sudo ufw default allow outgoing

# SSH (change port if needed)
sudo ufw allow 22/tcp

# HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Application ports
sudo ufw allow 8000/tcp

# Database (only from app servers)
sudo ufw allow from 10.0.0.0/8 to any port 3306

# Redis (only from app servers)
sudo ufw allow from 10.0.0.0/8 to any port 6379

sudo ufw enable
```

### Key Management

```bash
# Secure key storage
sudo mkdir -p /secure/oci-keys
sudo chmod 700 /secure/oci-keys
sudo chown www-data:www-data /secure/oci-keys

# Move keys to secure location
sudo mv .oci/api_key.pem /secure/oci-keys/
sudo chmod 600 /secure/oci-keys/api_key.pem
sudo chown www-data:www-data /secure/oci-keys/api_key.pem

# Update environment
echo "OCI_PRIVATE_KEY_PATH=/secure/oci-keys/api_key.pem" >> .env
```

---

## Performance Optimization

### PHP-FPM Configuration

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
[www]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 1000

; Performance tuning
request_terminate_timeout = 300
request_slowlog_timeout = 10
slowlog = /var/log/php-fpm-slow.log

; Environment variables
env[OCI_TENANCY_OCID] = $OCI_TENANCY_OCID
env[OCI_USER_OCID] = $OCI_USER_OCID
env[OCI_FINGERPRINT] = $OCI_FINGERPRINT
env[OCI_PRIVATE_KEY_PATH] = $OCI_PRIVATE_KEY_PATH
env[OCI_REGION] = $OCI_REGION
env[OCI_NAMESPACE] = $OCI_NAMESPACE
env[OCI_BUCKET] = $OCI_BUCKET
```

### Laravel Optimization

```bash
# Production optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Optimize Composer autoloader
composer install --optimize-autoloader --no-dev

# Enable OPcache
echo "opcache.enable=1" >> /etc/php/8.2/fpm/php.ini
echo "opcache.memory_consumption=256" >> /etc/php/8.2/fpm/php.ini
echo "opcache.max_accelerated_files=20000" >> /etc/php/8.2/fpm/php.ini
echo "opcache.validate_timestamps=0" >> /etc/php/8.2/fpm/php.ini
```

### Database Optimization

```sql
-- MySQL optimization for OCI metadata
CREATE INDEX idx_file_path ON file_metadata(file_path);
CREATE INDEX idx_user_files ON file_metadata(user_id, created_at);
CREATE INDEX idx_file_downloads ON file_downloads(file_path, downloaded_at);

-- Optimize queries
ANALYZE TABLE file_metadata;
ANALYZE TABLE file_downloads;
```

---

## Monitoring and Logging

### Application Monitoring

```php
// config/logging.php
'channels' => [
    'oci' => [
        'driver' => 'daily',
        'path' => storage_path('logs/oci.log'),
        'level' => env('LOG_LEVEL', 'info'),
        'days' => 30,
    ],
    
    'performance' => [
        'driver' => 'daily',
        'path' => storage_path('logs/performance.log'),
        'level' => 'info',
        'days' => 7,
    ],
    
    'security' => [
        'driver' => 'daily',
        'path' => storage_path('logs/security.log'),
        'level' => 'warning',
        'days' => 90,
    ],
],
```

### Health Check Endpoint

```php
// routes/web.php
Route::get('/health', function () {
    $checks = [
        'database' => $this->checkDatabase(),
        'cache' => $this->checkCache(),
        'oci' => $this->checkOci(),
        'disk_space' => $this->checkDiskSpace(),
    ];
    
    $healthy = collect($checks)->every(fn($check) => $check['status'] === 'ok');
    
    return response()->json([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $healthy ? 200 : 503);
});
```

### Log Rotation

```bash
# /etc/logrotate.d/laravel-oci
/var/www/laravel-app/storage/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 www-data www-data
    postrotate
        /bin/kill -USR1 $(cat /run/php/php8.2-fpm.pid 2>/dev/null) 2>/dev/null || true
    endscript
}
```

---

## CI/CD Integration

### GitHub Actions Workflow

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: 8.2
        extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv, imagick, openssl
    
    - name: Install dependencies
      run: composer install --prefer-dist --no-progress
    
    - name: Run tests
      run: php artisan test
    
    - name: Run static analysis
      run: ./vendor/bin/phpstan analyse
  
  deploy:
    needs: test
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main'
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Deploy to production
      uses: appleboy/ssh-action@v0.1.5
      with:
        host: ${{ secrets.PRODUCTION_HOST }}
        username: ${{ secrets.PRODUCTION_USER }}
        key: ${{ secrets.PRODUCTION_SSH_KEY }}
        script: |
          cd /var/www/laravel-app
          git pull origin main
          composer install --no-dev --optimize-autoloader
          php artisan migrate --force
          php artisan config:cache
          php artisan route:cache
          php artisan view:cache
          php artisan queue:restart
          sudo systemctl reload php8.2-fpm
          sudo systemctl reload nginx
          
          # Test deployment
          php artisan oci:status
          curl -f http://localhost/health || exit 1
```

### GitLab CI/CD

```yaml
# .gitlab-ci.yml
stages:
  - test
  - build
  - deploy

variables:
  MYSQL_DATABASE: laravel_test
  MYSQL_ROOT_PASSWORD: secret

test:
  stage: test
  image: php:8.2
  services:
    - mysql:8.0
  before_script:
    - apt-get update -qq && apt-get install -y -qq git curl libmcrypt-dev libjpeg-dev libpng-dev libfreetype6-dev libbz2-dev
    - docker-php-ext-install pdo_mysql
    - curl -sS https://getcomposer.org/installer | php
    - php composer.phar install --prefer-dist --no-progress
  script:
    - cp .env.testing .env
    - php artisan key:generate
    - php artisan migrate
    - php artisan test

deploy_production:
  stage: deploy
  image: alpine:latest
  before_script:
    - apk add --no-cache openssh-client
    - eval $(ssh-agent -s)
    - echo "$SSH_PRIVATE_KEY" | tr -d '\r' | ssh-add -
    - mkdir -p ~/.ssh
    - chmod 700 ~/.ssh
  script:
    - ssh -o StrictHostKeyChecking=no $PRODUCTION_USER@$PRODUCTION_HOST "
        cd /var/www/laravel-app &&
        git pull origin main &&
        composer install --no-dev --optimize-autoloader &&
        php artisan migrate --force &&
        php artisan config:cache &&
        php artisan route:cache &&
        php artisan view:cache &&
        php artisan queue:restart &&
        sudo systemctl reload php8.2-fpm &&
        sudo systemctl reload nginx &&
        php artisan oci:status
      "
  only:
    - main
  when: manual
```

---

## Docker Deployment

### Dockerfile

```dockerfile
# Dockerfile
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nginx \
    supervisor

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . /var/www

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www
RUN chmod -R 755 /var/www/storage

# Copy configuration files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

### Docker Compose

```yaml
# docker-compose.yml
version: '3.8'

services:
  app:
    build: .
    ports:
      - "80:80"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_HOST=mysql
      - REDIS_HOST=redis
      - OCI_TENANCY_OCID=${OCI_TENANCY_OCID}
      - OCI_USER_OCID=${OCI_USER_OCID}
      - OCI_FINGERPRINT=${OCI_FINGERPRINT}
      - OCI_PRIVATE_KEY_PATH=/secure/api_key.pem
      - OCI_REGION=${OCI_REGION}
      - OCI_NAMESPACE=${OCI_NAMESPACE}
      - OCI_BUCKET=${OCI_BUCKET}
    volumes:
      - ./storage:/var/www/storage
      - ./secure:/secure:ro
    depends_on:
      - mysql
      - redis
    networks:
      - laravel

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - laravel

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis_data:/data
    networks:
      - laravel

volumes:
  mysql_data:
  redis_data:

networks:
  laravel:
    driver: bridge
```

---

## Kubernetes Deployment

### Deployment Configuration

```yaml
# k8s/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-oci-app
  labels:
    app: laravel-oci-app
spec:
  replicas: 3
  selector:
    matchLabels:
      app: laravel-oci-app
  template:
    metadata:
      labels:
        app: laravel-oci-app
    spec:
      containers:
      - name: laravel-oci-app
        image: your-registry/laravel-oci-app:latest
        ports:
        - containerPort: 80
        env:
        - name: APP_ENV
          value: "production"
        - name: APP_DEBUG
          value: "false"
        - name: DB_HOST
          value: "mysql-service"
        - name: REDIS_HOST
          value: "redis-service"
        - name: OCI_TENANCY_OCID
          valueFrom:
            secretKeyRef:
              name: oci-secrets
              key: tenancy-ocid
        - name: OCI_USER_OCID
          valueFrom:
            secretKeyRef:
              name: oci-secrets
              key: user-ocid
        - name: OCI_FINGERPRINT
          valueFrom:
            secretKeyRef:
              name: oci-secrets
              key: fingerprint
        - name: OCI_PRIVATE_KEY_PATH
          value: "/secure/api_key.pem"
        - name: OCI_REGION
          valueFrom:
            configMapKeyRef:
              name: oci-config
              key: region
        - name: OCI_NAMESPACE
          valueFrom:
            configMapKeyRef:
              name: oci-config
              key: namespace
        - name: OCI_BUCKET
          valueFrom:
            configMapKeyRef:
              name: oci-config
              key: bucket
        volumeMounts:
        - name: oci-private-key
          mountPath: /secure
          readOnly: true
        - name: storage
          mountPath: /var/www/storage
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        livenessProbe:
          httpGet:
            path: /health
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /health
            port: 80
          initialDelaySeconds: 5
          periodSeconds: 5
      volumes:
      - name: oci-private-key
        secret:
          secretName: oci-private-key
          defaultMode: 0600
      - name: storage
        persistentVolumeClaim:
          claimName: laravel-storage-pvc
```

### Service Configuration

```yaml
# k8s/service.yaml
apiVersion: v1
kind: Service
metadata:
  name: laravel-oci-service
spec:
  selector:
    app: laravel-oci-app
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: LoadBalancer
```

### Secrets and ConfigMaps

```yaml
# k8s/secrets.yaml
apiVersion: v1
kind: Secret
metadata:
  name: oci-secrets
type: Opaque
data:
  tenancy-ocid: <base64-encoded-tenancy-ocid>
  user-ocid: <base64-encoded-user-ocid>
  fingerprint: <base64-encoded-fingerprint>

---
apiVersion: v1
kind: Secret
metadata:
  name: oci-private-key
type: Opaque
data:
  api_key.pem: <base64-encoded-private-key>

---
apiVersion: v1
kind: ConfigMap
metadata:
  name: oci-config
data:
  region: "us-phoenix-1"
  namespace: "your-namespace"
  bucket: "your-production-bucket"
```

---

## Load Balancing Considerations

### Nginx Load Balancer

```nginx
# nginx-lb.conf
upstream laravel_backend {
    least_conn;
    server 10.0.1.10:80 max_fails=3 fail_timeout=30s;
    server 10.0.1.11:80 max_fails=3 fail_timeout=30s;
    server 10.0.1.12:80 max_fails=3 fail_timeout=30s;
}

server {
    listen 80;
    server_name your-domain.com;
    
    location / {
        proxy_pass http://laravel_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Health check
        proxy_next_upstream error timeout http_500 http_502 http_503;
        proxy_connect_timeout 5s;
        proxy_send_timeout 10s;
        proxy_read_timeout 10s;
    }
    
    location /health {
        access_log off;
        proxy_pass http://laravel_backend;
    }
}
```

### Session Affinity

```php
// config/session.php
'driver' => env('SESSION_DRIVER', 'redis'),
'connection' => 'session',

// config/database.php
'redis' => [
    'session' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 1,
    ],
],
```

---

## Post-Deployment Checklist

### Immediate Checks
- [ ] Application responds to HTTP requests
- [ ] Health check endpoint returns 200
- [ ] OCI connection test passes
- [ ] Database migrations completed
- [ ] Cache is working
- [ ] Queues are processing
- [ ] Logs are being written

### Performance Checks
- [ ] Response times are acceptable
- [ ] Memory usage is within limits
- [ ] CPU usage is reasonable
- [ ] Database queries are optimized
- [ ] OCI operations are fast

### Security Checks
- [ ] SSL certificates are valid
- [ ] Firewall rules are active
- [ ] File permissions are correct
- [ ] Secrets are not exposed
- [ ] Audit logs are enabled

### Monitoring Setup
- [ ] Application monitoring is active
- [ ] Error tracking is configured
- [ ] Performance metrics are collected
- [ ] Alerts are configured
- [ ] Log aggregation is working

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Security Guide](SECURITY.md)
- [Performance Guide](PERFORMANCE.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md) 