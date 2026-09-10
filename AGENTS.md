# AGENTS.md

Canonical guide for AI coding agents working in this repository. The tool-specific files
(`CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`) are gitignored symlinks to this file —
run `composer run ai-docs` to (re)create them.

## Project Overview

InvoiceShelf is an open-source invoicing and expense tracking application built with Laravel 13 (PHP 8.4) and Vue 3. It supports multi-company tenancy, customer portals, recurring invoices, and PDF generation.

## Common Commands

### Development
```bash
composer run dev          # Starts PHP server, queue listener, log tail, and Vite dev server concurrently
pnpm dev               # Vite dev server only
pnpm build             # Production frontend build
```

### Demo data
```bash
php artisan db:seed --class=DemoSeeder --force            # demo user + Acme Inc, its address and settings
php artisan db:seed --class=RealisticDemoSeeder --force   # ~100 records: customers, invoices with tax, estimates, payments, expenses, notes, a recurring invoice
```

`DemoSeeder` is what the test suite and `php artisan reset:app` run — keep it cheap. `RealisticDemoSeeder` is development-only and never runs in tests; it is what to seed when you want the app to look like a real install (a company with a logo and postal address, taxed documents, a populated notes library).

> **Local environment (preferred):** the repo ships a `./devenv` script — a Docker Compose wrapper for the full local stack. Run `./devenv` once for interactive setup (pick MySQL/PostgreSQL/SQLite, optional Gotenberg; it adds the `invoiceshelf.test` host entry), then drive it with `./devenv start | stop | shell | logs | rebuild | test | format`. App at http://invoiceshelf.test, Adminer at `:8080`, Mailpit at `:8025`; the compose files live in `docker/development/` and your choice is remembered in `.devenvconfig`. (`composer run dev` / `pnpm dev` above are the native, non-Docker alternative.)

### Testing
```bash
php artisan test --compact                        # Run all tests
php artisan test --compact --filter=testName       # Run specific test
./vendor/bin/pest --stop-on-failure                # Run via Pest directly
make test                                          # Makefile shortcut
```

Tests use SQLite in-memory DB, configured in `phpunit.xml`. Tests seed via `DatabaseSeeder` + `DemoSeeder` in `beforeEach`. Authenticate with `Sanctum::actingAs()` and set the `company` header.

### Code Style
```bash
vendor/bin/pint --dirty --format agent    # Fix style on modified PHP files
vendor/bin/pint --test                    # Check style without fixing (CI uses this)
composer lint        # = pint --test   ;  composer lint:fix = pint
pnpm lint         # eslint (--max-warnings 0)  ;  pnpm lint:fix = eslint --fix
```

### Code Quality Gate (pre-commit hook)
A committed Git hook (`.githooks/pre-commit`) runs **Pint** on staged `.php` and **ESLint** on staged
`resources/scripts/**` `.{js,cjs,mjs,ts,vue}` files, and **blocks the commit on any failure** (ESLint runs
with `--max-warnings 0`). It lints **staged files only**, and soft-skips if PHP/Pint or `node_modules` is
unavailable (CI is the backstop). The hook is enabled via `core.hooksPath`, set automatically by the
`prepare` script on `pnpm install`; to enable it manually run:
```bash
git config core.hooksPath .githooks
```
Bypass intentionally (discouraged): `git commit --no-verify`. Intentional `v-html` is allowed via an
inline `<!-- eslint-disable-next-line vue/no-v-html -->` with a reason.

### Artisan Generators
Always use `php artisan make:*` with `--no-interaction` to create new files (models, controllers, migrations, tests, etc.).

## Architecture

### Multi-Tenancy
Every major model has a `company_id` foreign key. The `CompanyMiddleware` sets the active company from the `company` request header. Bouncer authorization is scoped to the company level via `DefaultScope` (`app/Bouncer/Scopes/DefaultScope.php`).

### Roles
- **`super admin`** — global platform admin (unscoped, manages all companies).
- **`owner`** — company-level admin (scoped to a company via Bouncer, full access to that company).

### Authentication
Three guards: `web` (session), `api` (Sanctum tokens for `/api/v1/`), `customer` (session for customer portal). API routes use `auth:sanctum` middleware; customer portal uses `auth:customer`.

