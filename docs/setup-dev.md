# Local development setup

Needs PHP 8.2+ (with `pdo_mysql`, `mbstring`), Composer 2, and MariaDB 10.6+ (or MySQL 8).
Tested with PHP 8.3 and MariaDB 10.11.

## 1. Databases

```sql
-- Development data
CREATE DATABASE muninn_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'muninn_dev'@'localhost' IDENTIFIED BY 'muninn_dev';
GRANT ALL ON muninn_dev.* TO 'muninn_dev'@'localhost';

-- Disposable test database: the test suite DROPS every table in it.
CREATE DATABASE muninn_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'muninn_test'@'localhost' IDENTIFIED BY 'muninn_test';
GRANT ALL ON muninn_test.* TO 'muninn_test'@'localhost';
```

## 2. API

```sh
cd api
composer install
cp config/config.example.php config/config.php
```

Edit `config/config.php` for local use:

```php
'app' => ['environment' => 'development'],
'database' => ['host' => '127.0.0.1', 'name' => 'muninn_dev', 'username' => 'muninn_dev', 'password' => 'muninn_dev'],
'cors' => ['allowed_origins' => ['http://localhost:8080']],
'frontend' => ['base_url' => 'http://localhost:8080'],
'logging' => ['file_path' => __DIR__ . '/../storage/logs/api.log'],
```

Then:

```sh
php bin/migrate.php
php bin/create-admin.php            # prompts for username, display name, password
php -S localhost:8000 -t public dev-router.php
```

`http://localhost:8000/api/v1/health` should return `{"data":{"status":"ok"}}`.

Browsers treat `localhost` as secure, so the `Secure` session cookie works over plain http there.
Frontend and API on different `localhost` ports are the same site, just like www.dx.se and
api.dx.se in production.

## 3. Frontend

```sh
cd frontend/public
cp includes/config.example.php includes/config.php   # set 'api_base_url' => 'http://localhost:8000'
php -S localhost:8080
```

Open `http://localhost:8080/login.php`.

## 4. Tests

```sh
cd api
vendor/bin/phpunit
```

Integration tests use `MUNINN_TEST_DB_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASSWORD`
(defaults: `127.0.0.1`, `3306`, `muninn_test`, `muninn_test`, `muninn_test`). CI runs the same
suite against MariaDB 10.11 on every pull request.

## 5. Building a release zip locally

```sh
deploy/build-release.sh v0.1.0      # → build/muninn-v0.1.0.zip
```
