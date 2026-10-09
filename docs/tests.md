# Tests

The PHPUnit 10 suite is under `test/phpunit`. It contains exact-decimal and production-contract tests for Phases 1 and 2 plus the Phase 3A hybrid payment architecture, locking, idempotence, append-only linkage, deletion trigger, rights, CSRF UI and absence of bank integration.

From the module directory, when `phpunit` is installed:

```shell
phpunit -c test/phpunit/phpunit.xml
```

This workspace also contains a PHPUnit 10.5 runner in a neighboring custom module, so maintainers may run:

```shell
php ../dolideo/test/phpunit/phpunit-10.5.phar -c test/phpunit/phpunit.xml
```

The contract suite does not require or mutate a database.

The guarded schema smoke test creates a uniquely named isolated database, executes all table and key files, verifies the eight module tables and the `DVOUCH` payment mode, then removes that database:

```powershell
$env:DOLIVOUCHER_SQL_SMOKE='1'; php test/sql_schema_smoke.php
```

The guarded transactional smoke test uses the same create/drop isolation and additionally exercises Phase 1 balances/rollback, idempotent series generation, reference/barcode sequencing, zero financial impact, partial printing, mixed-selection refusal, complement coverage, restrictive reprint, file/event SHA-256 consistency, preparation, delivery and material-journal immutability. Phase 3A assertions cover consumption inside a caller-owned transaction, native-object metadata, APPLY/REVERSAL linkage, exact restoration, idempotency uniqueness, refusal by the generic compensation path, double-compensation refusal and the read-only settlement API:

```powershell
$env:DOLIVOUCHER_INTEGRATION_SMOKE='1'; php test/integration_smoke.php
```

The guarded native Phase 3A integration test installs the complete Dolibarr 22 schema and data in a disposable database, installs DoliVoucher through its descriptor, and uses real `Facture` and `Paiement` objects. It covers total/partial/mixed settlement, multiple vouchers and invoices, idempotence, forced rollback checkpoints, native deletion protection, controlled reversal, non-reversible payments and concurrent independent connections for the same key, voucher and invoice:

```powershell
$env:DOLIVOUCHER_PHASE3_NATIVE='1'; php test/phase3_native_integration.php
```

The database name is generated under the strict `dolivoucher_phase3_native_*` prefix and is dropped in `finally`. Run this only with a MariaDB account allowed to create and drop databases. Browser-level CSRF and rendered PDF checks remain manual because this CLI suite does not authenticate an HTTP session or write into the production document directory.