### Routing
- **API**: All endpoints under `/api/v1/` in `routes/api.php`, grouped with `auth:sanctum`, `company`, and `bouncer` middleware
- **Web**: `routes/web.php` serves PDF endpoints, auth pages, and catch-all SPA routes (`/admin/{vue?}`, `/{company:slug}/customer/{vue?}`)

### Frontend
- Vue 3 + TypeScript + Pinia + vue-router + Tailwind v4 (`@tailwindcss/vite`)
- Entry point: `resources/scripts/main.ts` (single Vite input)
- Feature-folder layout under `resources/scripts/features/{admin,auth,company,customer-portal,...}` — each feature owns its own `routes.ts`, `views/`, `components/`
- Shared layers: `resources/scripts/{api,stores,components,composables,layouts,plugins,utils,types,config}`
- Path aliases: `@` → `resources/` (so most imports look like `@/scripts/api/client`, `@/scripts/stores/global.store`); `$fonts` → `resources/static/fonts`; `$images` → `resources/static/img`. There is no `@v2` alias — that was retired when the legacy v1 SPA was deleted.
- i18n: `lang/*.json` are dynamically imported by `resources/scripts/plugins/i18n.ts`. Locale-code → filename mismatches (e.g. `pt_BR` → `pt-br.json`) live in `LOCALE_FILE_MAP`. English is statically bundled; other locales lazy-load. Only edit `lang/en.json` directly — other locales are Crowdin-sourced.
- Vite dev server expects the `invoiceshelf.test` hostname (configured in `vite.config.js`)

### CSS Theme Tokens
The styling system uses **Tailwind v4 with CSS custom properties as the source of truth** — colors are not configured in JS, they live in CSS and are exposed to Tailwind via the `@theme` directive. Two files own this:

1. **`resources/css/themes.css`** — defines every color as a CSS custom property on `:root` (light) and `[data-theme="dark"]` (dark). This is where you change actual values.
2. **`resources/css/invoiceshelf.css`** — has an `@theme inline { ... }` block that **registers** each custom property as a Tailwind theme token (e.g. `--color-heading: var(--color-heading);`), making it available as utility classes (`bg-heading`, `text-heading`, `border-heading`, etc.). The block also uses the legacy `@theme { --spacing-88: 22rem; --font-base: Poppins, sans-serif; }` for non-color tokens.

**Token categories defined today:**
- `primary-{50…950}` — brand color scale
- `surface`, `surface-secondary`, `surface-tertiary`, `surface-muted` — background depth tiers
- `heading`, `body`, `muted`, `subtle` — text emphasis tiers
- `line-{light,default,strong}` — borders
- `hover`, `hover-strong` — hover backgrounds
- `header-from`, `header-to` — fixed header gradient stops (not dark-mode-aware)
- `btn-primary`, `btn-primary-hover` — button colors (fixed, always bold)
- `status-{yellow,green,blue,red,purple}` — status badge text colors
- `alert-{warning,error,success}-{bg,text}` — alert variants

**Dark mode** is toggled via the `[data-theme="dark"]` attribute on the `<html>` element. The same custom-property names get redefined under that selector — components do **not** need `dark:` variants or conditional logic, they just reference the semantic tokens and the right value is picked up automatically.

**Adding a new color token is a two-step ritual:**
1. Add the custom property to **both** `:root` and `[data-theme="dark"]` in `themes.css`
2. Add a matching `--color-X: var(--color-X);` line inside the `@theme inline` block in `invoiceshelf.css`

After that the token is usable as `bg-X` / `text-X` / `border-X` in Vue templates and as `var(--color-X)` in raw CSS. Skip step 2 and the value exists at the CSS level but Tailwind utility classes won't be generated.

**Convention — never hardcode hex/rgb values in components.** Use the semantic tokens: `text-heading` not `text-gray-900`, `bg-surface` not `bg-white`, `border-line-default` not `border-gray-300`. Hardcoded values won't follow dark-mode flips and will diverge from the rest of the app over time. There are **no exceptions** in the project — even the auth pages (which sit outside the admin chrome) use the same `bg-surface` / `text-heading` / `border-line-default` vocabulary as `BaseCard`, just composed differently.

