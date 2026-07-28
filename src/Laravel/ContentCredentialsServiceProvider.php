<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Provemark\ContentCredentials\Laravel\Console\ReadCommand;
use Provemark\ContentCredentials\Laravel\Console\SignCommand;
use Provemark\ContentCredentials\Laravel\Exception\MissingConfigurationException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers the Core signer/reader (and the facade manager) into the Laravel
 * container, configured from `config/content-credentials.php`. Depends only on
 * Core + illuminate/* — never the reverse (Deptrac: Laravel → Core).
 */
final class ContentCredentialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'content-credentials');

        $container = $this->container();

        $container->singleton(SigningServiceConfig::class, function (Container $app): SigningServiceConfig {
            ['base_url' => $baseUrl, 'api_key' => $apiKey] = $this->serviceConfig($app);

            if (trim($baseUrl) === '') {
                throw new MissingConfigurationException(
                    'Missing required configuration "content-credentials.service.base_url" (set the CONTENTAUTH_SERVICE_URL environment variable).'
                );
            }

            if (trim($apiKey) === '') {
                throw new MissingConfigurationException(
                    'Missing required configuration "content-credentials.service.api_key" (set the CONTENTAUTH_API_KEY environment variable).'
                );
            }

            return new SigningServiceConfig($baseUrl, $apiKey);
        });

        $container->singleton(SignerInterface::class, fn (Container $app): SigningServiceSigner => new SigningServiceSigner(
            $this->resolveClient($app),
            $this->resolveRequestFactory($app),
            $this->resolveStreamFactory($app),
            $app->make(SigningServiceConfig::class),
        ));

        $container->singleton(ReaderInterface::class, fn (Container $app): SigningServiceReader => new SigningServiceReader(
            $this->resolveClient($app),
            $this->resolveRequestFactory($app),
            $this->resolveStreamFactory($app),
            $app->make(SigningServiceConfig::class),
        ));

        $container->singleton(ContentCredentialsManager::class, fn (Container $app): ContentCredentialsManager => new ContentCredentialsManager(
            $app->make(SignerInterface::class),
            $app->make(ReaderInterface::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            $this->configPath() => config_path('content-credentials.php'),
        ], 'content-credentials-config');

        // Register the artisan commands when a console kernel is available. The
        // bound() check is safe on any container (unlike runningInConsole(),
        // which the bare test harness does not implement).
        if ($this->app->bound(ConsoleKernel::class)) {
            $this->commands([
                SignCommand::class,
                ReadCommand::class,
            ]);
        }
    }

    private function configPath(): string
    {
        return __DIR__.'/../../config/content-credentials.php';
    }

    /** The application container, typed for static analysis. */
    private function container(): Container
    {
        return $this->app;
    }

    /**
     * @return array{base_url: string, api_key: string}
     */
    private function serviceConfig(Container $app): array
    {
        $config = $app->make('config');
        $service = $config instanceof ConfigRepository ? $config->get('content-credentials.service') : null;

        $baseUrl = is_array($service) && isset($service['base_url']) && is_string($service['base_url'])
            ? $service['base_url']
            : '';
        $apiKey = is_array($service) && isset($service['api_key']) && is_string($service['api_key'])
            ? $service['api_key']
            : '';

        return ['base_url' => $baseUrl, 'api_key' => $apiKey];
    }

    private function resolveClient(Container $app): ClientInterface
    {
        return $app->bound(ClientInterface::class)
            ? $app->make(ClientInterface::class)
            : Psr18ClientDiscovery::find();
    }

    private function resolveRequestFactory(Container $app): RequestFactoryInterface
    {
        return $app->bound(RequestFactoryInterface::class)
            ? $app->make(RequestFactoryInterface::class)
            : Psr17FactoryDiscovery::findRequestFactory();
    }

    private function resolveStreamFactory(Container $app): StreamFactoryInterface
    {
        return $app->bound(StreamFactoryInterface::class)
            ? $app->make(StreamFactoryInterface::class)
            : Psr17FactoryDiscovery::findStreamFactory();
    }
}
