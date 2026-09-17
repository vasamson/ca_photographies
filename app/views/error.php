<?php /** @var int $status @var string $message */ ?>
  <main>
    <section class="about wrap">
      <div class="section-head"><h2>Erreur <em style="font-style:italic;color:var(--red)"><?= (int) $status ?></em></h2></div>
      <div class="about-body">
        <p><?= nl2br($e($message)) ?></p>
        <a class="btn red" href="/">Retour à l'accueil</a>
      </div>
    </section>
  </main>
