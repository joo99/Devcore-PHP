# Clinic API — PHP Version

A full port of the clinic booking API from Node.js/Express + Supabase JS SDK to **PHP + PDO**, keeping the same routes, the same Arabic messages, and the same dynamic `current_bookings` logic. The backend now runs on **MySQL / MariaDB** (Hostinger shared hosting), which does not support PostgreSQL.

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension enabled (to connect to MySQL/MariaDB on Hostinger)
- A web server (Apache/LiteSpeed with `mod_rewrite`, or Nginx, or `php -S` for local testing)
- Import `hostinger_mysql_schema.sql` once via phpMyAdmin to create the tables

## Project structure

```
clicnc/
├── api/
│   ├── index.php          # all routes and the request router
│   └── .htaccess          # front controller: routes /api/* to index.php
├── config/
│   └── database.php       # database connection + loading environment variables
├── helpers/
│   ├── http.php           # jsonResponse / jsonError / reading the request body
│   ├── jwt.php            # sign/verify JWT (HS256) with no external libraries
│   └── slots.php          # current_bookings counting + slot formatting
├── .htaccess              # HTTPS/React-Router + protects .env/config/helpers
├── .env.example           # environment variables template
└── hostinger_mysql_schema.sql  # MySQL schema to import once
```

## Setup

1. Copy `.env.example` to `.env` and fill in the values:

```bash
cp .env.example .env
```

2. Get `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` from **hPanel → Databases → MySQL Databases** (on Hostinger the host is usually `localhost`). Set a long random `JWT_SECRET`.

3. Import the schema once through **hPanel → phpMyAdmin → Import** using `hostinger_mysql_schema.sql`.

4. Run locally for testing:

```bash
php -S localhost:3000 api/index.php
```

> Note: when running with `php -S` directly, `.htaccess` is ignored. Pass `api/index.php` as the router script (as above) so every request reaches the router, or use a real Apache/Nginx server to get the same clean routes as the Express version (`/api/departments`).

### Running on Apache / LiteSpeed
Put the files under `public_html`, make sure `mod_rewrite` is enabled, and it works out of the box thanks to `.htaccess` (root) and `api/.htaccess` (front controller).

### Running on Nginx
Add to the server config:

```nginx
location / {
    try_files $uri $uri/ /api/index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Database

Tables: `admins`, `departments` (with an `order` column), `doctor_types`, `custom_slots`, `bookings`. The full schema is in `hostinger_mysql_schema.sql`. Numeric columns come back as numbers and boolean columns as `true`/`false` in the JSON responses, matching the original Postgres behavior the frontend depends on.

## Authentication & accounts

- Login endpoint: `POST /api/admin/login` — validates against the `admins` table.
- Each account has a `role`. The login response returns the account under a role-based key: role `admin` → `"admin"`, any other role → `"user"`.
- Default seeded accounts: `admin@clinic.com` / `admin123` (admin) and `user@clinic.com` / `user123` (user). **Change these passwords.**
- Note: the backend does not yet enforce the token/role on protected endpoints — access control is currently handled by the dashboard UI.

## Differences from the Node.js version

| Aspect | Node/Express | PHP |
|---|---|---|
| Database access | Supabase JS SDK (REST) | PDO directly on MySQL/MariaDB |
| JWT | `jsonwebtoken` library | minimal HS256 implementation in `helpers/jwt.php` (no Composer) |
| Routing | Express Router | simple regex router in `api/index.php` |
| Transactions | not used explicitly | `beginTransaction/commit/rollBack` used in `POST /api/departments` and `PUT /api/departments/:id/save` for data consistency |

All success/error messages stay in Arabic, and all response fields (`current_bookings`, `remaining`, `available`, `time_display`, ...) match the exact format of the original script.

## Security notes

- Passwords in `admins` are still compared as plain text (as in the original code) — it is strongly recommended to upgrade to hashing via `password_hash()` / `password_verify()`.
- Make sure `.env` is not reachable from the browser (the included `.htaccess` blocks it on Apache/LiteSpeed).
