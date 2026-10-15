# Fresh-install SQL

`digimon_nxt_full.sql` contains the current schema-8, 43-table fresh-install database, including the species catalog, 1,000 persistent AI tamer accounts and their starter parties, and the bundled shop/news/event data. AI accounts cannot log in as human players. No human player saves or private installation credentials are included.

Use this SQL only for an empty/new `digimon_nxt` database. For an existing save, use `_setup.php` → **Create / Repair Schema** so the migration can preserve collections, progression and related records. The preferred fresh-install path is also Create / Repair Schema, which applies and verifies the current PHP migration.

The copies under `public_html/database/` are generated from the same schema and seeds. Regenerate with `php tools/build_sql.php` using a database account permitted to create and drop the isolated temporary build database. Connection settings come from `public_html/config/app.php`; this release's supplied default password is blank. The SQL does not alter the MySQL account password.
