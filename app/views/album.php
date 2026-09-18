<?php /** @var array $album @var array $photos @var ?array $next @var array $settings */
$pad = fn(int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
$words = explode(' ', $album['title'] ?: 'Sans titre');
$first = array_shift($words);
// Répartition en 3 colonnes équilibrées par hauteur (le JS continue avec la même logique pour les pages suivantes)
$cols = [[], [], []]; $heights = [0, 0, 0];
foreach ($photos as $i => $p) { $k = array_search(min($heights), $heights, true); $cols[$k][] = [$p, $i]; $heights[$k] += $p['h'] / max(1, $p['w']); }
$hero = $album['cover'] ? ($album['cover']['sizes'] ? end($album['cover']['sizes'])['url'] : $album['cover']['thumb']) : null;
?>
  <main id="main">
    <section class="a-hero">
      <div class="bg"><?php if ($hero): ?><img src="<?= $e($hero) ?>" alt="" fetchpriority="high"><?php endif; ?></div>
      <div class="in">
        <div>
          <a class="back wide" href="/">Tous les albums</a>
          <h1><span class="l"><span><?= $e($first) ?></span></span><?php if ($words): ?><span class="l"><span><em><?= $e(implode(' ', $words)) ?></em></span></span><?php endif; ?></h1>
          <?php if ($album['description']): ?><p class="lede"><?= nl2br($e($album['description'])) ?></p><?php endif; ?>
        </div>
        <aside class="side">
          <dl class="facts">
            <?php if ($album['date']): ?><dt class="wide">Date</dt><dd><?= $e($album['date_fr']) ?></dd><?php endif; ?>
            <?php if ($album['location']): ?><dt class="wide">Lieu</dt><dd><?= $e($album['location']) ?></dd><?php endif; ?>
            <?php if ($album['category']): ?><dt class="wide">Catégorie</dt><dd><?= $e($album['category']) ?></dd><?php endif; ?>
            <dt class="wide">Photos</dt><dd><?= $album['count'] ?></dd>
          </dl>
          <div class="a-actions">
            <button class="btn small" id="share" type="button">Partager l'album</button>
            <?php if ($photos): ?><a class="btn small" href="#g-cols">Voir la série ↓</a><?php endif; ?>
          </div>
        </aside>
      </div>
    </section>

    <section class="gallery">
      <div class="g-head"><h2>La <em style="font-style:italic;color:var(--red)">série</em></h2><span class="wide"><?= $album['count'] ?> photos · cliquer pour agrandir · ⤓ pour télécharger</span></div>
      <?php if ($photos): ?>
      <div class="g-cols" id="g-cols">
        <?php foreach ($cols as $col): ?><div class="g-col"><?php foreach ($col as [$p, $i]): ?>
          <figure class="g-item" data-i="<?= $i ?>" style="aspect-ratio:<?= $p['w'] ?>/<?= $p['h'] ?>"><img src="<?= $e($p['thumb']) ?>" alt="<?= $e($p['name']) ?>" loading="lazy" decoding="async" width="<?= $p['w'] ?>" height="<?= $p['h'] ?>"><span class="num"><?= $pad($i + 1) ?></span><span class="cap"><?= $e($p['name']) ?></span><a class="dl" href="<?= $e($p['original']) ?>" download title="Télécharger la photo en haute définition" aria-label="Télécharger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3"/></svg></a></figure>
        <?php endforeach; ?></div><?php endforeach; ?>
      </div>
      <div class="more-wrap" id="g-more-wrap"><button class="btn <?= $initial['pages'] > 1 ? '' : 'hidden' ?>" id="g-more">Charger la suite <span class="wide" id="g-status"><?= count($photos) ?> / <?= $album['count'] ?></span></button></div>
      <?php else: ?>
      <p class="empty-state">Cet album ne contient pas encore de photos.</p>
      <?php endif; ?>
    </section>

    <?php if ($next): ?>
    <a class="next-album" href="<?= $e($next['url']) ?>">
      <?php if ($next['cover']): ?><img src="<?= $e($next['cover']['sizes'][0]['url'] ?? $next['cover']['thumb']) ?>" alt="" loading="lazy"><?php endif; ?>
      <div class="in"><span class="wide">Album suivant</span><h3><?= $e(explode(' ', $next['title'])[0]) ?><?php $nw = explode(' ', $next['title']); array_shift($nw); if ($nw): ?> <em><?= $e(implode(' ', $nw)) ?></em><?php endif; ?></h3></div>
    </a>
    <?php endif; ?>

    <div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Photo en plein écran">
      <div class="blur"></div>
      <div class="ui">
        <div class="lb-info"><span class="count"></span><span class="lb-name wide"></span></div>
        <div class="lb-actions">
          <a class="btn solid red small lb-dl" download><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3"/></svg><span>Télécharger la photo</span></a>
          <button class="lb-close" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
      </div>
      <div class="stage"><img alt=""></div>
      <button class="nav prev" aria-label="Photo précédente"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg></button>
      <button class="nav next" aria-label="Photo suivante"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg></button>
      <div class="strip"></div>
    </div>
  </main>
