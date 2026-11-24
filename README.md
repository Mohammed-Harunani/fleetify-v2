# Fleetify 2.0

**Multi-Tenant Fleet Management and Transport Operations Platform**

Fleetify 2.0 is a PHP and MySQL-based application designed to support fleet and transport operations through a multi-company architecture.

The project focuses on tenant isolation, secure staff authentication, access control, database integrity and a modular application structure.

## Project Overview

Fleetify 2.0 is being developed as a structured evolution of the original Fleetify application.

The current implementation establishes the core application infrastructure, database schema, staff authentication and security foundations.

## Technology Stack

- PHP 8.1+
- MySQL / MariaDB
- PDO
- HTML
- Tailwind CSS (CDN)
- Apache
- XAMPP (local development)

## Architecture

```text
fleetify-v2/
│
├── config/
│   ├── bootstrap.php
│   └── database.php
│
├── database/
│   ├── schema.sql
│   ├── seed.sql
│   └── migrations/
│
├── public/
│   ├── index.php
│   └── staff/
│       ├── login.php
│       ├── dashboard.php
│       └── logout.php
│
├── src/
│   ├── Auth/
│   │   ├── Authorization.php
│   │   ├── AuthService.php
│   │   ├── IpRateLimiter.php
│   │   ├── LoginRateLimiter.php
│   │   └── StaffGuard.php
│   │
│   ├── Core/
│   │   ├── Database.php
│   │   └── Session.php
│   │
│   ├── Helpers/
│   │   └── Security.php
│   │
│   └── Repositories/
│       └── UserRepository.php
│
├── tests/
│
├── .env.example
├── .gitignore
└── README.md
```

## Database Architecture

The current database schema includes the following tables:

| Table | Purpose |
|---|---|
| countries | Country reference data |
| states | State and regional reference data |
| cities | City reference data |
| companies | Company / tenant records |
| offices | Company office locations |
| users | Staff accounts |
| clients | Client records |
| vehicles | Fleet vehicle records |
| routes | Transport routes |
| consignments | Consignment records |
| consignment_events | Consignment event history |
| payments | Payment records |

The schema provides the foundation for company-level data separation and transport operations.

## Implemented Security Features

### Authentication
- Staff login and logout.
- Password verification using PHP password hashing.
- Company-level authentication validation.
- Rejection of inactive accounts and tenants.
- Session ID regeneration following authentication.

### Rate Limiting
- Account-based login rate limiting.
- IP-based login rate limiting.
- Failed-attempt tracking.
- Time-based lockout expiry.
- Separate IP tracking using keyed HMAC-SHA256 hashes.

### Session Security
- HTTP-only session cookies.
- SameSite cookie protection.
- Secure cookie configuration for HTTPS.
- Session destruction on logout.
- Authentication timeout validation.

### Authorisation
- Centralised permission checks.
- Staff authentication guard.
- Role-based access control foundation.

### Database Security
- PDO database connectivity.
- Native prepared statements.
- Exception-based database error handling.
- Environment-driven database credentials.

## Installation

### Requirements

- PHP 8.1 or newer.
- Apache web server.
- MySQL or MariaDB.
- PDO MySQL extension.
- Git.

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/fleetify-v2.git
cd fleetify-v2
```

### 2. Create the database

Create a database named:

```sql
CREATE DATABASE fleetify_v2
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

### 3. Import the database schema

Import the schema first:

```bash
mysql -u root -p fleetify_v2 < database/schema.sql
```

Then import the seed data:

```bash
mysql -u root -p fleetify_v2 < database/seed.sql
```

Review the seed data before using it outside development.

### 4. Configure database environment variables

The application reads database configuration from:

```text
FLEETIFY_DB_HOST
FLEETIFY_DB_PORT
FLEETIFY_DB_NAME
FLEETIFY_DB_USER
FLEETIFY_DB_PASSWORD
```

An example configuration is provided in `.env.example`.

Set these variables in your Apache/PHP environment or hosting configuration.

The application does not automatically load `.env` files.

### 5. Configure the IP hashing secret

The IP rate limiter requires a separate 32-byte secret encoded in Base64.

Generate a key using:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

In the current Windows XAMPP installation, the expected location is:

```text
C:\xampp\fleetify-v2-secrets\ip_hash_secret
```

Create the directory and place the generated key in that file.

The secret must remain outside the public web root and must never be committed to Git.

For other deployment layouts, the secret path in the application must be adapted accordingly.

### 6. Configure the web root

Configure Apache's document root to point to the project's `public/` directory.

For the current local installation:

```text
C:\xampp\htdocs\fleetify-v2\public
```

Do not expose configuration, source code, database backups or development files through the public web root.

## Testing

Run individual test scripts using PHP CLI:

```bash
php tests/test_auth.php
php tests/test_authorization.php
php tests/test_staff_guard.php
php tests/test_login_rate_limiter.php
php tests/test_login_rate_limiter_expiry.php
php tests/test_auth_rate_limit.php
php tests/test_ip_rate_limiter.php
php tests/test_ip_isolation.php
php tests/test_ip_expiry.php
php tests/test_ip_secret.php
php tests/test_invalid_tenant.php
php tests/test_inactive_tenant.php
```

Some authentication tests require a configured test administrator and test environment variables.

Run tests against a development database, never a production database.

## Development Status

The current project establishes:

- Core application bootstrap.
- PDO database infrastructure.
- Multi-tenant database schema.
- Staff authentication.
- Role-based authorisation foundation.
- Account and IP rate limiting.
- Session security.
- Initial public and staff interfaces.
- Authentication and security regression tests.

The broader fleet, route, consignment and payment workflows remain subject to further implementation and integration.

## Security Considerations

- Never commit database passwords or application secrets.
- Use dedicated database credentials in production.
- Use HTTPS in production.
- Keep PHP and database software updated.
- Restrict access to configuration and storage directories.
- Review permissions before deploying new application modules.
- Maintain secure database backups.

## Project Status

Fleetify 2.0 is under active development.

The current development focus is establishing a secure, maintainable foundation for the wider fleet management platform.

## License

See the repository's license file for applicable licensing terms.