### Backend Patterns
- **Authorization**: Silber/Bouncer with policies in `app/Policies/`. Controllers use `$this->authorize()`.
- **Validation**: Form Request classes, never inline validation
- **API responses**: Eloquent API Resources in `app/Http/Resources/`
- **PDF generation**: Pluggable driver — `dompdf` (default, via `GeneratesPdfTrait`) or `gotenberg` (headless Chromium). Driver chosen per company through the **PDF Generation** admin settings page.
- **Email**: Mailable classes with `EmailLog` tracking. Mail driver is configurable globally and may be overridden per-company.
- **File storage**: Spatie MediaLibrary backed by the **FileDisk** model — admins create named disk entries (local / S3 / Dropbox / DigitalOcean Spaces) and assign them to purposes (`media_storage`, `pdf_storage`, `backup_storage`) in **Admin → File Disks → Disk Assignments**. New uploads go to the assigned disk; existing files stay where they were and require `php artisan media:secure` to migrate.
- **Serial numbers**: `SerialNumberService`
- **Company settings**: `CompanySetting` model (key-value per company)
- **User settings**: User-level preferences (notably `language`) stored as JSON via `setSettings()`. The sentinel value `'default'` means "inherit the company-level setting" — used for the per-user language preference so promoting/inviting members doesn't freeze a copy of the inviter's language.

### PDF Font System
PDFs ship with bundled **Noto Sans** (Latin / Greek / Cyrillic) as the default face. Non-Latin scripts come from on-demand **Font Packages** managed in **Admin → Font Packages** and defined in `FontService::FONT_PACKAGES` (`app/Services/FontService.php`). Currently shipped packages: `noto-sans` (bundled, marker only), `noto-sans-{sc,tc,jp,kr}` (CJK), `noto-sans-hebrew`, `noto-naskh-arabic` (covers `ar`/`fa`/`ur`), `noto-sans-devanagari` (`hi`), `sarabun` (Thai). `GeneratesPdfTrait::ensureFontsForLocale()` synchronously installs the matching package on the first PDF render for a given company language.

Two non-obvious constraints when extending the font system:
1. **dompdf's PHP-Font-Lib does not parse variable fonts** (`fvar`/`gvar` tables). Any new package must source **static TTF** files — Google Fonts' main repo ships variable fonts and produces empty boxes. Reliable static-TTF sources used today: `openmaptiles/fonts` for non-CJK Noto scripts, `life888888/cjk-fonts-ttf` for the CJK packages, `google/fonts/ofl/sarabun` for Thai.
2. **dompdf does not glyph-fall-back through the `font-family` chain** — it uses the *first* font for ALL characters. So locale-specific packages must be the **primary** font for that locale, not a fallback. Selection happens in `FontService::getFontFamilyForLocale()`. This is also why a Latin-locale company with a Hebrew customer name will still render boxes for the Hebrew text — solving that needs Gotenberg or a custom mid-render font-switching pass.

The bundled NotoSans is also surfaced as a `bundled: true` package entry (no download URL, files served from `resources/static/fonts/` instead of `storage/fonts/`) so it appears alongside the on-demand packages in the admin UI with a "Bundled" pill instead of an Install button.

### Database
Supports MySQL, PostgreSQL, and SQLite — **every migration and query must work on all three** (the test suite runs on SQLite `:memory:`); no vendor-specific SQL. Prefer Eloquent over raw queries. Use `Model::query()` instead of `DB::`. Use eager loading to prevent N+1 queries.

**Migrations — foreign keys are `unsignedInteger`, never `foreignId()`.** Parent tables (`users`, `companies`, `currencies`, …) key on **INT UNSIGNED**, so every reference column must match that width: declare FKs as `$table->unsignedInteger('company_id')` plus an index. **Don't use `foreignId()`** — it's BIGINT, and a `foreignId()->constrained()` against an INT PK fails on MySQL 8 with error 3780 (type mismatch), which is exactly what breaks the v2→v3 upgrade.

- **No DB-level FK constraints** for these refs — plain `unsignedInteger` columns + indexes; relationships and cascades are handled in app code, not via `->constrained()` / `cascadeOnDelete()`.
- This is the codebase-wide convention (~27 migrations use `unsignedInteger`, only 2 use `foreignId()`). The one deliberate exception is `ai_messages.conversation_id`, which references the BIGINT `ai_conversations.id` and keeps `->constrained()->cascadeOnDelete()` — that cascade is intentional and covered by `AiChatFlowTest`.

