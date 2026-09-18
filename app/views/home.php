<?php /** @var array $albums @var int $total @var array $facets @var array $settings */
$pad = fn(int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
$splitTitle = function (string $t) use ($e) { $w = explode(' ', $t ?: 'Sans titre'); $f = array_shift($w); return $e($f) . ($w ? ' <em>' . $e(implode(' ', $w)) . '</em>' : ''); };
$splitWords = function (string $t) use ($e) { $out = []; foreach (explode(' ', $t ?: 'Sans titre') as $i => $w) $out[] = '<span class="w"><span>' . ($i ? '<em>' . $e($w) . '</em>' : $e($w)) . '</span></span>'; return implode(' ', $out); };
$covers = array_values(array_filter(array_map(fn($a) => $a['cover'] ? ($a['cover']['sizes'] ? end($a['cover']['sizes'])['url'] : $a['cover']['thumb']) : null, array_slice($albums, 0, 6))));
?>
  <div class="loader" id="loader" aria-hidden="true">
    <span class="wide lbl">Développement en cours</span>
    <div class="num"><span id="loader-num">00</span><i>%</i></div>
    <div class="bar" id="loader-bar"></div>
  </div>

  <main id="main">
    <section class="hero">
      <div class="hero-bg" id="hero-bg"><?php foreach ($covers as $i => $c): ?><img src="<?= $e($c) ?>" alt="" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>><?php endforeach; ?></div>
      <div class="hero-content">
        <div class="hero-kicker"><span class="line"></span><span class="wide"><?= $e($settings['tagline']) ?></span></div>
        <h1><span class="l"><span>Les</span></span><span class="l"><span><em>albums</em></span></span></h1>
      </div>
      <div class="hero-foot">
        <p><?= $e($settings['home_intro']) ?></p>
        <div class="count wide">Albums<b data-count><?= $pad($total) ?></b></div>
        <div class="scroll wide"><span>Défiler</span><i></i></div>
      </div>
    </section>

    <div class="ticker" aria-hidden="true"><div class="track" id="ticker"><?php $names = array_slice(array_column($albums, 'title'), 0, 12) ?: ['Bientôt']; foreach ([...$names, ...$names] as $n): ?><span><?= $e($n) ?></span><?php endforeach; ?></div></div>

    <section class="index" id="index">
      <div class="section-head"><h2>Index <em class="display" style="font-style:italic;color:var(--red)">complet</em></h2><span class="wide">Survoler pour voir</span></div>
      <div class="index-tools">
        <label class="search"><span class="wide">Rechercher</span><input id="search" type="search" placeholder="Plouay, Tour du Morbihan, juniors…" autocomplete="off"></label>
        <?php if (count($facets['years']) > 1): ?>
        <label class="search"><span class="wide">Année</span><select id="year"><option value="">Toutes</option><?php foreach ($facets['years'] as $y): ?><option value="<?= $e($y) ?>"><?= $e($y) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <?php if ($facets['categories']): ?>
        <label class="search"><span class="wide">Catégorie</span><select id="category"><option value="">Toutes</option><?php foreach ($facets['categories'] as $c): ?><option value="<?= $e($c) ?>"><?= $e($c) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <span class="wide" id="index-status"><?= $pad(count($albums)) ?> / <?= $pad($total) ?></span>
      </div>
      <div class="rows" id="rows">
        <?php foreach ($albums as $i => $a): ?>
        <a class="row" href="<?= $e($a['url']) ?>" data-id="<?= $a['id'] ?>">
          <span class="n"><?= str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) ?></span>
          <span class="t"><?= $splitWords($a['title']) ?></span>
          <?php if ($a['cover']): ?><img class="thumb" src="<?= $e($a['cover']['thumb']) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="m"><span class="d"><?= $e($a['date_fr']) ?><?= $a['location'] ? ' · ' . $e($a['location']) : '' ?></span><span class="c"><?= $a['count'] ?> photos</span></span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="more-wrap"><button class="btn <?= $pages > 1 ? '' : 'hidden' ?>" id="more">Charger plus d'albums</button></div>
    </section>

    <?php if ($albums): ?>
    <section class="strip" id="strip">
      <div class="section-head"><h2>En <em style="font-style:italic;color:var(--red)">images</em></h2><span class="wide">Défiler pour parcourir</span></div>
      <div class="strip-track" id="strip-track">
        <?php foreach (array_slice($albums, 0, 10) as $i => $a): ?>
        <a class="card" href="<?= $e($a['url']) ?>">
          <?php if ($a['cover']): ?><img src="<?= $e($a['cover']['sizes'][0]['url'] ?? $a['cover']['thumb']) ?>" alt="<?= $e($a['title']) ?>" loading="lazy"><?php else: ?><div class="empty">Album vide</div><?php endif; ?>
          <span class="idx"><?= $pad($i + 1) ?></span>
          <div class="cap"><h3><?= $splitTitle($a['title']) ?></h3><span class="wide"><?= $e($a['date_fr']) ?></span></div>
        </a>
        <?php endforeach; ?>
        <div class="strip-end"><a href="#index">Voir l'index ↑</a></div>
      </div>
    </section>
    <?php endif; ?>
  </main>
