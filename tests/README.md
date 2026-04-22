# TypeDock Test Suite

PHPUnit-based tests covering pure logic (unit) and migration / wiring smoke tests (integration).

## Layout

```
tests/
├── bootstrap.php          # Composer autoload + TYPEDOCK_ROOT + per-run SQLite DB env
├── Unit/                  # Fast, isolated tests (no real DB / no filesystem state)
│   ├── Auth/
│   ├── Content/
│   └── Core/
└── Integration/           # Spins up SQLite, runs the migrations, etc.
```

## Running

```bash
# All tests
vendor/bin/phpunit

# Just one suite
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=integration

# A single file or filter
vendor/bin/phpunit tests/Unit/Content/SlugValidatorTest.php
vendor/bin/phpunit --filter=testCreateProducesProperlyFormattedToken
```

## Conventions

- Unit tests must not touch the real filesystem outside `sys_get_temp_dir()` and must not depend on network or external services. In-memory SQLite (`sqlite::memory:`) is fine.
- Integration tests get a fresh SQLite file per test (see `MigrationsTest`) and clean up in `tearDown()`.
- Test classes live under the `TypeDock\Tests\` namespace, mirroring the directory layout under `tests/`.
- Run `composer install` first so `vendor/bin/phpunit` is available (PHPUnit ^11 is declared in `require-dev`).
