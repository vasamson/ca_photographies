<?php
/** @var array $settings  @var string $content  @var string $page  @var ?string $title */
$siteName = $settings['site_name'];
$pageTitle = isset($title) && $title ? "$title — $siteName" : $siteName;
$desc = $description ?? $settings['tagline'];
$canonical = \App\Config::appUrl() . ($_SERVER['REQUEST_URI'] ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/');
$parts = explode(' ', $siteName); $last = array_pop($parts);
$brandHtml = $e(implode(' ', $parts)) . ' <em>' . $e($last) . '</em>';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#070606">
  <title><?= $e($pageTitle) ?></title>
  <meta name="description" content="<?= $e(\App\Util::excerpt($desc, 160)) ?>">
  <link rel="canonical" href="<?= $e($canonical) ?>">
  <?php if (!empty($noindex)): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
  <meta property="og:site_name" content="<?= $e($siteName) ?>">
  <meta property="og:title" content="<?= $e($pageTitle) ?>">
  <meta property="og:description" content="<?= $e(\App\Util::excerpt($desc, 200)) ?>">
  <meta property="og:type" content="website">
  <?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= $e($ogImage) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400;0,6..96,500;1,6..96,400&family=Unbounded:wght@400;700&family=Manrope:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(BASE_PATH . '/public/assets/css/style.css') ?>">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%23070606'/%3E%3Ccircle cx='16' cy='16' r='9' fill='none' stroke='%23ff3b1d' stroke-width='3'/%3E%3C/svg%3E">
</head>
<body data-page="<?= $e($page) ?>">
<?php if ($page !== 'admin'): ?>
  <a class="skip" href="#main">Aller au contenu</a>
  <div class="cursor" aria-hidden="true"><div class="ring"></div><div class="dot"></div></div>
  <div class="grain" aria-hidden="true"></div>
  <div class="vignette" aria-hidden="true"></div>
  <div class="wipe" aria-hidden="true"></div>
  <header class="topbar">
    <a class="brand" href="/"><?= $brandHtml ?></a>
    <nav>
      <a href="/albums"><span data-t="Albums">Albums</span></a>
      <a href="/a-propos"><span data-t="À propos">À propos</span></a>
      <?php if ($settings['instagram']): ?><a href="<?= $e($settings['instagram']) ?>" target="_blank" rel="noopener"><span data-t="Instagram">Instagram</span></a><?php endif; ?>
    </nav>
  </header>
<?php else: ?>
  <div class="grain" aria-hidden="true"></div>
<?php endif; ?>

<?= $content ?>

<?php if ($page !== 'admin'): ?>
  <footer class="foot">
    <a class="big" href="/"><?= $e($siteName) ?></a>
    <div class="foot-grid">
      <div class="foot-col">
        <span class="wide">Navigation</span>
        <a href="/albums">Tous les albums</a>
        <a href="/a-propos"><?= $e($settings['about_title']) ?></a>
        <a href="/admin" rel="nofollow">Espace photographes</a>
      </div>
      <div class="foot-col">
        <span class="wide">Contact</span>
        <?php if ($settings['contact_email']): ?><a href="mailto:<?= $e($settings['contact_email']) ?>"><?= $e($settings['contact_email']) ?></a><?php endif; ?>
        <?php if ($settings['instagram']): ?><a href="<?= $e($settings['instagram']) ?>" target="_blank" rel="noopener">Instagram ↗</a><?php endif; ?>
        <?php if ($settings['facebook']): ?><a href="<?= $e($settings['facebook']) ?>" target="_blank" rel="noopener">Facebook ↗</a><?php endif; ?>
        <?php if (!$settings['contact_email'] && !$settings['instagram'] && !$settings['facebook']): ?><span class="muted">Via la page <?= $e($settings['about_title']) ?></span><?php endif; ?>
      </div>
      <div class="foot-col">
        <span class="wide">À propos</span>
        <p class="muted"><?= $e($settings['tagline']) ?></p>
      </div>
    </div>
    <div class="meta wide">
      <span>© <?= date('Y') ?> <?= $e($siteName) ?> · Toutes les photos sont protégées</span>
      <span>Site créé par <a class="credit" href="mailto:samson.valentin2005@gmail.com">Valentin Samson</a></span>
    </div>
  </footer>
  <button class="totop" id="totop" aria-label="Retour en haut" hidden>↑</button>
  <div class="float-img" id="float-img" aria-hidden="true"></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
  <?php if (isset($initial)): ?><script type="application/json" id="initial-data"><?= str_replace('</', '<\/', json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></script><?php endif; ?>
  <script src="/assets/js/common.js?v=<?= filemtime(BASE_PATH . '/public/assets/js/common.js') ?>"></script>
  <script src="/assets/js/site.js?v=<?= filemtime(BASE_PATH . '/public/assets/js/site.js') ?>"></script>
<?php else: ?>
  <script type="application/json" id="admin-boot"><?= str_replace('</', '<\/', json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></script>
  <script src="/assets/js/common.js?v=<?= filemtime(BASE_PATH . '/public/assets/js/common.js') ?>"></script>
  <script src="/assets/js/admin.js?v=<?= filemtime(BASE_PATH . '/public/assets/js/admin.js') ?>"></script>
<?php endif; ?>
</body>
</html>
