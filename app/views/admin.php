<?php /** @var array $boot @var array $settings */ ?>
  <header class="topbar">
    <a class="brand" href="/admin">Espace <em>photographes</em></a>
    <nav><a href="/" target="_blank" rel="noopener"><span data-t="Voir le site ↗">Voir le site ↗</span></a></nav>
  </header>
  <div class="wrap admin-page" id="app">
    <section class="login" id="login" <?= $boot['user'] ? 'hidden' : '' ?>>
      <h1>Se <em>connecter</em></h1>
      <p>Espace réservé à l'administration de <?= $e($settings['site_name']) ?>.</p>
      <form class="form-grid" id="login-form" autocomplete="on">
        <div class="field"><label for="in-user">Identifiant</label><input id="in-user" name="username" autocomplete="username" required></div>
        <div class="field"><label for="in-pass">Mot de passe</label><input id="in-pass" name="password" type="password" autocomplete="current-password" required></div>
        <div class="field full"><div class="msg" id="login-msg"></div><button class="btn solid red" type="submit">Connexion</button></div>
      </form>
    </section>
    <section class="admin-shell" id="dash" <?= $boot['user'] ? '' : 'hidden' ?>>
      <aside class="admin-side">
        <div class="who"><span id="who"></span><button id="btn-logout" type="button">Déconnexion</button></div>
        <nav class="admin-nav">
          <button class="btn" data-view="dashboard">Tableau de bord</button>
          <button class="btn" data-view="albums">Albums</button>
          <button class="btn solid red" data-view="new">+ Créer un album</button>
          <button class="btn" data-view="settings">Paramètres</button>
        </nav>
        <label class="search side-search"><span class="wide">Filtrer</span><input id="side-search" type="search" placeholder="Titre, lieu…" autocomplete="off"></label>
        <ul class="album-list" id="album-list"></ul>
        <button class="btn small hidden" id="side-more" type="button">Plus d'albums</button>
      </aside>
      <main class="admin-main" id="main"></main>
    </section>
  </div>
  <noscript><p class="empty-state">L'administration nécessite JavaScript.</p></noscript>
