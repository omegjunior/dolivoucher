# Tests

The PHPUnit 10 suite is under `test/phpunit`. It contains exact-decimal unit tests and production-contract tests for portfolio and voucher rules, uniqueness, lifecycle guards, append-only behavior, compensation, transfer pairing, transactions, locks, entity isolation, reconciliation and excluded native writes.

From the module directory, when `phpunit` is installed:

```shell
phpunit -c test/phpunit/phpunit.xml
```

This workspace also contains a PHPUnit 10.5 runner in a neighboring custom module, so maintainers may run:

```shell
php ../dolideo/test/phpunit/phpunit-10.5.phar -c test/phpunit/phpunit.xml
```

The contract suite does not require or mutate a database. Before production deployment, enable DoliVoucher in a disposable Dolibarr 22.0.5 database and add/run database integration tests for concurrent activation, concurrent consumption, transfer rollback and installation replay.

The guarded schema smoke test creates a uniquely named isolated database, executes all table and key files, verifies the three tables, then removes that database:

```powershell
$env:DOLIVOUCHER_SQL_SMOKE='1'; php test/sql_schema_smoke.php
```

The guarded transactional smoke test uses the same create/drop isolation and exercises creation rules, uniqueness, activation, partial/total consumption, block/unblock, cancellation, compensation, entity refusal, transfers and journal reconciliation:

```powershell
$env:DOLIVOUCHER_INTEGRATION_SMOKE='1'; php test/integration_smoke.php
```
