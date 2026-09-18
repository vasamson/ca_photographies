<?php /** @var array $albums @var int $total @var array $facets @var array $settings */
$pad = fn(int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
$splitTitle = function (string $t) use ($e) { $w = explode(' ', $t ?: 'Sans titre'); $f = array_shift($w); return $e($f) . ($w ? ' <em>' . $e(implode(' ', $w)) . '</em>' : ''); };
$splitWords = function (string $t) use ($e) { $out = []; foreach (explode(' ', $t ?: 'Sans titre') as $i => $w) $out[] = '<span class="w"><span>' . ($i ? '<em>' . $e($w) . '</em>' : $e($w)) . '</span></span>'; return implode(' ', $out); };
$covers = array_values(array_filter(array_map(fn($a) => $a['cover'] ? ($a['cover']['sizes'] ? end($a['cover']['sizes'])['url'] : $a['cover']['thumb']) : null, array_slice($albums, 0, 6))));
?>
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

    <section class="latest" id="latest">
      <div class="section-head"><h2>Derniers <em class="display" style="font-style:italic;color:var(--red)">albums</em></h2><a class="wide" href="/albums">Tous les albums →</a></div>
      <div class="rows" id="rows">
        <?php foreach ($albums as $i => $a): ?>
        <a class="row" href="<?= $e($a['url']) ?>" data-id="<?= $a['id'] ?>">
          <span class="n"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <span class="t"><?= $splitWords($a['title']) ?></span>
          <?php if ($a['cover']): ?><img class="thumb" src="<?= $e($a['cover']['thumb']) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="m"><span class="d"><?= $e($a['date_fr']) ?><?= $a['location'] ? ' · ' . $e($a['location']) : '' ?></span><span class="c"><?= $a['count'] ?> photos</span></span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="more-wrap"><a class="btn red" href="/albums">Voir les <?= $total ?> albums</a></div>
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
        <div class="strip-end"><a href="/albums">Tous les albums →</a></div>
      </div>
    </section>
    <?php endif; ?>
  </main>
