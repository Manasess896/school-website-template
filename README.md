# Greenfield Academy - PHP/MongoDB School Website

A PHP 8.2 website with a public site and an admin dashboard. Data is stored in MongoDB, emails are sent via SMTP with PHPMailer, and environment variables are managed with phpdotenv. reCAPTCHA protects forms, and the admin area enforces Two-Factor Authentication (TOTP).

## At a glance

- PHP 8.2 (Apache) + Composer autoload
- MongoDB PHP driver and GridFS for media
- PHPMailer for SMTP email
- OTPHP for TOTP-based 2FA
- Dotenv for configuration
- Google reCAPTCHA v2 on public/admin forms
- Pexels API for stock images on the public pages
- Pretty URLs via `.htaccess`
- Dockerfile provided for containerized runs

## Project structure and what each file does

Top-level (custom files only):

- `index.php` — Public homepage. Loads images from Pexels (via `pexels-api.php`), shows sections, and implements Newsletter signup (AJAX) with CSRF + reCAPTCHA + rate limiting (MongoDB `attempts`). Writes subscribers to `newsletter` collection.
- `contact.php` — Public contact form. Validates CSRF + reCAPTCHA, stores message in `contacts` collection, and emails a confirmation to the sender via PHPMailer.
- `news-events.php` — Public listing/detail for News & Events from `news_events` collection, with filters and pagination. Uses `media.php?file=<id>` to render GridFS images (see note in Known issues).
- `media.php` — Streams GridFS files from MongoDB given `?file=<ObjectId>`, sets content type/ETag/Caching. Note: currently blocked by `.htaccess` and an early redirect (see Known issues).
- `pexels-api.php` — Fetches image search results in parallel from Pexels API and exposes arrays of image URLs (hero/about/programs/gallery/etc.) for `index.php`.
- `404.php` — Custom 404 page with helpful links and lightweight suggestions/redirects based on the URL.
- `.htaccess` — Enables pretty URLs (e.g., `/home`, `/contact-us`) and denies direct access to some internal scripts. Maps routes to PHP files (see “Routing”).
- `dockerfile` — Multi-stage build using `composer:2` and `php:8.2-apache`. Installs required PHP extensions (incl. `mongodb`).
- `.env.example` — Template of environment variables; copy to `.env` and fill in.
- `composer.json` — PHP dependencies.

Admin area (`admin/`):

- `login.php` — Admin login with CSRF + reCAPTCHA; on password success enforces 2FA. If admin has no `twofa_secret`, redirects to setup.
- `2fa-setup.php` — Generates a TOTP secret & provisioning URI (QR code) for the admin to enroll in Google/Microsoft Authenticator. Saves the secret on successful code verification.
- `2fa-verify.php` — Verifies TOTP after login. On success, creates admin session and redirects to dashboard.
- `admin.php` — Admin dashboard ("dashboard" route). Requires a valid admin session. Modules:
  - Manage News/Events: create/delete posts (optional image stored in GridFS via `fs.files`/`fs.chunks`).
  - Send newsletter: sends HTML/text emails to all subscribers using PHPMailer; includes unsubscribe footer URL from env.
  - Resolve contacts: mark contact messages as resolved.
  - (CEO only) Manage admins: add ADMIN users, manage permissions of non-CEO admins.
    Includes CSRF checks and per-action permission checks.
- `unsubscribe.php` — Public self-service unsubscribe workflow. Sends a 6-digit email code to confirm removal from `newsletter` subscription. Implements throttling and expiry.
- `admin.inject.php` — DEV-ONLY bootstrap to create the initial CEO account. It is auto-deleted upon success; do not deploy to production.
- `style.css` — Styling for admin login/dashboard.

Vendor packages reside under `vendor/` (Composer managed).

## Routing and URLs (.htaccess)

Key rewrite rules:

