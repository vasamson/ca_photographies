<?php /** @var array $settings @var ?string $sent @var ?string $error @var array $old */ ?>
  <main id="main">
    <section class="about wrap">
      <div class="section-head"><h2>Nous <em style="font-style:italic;color:var(--red)">contacter</em></h2><span class="wide">Réponse sous quelques jours</span></div>
      <div class="contact-grid">
        <div class="about-body">
          <p>Une photo à retrouver, une course à couvrir, une question sur un album ? Écrivez-nous, on vous répond directement par e-mail.</p>
          <dl class="facts">
            <?php if ($settings['contact_email']): ?><dt class="wide">E-mail</dt><dd><a href="mailto:<?= $e($settings['contact_email']) ?>"><?= $e($settings['contact_email']) ?></a></dd><?php endif; ?>
            <?php if ($settings['instagram']): ?><dt class="wide">Instagram</dt><dd><a href="<?= $e($settings['instagram']) ?>" target="_blank" rel="noopener">Voir le compte ↗</a></dd><?php endif; ?>
            <?php if ($settings['facebook']): ?><dt class="wide">Facebook</dt><dd><a href="<?= $e($settings['facebook']) ?>" target="_blank" rel="noopener">Voir la page ↗</a></dd><?php endif; ?>
          </dl>
        </div>
        <?php if ($sent): ?>
          <div class="contact-form"><p class="msg ok">Merci <?= $e($sent) ?>, votre message est bien envoyé. Nous vous répondons dès que possible.</p><a class="btn red" href="/">Retour aux albums</a></div>
        <?php else: ?>
        <form class="contact-form form-grid" method="post" action="/contact" novalidate>
          <input type="hidden" name="_token" value="<?= $e($token) ?>">
          <input type="hidden" name="_t" value="<?= time() ?>">
          <div class="hp" aria-hidden="true"><label>Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="field"><label for="c-name">Votre nom</label><input id="c-name" name="name" required maxlength="120" autocomplete="name" value="<?= $e($old['name'] ?? '') ?>"></div>
          <div class="field"><label for="c-email">Votre e-mail</label><input id="c-email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= $e($old['email'] ?? '') ?>"></div>
          <div class="field full"><label for="c-subject">Sujet</label><input id="c-subject" name="subject" maxlength="150" placeholder="Ex. Photo n° 27 de Plouay pro hommes" value="<?= $e($old['subject'] ?? '') ?>"></div>
          <div class="field full"><label for="c-message">Message</label><textarea id="c-message" name="message" required maxlength="5000"><?= $e($old['message'] ?? '') ?></textarea></div>
          <div class="field full"><div class="msg <?= $error ? 'err' : '' ?>"><?= $e($error ?? '') ?></div><button class="btn solid red" type="submit">Envoyer le message</button></div>
        </form>
        <?php endif; ?>
      </div>
    </section>
  </main>
