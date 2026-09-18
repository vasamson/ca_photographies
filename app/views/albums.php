<?php /** @var array $albums @var int $total @var int $pages @var array $facets */
$pad = fn(int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
$splitWords = function (string $t) use ($e) { $out = []; foreach (explode(' ', $t ?: 'Sans titre') as $i => $w) $out[] = '<span class="w"><span>' . ($i ? '<em>' . $e($w) . '</em>' : $e($w)) . '</span></span>'; return implode(' ', $out); };
?>
  <main id="main">
    <section class="index" id="index">
      <div class="section-head"><h2>Tous les <em class="display" style="font-style:italic;color:var(--red)">albums</em></h2><span class="wide"><?= $total ?> albums · survoler pour voir</span></div>
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
  </main>