See PRs #618 / #683.

### Service Pattern
All business logic must live in Service classes (`app/Services/`), not in Models or Controllers. Controllers are thin — they authorize, call the service, and return a response. Models only contain relationships, scopes, accessors, mutators, and constants. Services are injected via constructor injection.

### Testing (TDD)
InvoiceShelf follows TDD development style:
- **Feature tests** (`tests/Feature/`) — test API routes end-to-end (HTTP requests, responses, database assertions)
- **Unit tests** (`tests/Unit/`) — test service classes and business logic in isolation
- Write tests before or alongside implementation. Every new feature or bug fix must have tests.

## Code Conventions

- PHP: snake_case, constructor property promotion, explicit return types, PHPDoc blocks over inline comments
- TS / Vue: camelCase, `<script setup lang="ts">`, prefer Composition API + Pinia stores over component-local state for anything cross-cutting
- Always check sibling files for patterns before creating new ones
- Use `config()` helper, never `env()` outside config files
- Every change must have tests
- Run `vendor/bin/pint --dirty --format agent` after modifying PHP files
- After editing `lang/en.json` or any file under `resources/scripts/`, rebuild via `pnpm build` — the bundled chunks (including locale chunks) are content-hashed by Vite, so the browser will pick them up on hard refresh

## Releasing

Releases are cut by pushing a tag. Nothing is typed into GitHub by hand.

```bash
# 1. Add a "## <version> — <date>" section to CHANGELOG.md and bump version.md,
#    in a PR like any other change — the notes are reviewed with the code.
# 2. Once merged, tag the merge commit:
git tag 3.0.0-alpha.2 && git push origin 3.0.0-alpha.2
```

`release.yaml` then runs the tests, reads the `CHANGELOG.md` section for that tag,
builds the package with `make clean dist`, and creates a **draft** release with the
zip attached. It stops there and prints the draft URL in the run summary.

**You publish the draft yourself.** That is deliberate, not an omission: GitHub does
not start workflow runs from events created with `GITHUB_TOKEN`, so a release
published by the workflow reaches nothing downstream. Pressing Publish fires
`release: published` under your own identity, which triggers `publish.yaml` to
register the release on the updater and build the Docker images. **Until you
publish, no install is offered anything.**

Notes on the mechanics:

- **A tag with no `CHANGELOG.md` section fails the run** before anything is built,
  so a release can never go out with empty notes.
- **`prerelease` and "mark as latest" are set on the draft**, derived from the tag: a
  `-` suffix (`3.0.0-alpha.2`) means pre-release, which routes it to the **insider**
  channel so ordinary installs are not offered it. "Latest" is gated on
  `LATEST_MAJOR` in the workflows — bump it there when 3.x becomes the stable line.
- **If registration fails**, re-run it without cutting a new release: run the
  `Publish Release` workflow manually with `register_tag` set to the version.
  That path is idempotent and does not rebuild the Docker images.
- `.github/scripts/changelog-section.php <version>` prints what the updater will be
  sent, so you can check the notes locally before tagging.

## CI Pipeline

GitHub Actions (`check.yaml`): runs Pint style check, then runs Pest tests in parallel (`php artisan test --parallel`) on PHP 8.4 with Xdebug disabled (`coverage: none`). The test job does **not** build the frontend — the suite is API/JSON only and never renders the Vite blade, so no Node/Vite step is needed (release/docker workflows still build assets in their own jobs).

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== filament/filament/core rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

Always use static `make()` methods to initialize components. Most configuration methods accept a `Closure` for dynamic values.

Use `Get $get` to read other form field values for conditional logic:

<code-snippet name="Conditional form field visibility" lang="php">
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

Select::make('type')
    ->options(CompanyType::class)
    ->required()
    ->live(),

TextInput::make('company_name')
    ->required()
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

</code-snippet>

Use `Set $set` inside `->afterStateUpdated()` on a `->live()` field to mutate another field reactively. Prefer `->live(onBlur: true)` on text inputs to avoid per-keystroke updates:

