# Practica OJT Tracking System

Practica is a PHP + MySQL web app for tracking OJT (On-the-Job Training) hours, progress, and allowance in one dashboard.

Current version: v0.5.0

Live website: https://getpractica.me

## What's Updated in v0.5.0

- Dashboard quick log flow now supports fast current-day logging from multiple triggers.
- Dashboard status and allowance cards were refined for readability and tighter spacing.
- Allowance card labels now use "Allowance Summary" with weekly and total context.
- Mobile header/sidebar behavior was fixed (toggle binding conflict resolved).
- Mobile Time Logs header actions were redesigned into touch-friendly button layout.
- Shared version display now reads from APP_VERSION consistently.

## Tech Stack

- PHP 8+
- MySQL / MariaDB (PDO)
- Vanilla JavaScript
- Modular CSS files per page/section

## Project Structure

```
ojt_tracker/
├── landing.php
├── auth.php
├── forgot_password.php
├── dashboard.php
├── logs.php
├── settings.php
├── logout.php
├── includes/
│   ├── config.php
│   ├── header.php
│   └── footer.php
├── css/
│   ├── main.css
│   ├── header.css
│   ├── auth.css
│   ├── dashboard.css
│   ├── logs.css
│   └── landing.css
└── js/
    └── app.js
```

## Core Features

- Authentication: login, register, logout, and password reset with security question.
- Dashboard analytics: total logged hours, remaining hours, completion percentage, and estimated completion date.
- Quick Log modal from dashboard for faster same-day entries.
- Allowance Summary card with collected this week, total collected, and projected remaining by days left.
- Time Logs page with calendar/list modes, pagination, edit/delete, and bulk log entry.
- Settings page for profile, required hours, allowance per day, currency, and password updates.
- Mobile-responsive layout with sidebar drawer, topbar controls, and optimized action buttons.

## Requirements

- PHP 8.0 or higher
- MySQL or MariaDB
- Apache/Nginx or PHP built-in server

## Database Setup

1. Create database:

```sql
CREATE DATABASE ojt_tracker;
```

2. Create tables (minimum required):

```sql
CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  required_hours DECIMAL(10,2) NOT NULL DEFAULT 500,
  allowance_per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'PHP',
  security_question VARCHAR(255) NULL,
  security_answer VARCHAR(255) NULL,
  email VARCHAR(255) NULL,
  tutorial_completed SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE time_logs (
  id VARCHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  description TEXT NULL,
  time_from TIME NOT NULL,
  time_to TIME NOT NULL,
  hours DECIMAL(10,4) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_time_logs_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX idx_time_logs_user_date ON time_logs(user_id, date);
```

3. Update database credentials in environment variables or `includes/config.php`:

- `DATABASE_URL` (e.g. `postgres://user:pass@host:port/dbname`)
- Or provide them individually: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`

Note: Some user columns are auto-migrated when missing, but required tables should exist first.

## Run Locally

### Option A: XAMPP

1. Put this project in htdocs (example: C:/xampp/htdocs/ojt_tracker).
2. Start Apache and MySQL in XAMPP.
3. Open http://localhost/ojt_tracker/landing.php

### Option B: PHP built-in server

```bash
cd ojt_tracker
php -S localhost:8000
```

Then open http://localhost:8000/landing.php

### Production

Live website: https://getpractica.me

## Security Notes

- Passwords use PASSWORD_DEFAULT hashing.
- Security answers are verified case-insensitively and support hashed storage.
- Output escaping uses htmlspecialchars via helper e().
- Session-based authentication is enforced on protected pages.

## Notes

- App timezone defaults to Asia/Manila.
- UI assets are cache-busted with query-string timestamps in shared includes.
