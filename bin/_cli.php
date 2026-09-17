<?php
/** Amorçage commun des scripts en ligne de commande. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement\n"); }
require dirname(__DIR__) . '/app/bootstrap.php';
set_time_limit(0);
ini_set('memory_limit', '-1');

function out(string $s = ''): void { fwrite(STDOUT, $s . PHP_EOL); }
function err(string $s): void { fwrite(STDERR, "\033[31m$s\033[0m" . PHP_EOL); }
function ask(string $q, bool $hidden = false): string
{
    fwrite(STDOUT, $q);
    if ($hidden && stripos(PHP_OS, 'WIN') === false) { system('stty -echo'); $v = trim((string) fgets(STDIN)); system('stty echo'); fwrite(STDOUT, PHP_EOL); return $v; }
    return trim((string) fgets(STDIN));
}
function opt(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $a) { if ($a === "--$name") return '1'; if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3); }
    return $default;
}
