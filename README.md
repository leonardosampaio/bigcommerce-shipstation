# Bigcommerce orders report

Orders report in CSV from BigCommmerce and ShipStation API calls.

## Dependencies

1. PHP 7.4+
2. php7.4-curl
3. php7.4-json
4. composer
5. Apache: activate mod rewrite (`sudo a2enmod rewrite`) and AllowOverride All in apache2.conf

## Instalation

1. Execute `composer update` in root path
2. Create a symbolic link to /public in Apache/Nginx public html folder
## Configuration

1. Copy configuration/application.json.dist to configuration/application.json and set

    | Key | Description |
    | ----------- | ----------- |
    | adminUser | login of the admin user |
    | adminPassword | password of the admin user |
    | timezone | timezone that should be considered on date conversions (see https://www.php.net/manual/en/timezones.php) |
    | memoryLimit | default "1024M". Maximum memory that should be used (see https://www.php.net/manual/function.ini-set.php) |
    | serverPath | Optional, if the application runs in a subpath (for instance, https://example.com/application), set this value as "/application"

2. Copy configuration/bigcommerce.json.dist to configuration/bigcommerce.json and set

    | Key | Description |
    | ----------- | ----------- |
    | store | id of the store, comes from the API PATH url |
    | baseUrl | default "https://api.bigcommerce.com/stores". Base url of the API calls |
    | access_token | provided by BigCommerce |
    | pageSize | default 250. Number of results per each API call
    | parallelConnections | default 10. Number of parallel API calls  Note that increasing this number does not guaranteed to speed things up and may trigger rate limiting faster (see https://developer.bigcommerce.com/api-docs/getting-started/best-practices#api-rate-limits) |

2. Copy configuration/shipstation.json.dist to configuration/shipstation.json and set

    | Key | Description |
    | ----------- | ----------- |
    | baseUrl | default "https://ssapi.shipstation.com". Base url of the API calls |
    | api_key | provided by ShipStation |
    | api_secret | provided by ShipStation |
    | pageSize | default 250. Number of results per each API call
    | parallelConnections | default 10. Number of parallel API calls. Note that increasing this number does not guaranteed to speed things up and may trigger rate limiting faster (see https://www.shipstation.com/docs/api/requirements/#api-rate-limits) |

## Usage

* Begin and End dates are used to search orders and Shipments in that timeframe.
* Cache options reuse previous results, if avaliabe, to speed new queries. "Cache for orders" retrieves a complete result set from file storage, thus does not make any new API calls.
* Click "Search" to retrieve the results, afterwards click "CSV" to export. Every new live search renews the cache.
