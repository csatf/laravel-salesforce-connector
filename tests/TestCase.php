<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Tests;

use Csatf\LaravelSalesforceConnector\SalesforceConnectorServiceProvider;
use Illuminate\Foundation\Application;
use Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ForrestServiceProvider::class,
            SalesforceConnectorServiceProvider::class,
        ];
    }
}
