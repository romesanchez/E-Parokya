# Parish Service Compass (PHP)

PHP 8 + MySQL port of the original React/Supabase app. Same pages and features:

- `/index.php` – home: mass schedule, services
- `/request.php` – service request form with live field-by-field guidance
- `/dashboard.php` – parish office dashboard: stats, status filter, status updates

## Run with XAMPP / any Apache + MySQL host
1. Copy this folder into `htdocs` (e.g. `htdocs/parish`).
2. Import `database/schema.sql` in phpMyAdmin (creates the DB, table and sample rows).
3. If your MySQL user/password differ from `root` / empty, edit `includes/config.php`.
4. Open `http://localhost/parish/public/`.

## Quick try (no MySQL)
```sh
DB_DRIVER=sqlite php -S localhost:8000 -t public
```
Creates `data/parish.sqlite` automatically with the sample rows.

## Layout
- `public/` – web root (pages, CSS, JS, images)
- `includes/` – config, DB access, layout, service labels and guidance (`guides.php`)
- `database/` – MySQL and SQLite schemas

## Note
As in the original, **the dashboard has no login** (anyone with the link can view and change requests).
Add staff authentication before using it with real parishioner data.
