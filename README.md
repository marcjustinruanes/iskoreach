# IskoReach

IskoReach is a scholarship discovery, application, and monitoring platform for students and scholarship providers in the Philippines. It brings scholarship listings, applications, document requirements, and scholar support into one role-based system.

Students can discover opportunities and track their applications. Providers can manage scholarship programs and support their scholars. Administrators oversee provider verification, platform records, reports, and public award publication.

## Core features

- **Public website:** scholarship search, program details, and public scholar listings governed by student consent and publication approval.
- **Student workspace:** academic profiles, application drafts and tracking, private document vaults, requirement checklists, messages, notifications, renewals, and benefit history.
- **Provider workspace:** organization profiles, scholarship management, applicant review, private student conversations, renewal requests, confirmed awards, and benefit-release records.
- **Administration:** provider verification, account and scholarship monitoring, academic-term management, external applicant-count verification, reports, CSV exports, and activity logs.
- **Access control:** role-based permissions, ownership policies, provider approval requirements, and authorized private-document downloads.

Benefit-release records track reported amounts and statuses; the platform does not transfer money. Public scholar listings require a confirmed eligible award, student consent, and publication approval.

## Tech stack

| Layer | Technologies |
| --- | --- |
| Backend | PHP, Laravel 13 |
| Frontend | React 19, TypeScript, Inertia.js 2 |
| Styling and interface | Tailwind CSS 4, Headless UI, Lucide icons, Plus Jakarta Sans |
| Database | MySQL, Eloquent ORM |
| Authentication | Laravel Fortify, session authentication |
| Notifications and email | Laravel Notifications, Laravel Mail, queued jobs |
| Realtime communication | Laravel Reverb, Laravel Echo, Pusher protocol client, HTTP polling fallback |
| Asset tooling | Vite 8, npm, Composer |
| Testing and code quality | PHPUnit, SQLite for isolated tests, Laravel Pint, TypeScript checking, Chrome browser verification |

## Architecture

Laravel handles routing, validation, authorization, business workflows, and persistence. Inertia connects the Laravel backend to React pages within a single application. Private files are delivered through authorized backend endpoints. Queued jobs support email and notification delivery, while the scheduler supports renewal reminders.

The interface provides responsive public pages and dedicated student, provider, and administrator workspaces. Scholarship coverage supports nationwide, regional, and city/municipality scopes. Geographic directory accuracy depends on the configured dataset; adoption of a verified official dataset remains in progress.
