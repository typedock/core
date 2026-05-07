<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Theme\LatteFactory;
use TypeDock\Theme\ThemeLoader;
use TypeDock\Theme\ThemeSettingsService;
use TypeDock\Theme\ThemeStyleRenderer;
use TypeDock\Auth\SessionService;
use TypeDock\Auth\ApiKeyService;
use TypeDock\Auth\PermissionChecker;
use TypeDock\Contract\CaptchaProvider;
use TypeDock\Search\LikeSearchEngine;
use TypeDock\Security\NullCaptchaProvider;
use TypeDock\Storage\LocalStorage;
use TypeDock\Component\ComponentRegistry;
use TypeDock\Component\ComponentRenderer;
use TypeDock\Component\CoreComponentRegistrar;
use TypeDock\Admin\PluginAdminMenu;
use TypeDock\Core\PluginDiagnostics;
use TypeDock\Core\ProviderRegistry;
use TypeDock\Locale\LocaleService;
use TypeDock\Mail\MailService;
use TypeDock\Media\MediaService;
use TypeDock\ExternalSource\ExternalSourceAdapterRegistry;
use TypeDock\ExternalSource\ExternalSourceService;
use flight\database\SimplePdo;

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
        $this->registerThemeSettings();
        $this->registerMedia();
        $this->registerMail();
        $this->registerExternalSources();
    }

    private function registerMail(): void
    {
        \Flight::map('mailer', function (): MailService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new MailService();
            return $service;
        });

        \Flight::map('locales', function (): LocaleService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new LocaleService(\Flight::db());
            return $service;
        });
    }

    private function registerMedia(): void
    {
        \Flight::map('media_service', function (): MediaService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new MediaService(\Flight::db(), \Flight::storage());
            return $service;
        });

        \Flight::map('plugin_admin_menu', function (): PluginAdminMenu {
            static $menu = null;
            if ($menu !== null) {
                return $menu;
            }
            $menu = new PluginAdminMenu();
            return $menu;
        });

        \Flight::map('provider_registry', function (): ProviderRegistry {
            static $registry = null;
            if ($registry !== null) {
                return $registry;
            }
            $registry = new ProviderRegistry();
            return $registry;
        });

        \Flight::map('plugin_diagnostics', function (): PluginDiagnostics {
            static $diag = null;
            if ($diag !== null) {
                return $diag;
            }
            $diag = new PluginDiagnostics();
            return $diag;
        });
    }

    private function registerDatabase(): void
    {
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

            $pdoOptions = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $pdo = new SimplePdo(
                $dsn,
                $driver === 'sqlite' ? null : ($db['username'] ?? 'root'),
                $driver === 'sqlite' ? null : ($db['password'] ?? ''),
                $pdoOptions
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

        \Flight::map('captcha', function (): CaptchaProvider {
            $override = \Flight::provider_registry()->get('captcha');
            if ($override instanceof CaptchaProvider) {
                return $override;
            }

            static $provider = null;
            if ($provider !== null) {
                return $provider;
            }
            return $provider = new NullCaptchaProvider();
        });
    }

    private function registerStorage(): void
    {
        \Flight::map('storage', function (): \TypeDock\Contract\StorageDriver {
            $override = \Flight::provider_registry()->get('storage');
            if ($override instanceof \TypeDock\Contract\StorageDriver) {
                return $override;
            }

            static $driver = null;
            if ($driver !== null) {
                return $driver;
            }

            $driver = new LocalStorage(config('filesystems.local', []));

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
            // Seed the registry with the built-in components (search_form,
            // latest_posts, menu, etc.) so the slot admin dropdown and the
            // frontend `{component}` tag have something to offer out of the
            // box. Modules and plugins can layer more via PluginContext.
            (new CoreComponentRegistrar())->register($registry);
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

    private function registerThemeSettings(): void
    {
        \Flight::map('theme_settings', function (): ThemeSettingsService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new ThemeSettingsService(\Flight::db(), new ThemeLoader());
            return $service;
        });

        \Flight::map('theme_style', function (): ThemeStyleRenderer {
            static $renderer = null;
            if ($renderer !== null) {
                return $renderer;
            }
            $renderer = new ThemeStyleRenderer(\Flight::theme_settings());
            return $renderer;
        });
    }

    private function registerExternalSources(): void
    {
        \Flight::map('external_source_adapters', function (): ExternalSourceAdapterRegistry {
            static $registry = null;
            if ($registry !== null) {
                return $registry;
            }
            $registry = ExternalSourceAdapterRegistry::withBuiltIns();
            return $registry;
        });

        \Flight::map('external_sources', function (): ExternalSourceService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new ExternalSourceService(\Flight::db(), adapterRegistry: \Flight::external_source_adapters());
            return $service;
        });
    }
}