- `/home` -> `index.php`
- `/contact-us` -> `contact.php`
- `/news-events` or `/news&events` -> `news-events.php`
- `/login` or `/admin/login` -> `admin/login.php`
- `/2fa-setup` -> `admin/2fa-setup.php`
- `/2fa-verify` -> `admin/2fa-verify.php`
- `/dashboard` or `/admin/dashboard` -> `admin/admin.php`
- `/unsubscribe` -> `admin/unsubscribe.php`
- `ErrorDocument 404 /404.php`
- Note: Direct access to `pexels-api.php` and `media.php` is intentionally denied with 404 in the provided rules.

## Data model (MongoDB collections used)

- `admin`
  - Fields used: `email`, `username`, `role` (e.g., CEO/ADMIN), `password` (bcrypt), `twofa_secret` (TOTP), `allowed_permissions` (array), `created_at`.
- `attempts`
  - Used for rate limiting (e.g., newsletter signup): `ip`, `type` (e.g., `newsletter`), `timestamp` (int), `success` (bool).
- `newsletter`
  - Subscriber: `email`, `subscribed_at` (UTCDateTime), `ip_address`, `status` (e.g., `active`), `source`.
  - Unsubscribe flow: `pending_unsub_code_hash`, `pending_unsub_expires`, `pending_unsub_attempts`, `pending_unsub_requested_at`.
- `contacts`
  - Contact message: `name`, `email`, `subject`, `message`, `ip`, `created_at` (UTCDateTime). Admin UI marks messages resolved with `is_resolved`, `resolved_at`.
- `news_events`
  - Post: `title`, `content`, `type` (`news`|`event`), `event_date` (UTCDateTime, optional), `created_at`, `created_by` (ObjectId), `creator_name`, optional `image_file_id` (ObjectId into GridFS).
- GridFS: `fs.files`/`fs.chunks` store uploaded images; `fs.files.metadata.mime` used to set the `Content-Type`.

## Session model (who sets what and where it is used)

Common session keys:

- `csrf_token` — Anti-CSRF token set in many pages (`index.php`, `contact.php`, `admin/login.php`, `admin/2fa-*`, `admin/admin.php`) and validated on POST.

2FA login flow sessions:

- After password in `admin/login.php`:
  - If 2FA not set: `2fa_requires_setup`, `2fa_user_id`, `2fa_email` -> redirect to `2fa-setup.php`.
  - If 2FA set: `2fa_id`, `2fa_email` -> redirect to `2fa-verify.php`.
- During setup in `admin/2fa-setup.php`:
  - Generates: `twofa_setup_secret`, `twofa_setup_uri` for QR. On success, saves secret to DB, clears setup sessions, and returns to login.
- During verify in `admin/2fa-verify.php`:
  - On success: regenerates session id and sets persistent admin session:
    - `user_id`, `admin_id`, `username`, `email`, `user_role`, `is_admin` (true), `2fa_passed` (random token), then redirects to `/dashboard`.

Admin dashboard (`admin/admin.php`) sessions:

- Verifies the user via `user_id`/`username`. Loads admin from DB and refreshes:
  - `is_admin`, `admin_id`, `user_role`, `user_name`, `user_email`, `allowed_permissions`.
- Logout (`?logout=1`): clears session and redirects to login.

Public pages:

- `index.php` sets `csrf_token` and handles newsletter POST via Ajax.
- `contact.php` sets `csrf_token` and handles contact POST.
- `admin/unsubscribe.php` sets `csrf_token` and handles JSON POST for unsubscribe steps.

## External libraries (Composer)

- `vlucas/phpdotenv` — Load env vars from `.env`.
- `mongodb/mongodb` — MongoDB driver incl. GridFS.
- `phpmailer/phpmailer` — SMTP email.
- `spomky-labs/otphp` — TOTP (2FA) generation/verification.
- `paragonie/constant_time_encoding` — crypto-safe encoding utilities (transitive).

## Environment variables

Copy `.env.example` to `.env` and fill values. Keys used in code:

- MongoDB
  - `MONGODB_URI`
  - `MONGODB_DATABASE`
