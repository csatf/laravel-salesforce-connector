<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector;

use Csatf\LaravelSalesforceConnector\Health\SalesforceHealth;
use Csatf\LaravelSalesforceConnector\Soql\SoqlClient;
use Illuminate\Support\ServiceProvider;

class SalesforceConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/salesforce-connector.php', 'salesforce-connector');

        $this->publishForrestConfig();

        $this->app->singleton(SoqlClient::class, static fn (): SoqlClient => new SoqlClient);
        $this->app->singleton(SalesforceHealth::class, static fn ($app): SalesforceHealth => new SalesforceHealth(
            $app->make(SoqlClient::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/salesforce-connector.php' => config_path('salesforce-connector.php'),
        ], 'salesforce-connector-config');
    }

    /**
     * Push this package's Forrest defaults into `config('forrest')`.
     *
     * Forrest's own service provider only *publishes* its config; it never
     * merges a default. Apps that have not run `vendor:publish` therefore get
     * `config('forrest') === null` and fail on the first call. Supplying the
     * config here means consuming apps carry SF_* env vars and nothing else.
     *
     * This runs in register() on purpose: Forrest resolves `config('forrest')`
     * lazily inside its singleton closure, so provider boot order does not
     * matter as long as the value is in place before first resolution.
     *
     * Anything already present in `config('forrest')` — i.e. an app that
     * published the file deliberately — takes precedence.
     */
    protected function publishForrestConfig(): void
    {
        if (! config('salesforce-connector.manage_forrest_config', true)) {
            return;
        }

        $defaults = (array) config('salesforce-connector.forrest', []);
        $existing = (array) config('forrest', []);

        config(['forrest' => array_replace_recursive($defaults, $existing)]);
    }
}
