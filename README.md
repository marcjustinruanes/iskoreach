# IskoReach

Development foundation for a community scholarship discovery, application, and monitoring system. Laravel serves React/TypeScript pages through Inertia. Authentication and optional realtime dependencies are installed. Scholarship workflows remain future work.

**Phase 1 is implemented:** student/provider registration, administrator approval, role middleware, ownership policies, profile editing, and responsive layouts. See [the Phase 1 guide](docs/PHASE-1.md) and [full architecture/schema proposal](docs/ARCHITECTURE-PROPOSAL.md). The original stack report is a historical snapshot.

**UI Stage 1 is complete:** authentication/public layouts now follow Stitch, password fields have visibility controls, reset requests have safe confirmations, and pending/rejected providers have a dedicated status page. See [UI Stage 1 inventory, file guide, checks and limitations](docs/UI-STAGE-1.md). Later UI stages require review before implementation.

The supplied stitch_iskoreach_landing_page_design.zip now provides the landing reference. The React landing page follows its section order, navy/teal palette, and locally bundled Plus Jakarta Sans typography. See [the design system and landing wireframe](docs/DESIGN-SYSTEM.md). Scholarship examples are labelled fixtures, not live listings. Other pages remain at Phase 1; the next design page needs confirmation.

## Requirements and teammate installation

Use PHP 8.5 (tested: 8.5.9), Composer 2, Node.js 24 LTS, npm 11, Git, and MySQL 8. Enable `pdo_mysql` and `pdo_sqlite` alongside Composer's required PHP extensions. Some locked packages require newer PHP than Laravel's minimum; run `composer check-platform-reqs`.

When the team creates its GitHub repository and uploads the project, replace the repository URL:

```powershell
git clone <repository-url> iskoreach
cd iskoreach
composer install
npm ci
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

On a new installation with an empty APP_KEY, run `php artisan key:generate`. Preserve an existing key. This workspace already has a generated local key. Do not overwrite an existing .env or commit credentials. Git is initialized locally; no commit, remote, push, or deployment was created.

## MySQL setup

Install/start MySQL Server if necessary. `mysql --version` only verifies the client. Log in using your administrator account and enter its password at the prompt:

```powershell
mysql -u root -p
```

For a NEW database/user, run this SQL after replacing the password placeholder. If either already exists, inspect and reuse it instead of recreating it:

```sql
CREATE DATABASE iskoreach CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'iskoreach_dev'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_LOCAL_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON iskoreach.* TO 'iskoreach_dev'@'127.0.0.1';
```

Put your own credentials in .env:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=iskoreach
DB_USERNAME=iskoreach_dev
DB_PASSWORD="your-local-password"
```

Verify connectivity, then apply normal forward migrations:

```powershell
php artisan config:clear
php artisan migrate:status
php artisan migrate
```

On a new database, migrate:status may report an absent migration table. A connection exception indicates a server/credentials/permission issue. Migrations create users, password reset tokens, sessions, cache, job tables, and the user role column. No MySQL connection or migration was attempted during setup because you have not created the database/user yet. Do not run database resets, migrate:fresh, or db:wipe against shared data.

The seeder intentionally creates no default accounts/passwords. Factories support isolated tests and deliberate development data. After reviewing the current empty seeder, `php artisan db:seed` is safe but creates no records.

## Run locally

