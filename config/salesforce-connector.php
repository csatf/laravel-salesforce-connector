<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Forrest configuration management
    |--------------------------------------------------------------------------
    |
    | omniphx/forrest reads `config('forrest')` but never merges a default, so
    | an app that has not published `config/forrest.php` gets NULL and fails at
    | the first call. This package supplies the whole thing from the `forrest`
    | key below, so apps only ever set SF_* environment variables.
    |
    | If an app publishes its own `config/forrest.php`, that file wins — values
    | are merged recursively over these defaults. Set this to false to opt out
    | of the package managing Forrest config entirely.
    |
    */

    'manage_forrest_config' => env('SF_MANAGE_FORREST_CONFIG', true),

    /*
    |--------------------------------------------------------------------------
    | Salesforce API limits
    |--------------------------------------------------------------------------
    |
    | `max_offset` is a hard platform ceiling: SOQL rejects OFFSET above 2000.
    | Deep pagination past this point is not possible via OFFSET and needs a
    | keyset/cursor strategy instead. SoqlQuery clamps to this value rather
    | than letting Salesforce return an error.
    |
    */

    'limits' => [
        'max_offset' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health probe
    |--------------------------------------------------------------------------
    |
    | The object queried by SalesforceHealth::check() to prove real read access
    | rather than only proving that OAuth works. Leave null to check auth only.
    |
    | Note: do NOT use Forrest::limits() as a health check — /limits returns
    | 403 API_DISABLED_FOR_ORG for the Salesforce Integration license.
    |
    */

    'health' => [
        'probe_object' => env('SF_HEALTH_PROBE_OBJECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Forrest defaults
    |--------------------------------------------------------------------------
    |
    | CSATF defaults for omniphx/forrest. These differ from the package's own
    | published defaults in three deliberate ways:
    |
    |   - `authentication` defaults to ClientCredentials, not WebServer. No
    |     certificate to rotate and no callback, so it works headlessly.
    |   - `client.verify` defaults to TRUE. Forrest ships this as false, which
    |     silently disables TLS verification.
    |   - `storage.type` defaults to cache, not session. Server-to-server calls
    |     have no session.
    |
    */

    'forrest' => [

        'authentication' => env('SF_AUTH_METHOD', 'ClientCredentials'),

        'credentials' => [
            'consumerKey' => env('SF_CONSUMER_KEY'),
            'consumerSecret' => env('SF_CONSUMER_SECRET'),
            'callbackURI' => env('SF_CALLBACK_URI'),

            /*
             | MUST be the org's My Domain URL. login.salesforce.com and
             | test.salesforce.com return "invalid_grant: request not supported
             | on this domain" under the Client Credentials flow.
             */
            'loginURL' => env('SF_LOGIN_URL', 'https://login.salesforce.com'),

            'username' => env('SF_USERNAME'),
            'password' => env('SF_PASSWORD'),
            'privateKey' => env('SF_PRIVATE_KEY', ''),
        ],

        'parameters' => [
            'display' => '',
            'immediate' => false,
            'state' => '',
            'scope' => '',
            'prompt' => '',
        ],

        'defaults' => [
            'method' => 'get',
            'format' => 'json',
            'compression' => false,
            'compressionType' => 'gzip',
        ],

        'client' => [
            'http_errors' => true,
            'verify' => env('SF_VERIFY_SSL', true),
        ],

        'storage' => [
            'type' => env('SF_TOKEN_STORAGE', 'cache'),
            'path' => 'forrest_',
            'expire_in' => 3600,
            'store_forever' => false,
        ],

        /*
         | Pin this. A blank version makes resource calls fail with an opaque
         | "SalesforceException null".
         */
        'version' => env('SF_API_VERSION', ''),

        'instanceURL' => env('SF_INSTANCE_URL', ''),

        'language' => 'en_US',
    ],

];
