# Girofeeds Product API for Magento 2

Product feed export and update REST API endpoints for Magento 2.

## Installation

### Using Composer

```bash
composer require girofeeds/module-product-api
bin/magento module:enable Girofeeds_ProductApi
bin/magento setup:upgrade
bin/magento cache:flush
```

If running in production mode:

```bash
bin/magento setup:static-content:deploy
```

## Configuration

Go to **Stores > Configuration > Girofeeds > Product API** to configure the module.

## CLI Commands

```bash
bin/magento girofeeds:token:generate        # Generate API key
bin/magento girofeeds:token:generate --force # Regenerate API key
bin/magento girofeeds:selftest              # Run self-test
```

## API Endpoints

### Frontend Routes

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/girofeeds/feed/json?id={storeId}&token={key}` | Product feed |
| POST | `/girofeeds/updateproduct/index?key={key}` | Update product |
| GET | `/girofeeds/count/index?key={key}` | Product count |
| GET | `/girofeeds/test/index?key={key}` | Module test |

### REST API Routes

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/rest/V1/girofeeds/count` | Product count |
| GET | `/rest/V1/girofeeds/test` | Module test |
| POST | `/rest/V1/girofeeds/updateproduct` | Update product |

## Compatibility

- PHP >= 8.1
- Magento 2.4.x
