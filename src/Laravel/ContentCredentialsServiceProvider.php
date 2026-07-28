<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel;

use GuzzleHttp\Client;
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

        // Timeout options for the client we build when none is injected (SPEC-008).
        $container->singleton(HttpClientOptions::class, fn (Container $app): HttpClientOptions => $this->httpClientOptions($app));

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
        // An application-bound client owns its own timeout (SPEC-008 AC3): use it
        // unchanged, never wrap or re-instantiate it.
        if ($app->bound(ClientInterface::class)) {
            return $app->make(ClientInterface::class);
        }

        // No bound client: build one with a bounded timeout (SPEC-008 D1/D4). The
        // Guzzle reference lives only here in the Laravel layer — Core stays
        // client-agnostic.
        if (class_exists(Client::class)) {
            return new Client($app->make(HttpClientOptions::class)->toArray());
        }

        // Guzzle absent: discovery returns a pre-built client to which no timeout
        // can be applied (documented caveat, D4). Better a working client than none.
        return Psr18ClientDiscovery::find();
    }

    private function httpClientOptions(Container $app): HttpClientOptions
    {
        return new HttpClientOptions(
            $this->timeoutSeconds($app, 'timeout', 10.0),
            $this->timeoutSeconds($app, 'connect_timeout', 5.0),
        );
    }

    private function timeoutSeconds(Container $app, string $key, float $default): float
    {
        $config = $app->make('config');
        $value = $config instanceof ConfigRepository ? $config->get("content-credentials.service.{$key}") : null;

        if ($value === null) {
            return $default;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new MissingConfigurationException(sprintf(
                'Invalid configuration "content-credentials.service.%s": expected a non-negative number of seconds.',
                $key,
            ));
        }

        return (float) $value;
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
