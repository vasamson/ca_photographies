<?php
/**
 * Crée (ou réinitialise le mot de passe d')un administrateur.
 * Usage : php bin/create-admin.php [--username=camille] [--password=…]   (sinon demandé interactivement)
 */
require __DIR__ . '/_cli.php';
use App\{Auth, Db};

$username = opt('username') ?? ask('Identifiant : ');
$password = opt('password') ?? ask('Mot de passe (10 caractères min.) : ', true);
try { Auth::validatePassword($password); } catch (\Throwable $e) { err($e->getMessage()); exit(1); }
$existing = Db::one('SELECT id FROM users WHERE username = ?', [$username]);
if ($existing) {
    Db::update('users', ['password_hash' => Auth::hash($password)], 'id = :id', ['id' => $existing['id']]);
    out("Mot de passe de « $username » réinitialisé.");
} else {
    Db::insert('users', ['username' => $username, 'password_hash' => Auth::hash($password), 'role' => 'admin', 'created_at' => Db::now()]);
    out("Administrateur « $username » créé.");
}
