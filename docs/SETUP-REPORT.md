# IskoReach setup report

Verified on 19 October 2026. Scope: technology foundation only.

## Initial inspection

The workspace was empty, with no existing application, environment file, Git repository/history/remotes, or Stitch assets. Tools were already installed. PHP includes pdo_mysql and pdo_sqlite. No MySQL credentials were supplied; the user confirmed that a local database/user still needs to be created.

## Actual installed versions

| Component | Version |
| --- | --- |
| PHP | 8.5.9 |
| Composer | 2.10.2 |
| Node.js | 24.19.0 |
| npm | 11.17.0 |
| Git | 2.55.0.windows.3 |
| MySQL client | 8.0.45 (server not queried) |
| Laravel framework | 13.37.0 |
| Inertia Laravel adapter | 2.0.28 |
| Inertia React adapter | 2.3.29 |
| React / React DOM | 19.3.1 |
| TypeScript | 5.9.3 |
| Tailwind / Tailwind Vite plugin | 4.3.3 |
| Vite | 8.3.4 |
| Laravel Vite plugin | 3.2.0 |
| Vite React plugin | 6.1.2 |
| Fortify | 1.41.0 |
| Reverb | 1.12.0 |
| Echo | 2.6.1 |
| Pusher JS | 8.6.0 |
| PHPUnit | 12.5.40 |
| Laravel Pint | 1.32.1 |

Lockfiles record all transitive dependencies. The Laravel skeleton was v13.11.0; its framework constraint resolved to 13.37.0. No Express backend, shadcn/ui, Lucide, charts, Docker, or Google Sheets integration was installed. Fortify's own transitive packages are present even though optional two-factor/passkey features are disabled.

## Files

