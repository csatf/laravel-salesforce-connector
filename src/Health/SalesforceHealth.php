<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Health;

use Csatf\LaravelSalesforceConnector\Soql\SoqlClient;
use Csatf\LaravelSalesforceConnector\Soql\SoqlQuery;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use Throwable;

/**
 * A connectivity probe suitable for a health endpoint.
 *
 * Deliberately does NOT use `Forrest::limits()`: the /limits resource returns
 * 403 API_DISABLED_FOR_ORG under the Salesforce Integration license, so a
 * limits-based health check reports a permanent outage on a healthy org.
 *
 * `versions()` needs no object permissions, so it isolates "can we authenticate
 * and reach the org" from "do we have field-level access". Configure
 * `salesforce-connector.health.probe_object` to also assert a real read.
 */
class SalesforceHealth
{
    public function __construct(private SoqlClient $client) {}

    /**
     * @return array{healthy: bool, auth: bool, read: bool|null, error: string|null}
     */
    public function check(): array
    {
        try {
            $this->client->authenticate();

            Forrest::versions();
        } catch (Throwable $e) {
            return [
                'healthy' => false,
                'auth' => false,
                'read' => null,
                'error' => $this->describe($e),
            ];
        }

        $probeObject = config('salesforce-connector.health.probe_object');

        if (! is_string($probeObject) || $probeObject === '') {
            return ['healthy' => true, 'auth' => true, 'read' => null, 'error' => null];
        }

        try {
            $this->client->query(SoqlQuery::from($probeObject)->select('Id')->limit(1));
        } catch (Throwable $e) {
            return [
                'healthy' => false,
                'auth' => true,
                'read' => false,
                'error' => $this->describe($e),
            ];
        }

        return ['healthy' => true, 'auth' => true, 'read' => true, 'error' => null];
    }

    public function isHealthy(): bool
    {
        return $this->check()['healthy'];
    }

    /**
     * Salesforce error bodies can echo back the query, which may contain
     * personal data from search filters. Report the class and message only.
     */
    private function describe(Throwable $e): string
    {
        return class_basename($e).': '.$e->getMessage();
    }
}
