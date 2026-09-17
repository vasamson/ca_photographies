<?php /** @var array $settings */ ?>
  <main>
    <section class="about wrap">
      <div class="section-head"><h2><?= $e($settings['about_title']) ?></h2><span class="wide"><?= $e($settings['tagline']) ?></span></div>
      <div class="about-body">
        <?php foreach (preg_split('/\n{2,}/', trim($settings['about_text'])) as $para): ?><p><?= nl2br($e($para)) ?></p><?php endforeach; ?>
        <dl class="facts">
          <?php if ($settings['contact_email']): ?><dt class="wide">Contact</dt><dd><a href="mailto:<?= $e($settings['contact_email']) ?>"><?= $e($settings['contact_email']) ?></a></dd><?php endif; ?>
          <?php if ($settings['instagram']): ?><dt class="wide">Instagram</dt><dd><a href="<?= $e($settings['instagram']) ?>" target="_blank" rel="noopener">Voir le compte ↗</a></dd><?php endif; ?>
          <?php if ($settings['facebook']): ?><dt class="wide">Facebook</dt><dd><a href="<?= $e($settings['facebook']) ?>" target="_blank" rel="noopener">Voir la page ↗</a></dd><?php endif; ?>
        </dl>
        <a class="btn red" href="/">Voir les albums</a>
      </div>
    </section>
  </main>