Laravel was created directly in the workspace. The full baseline includes artisan, public/index.php, app/Models/User.php, config/*.php, database factories/migrations/seeders, storage structure, PHPUnit configuration and example tests, dependency manifests/lockfiles, and Git ignore/editor configuration.

Targeted integration files:

- composer.json / composer.lock: project identity, resolved backend packages, safe setup scripts. Removed the generated automatic migrate/setup script and SQLite creation hook; setup never resets a database.
- package.json / package-lock.json: React/Inertia/Echo, TypeScript, Vite/Tailwind, and typecheck script.
- .env.example: MySQL placeholders, file sessions/cache, log mail/broadcasting, optional Reverb variables.
- .env: newly created from the example and given a generated key; ignored by Git. No existing credentials were overwritten.
- vite.config.ts / tsconfig.json: React/Tailwind plugins and strict TypeScript. Replaced the generated Vite JS config and unused JS entry with TypeScript.
- resources/js/app.tsx, types.ts, realtime.ts, pages/{Welcome,Auth,Dashboard}.tsx: minimal Inertia pages and optional Echo initializer.
- resources/views/app.blade.php: Inertia root with Vite and React refresh.
- app/Http/Middleware/HandleInertiaRequests.php: restricted authenticated user props and session status.
- app/Providers/FortifyServiceProvider.php and app/Actions/Fortify/*: Fortify scaffolding and Inertia views.
- config/fortify.php / bootstrap/providers.php: registration, reset, session auth, and provider registration.
- app/Enums/UserRole.php, app/Models/User.php, app/Providers/AppServiceProvider.php: student/provider enum, casting, and gates.
- database/migrations/2026_10_19_000001_add_role_to_users_table.php: student default; role excluded from public mass assignment.
- database/seeders/DatabaseSeeder.php: no default accounts or shared passwords. Unused Fortify-generated optional feature migrations were removed before use.
- config/broadcasting.php, config/reverb.php, routes/channels.php, bootstrap/app.php: broadcasting and owner-only channels; default remains log.
- config/filesystems.php: automatic private-file serving disabled.
- routes/web.php: temporary welcome and authenticated account page.
- tests/Feature/FoundationTest.php: authentication, validation, password reset, role escalation prevention, gates, channel ownership.
- scripts/verify-browser.ts: Chrome smoke check using Node's standard APIs, no browser testing package.
- .gitignore: ignore environment variants while preserving .env.example.
- README.md / this report: local/team/database setup, startup, optional processes, limits and verification.

Generated build assets are in public/build and ignored. The generated Laravel welcome Blade file remains unused. No Stitch design conversion was attempted.

## Commands and results

| Command/check | Actual result |
| --- | --- |
| PHP/Composer/Node/npm/Git/MySQL --version | Available; versions above |
| Workspace listing and configuration inspection | Initially empty; then inspected generated Laravel configuration |
| composer create-project laravel/laravel . --no-interaction --no-scripts | Completed on approved network retry |
| composer require inertiajs/inertia-laravel:~2.0 laravel/fortify laravel/reverb --with-all-dependencies --no-interaction | Completed; compatible versions locked |
| npm install | Completed; 108 packages added, zero reported vulnerabilities |
| php artisan fortify:install --no-interaction | Completed |
| php artisan vendor:publish --provider=Laravel\Reverb\ReverbServiceProvider --tag=reverb-config --no-interaction | Completed |
| php artisan key:generate --no-interaction | Completed for newly created local .env |
| composer update --lock --no-install --no-interaction | Refreshed metadata; no dependency changes; no security advisories |
| composer validate --strict | Passed |
| composer check-platform-reqs | Passed |
| composer show --direct / npm ls --depth=0 | Resolved versions listed successfully; no missing direct npm packages |
| npm run typecheck | Passed with strict checking |
| npm run build | Passed; React pages and Tailwind CSS compiled |
| php artisan test | Passed: 8 tests, 64 assertions, including generated example tests |
| php vendor/bin/pint --dirty, then --test | Formatting applied; final format check passed |
| php artisan serve --host=127.0.0.1 --port=8000 --no-reload | Started successfully; health endpoint returned HTTP 200 |
| npm run dev -- --host 127.0.0.1 | Started successfully; Vite client returned HTTP 200 |
| node scripts/verify-browser.ts | Passed with Vite and again with compiled assets: rendering, styles, Inertia navigation, mobile fit, no runtime errors, no Reverb connection |
| php artisan route:list --except-vendor | Welcome and authenticated account routes present |
| php artisan reverb:start --help | Reverb CLI available; no live server started |
| php artisan schedule:list | No scheduled tasks, as intended |
| git init / git check-ignore | Initialized local repo; .env and private file paths correctly ignored |

Initial failures were resolved: the sandbox proxy blocked the first Composer request; approved network access allowed installation. Windows shell processing stripped a caret constraint, selecting an incompatible exact Inertia version; a tilde constraint fixed it. Reverb requires Guzzle 7 / PSR-7 2, so Composer resolved those compatible versions instead of the fresh skeleton's Guzzle 8 / PSR-7 3. No platform requirements were bypassed. Vite initially hit Windows sandbox process restrictions; the approved build passed. A channel test initially used the null/log driver; it now selects Reverb and registers its actual channel callbacks, verifying both denial of another user's channel and successful owner authorization. Expected authorization outcomes were retained.

## Verified state and limitations

Laravel, React, TypeScript, Inertia, Tailwind and Vite are configured and verified in CLI/build/browser checks. Fortify authentication is installed and tested against isolated SQLite. Roles are a small server-side foundation, not a complete permissions system. CSRF/session middleware remains enabled; no HTTP endpoint disables it for application forms.

MySQL is configured in .env.example and the new local .env, but credentials are blank. **No MySQL connectivity, server version, or MySQL migrations were verified.** The README contains SQL and the exact steps to create a local database/user. Authentication against MySQL requires those steps.

Reverb/Echo are installed and optional. Default pages do not connect to a WebSocket server. Channel signing/ownership is tested without network access. End-to-end realtime delivery, real SMTP delivery, production processes and deployment remain untested. No scheduled jobs or domain-specific notifications were invented.

GitHub repository creation, remote configuration, commits and pushes were not performed. No application data was deleted, no existing environment file was overwritten, and no deployment occurred. Temporary Laravel/Vite verification servers were stopped. Stopping Vite left its generated public/hot marker behind; the first compiled-assets browser check timed out, then passed after that stale marker was removed. This troubleshooting step is documented in README.md.

## Remaining manual work and startup

1. Create the local MySQL database/user using README SQL; fill DB_USERNAME and DB_PASSWORD in .env.
2. Run php artisan config:clear, php artisan migrate:status, then php artisan migrate. Register a local account in the browser afterward.
3. Supply Stitch source files for any later design conversion.
4. Have the team create/connect its GitHub repository when ready. The README includes clone, composer install, npm ci, environment, database, and verification instructions.

Start in separate project-root terminals:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
npm run dev -- --host 127.0.0.1
```

Open http://127.0.0.1:8000. Optional Reverb, queue, scheduler and eventual production requirements are documented in README.md.
