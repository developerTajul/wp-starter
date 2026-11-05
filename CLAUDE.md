# CLAUDE.md — WP Mini Laravel Guidelines

## Architecture & Coding Standards

- **CRITICAL: Never use the `env()` function directly in application code (`App\`, `Core\`, `routes/`, `views/`, tests, etc.).** Always map environment variables in `config/*.php` files using `env()`, and inject the `Framework\Config\Config` class into the constructor to retrieve those values via `$this->config->get('file.key')`.
- Environment variables are read **once, at config load time**, inside `config/` files only. Everything else in the application reads configuration through `Framework\Config\Config` — either via the global `config()` helper or by dependency injection of the class into a constructor property.
- The `env()` helper looks up `$_ENV`, then `$_SERVER`, then `getenv()` and coerces Laravel-style string booleans (`true`/`false`/`(true)`/`(false)` → bool, `(null)`/`''` → null, `(empty)` → `''`).
- Configuration files (`config/*.php`) are the single source of truth for the application. They may call `env()` — no file outside that directory may.
- Use Dependency Injection over static calls where a class is constructed by the container; prefer constructor injection (`public function __construct(private readonly Config $config)`) so values are captured once and tests can inject a mock or an in-memory replacement.

## Boot order (do not reorder)

1. `wp-mini-laravel.php` requires `vendor/autoload.php`, then loads `.env` via `\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad()`.
2. Helper files (`framework/Support/helpers.php`) are autoloaded via Composer; the global `config()` helper delegates to the static `Framework\Config\Config` class.
3. The entry file binds `path.base` and loads `config/*.php` into `Config::$items`.
4. The `App\Kernel` registers service providers.
5. `app()->boot()` runs at the `plugins_loaded` hook.

## Rebranding (hybrid: static + .env)

The rebrander lives at **`framework/bin/init-plugin.php`** and is run through Composer:

```bash
composer rebrand -- --name="Lead Bridge" --slug=lead-bridge --vendor=acme --namespace=LeadBridge
```

It performs two kinds of rebranding in one pass:

- **Static (structural)** — the plugin header in the renamed entry file, the `composer.json` package name and PSR-4 maps, and every `App\` / `Core\` namespace across `app/`, `core/`, `config/`, `routes/` and `tests/`.
- **Dynamic (`.env`)** — the `env()` **fallback defaults** in `config/app.php` (the second argument of each `env()` call), plus the identity values in `.env.example` and `.env`.

Relocated from `bin/` to `framework/bin/` so the script ships with the framework layer. Never edit files under `framework/` when rebranding a project — see the README's rebranding section.

## Dependency injection

- `Framework\Config\Config` is a **static** class; the `config()` helper calls `Config::get($key, $default)` directly — do not try to resolve a container instance for it.
- Where a class is built by the container and needs config, inject `Framework\Config\Config` in the constructor.

## Testing

- Run `php vendor/bin/phpunit` from the plugin root.
- The test suite is committed on purpose (needed by CI/CD and collaborators).
