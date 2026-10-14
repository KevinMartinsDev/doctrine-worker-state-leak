# Reproducer: Doctrine state leaks between requests in worker mode

Between two requests, `Doctrine\Bundle\DoctrineBundle\Registry::reset()` only calls `EntityManager::clear()` when the entity manager is still open. This clears the identity map, but two other pieces of state survive into the next request handled by the same worker:

1. **SQL filters**: a filter enabled, disabled or parameterized by one request stays that way for the following requests.
2. **Open transactions**: a transaction left open by a failed request swallows the writes of the following requests, which are never committed.

This repository is a fresh `symfony/skeleton` (8.0) with `symfony/orm-pack` (DoctrineBundle 3.3.2, ORM 3.7.5, DBAL 4.5.0) and SQLite. It contains a `Note` entity, a `soft_delete` SQL filter enabled by default, and a single controller: [`src/Controller/NoteController.php`](src/Controller/NoteController.php).

The same image runs twice with FrankenPHP, in production mode (`APP_ENV=prod`, `APP_DEBUG=0`):

| Service | Port | Mode |
|---|---|---|
| `worker` | 8380 | Worker mode, 1 worker: Symfony boots once and serves every request |
| `classic` | 8381 | Classic mode: a fresh application for every request |

## Run it

```bash
docker compose up -d --build
./reproduce.sh
```

To start again from a clean worker: `docker compose restart worker`.

## Scenarios

1. **SQL filters.** `/notes?admin=1` disables the `soft_delete` filter, like an admin page that also lists deleted records. The next regular request to `/notes` should only see `alice`.
2. **Open transaction.** `/transaction/fail` calls `beginTransaction()`, flushes a note, then throws. `/transaction/write` then flushes a note without any explicit transaction. `/transaction/committed` reads the database through an independent PDO connection, i.e. what any other process sees.

## Result

```
=== worker mode (port 8380)
  /setup                     "ok"
 1) SQL filters
  /notes                     {"enabled_filters":["soft_delete"],"notes":["alice"]}
  /notes?admin=1             {"enabled_filters":[],"notes":["alice","deleted-note"]}
  /notes                     {"enabled_filters":[],"notes":["alice","deleted-note"]}
 2) Transaction left open by a failed request
  /transaction/fail          HTTP 500
  /transaction/write         {"transaction_nesting_level":1}
  /transaction/committed     {"committed_notes":["alice","deleted-note"]}

=== classic mode (port 8381)
  /setup                     "ok"
 1) SQL filters
  /notes                     {"enabled_filters":["soft_delete"],"notes":["alice"]}
  /notes?admin=1             {"enabled_filters":[],"notes":["alice","deleted-note"]}
  /notes                     {"enabled_filters":["soft_delete"],"notes":["alice"]}
 2) Transaction left open by a failed request
  /transaction/fail          HTTP 500
  /transaction/write         {"transaction_nesting_level":0}
  /transaction/committed     {"committed_notes":["alice","deleted-note","bob-order"]}
```

- **SQL filters**: in worker mode, the regular request after the admin page still runs without the `soft_delete` filter and sees `deleted-note`.
- **Open transaction**: in worker mode, the next request runs at transaction nesting level 1, so `bob-order` is never committed. It is lost when the worker stops. In classic mode, PDO rolls back the failed transaction at the end of the script and `bob-order` is committed.

Found with [igor-php](https://github.com/igor-php/igor-php), a static analyzer that detects state leaks in worker mode.
