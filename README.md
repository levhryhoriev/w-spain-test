# Housing Offers API

Laravel 12 API for asynchronous supplier-offer imports and reservation-safe inventory.

## Setup

Docker Compose is required. It provides PHP 8.5, MySQL, Redis, Horizon, and Nginx.

```bash
cp .env.example .env
```

Before the remaining commands, set `DB_PASSWORD` and `DB_ROOT_PASSWORD` in `.env` to non-empty local values.

```bash
docker compose build app
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose up -d mysql redis app
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose up -d nginx
docker compose --profile worker up -d worker
```

The API listens on port 8080. The worker profile runs Horizon and processes queued imports.

## Tests

Tests use the disposable Compose `mysql-test` service and its `laravel_test` database, never the persistent development MySQL service.

```bash
docker compose --profile test run --rm tests
```

## Imports

An import is uniquely identified by supplier and external import ID. Replaying the same identity returns the first Import and does not queue the Job again.

The Import is accepted before its identity-only Job processes supplier offers asynchronously.

## Reservations

Reservation creation locks the Offer row with `FOR UPDATE`, then rechecks expiry and available units. It inserts the Reservation and decrements one unit in the same transaction, so competing last-unit requests serialize atomically.

Reservations store Offer, date, price, currency, and customer snapshots. A later supplier refresh can restore local available units, so this is not a supplier reservation ledger.
