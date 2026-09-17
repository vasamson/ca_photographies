// ─── Espace administrateur ──────────────────────────────────────────────
// Application mono-page : dialogue avec /api/admin/* (session + jeton CSRF).
(function () {
  const { esc, fmtDate, fmtSize, slugify, toast, api, initial } = window.CA;
  const boot = initial("admin-boot") || {};
  api.setCsrf(boot.csrf || "");

  const $ = (s, el = document) => el.querySelector(s);
  const main = $("#main"), list = $("#album-list"), dash = $("#dash"), login = $("#login");
  let user = boot.user;
  let side = { page: 1, pages: 1, q: "" };

  // ═══ Session ═════════════════════════════════════════════════════════
  $("#login-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const msg = $("#login-msg"); msg.textContent = "Connexion…"; msg.className = "msg";
    try {
      const r = await api("/api/admin/login", { method: "POST", body: { username: $("#in-user").value, password: $("#in-pass").value } });
      user = r.user; api.setCsrf(r.csrf); enter();
    } catch (err) { msg.textContent = err.message; msg.className = "msg err"; }
  });
  $("#btn-logout").addEventListener("click", async () => { try { await api("/api/admin/logout", { method: "POST" }); } catch {} location.reload(); });

  function enter() {
    login.hidden = true; dash.hidden = false;
    $("#who").textContent = user.username;
    loadSide(true);
    route();
  }

  // ═══ Navigation ══════════════════════════════════════════════════════
  document.querySelectorAll(".admin-nav [data-view]").forEach((b) => b.addEventListener("click", () => go(b.dataset.view)));
  function go(view, id) { location.hash = id ? `#${view}/${id}` : `#${view}`; }
  addEventListener("hashchange", route);
  function route() {
    if (!user) return;
    const [view = "dashboard", id] = location.hash.slice(1).split("/");
    main.onclick = null; // pas d'écouteurs hérités de la vue précédente
    document.querySelectorAll(".admin-nav [data-view]").forEach((b) => b.classList.toggle("active", b.dataset.view === view));
    list.querySelectorAll("button").forEach((b) => b.classList.toggle("active", view === "album" && b.dataset.id === id));
    ({ dashboard: viewDashboard, albums: viewAlbums, new: viewNew, album: () => viewAlbum(+id), settings: viewSettings }[view] || viewDashboard)();
  }

  // ═══ Barre latérale ══════════════════════════════════════════════════
  let sideTimer;
  $("#side-search").addEventListener("input", (e) => { clearTimeout(sideTimer); sideTimer = setTimeout(() => { side.q = e.target.value.trim(); loadSide(true); }, 300); });
  $("#side-more").addEventListener("click", () => { side.page++; loadSide(false); });
  async function loadSide(reset) {
    if (reset) { side.page = 1; list.innerHTML = ""; }
    try {
      const r = await api(`/api/admin/albums?page=${side.page}&per=40&q=${encodeURIComponent(side.q)}`);
      side.pages = r.pages;
      const [, cur] = location.hash.slice(1).split("/");
      list.insertAdjacentHTML("beforeend", r.albums.map((a) => `
        <li><button data-id="${a.id}" class="${String(a.id) === cur ? "active" : ""}">
          <span class="t">${esc(a.title)}${a.published ? "" : ' <i class="badge">brouillon</i>'}</span>
          <span class="s">${esc(fmtDate(a.date))} · ${a.count} photos</span>
        </button></li>`).join(""));
      $("#side-more").classList.toggle("hidden", side.page >= side.pages);
    } catch (e) { if (e.status === 401) location.reload(); else toast(e.message, true); }
  }
  list.addEventListener("click", (e) => { const b = e.target.closest("button[data-id]"); if (b) go("album", b.dataset.id); });

  const busy = (html = "Chargement…") => (main.innerHTML = `<p class="empty-state">${html}</p>`);
  const handle = (e) => { if (e.status === 401) location.reload(); toast(e.message, true); };

  // ═══ Tableau de bord ═════════════════════════════════════════════════
  async function viewDashboard() {
    busy();
    try {
      const s = await api("/api/admin/stats");
      main.innerHTML = `
        <div class="editor-head"><h2>Tableau de <em>bord</em></h2><div class="actions"><button class="btn solid red" id="d-new">+ Créer un album</button></div></div>
        <div class="stats">
          <div class="stat"><b>${s.albums}</b><span class="wide">Albums</span><small>${s.published} publiés · ${s.drafts} brouillons</small></div>
          <div class="stat"><b>${s.photos.toLocaleString("fr-FR")}</b><span class="wide">Photos</span><small>${s.photos_legacy.toLocaleString("fr-FR")} héritées de WordPress</small></div>
          <div class="stat"><b>${fmtSize(s.bytes)}</b><span class="wide">Nouveaux originaux</span><small>${s.free_space != null ? fmtSize(s.free_space) + " libres sur le serveur" : ""}</small></div>
          <div class="stat"><b>${s.trash}</b><span class="wide">Corbeille</span><small>${s.uploads_pending ? s.uploads_pending + " upload(s) à reprendre" : "aucun upload en attente"}</small></div>
        </div>
        ${s.variants_pending ? `<div class="msg err">${s.variants_pending} photo(s) sans versions web : lance <code>php bin/generate-variants.php</code> sur le serveur.</div>` : ""}
        <p class="hint">Moteur d'images : ${s.image_driver}${s.webp ? " · WebP" : " · JPEG (WebP indisponible)"}</p>
        <div class="section-title"><h3>Derniers albums modifiés</h3></div>
        <div class="album-cards">${s.recent.map(cardHtml).join("") || '<p class="empty-state">Aucun album. Crée le premier !</p>'}</div>`;
      $("#d-new").onclick = () => go("new");
      main.querySelectorAll(".acard").forEach((c) => (c.onclick = () => go("album", c.dataset.id)));
    } catch (e) { handle(e); }
  }
  const cardHtml = (a) => `
    <button class="acard" data-id="${a.id}">
      ${a.cover ? `<img src="${esc(a.cover.thumb)}" alt="" loading="lazy">` : '<div class="empty">Sans photo</div>'}
      <span class="cap"><b>${esc(a.title)}</b><span class="wide">${esc(fmtDate(a.date))} · ${a.count} photos${a.published ? "" : " · brouillon"}</span></span>
    </button>`;

  // ═══ Liste des albums ════════════════════════════════════════════════
  async function viewAlbums() {
    let state = { page: 1, q: "", status: "" };
    const render = async () => {
      try {
        const r = await api(`/api/admin/albums?page=${state.page}&per=40&q=${encodeURIComponent(state.q)}&status=${state.status}`);
        $("#al-body").innerHTML = r.albums.map((a) => `
          <tr data-id="${a.id}" class="${a.deleted_at ? "trashed" : ""}">
            <td>${a.cover ? `<img src="${esc(a.cover.thumb)}" alt="">` : ""}</td>
            <td><b>${esc(a.title)}</b><br><span class="wide">/albums/${esc(a.slug)}</span></td>
            <td>${esc(fmtDate(a.date))}</td><td>${esc(a.location || "")}</td><td>${a.count}</td>
            <td>${a.deleted_at ? '<i class="badge red">corbeille</i>' : a.published ? '<i class="badge ok">publié</i>' : '<i class="badge">brouillon</i>'}</td>
            <td>${a.deleted_at ? `<button class="btn small" data-restore="${a.id}">Restaurer</button>` : `<button class="btn small" data-open="${a.id}">Ouvrir</button>`}</td>
          </tr>`).join("") || '<tr><td colspan="7" class="empty-state">Aucun album.</td></tr>';
        $("#al-pager").innerHTML = r.pages > 1 ? `<button class="btn small" ${r.page <= 1 ? "disabled" : ""} data-p="${r.page - 1}">←</button> <span class="wide">${r.page} / ${r.pages} · ${r.total} albums</span> <button class="btn small" ${r.page >= r.pages ? "disabled" : ""} data-p="${r.page + 1}">→</button>` : `<span class="wide">${r.total} albums</span>`;
      } catch (e) { handle(e); }
    };
    main.innerHTML = `
      <div class="editor-head"><h2>Tous les <em>albums</em></h2><div class="actions"><button class="btn solid red" id="al-new">+ Créer un album</button></div></div>
      <div class="index-tools">
        <label class="search"><span class="wide">Rechercher</span><input id="al-q" type="search" placeholder="Titre, lieu…"></label>
        <label class="search"><span class="wide">Statut</span><select id="al-status"><option value="">Tous</option><option value="published">Publiés</option><option value="draft">Brouillons</option><option value="trash">Corbeille</option></select></label>
      </div>
      <div class="table-wrap"><table class="table"><thead><tr><th></th><th>Album</th><th>Date</th><th>Lieu</th><th>Photos</th><th>Statut</th><th></th></tr></thead><tbody id="al-body"></tbody></table></div>
      <div class="pager" id="al-pager"></div>`;
    $("#al-new").onclick = () => go("new");
    let t; $("#al-q").oninput = (e) => { clearTimeout(t); t = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; render(); }, 300); };
    $("#al-status").onchange = (e) => { state.status = e.target.value; state.page = 1; render(); };
    main.onclick = async (e) => {
      const p = e.target.closest("[data-p]"); if (p) { state.page = +p.dataset.p; render(); }
      const o = e.target.closest("[data-open]"); if (o) go("album", o.dataset.open);
      const r = e.target.closest("[data-restore]");
      if (r) { try { await api(`/api/admin/albums/${r.dataset.restore}/restore`, { method: "POST" }); toast("Album restauré"); loadSide(true); render(); } catch (err) { handle(err); } }
    };
    render();
  }

  // ═══ Formulaire album (création / édition) ═══════════════════════════
  const formHtml = (a = {}) => `
    <div class="form-grid">
      <div class="field"><label>Titre</label><input id="f-title" value="${esc(a.title || "")}" placeholder="Tour du Morbihan Juniors"></div>
      <div class="field"><label>Adresse (slug)</label><input id="f-slug" value="${esc(a.slug || "")}" placeholder="tour-du-morbihan-juniors"><span class="hint">URL publique : /albums/<b id="slug-preview">${esc(a.slug || "…")}</b>. Évite de la changer une fois l'album partagé.</span></div>
      <div class="field"><label>Date de l'événement</label><input id="f-date" type="date" value="${esc(a.date || "")}"></div>
      <div class="field"><label>Lieu</label><input id="f-location" value="${esc(a.location || "")}" placeholder="Plouay (56)"></div>
      <div class="field"><label>Catégorie</label><input id="f-category" value="${esc(a.category || "")}" placeholder="Route, VTT, Cyclo-cross…" list="cat-list"></div>
      <div class="field"><label>Statut</label><select id="f-published"><option value="0" ${a.published ? "" : "selected"}>Brouillon (invisible sur le site)</option><option value="1" ${a.published ? "selected" : ""}>Publié</option></select></div>
      <div class="field full"><label>Description</label><textarea id="f-desc" placeholder="Quelques mots sur la course…">${esc(a.description || "")}</textarea></div>
    </div>`;
  const readForm = () => ({
    title: $("#f-title").value, slug: $("#f-slug").value, event_date: $("#f-date").value, location: $("#f-location").value,
    category: $("#f-category").value, published: $("#f-published").value === "1", description: $("#f-desc").value,
  });
  function bindSlug() {
    const t = $("#f-title"), s = $("#f-slug"), p = $("#slug-preview");
    let manual = !!s.value;
    t.addEventListener("input", () => { if (!manual) { s.value = slugify(t.value); p.textContent = s.value || "…"; } });
    s.addEventListener("input", () => { manual = s.value !== ""; p.textContent = slugify(s.value) || "…"; });
  }

  function viewNew() {
    main.innerHTML = `
      <div class="editor-head"><h2>Nouvel <em>album</em></h2><div class="actions"><button class="btn solid red" id="btn-create">Créer puis ajouter les photos</button></div></div>
      ${formHtml({ date: new Date().toISOString().slice(0, 10) })}
      <p class="hint">Les photos s'ajoutent à l'étape suivante, une fois l'album créé. L'album reste en brouillon tant que tu ne le publies pas.</p>`;
    bindSlug();
    $("#btn-create").onclick = async () => {
      try { const r = await api("/api/admin/albums", { method: "POST", body: readForm() }); toast("Album créé"); loadSide(true); go("album", r.album.id); }
      catch (e) { handle(e); }
    };
  }

  // ═══ Éditeur d'album ═════════════════════════════════════════════════
  async function viewAlbum(id) {
    busy();
    let album, photos = [], photosPage = 1, photosPages = 1, showTrash = false;
    try { const r = await api(`/api/admin/albums/${id}`); album = r.album; var pending = r.uploads_pending; }
    catch (e) { return handle(e); }

    main.innerHTML = `
      <div class="editor-head">
        <h2>${esc(album.title)}${album.published ? "" : ' <i class="badge">brouillon</i>'}</h2>
        <div class="actions">
          <a class="btn small" href="/albums/${esc(album.slug)}" target="_blank" rel="noopener">Voir sur le site ↗</a>
          <button class="btn red small" id="btn-delete">Supprimer l'album</button>
          <button class="btn solid red" id="btn-save">Enregistrer</button>
        </div>
      </div>
      ${album.deleted_at ? `<div class="msg err">Cet album est dans la corbeille. <button class="btn small" id="btn-restore-album">Restaurer</button></div>` : ""}
      ${formHtml(album)}
      ${album.wp_post_id ? `<p class="hint">Album importé de WordPress (article n° ${album.wp_post_id}). Ancienne adresse redirigée : ${esc(album.legacy_url || "")}</p>` : ""}

      <div class="section-title">
        <h3>Photos <span class="wide" id="ph-count"></span></h3>
        <span class="wide">★ couverture · glisser pour réordonner · <button class="linkbtn" id="btn-trash-toggle">voir la corbeille</button></span>
      </div>

      <div class="dropzone" id="dropzone">
        Glisse tes photos ici, ou clique pour choisir
        <small>JPEG · PNG · WebP — originaux conservés, versions web générées automatiquement · plusieurs centaines de photos possibles</small>
        <input type="file" id="file-input" accept="image/jpeg,image/png,image/webp" multiple hidden>
      </div>
      <div class="upload-panel hidden" id="upload-panel">
        <div class="upload-head"><b id="up-title">Upload en cours…</b><div><button class="btn small hidden" id="up-retry">Réessayer les échecs</button> <button class="btn small red" id="up-cancel">Arrêter</button></div></div>
        <div class="bar"><i id="up-bar"></i></div>
        <div class="upload-meta wide"><span id="up-files">0 / 0 photos</span><span id="up-bytes"></span><span id="up-speed"></span></div>
        <ul class="upload-log" id="up-log"></ul>
      </div>
      ${pending?.length ? `<div class="msg" id="pending-box">${pending.length} upload(s) interrompu(s) : ${pending.map((p) => esc(p.filename) + ` (${p.received}/${p.chunks_total} morceaux)`).join(", ")}. Re-sélectionne ces fichiers : seuls les morceaux manquants seront renvoyés.</div>` : ""}

      <div class="admin-photos" id="admin-photos"></div>
      <div class="more-wrap"><button class="btn small hidden" id="ph-more">Afficher les photos suivantes</button></div>`;
    bindSlug();

    // ── Formulaire ──
    $("#btn-save").onclick = async () => {
      try { const r = await api(`/api/admin/albums/${id}`, { method: "PATCH", body: readForm() }); album = r.album; toast("Enregistré"); loadSide(true); $("h2", main).innerHTML = esc(album.title) + (album.published ? "" : ' <i class="badge">brouillon</i>'); }
      catch (e) { handle(e); }
    };
    $("#btn-delete").onclick = async () => {
      const typed = prompt(`Supprimer l'album « ${album.title} » et ses ${album.count} photos ?\n\nLes fichiers partent dans la corbeille du serveur (les photos héritées de WordPress ne sont jamais supprimées).\n\nPour confirmer, recopie l'identifiant : ${album.slug}`);
      if (typed === null) return;
      try { await api(`/api/admin/albums/${id}`, { method: "DELETE", body: { confirm: typed } }); toast("Album mis à la corbeille"); loadSide(true); go("albums"); }
      catch (e) { handle(e); }
    };
    $("#btn-restore-album")?.addEventListener("click", async () => { try { await api(`/api/admin/albums/${id}/restore`, { method: "POST" }); toast("Album restauré"); loadSide(true); viewAlbum(id); } catch (e) { handle(e); } });

    // ── Grille de photos ──
    const grid = $("#admin-photos"), moreBtn = $("#ph-more"), count = $("#ph-count");
    const phHtml = (p) => `
      <div class="ph ${p.id === album.cover_photo_id ? "cover" : ""} ${p.variants_status !== "ok" ? "warn" : ""} ${showTrash ? "trashed" : ""}" data-id="${p.id}" draggable="${!showTrash}">
        <img src="${esc(p.thumb)}" alt="" loading="lazy" decoding="async">
        <span class="meta wide">${esc(p.name)}<br>${p.w}×${p.h} · ${fmtSize(p.filesize)}${p.storage === "legacy" ? " · WP" : ""}</span>
        <div class="tools">
          ${showTrash ? `<button data-act="restore" title="Restaurer">↶</button>` : `
          <button data-act="cover" title="Définir comme couverture">★</button>
          <button data-act="first" title="Mettre en premier">⇤</button>
          ${p.variants_status !== "ok" && p.storage === "photos" ? `<button data-act="regen" title="Regénérer les versions web">⟳</button>` : ""}
          <button data-act="del" class="del" title="Supprimer">✕</button>`}
        </div>
      </div>`;
    async function loadPhotos(reset) {
      if (reset) { photosPage = 1; photos = []; grid.innerHTML = ""; }
      try {
        const r = await api(`/api/admin/albums/${id}/photos?page=${photosPage}&per=200${showTrash ? "&trash=1" : ""}`);
        photos.push(...r.photos); photosPages = r.pages;
        grid.insertAdjacentHTML("beforeend", r.photos.map(phHtml).join(""));
        count.textContent = showTrash ? `${r.total} dans la corbeille` : `${photos.length} / ${r.total}`;
        moreBtn.classList.toggle("hidden", photosPage >= photosPages);
        if (!r.total) grid.innerHTML = `<p class="empty-state">${showTrash ? "Corbeille vide." : "Aucune photo. Glisse-les dans la zone ci-dessus."}</p>`;
      } catch (e) { handle(e); }
    }
    moreBtn.onclick = () => { photosPage++; loadPhotos(false); };
    $("#btn-trash-toggle").onclick = (e) => { showTrash = !showTrash; e.target.textContent = showTrash ? "revenir aux photos" : "voir la corbeille"; loadPhotos(true); };
    loadPhotos(true);

    grid.addEventListener("click", async (e) => {
      const b = e.target.closest("button[data-act]"); if (!b) return;
      const el = b.closest(".ph"), pid = +el.dataset.id, act = b.dataset.act;
      try {
        if (act === "cover") { const r = await api(`/api/admin/albums/${id}/cover`, { method: "PATCH", body: { photo_id: pid } }); album = r.album; grid.querySelectorAll(".ph").forEach((x) => x.classList.toggle("cover", +x.dataset.id === pid)); toast("Couverture définie"); loadSide(true); }
        if (act === "first") { grid.prepend(el); await saveOrder(); }
        if (act === "regen") { b.disabled = true; await api(`/api/admin/photos/${pid}/regenerate`, { method: "POST" }); el.classList.remove("warn"); b.remove(); toast("Versions web regénérées"); }
        if (act === "del") {
          const p = photos.find((x) => x.id === pid);
          if (!confirm(`Supprimer « ${p?.name} » ?\n${p?.storage === "legacy" ? "La photo sera retirée de l'album ; le fichier WordPress d'origine reste intact." : "Le fichier part dans la corbeille (restaurable)."}`)) return;
          const r = await api(`/api/admin/photos/${pid}`, { method: "DELETE" }); el.remove(); album.count--; count.textContent = `${grid.children.length} / ${album.count}`; toast(r.note || "Photo supprimée");
        }
        if (act === "restore") { await api(`/api/admin/photos/${pid}/restore`, { method: "POST" }); el.remove(); toast("Photo restaurée"); }
      } catch (err) { handle(err); }
    });

    // ── Réordonnancement par glisser-déposer ──
    let dragEl = null;
    grid.addEventListener("dragstart", (e) => { dragEl = e.target.closest(".ph"); if (dragEl) { dragEl.classList.add("dragging"); e.dataTransfer.effectAllowed = "move"; } });
    grid.addEventListener("dragover", (e) => {
      e.preventDefault(); const over = e.target.closest(".ph"); if (!over || over === dragEl || !dragEl) return;
      const r = over.getBoundingClientRect(); const before = (e.clientX - r.left) / r.width < .5;
      grid.insertBefore(dragEl, before ? over : over.nextSibling);
    });
    grid.addEventListener("dragend", async () => { if (dragEl) { dragEl.classList.remove("dragging"); dragEl = null; await saveOrder(); } });
    async function saveOrder() {
      const ids = [...grid.querySelectorAll(".ph")].map((x) => +x.dataset.id);
      try { await api(`/api/admin/albums/${id}/order`, { method: "PATCH", body: { ids } }); toast("Ordre enregistré"); } catch (e) { handle(e); }
    }

    // ── Upload ──
    const dz = $("#dropzone"), input = $("#file-input");
    dz.onclick = () => input.click();
    input.onchange = () => { enqueue([...input.files]); input.value = ""; };
    ["dragenter", "dragover"].forEach((ev) => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add("over"); }));
    ["dragleave", "drop"].forEach((ev) => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.remove("over"); }));
    dz.addEventListener("drop", (e) => enqueue([...e.dataTransfer.files].filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name))));

    const uploader = createUploader(id, {
      onPhoto: (p) => { photos.push(p); if (!showTrash) { const empty = grid.querySelector(".empty-state"); if (empty) empty.remove(); grid.insertAdjacentHTML("beforeend", phHtml(p)); } album.count++; count.textContent = `${grid.querySelectorAll(".ph").length} / ${album.count}`; if (!album.cover_photo_id) { album.cover_photo_id = p.id; grid.querySelector(`.ph[data-id="${p.id}"]`)?.classList.add("cover"); } },
      onDone: () => { loadSide(true); $("#pending-box")?.remove(); },
    });
    function enqueue(files) { if (files.length) uploader.add(files); }
    $("#up-cancel").onclick = () => uploader.cancel();
    $("#up-retry").onclick = () => uploader.retryFailed();
  }

  // ═══ Uploader par chunks, reprenable ═════════════════════════════════
  //   Pour chaque fichier : init (le serveur renvoie les morceaux déjà reçus) → PUT des morceaux manquants → complete.
  //   3 fichiers en parallèle, 3 tentatives par morceau, état affiché en continu.
  function createUploader(albumId, { onPhoto, onDone }) {
    const panel = $("#upload-panel"), bar = $("#up-bar"), filesEl = $("#up-files"), bytesEl = $("#up-bytes"), speedEl = $("#up-speed"), title = $("#up-title"), log = $("#up-log"), retryBtn = $("#up-retry");
    const chunkSize = boot.chunk_size || 4 * 1024 * 1024;
    const PARALLEL = 3;
    let queue = [], failed = [], active = 0, cancelled = false, running = false;
    let stats = { total: 0, done: 0, bytesTotal: 0, bytesSent: 0, start: 0 };

    function add(files) {
      if (!running) { stats = { total: 0, done: 0, bytesTotal: 0, bytesSent: 0, start: Date.now() }; log.innerHTML = ""; failed = []; retryBtn.classList.add("hidden"); }
      files.forEach((f) => { queue.push(f); stats.total++; stats.bytesTotal += f.size; });
      panel.classList.remove("hidden"); cancelled = false; running = true;
      addEventListener("beforeunload", warn);
      render(); pump();
    }
    const warn = (e) => { e.preventDefault(); e.returnValue = ""; };
    function pump() { while (active < PARALLEL && queue.length && !cancelled) { active++; one(queue.shift()).finally(() => { active--; queue.length && !cancelled ? pump() : (!active && finish()); }); } if (!active && !queue.length) finish(); }
    function finish() {
      if (!running) return;
      running = false; removeEventListener("beforeunload", warn);
      title.textContent = cancelled ? "Upload arrêté" : failed.length ? `Terminé avec ${failed.length} échec(s)` : "Upload terminé ✓";
      retryBtn.classList.toggle("hidden", !failed.length);
      render(); onDone?.();
    }
    function render() {
      const pct = stats.bytesTotal ? (stats.bytesSent / stats.bytesTotal) * 100 : 0;
      bar.style.width = pct.toFixed(1) + "%";
      filesEl.textContent = `${stats.done} / ${stats.total} photos`;
      bytesEl.textContent = `${fmtSize(stats.bytesSent)} / ${fmtSize(stats.bytesTotal)}`;
      const s = (Date.now() - stats.start) / 1000, rate = s > 1 ? stats.bytesSent / s : 0;
      const left = rate ? (stats.bytesTotal - stats.bytesSent) / rate : 0;
      speedEl.textContent = running && rate ? `${fmtSize(rate)}/s · ~${left > 90 ? Math.round(left / 60) + " min" : Math.round(left) + " s"} restantes` : "";
    }
    const line = (msg, cls = "") => { const li = document.createElement("li"); li.className = cls; li.textContent = msg; log.prepend(li); while (log.children.length > 60) log.lastChild.remove(); };

    async function one(file) {
      try {
        const init = await api(`/api/admin/albums/${albumId}/uploads`, { method: "POST", body: { name: file.name, size: file.size, type: file.type, last_modified: file.lastModified } });
        if (init.status === "done") { stats.done++; stats.bytesSent += file.size; line(`${file.name} — déjà importée, ignorée`); render(); return; }
        const have = new Set(init.received); const total = init.chunks_total; const cs = init.chunk_size || chunkSize;
        stats.bytesSent += Math.min(file.size, have.size * cs); render();
        for (let i = 0; i < total; i++) {
          if (cancelled) return;
          if (have.has(i)) continue;
          const blob = file.slice(i * cs, Math.min(file.size, (i + 1) * cs));
          await retry(() => api(`/api/admin/albums/${albumId}/uploads/${init.upload_id}/chunks/${i}`, { method: "PUT", body: blob, raw: true }), 3);
          stats.bytesSent += blob.size; render();
        }
        const r = await retry(() => api(`/api/admin/albums/${albumId}/uploads/${init.upload_id}/complete`, { method: "POST" }), 2);
        stats.done++; render(); onPhoto?.(r.photo);
        if (r.photo.variants_status !== "ok") line(`${file.name} — importée, versions web à regénérer (⟳)`, "warn");
      } catch (e) {
        if (e.status === 401) location.reload();
        failed.push(file); line(`${file.name} — ${e.message}`, "err");
      }
    }
    async function retry(fn, n) { let last; for (let k = 0; k < n; k++) { try { return await fn(); } catch (e) { last = e; if (e.status && e.status < 500 && e.status !== 409 && e.status !== 429) throw e; await new Promise((r) => setTimeout(r, 800 * (k + 1))); } } throw last; }
    function cancel() { cancelled = true; queue = []; if (!active) finish(); }
    function retryFailed() { const f = failed; failed = []; stats.total -= f.length; stats.bytesTotal -= f.reduce((s, x) => s + x.size, 0); add(f); }
    return { add, cancel, retryFailed };
  }

  // ═══ Paramètres ══════════════════════════════════════════════════════
  async function viewSettings() {
    busy();
    try {
      const { settings: s } = await api("/api/admin/settings");
      const f = (k, label, type = "input", hint = "") => `<div class="field ${type === "textarea" ? "full" : ""}"><label>${label}</label>${type === "textarea" ? `<textarea id="s-${k}">${esc(s[k])}</textarea>` : `<input id="s-${k}" value="${esc(s[k])}">`}${hint ? `<span class="hint">${hint}</span>` : ""}</div>`;
      main.innerHTML = `
        <div class="editor-head"><h2>Para<em>mètres</em></h2><div class="actions"><button class="btn solid red" id="s-save">Enregistrer</button></div></div>
        <div class="form-grid">
          ${f("site_name", "Nom du site")}${f("tagline", "Sous-titre")}
          ${f("home_intro", "Phrase d'accueil")}${f("contact_email", "E-mail de contact")}
          ${f("instagram", "Lien Instagram")}${f("facebook", "Lien Facebook")}
          ${f("about_title", "Titre de la page À propos")}${f("per_page", "Albums par page (accueil)")}
          ${f("photos_per_page", "Photos chargées par lot (page album)", "input", "Le reste se charge au défilement.")}
          ${f("about_text", "Texte de la page À propos", "textarea")}
        </div>
        <div class="section-title"><h3>Mot de passe</h3></div>
        <div class="form-grid">
          <div class="field"><label>Mot de passe actuel</label><input id="p-cur" type="password" autocomplete="current-password"></div>
          <div class="field"><label>Nouveau mot de passe (10 caractères min.)</label><input id="p-new" type="password" autocomplete="new-password"></div>
          <div class="field full"><button class="btn" id="p-save">Changer le mot de passe</button></div>
        </div>`;
      $("#s-save").onclick = async () => {
        const body = {}; Object.keys(s).forEach((k) => { const el = $(`#s-${k}`); if (el) body[k] = el.value; });
        try { await api("/api/admin/settings", { method: "PATCH", body }); toast("Paramètres enregistrés"); } catch (e) { handle(e); }
      };
      $("#p-save").onclick = async () => {
        try { await api("/api/admin/password", { method: "POST", body: { current: $("#p-cur").value, new: $("#p-new").value } }); toast("Mot de passe changé"); $("#p-cur").value = $("#p-new").value = ""; } catch (e) { handle(e); }
      };
    } catch (e) { handle(e); }
  }

  if (user) enter();
})();
