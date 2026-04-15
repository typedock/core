<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Theme\LatteFactory;
use TypeDock\Auth\SessionService;
use TypeDock\Auth\ApiKeyService;
use TypeDock\Auth\PermissionChecker;
use TypeDock\Search\LikeSearchEngine;
use TypeDock\Storage\LocalStorage;
use TypeDock\Storage\S3Storage;
use TypeDock\Component\ComponentRegistry;
use TypeDock\Component\ComponentRenderer;

class ServiceProvider
{
    public function register(): void
    {
        $this->registerDatabase();
        $this->registerLatte();
        $this->registerAuth();
        $this->registerStorage();
        $this->registerSearch();
        $this->registerComponents();
    }

    private function registerDatabase(): void
    {
        \Flight::register('db', \PDO::class, [], function (\PDO $pdo): void {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        });

        // Override default PDO creation to use our config
        \Flight::map('db', function (): \PDO {
            static $pdo = null;
            if ($pdo !== null) {
                return $pdo;
            }

            $db     = config('database');
            $driver = $db['driver'] ?? 'mysql';

            $dsn = match ($driver) {
                'sqlite' => 'sqlite:' . ($db['sqlite_path'] ?? TYPEDOCK_ROOT . '/storage/database.sqlite'),
                'pgsql'  => sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $db['host'] ?? '127.0.0.1',
                    (int) ($db['port'] ?? 5432),
                    $db['database'] ?? 'typedock'
                ),
                default => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $db['host'] ?? '127.0.0.1',
                    (int) ($db['port'] ?? 3306),
                    $db['database'] ?? 'typedock',
                    $db['charset'] ?? 'utf8mb4'
                ),
            };

            $options = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $pdo = new \PDO(
                $dsn,
                $driver === 'sqlite' ? null : ($db['username'] ?? 'root'),
                $driver === 'sqlite' ? null : ($db['password'] ?? ''),
                $options
            );

            return $pdo;
        });
    }

    private function registerLatte(): void
    {
        \Flight::map('latte', function (): LatteFactory {
            static $factory = null;
            if ($factory !== null) {
                return $factory;
            }
            $factory = new LatteFactory();
            return $factory;
        });
    }

    private function registerAuth(): void
    {
        \Flight::map('session', function (): SessionService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new SessionService(\Flight::db());
            return $service;
        });

        \Flight::map('apikey', function (): ApiKeyService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new ApiKeyService(\Flight::db());
            return $service;
        });

        \Flight::map('permissions', function (): PermissionChecker {
            static $checker = null;
            if ($checker !== null) {
                return $checker;
            }
            $checker = new PermissionChecker();
            return $checker;
        });
    }

    private function registerStorage(): void
    {
        \Flight::map('storage', function (): \TypeDock\Contract\StorageDriver {
            static $driver = null;
            if ($driver !== null) {
                return $driver;
            }

            $default = config('filesystems.default', 'local');
            $driver = match ($default) {
                's3'    => new S3Storage(config('filesystems.s3', [])),
                default => new LocalStorage(config('filesystems.local', [])),
            };

            return $driver;
        });
    }

    private function registerSearch(): void
    {
        \Flight::map('search', function (): \TypeDock\Contract\SearchEngine {
            static $engine = null;
            if ($engine !== null) {
                return $engine;
            }
            $engine = new LikeSearchEngine(\Flight::db());
            return $engine;
        });
    }

    private function registerComponents(): void
    {
        \Flight::map('components', function (): ComponentRegistry {
            static $registry = null;
            if ($registry !== null) {
                return $registry;
            }
            $registry = new ComponentRegistry();
            return $registry;
        });

        \Flight::map('component_renderer', function (): ComponentRenderer {
            static $renderer = null;
            if ($renderer !== null) {
                return $renderer;
            }
            $renderer = new ComponentRenderer(\Flight::components(), \Flight::latte());
            return $renderer;
        });
    }
}
