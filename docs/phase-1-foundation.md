# Phase 1 — Foundation

Scaffold the application and all the plumbing later phases build on.

## What this phase delivers

- **Laravel 12** application (PHP 8.2+).
- **Jetstream** with the **Inertia + Vue 3** stack and **teams** enabled, plus
  Sanctum. Teams map naturally to sales pods for lead delegation later.
- **Broadcasting plumbing installed** (`config/broadcasting.php`,
  `routes/channels.php`) and the Pusher/Echo dependencies added, ready for the
  real-time feature in Phase 2. No live feature is wired yet.
- **Database strategy:** MySQL is the production target; SQLite is used for
  zero-config local/CI runs. Switching is a one-line `.env` change
  (`DB_CONNECTION=mysql` + `DB_*`). Migrations are MySQL-compatible.
- **Environment template** (`.env.example`) documenting Pusher, Reverb, MySQL
  and Voiceflow settings. Secrets stay out of git.

## Key files

| File | Purpose |
| ---- | ------- |
| `composer.json`, `package.json` | Backend/frontend dependencies (incl. `laravel/jetstream`, `pusher/pusher-php-server`, `laravel-echo`, `pusher-js`). |
| `config/broadcasting.php` | Broadcast connections (Pusher default). |
| `routes/channels.php` | Channel authorization (default user channel). |
| `.env.example` | Documented config template. |
| `app/Models/{User,Team}.php` | Jetstream user + team models. |

## Verify

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan test        # Jetstream suite passes
npm run build           # assets compile
php artisan serve       # / , /login , /up respond; /dashboard redirects to login
```

## Next

Phase 2 wires the first real-time feature on top of this broadcasting plumbing.