<code-snippet name="Reactive field update" lang="php">
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

TextInput::make('title')
    ->required()
    ->live(onBlur: true)
    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
        'slug',
        Str::slug($state ?? ''),
    )),

TextInput::make('slug')
    ->required(),

</code-snippet>

Compose layout by nesting `Section` and `Grid`. Children need explicit `->columnSpan()` or `->columnSpanFull()`:

<code-snippet name="Section and Grid layout" lang="php">
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

Section::make('Details')
    ->schema([
        Grid::make(2)->schema([
            TextInput::make('first_name')
                ->columnSpan(1),
            TextInput::make('last_name')
                ->columnSpan(1),
            TextInput::make('bio')
                ->columnSpanFull(),
        ]),
    ]),

</code-snippet>

Use `Repeater` for inline `HasMany` management. `->relationship()` with no args binds to the relationship matching the field name:

<code-snippet name="Repeater for HasMany" lang="php">
use Filament\Forms\Components\Repeater;

Repeater::make('qualifications')
    ->relationship()
    ->schema([
        TextInput::make('institution')
            ->required(),
        TextInput::make('qualification')
            ->required(),
    ])
    ->columns(2),

</code-snippet>

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column value" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Use `SelectFilter` for enum or relationship filters, and `Filter` with a `->query()` closure for custom logic:

<code-snippet name="Table filters" lang="php">
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

SelectFilter::make('status')
    ->options(UserStatus::class),

SelectFilter::make('author')
    ->relationship('author', 'name'),

Filter::make('verified')
    ->query(fn (Builder $query) => $query->whereNotNull('email_verified_at')),

</code-snippet>

Actions are buttons that encapsulate optional modal forms and behavior:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;

Action::make('updateEmail')
    ->schema([
        TextInput::make('email')
            ->email()
            ->required(),
    ])
    ->action(fn (array $data, User $record) => $record->update($data)),

</code-snippet>

### Testing

Testing setup (requires `pestphp/pest-plugin-livewire` in `composer.json`):

- Always call `$this->actingAs(User::factory()->create())` before testing panel functionality.
- For edit pages, pass `['record' => $user->id]`, use `->call('save')` (not `->call('create')`), and do not assert `->assertRedirect()` (edit pages do not redirect after save).

<code-snippet name="Table test" lang="php">
use function Pest\Livewire\livewire;

livewire(ListUsers::class)
    ->assertCanSeeTableRecords($users)
    ->searchTable($users->first()->name)
    ->assertCanSeeTableRecords($users->take(1))
    ->assertCanNotSeeTableRecords($users->skip(1));

</code-snippet>

<code-snippet name="Create resource test" lang="php">
use function Pest\Laravel\assertDatabaseHas;

livewire(CreateUser::class)
    ->fillForm([
        'name' => 'Test',
        'email' => 'test@example.com',
    ])
    ->call('create')
    ->assertNotified()
    ->assertHasNoFormErrors()
    ->assertRedirect();

assertDatabaseHas(User::class, [
    'name' => 'Test',
    'email' => 'test@example.com',
]);

</code-snippet>

<code-snippet name="Edit resource test" lang="php">
livewire(EditUser::class, ['record' => $user->id])
    ->fillForm(['name' => 'Updated'])
    ->call('save')
    ->assertNotified()
    ->assertHasNoFormErrors();

assertDatabaseHas(User::class, [
    'id' => $user->id,
    'name' => 'Updated',
]);

</code-snippet>

<code-snippet name="Testing validation" lang="php">
livewire(CreateUser::class)
    ->fillForm([
        'name' => null,
        'email' => 'invalid-email',
    ])
    ->call('create')
    ->assertHasFormErrors([
        'name' => 'required',
        'email' => 'email',
    ])
    ->assertNotNotified();

</code-snippet>

Use `->callAction(DeleteAction::class)` for page actions, or `->callAction(TestAction::make('name')->table($record))` for table actions:

<code-snippet name="Calling actions" lang="php">
use Filament\Actions\Testing\TestAction;

livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), [
        'role' => 'admin',
    ])
    ->assertNotified();

</code-snippet>

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` does not exist in v4.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== spatie/laravel-medialibrary/core rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

</laravel-boost-guidelines>