Open two terminals in the project root:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
npm run dev -- --host 127.0.0.1
```

Visit http://127.0.0.1:8000. Use Laravel's URL for the app. Keep the same hostname while testing sessions. Set APP_URL to your chosen Laravel URL for generated email links; its example default is http://localhost:8000. Restart Vite after changing VITE variables.

File sessions/cache let welcome and login pages load before database setup. Registration, login, and resets require the migrated database. No Reverb process is needed.

## Verification

```powershell
composer validate --strict
composer check-platform-reqs
npm ls --depth=0
npm run typecheck
npm run build
php artisan test
php vendor/bin/pint --test
```

Tests force SQLite :memory: and refuse to start if cached configuration selects another database. They never touch MySQL. The Chrome smoke check requires Node 24, Chrome, and a running Laravel server, with either Vite running or compiled assets from npm run build:

```powershell
node scripts/verify-browser.ts
```

Set CHROME_PATH for a different installation or APP_TEST_URL for another Laravel URL. The script uses an isolated profile in the OS temporary directory. It verifies React rendering, Tailwind styles, Inertia navigation, mobile overflow, runtime exceptions, and disabled Reverb. See docs/SETUP-REPORT.md for actual results and versions.

If Vite is interrupted and Laravel still points to its stopped server, remove the generated public/hot file before using compiled assets. Vite normally manages this file automatically.

## Authentication and security

Fortify provides registration, login/logout, login throttling, password reset/confirmation, backend validation, password hashing, and session authentication. Inertia forms use Laravel's supported CSRF/session mechanism and web middleware. MAIL_MAILER=log writes reset emails to storage/logs/laravel.log; configure SMTP before expecting delivery. Keep logs containing reset links private.

Public registration offers student/provider accounts through a validated account_type field. Student access is immediate; providers start pending. Submitted role/verification_status fields cannot create an admin or approve a provider. Role/review fields are not mass assignable. App/Enums/UserRole defines student/provider/admin. Role middleware and resource policies protect each area; provider.verified blocks unapproved workspace access. Future scholarship publishing routes must use that middleware plus resource policies.

After migrations, run `php artisan app:create-admin` locally. It prompts for a name, NEW email, and confirmed password of at least 12 characters. Passwords are hidden and never passed as command arguments; existing accounts are not promoted. Sign in normally and use /admin/dashboard to approve/reject providers. Approval records an administrator decision, not an automatic identity check. Provider identity changes reset approval and are audited. Broader administration and email verification remain outside Phase 1.

Shared Inertia props expose only the authenticated user's ID, name, email, and role. Private documents belong in storage/app/private; automatic serving is disabled. Future uploads/downloads need backend validation and policy-authorized controllers. Do not put private student files on the public disk.

## Optional realtime, queue, and scheduler

Reverb, Echo, Pusher protocol clients, broadcasting configuration, and an owner-only user channel are installed. Defaults are BROADCAST_CONNECTION=log and VITE_REVERB_ENABLED=false. No chat features, event delivery, or realtime subscriptions are implemented.

When implementing realtime, generate local values for REVERB_APP_ID, REVERB_APP_KEY, and REVERB_APP_SECRET. For example, run `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` separately for random values. Store these only in .env, then set:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<local-id>
REVERB_APP_KEY=<local-key>
REVERB_APP_SECRET=<local-secret>
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
VITE_REVERB_ENABLED=true
```

The VITE_REVERB host/key variables in .env.example reference the server values. Only the app key is public; never prefix a secret with VITE_. Restart Vite and run php artisan config:clear after changes. resources/js/realtime.ts exports the Echo initializer for future subscriptions. With the Reverb driver, private user channels authorize only their owner.

Run each required process in its own terminal:

```powershell
php artisan reverb:start --host=127.0.0.1 --port=8080
```

```powershell
php artisan queue:work --tries=3
```

```powershell
php artisan schedule:work
```

Queued mail/broadcasts/jobs require migrated job tables and a worker. Add reminder schedules to routes/console.php when actual rules exist; none are defined yet. Reverb's CLI/configuration and channel authorization were checked; end-to-end WebSocket delivery was not tested.

For eventual production, configure HTTPS, APP_ENV=production, APP_DEBUG=false, secure session cookies, real mail, backups, and protected writable storage. Build assets with npm run build. Supervise queue workers and Reverb as long-running processes. Run php artisan schedule:run every minute with cron or an equivalent scheduler. Reverb needs a TLS reverse proxy, restricted allowed origins, firewall rules, and appropriate process/file-descriptor limits. Restart workers after releases. Windows development does not verify production scale or Unix signal behavior. Nothing was deployed.

## Organization and future work

- routes/web.php: welcome and authenticated account page.
- resources/js/pages/*.tsx: React pages; types.ts: shared props; app.tsx: entry point.
- app/Actions/Fortify: validated authentication actions; app/Enums/UserRole.php: role foundation.
- app/Http/{Controllers,Middleware,Requests} and app/Policies: role routing, validated profile ownership, and approval.
- resources/js/layouts and resources/js/components: shared responsive layouts, typography, labelled inputs, and buttons.
- app/Models and database/{migrations,factories,seeders}: Eloquent and reproducible data structure.
- config/{broadcasting,reverb}.php, routes/channels.php, resources/js/realtime.ts: optional realtime.
- tests/Feature/FoundationTest.php: isolated authentication/authorization tests.
- scripts/verify-browser.ts: dependency-free browser smoke test.

Add scholarship models, policies, form requests, notifications, jobs, and document/conversation controllers as requirements are defined. MySQL remains the source of truth for applications submitted here. External Forms/Sheets require an authorized integration before automatic counts are possible. Eligibility/compatibility must follow provider rules. Release tracking records statuses/dates; payment processing is outside this setup.

Commit source, migrations, manifests, and both lockfiles together. Use composer install and npm ci after pulling dependency changes, and review migrations before applying them to shared databases. .env, vendor, node_modules, generated builds, and private storage are ignored. Repository creation and publishing remain team actions.

This setup follows official [Fortify](https://laravel.com/docs/13.x/fortify), [Inertia server](https://inertiajs.com/docs/v2/installation/server-side-setup), [Inertia client](https://inertiajs.com/docs/v2/installation/client-side-setup), [Tailwind/Vite](https://tailwindcss.com/docs/installation/using-vite), and [Reverb](https://laravel.com/docs/13.x/reverb) instructions. The official React starter includes shadcn/ui; manual integration avoids that optional dependency.