- Mail (SMTP)
  - `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
  - `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_ENCRYPTION` (tls if not set)
- reCAPTCHA
  - `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`
- Pexels
  - `PEXELS_API_KEY` (used by `pexels-api.php`)
- General
  - `APP_ENV` (optional: `local|dev|development` enables more verbose errors in unsubscribe flow)
  - `UNSUBSCRIBE_NEWSLETTER_URL` — Link placed in newsletter footer (points to `/unsubscribe`).

Note: `.env.example` in this repo has been aligned to include `PEXELS_API_KEY`.

## Application flows

Public Newsletter signup (index):

1. User completes form and reCAPTCHA -> Ajax POST to `index.php`.
2. Server rate-limits by IP via `attempts` collection, validates CSRF + reCAPTCHA.
3. Inserts into `newsletter` if not already subscribed.
4. Returns JSON for SweetAlert feedback.

Contact form:

1. User fills fields and reCAPTCHA -> POST to `contact.php`.
2. Validates CSRF + reCAPTCHA; stores message in `contacts` and emails a confirmation.

Admin authentication and 2FA:

1. `admin/login.php`: email+password, CSRF + reCAPTCHA.
2. If no TOTP secret, redirect to `2fa-setup.php` to enroll, else to `2fa-verify.php`.
3. On valid TOTP, admin session created and sent to `/dashboard` (`admin/admin.php`).

Admin modules (dashboard):

- News/Events CRUD (create + delete) with optional image (GridFS). Public pages read them in `news-events.php` (detail/list).
- Newsletter broadcast to all subscribers via PHPMailer; includes unsubscribe footer URL.
- Contact messages list, mark-as-resolved.
- CEO can add admins (role=ADMIN) and grant permissions per action.

Unsubscribe flow:

1. User visits `/unsubscribe` and provides email.
2. Server generates a 6-digit code, stores a hash + expiry in subscriber document, and emails the code.
3. User submits code; server verifies hash and deletes the subscriber document.

## Setup and run

### Local (XAMPP on Windows)

1. Install PHP extensions for MongoDB and enable curl, mbstring, intl, zip, gd as needed.
1. Create MongoDB database and user; update `.env` from `.env.example`.
1. Install Composer deps in the project folder:

```powershell
# From C:\xampp\htdocs\school-website
composer install
```

1. Start Apache (XAMPP). Visit `http://localhost/school-website/home`.
1. Create initial CEO account (DEV ONLY): temporarily visit `admin/admin.inject.php`, submit form, then remove the file (it self-deletes on success).

### Docker

Build and run the container (provide `.env` at runtime or bake in during copy):

```powershell
# From the project root
docker build -t school-website .
docker run --rm -p 8080:80 --name school-website `
  -v ${PWD}:/var/www/html `
  --env-file .env `
  school-website
# Open http://localhost:8080/home
```

Note: MongoDB must be reachable from the container via `MONGODB_URI`.

## Security features

- CSRF protection on all forms (server validates `csrf_token`).
- reCAPTCHA validation on public and admin login forms.
- Rate limiting newsletter subscription by IP (`attempts`).
- 2FA (TOTP) mandatory for admin login.
- Passwords stored with `password_hash` (bcrypt).
- Strict email validation and encoding/escaping for outputs.

## Known issues and tips

- `media.php` access: `.htaccess` currently blocks `media.php` with a 404 rule and the file itself sends a Location header to `404.php`. If you want GridFS images to render on public pages, remove the early `header('location: 404.php');` from `media.php` and allow the route in `.htaccess` (e.g., remove `^media.php$` rule or add an allow rule before it).
- Contacts date field: `contact.php` writes `created_at` but `admin.php` lists by `submitted_at`. Align the field name (e.g., use `created_at` everywhere) to sort correctly.
- Pexels key name: Ensure `.env` has `PEXELS_API_KEY` (not `PIXABAY_API_KEY`). The code expects `PEXELS_API_KEY`.
- Ensure `APP_ENV=local` only in development; do not leak stack traces or dev detail in production.

## License

This project bundles third-party libraries under their respective licenses via Composer. Your own source is under your chosen license.
