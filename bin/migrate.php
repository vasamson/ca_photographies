<?php
/** Crée / met à jour le schéma de la base (idempotent).  Usage : php bin/migrate.php */
require __DIR__ . '/_cli.php';
use App\Db;
Db::migrate();
out('Schéma appliqué (' . Db::driver() . ').');
foreach (['users', 'albums', 'photos', 'photo_variants', 'uploads'] as $t) out(sprintf('  %-16s %d ligne(s)', $t, (int) Db::value("SELECT COUNT(*) FROM $t")));
