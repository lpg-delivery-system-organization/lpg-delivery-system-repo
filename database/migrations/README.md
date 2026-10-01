# Database Migrations

SQL migrations for `lpg_delivery_v2`. Each file is a single, self-contained
change to the database schema. They are plain `.sql` so they can be executed by
hand — in phpMyAdmin, a MySQL client, or any hosting panel's SQL editor.

## Filename convention

```
YYYY-MM-DD_HHMMSS_<verb>_<table>_table.sql
```

Every filename states **when** it was written and **which table** it touches, so
migrations can be identified, ordered and applied without opening them:

```
2026-09-30_182932_create_users_table.sql
2026-09-30_183331_alter_orders_table.sql
```

- Timestamps use **Asia/Manila**, the same clock the application runs on
  (`APP_TIMEZONE` in `includes/helpers.php`). Using the host's own timezone would
  put the recorded order out of step with reality.
- Timestamps are strictly increasing and **never sort before an existing
  migration**, so filename order is always the correct apply order.
- `<verb>` is `create` for a new table, `alter` for a column/index/constraint
  change.

## Apply order

Sort by filename and run in order. The generator keeps each migration's
timestamp above the newest one already in this folder, so dependencies always
resolve. The baseline order is:

| # | File | Notes |
|---|------|-------|
| 1 | `..._create_schema_migrations_table.sql` | Run this **first**; every other file logs into it |
| 2 | `..._create_users_table.sql` | No dependencies |
| 3 | `..._create_products_table.sql` | No dependencies |
| 4 | `..._create_orders_table.sql` | Needs `users`, `products` |
| 5 | `..._create_notifications_table.sql` | Needs `users`, `orders` |
| 6 | `..._create_chat_messages_table.sql` | Needs `users`, `orders` |
| 7 | `..._create_rider_locations_table.sql` | Needs `users`, `orders` |
| 8 | `..._create_password_resets_table.sql` | Needs `users` |

Foreign keys depend on column types matching exactly, so the referenced table
must already exist. Each file's header lists its dependencies under
`Depends on:`.

## Running them

```bash
mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_182932_create_users_table.sql
```

Or paste the whole file into **phpMyAdmin → SQL → Go**.

## Checking what has been applied

Every migration writes a row to `schema_migrations` when it runs:

```sql
SELECT * FROM schema_migrations ORDER BY migration;
```

Compare that against the files in this folder to see what is still pending.

## Re-running is safe

`create` migrations use `CREATE TABLE IF NOT EXISTS` and log with
`INSERT IGNORE`, so running the whole set twice changes nothing and does not
duplicate ledger rows. This has been verified against a throwaway database.

`alter` migrations are **not** automatically idempotent — re-running one that has
already been applied will fail with a duplicate-column or duplicate-key error.
That failure is intentional: it means "already applied, stop." Check
`schema_migrations` before running an `alter` file you have applied before.

`ALTER` statements deliberately use plain, portable syntax rather than MariaDB's
`ADD COLUMN IF NOT EXISTS` extension, so the same file works on MySQL 8 and
MariaDB.

## Creating a new migration

Do not hand-write the DDL. Generate it from the live database so the migration
reproduces the real structure — exact column types, enum values, defaults,
indexes, foreign keys and collation — instead of drifting from it:

```bash
# New table (captures its current DDL)
php database/tools/generate-migration.php --table=<name> --purpose="One line describing it."

# Column / index / constraint change to an existing table
php database/tools/generate-migration.php --table=<name> --alter-only \
    --alter="ADD COLUMN \`foo\` varchar(50) DEFAULT NULL" \
    --purpose="One line describing it."

# Preview without writing a file
php database/tools/generate-migration.php --table=<name> --purpose="..." --dry-run
```

**Procedure for any schema change:** make the change in the development
database first, then generate the migration from it. The generator strips
`AUTO_INCREMENT=` so a production table never inherits this environment's ID
watermark and skips the first several thousand IDs.

### Known schema quirk

`password_resets.expires_at` is defined as
`timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()`.
The `ON UPDATE` clause means the expiry is pushed forward to *now* every time
the row is touched — for example when `used` is flipped to 1 — so a token's
expiry drifts instead of staying fixed. This is faithful to the current schema
and is reproduced as-is in the baseline migration rather than silently
corrected. Fixing it needs its own `alter` migration so the change is explicit
and reviewable.

## Note on the seed data

`database/lpg_delivery_v2.sql` contains the full DDL **plus** demo users,
products and orders. It is the original all-in-one installer. The files in this
folder are schema-only: they create structure and never insert demo data, which
is what you want when pointing at a production database.
