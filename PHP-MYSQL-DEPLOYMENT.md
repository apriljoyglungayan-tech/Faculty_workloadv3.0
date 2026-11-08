# PHP and MySQL deployment

This version keeps the React frontend and replaces the Node/SQLite runtime with a PHP API backed by MySQL/MariaDB. phpMyAdmin is used to create/import/manage the database; it is not the application runtime.

## Requirements

- PHP 8.1 or later (8.2+ recommended)
- MySQL or MariaDB, with a database and user created in the hosting control panel
- PHP PDO MySQL and cURL extensions
- Apache with `mod_rewrite` and `.htaccess` enabled
- HTTPS enabled for the site
- Node.js locally to build the React frontend

## Create and configure the database

1. In the host control panel, create a database and a database user. Grant that user access to this database.
2. Open phpMyAdmin, select the new database, choose **Import**, and import `database/schema.sql`.
3. Copy `private-config.example.php` to `private-config.php`. Keep the new file out of Git and ZIP archives. Enter database details, `GEMINI_API_KEY`, and `TAVILY_API_KEY` there, or configure equivalent server environment variables. Syllabus drafting uses Gemini for generation and Tavily for CHED web search; keep both keys server-side.
4. Configure distinct demo-account email addresses and unique passwords of at least 12 characters. Demo accounts are created on the first `/api/demo-accounts` request, if those email addresses do not already exist. Changing the configuration later does not reset an existing account's password.
5. Keep `SHOW_DEMO_CREDENTIALS` set to `false` unless intentionally publishing demo passwords on the login screen. Never use public demo credentials for real or sensitive data.
6. Set `COOKIE_SECURE` to `true`, use HTTPS, and leave `APP_DEBUG` set to `false`.

The host can load settings from `private-config.php` in the application directory (the supplied `.htaccess` denies web access to it). Prefer placing it outside the web root and setting `APP_CONFIG_PATH` to its absolute path when the hosting provider supports that.

## Build and upload

From the project directory:

```powershell
npm install
npm run lint
npm run build
```

Upload the **contents** of `dist/` into the website document root, alongside `.htaccess`, `index.php`, `api.php`, and `php-api.php`. Do not upload `.env`, `private-config.php` with secrets, `node_modules`, the local SQLite database, or API keys in frontend files. Keep `database/schema.sql` only if you need it for later database administration; the nested `.htaccess` denies direct web access to that folder.

Confirm the host's document root uses the provided `.htaccess`. It routes `/api/*` to the PHP API and sends frontend routes such as `/help/admin-manual` to the React app.

## Smoke checks

1. Open `https://your-domain.example/api/health`; expect JSON with `"status":"ok"`.
2. Open the home page and confirm the configured demo accounts appear. Passwords are displayed only when explicitly enabled in private configuration.
3. Sign in, change a profile field or schedule, wait for the **saved to MySQL** notice, then reload and verify the change remains.
4. Sign in as the configured administrator and open **Schedule Dataset Catalog**. The first request seeds 60 sample rows.
5. Generate a syllabus to verify the PHP cURL extension, Gemini key, and Tavily key. AR/LAR generation and refinement also use the Gemini key.

If PHP returns a server error, inspect the hosting PHP error log; do not enable `APP_DEBUG` on a public site. Ensure the database schema was imported and the PHP host permits outbound HTTPS requests to `generativelanguage.googleapis.com` and `api.tavily.com`.

## Data and security notes

- `users` stores account emails, roles, profile JSON, and PHP password hashes. `auth_sessions` stores hashes of random session tokens; the browser receives only an HttpOnly cookie.
- `user_data` stores profile, schedule, syllabus, AR, and LAR JSON scoped to the authenticated user. `schedule_datasets` contains the separate 60-row demonstration catalog.
- Existing localStorage data is not automatically copied to MySQL. Keep a backup/export of any browser-local records before switching deployments.
- The original `server.ts` is retained for reference but is not used by this PHP-hosted deployment.
- Demo authentication and sample content are for demonstration. Complete institutional privacy, backup, access-control, and security review before using real faculty or student records.
