// ─── Site public « Chambre noire » : accueil + page album ──────────────
// Le HTML est rendu par le serveur ; ce script ajoute les animations, la recherche,
// la pagination et la lightbox. Tout fonctionne (sans animation) si GSAP ne charge pas.
(function () {
  const { esc, fmtDate, toast, api, initial, pickSize } = window.CA;
  const page = document.body.dataset.page;
  const hasGsap = !!window.gsap;
  const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;
  const fine = matchMedia("(hover: hover) and (pointer: fine)").matches;
  const animate = hasGsap && !reduce;
  if (hasGsap) gsap.registerPlugin(ScrollTrigger);
  const pad = (n) => String(n).padStart(2, "0");

  // ─── Curseur custom ─────────────────────────────────────────────────
  const cursor = document.querySelector(".cursor");
  if (cursor && fine && !reduce) {
    document.body.classList.add("no-cursor");
    let cx = innerWidth / 2, cy = innerHeight / 2, tx = cx, ty = cy;
    addEventListener("mousemove", (e) => { tx = e.clientX; ty = e.clientY; });
    (function loop() { cx += (tx - cx) * .2; cy += (ty - cy) * .2; cursor.style.transform = `translate(${cx}px,${cy}px)`; requestAnimationFrame(loop); })();
    document.addEventListener("mouseover", (e) => {
      const t = e.target.closest("a, button, .g-item, .strip img, input, select");
      cursor.classList.toggle("hover", !!t && !e.target.closest(".row, .card"));
      cursor.classList.toggle("view", !!e.target.closest(".row, .card"));
    });
  }

  // ─── Transitions de page ────────────────────────────────────────────
  const wipe = document.querySelector(".wipe");
  if (wipe && sessionStorage.getItem("wipe") && !reduce) { wipe.classList.add("out"); sessionStorage.removeItem("wipe"); }
  document.addEventListener("click", (e) => {
    const a = e.target.closest("a[href]");
    if (!a || !wipe || reduce || a.target === "_blank" || a.hasAttribute("download")) return;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || url.hash || url.pathname.startsWith("/admin")) return;
    if (url.pathname === location.pathname && url.search === location.search) return;
    e.preventDefault();
    sessionStorage.setItem("wipe", "1");
    wipe.classList.remove("out"); wipe.classList.add("in");
    setTimeout(() => (location.href = url.href), 650);
  });

  // ─── Retour en haut ─────────────────────────────────────────────────
  const totop = document.getElementById("totop");
  if (totop) {
    addEventListener("scroll", () => { totop.hidden = scrollY < innerHeight * 1.5; }, { passive: true });
    totop.addEventListener("click", () => scrollTo({ top: 0, behavior: reduce ? "auto" : "smooth" }));
  }

  const splitWords = (title) => (title || "Sans titre").split(" ").map((w, i) => `<span class="w"><span>${i ? `<em>${esc(w)}</em>` : esc(w)}</span></span>`).join(" ");

  try {
    if (page === "home") renderHome();
    if (page === "album") renderAlbum();
    if (page === "about" || page === "error") revealFoot();
  } catch (e) { console.error(e); }

  function revealFoot() {
    if (animate) gsap.from(".foot .big", { yPercent: 40, opacity: 0, duration: 1.2, ease: "expo.out", scrollTrigger: { trigger: ".foot", start: "top 90%", once: true } });
  }

  // ═══════════════════════════════════════════════════════════════════
  // ACCUEIL
  // ═══════════════════════════════════════════════════════════════════
  function renderHome() {
    const data = initial("initial-data") || { albums: [], total: 0, pages: 1 };
    const rows = document.getElementById("rows"), more = document.getElementById("more"), status = document.getElementById("index-status");
    const searchIn = document.getElementById("search"), yearSel = document.getElementById("year"), catSel = document.getElementById("category");
    const fl = document.getElementById("float-img");
    let state = { page: 1, pages: data.pages, q: "", year: "", category: "", offset: data.albums.length };

    const rowHtml = (a, i) => `
      <a class="row" href="${esc(a.url)}" data-id="${a.id}">
        <span class="n">${String(i + 1).padStart(3, "0")}</span>
        <span class="t">${splitWords(a.title)}</span>
        ${a.cover ? `<img class="thumb" src="${esc(a.cover.thumb)}" alt="" loading="lazy">` : ""}
        <span class="m"><span class="d">${esc(fmtDate(a.date))}${a.location ? " · " + esc(a.location) : ""}</span><span class="c">${a.count} photos</span></span>
      </a>`;

    function registerFloat(albums) {
      albums.forEach((a) => { if (a.cover && !fl.querySelector(`[data-id="${a.id}"]`)) fl.insertAdjacentHTML("beforeend", `<img src="${esc(a.cover.thumb)}" alt="" data-id="${a.id}" loading="lazy">`); });
    }
    function appendRows(albums) {
      const frag = document.createElement("div");
      frag.innerHTML = albums.map((a, i) => rowHtml(a, state.offset + i)).join("");
      const els = [...frag.children];
      els.forEach((el) => rows.appendChild(el));
      state.offset += albums.length;
      registerFloat(albums);
      if (animate) revealRows(els); else els.forEach((el) => el.querySelectorAll(".w span").forEach((s) => (s.style.transform = "none")));
    }
    function updateStatus(res) {
      status.textContent = res.total ? `${pad(state.offset)} / ${pad(res.total)}` : "Aucun résultat";
      more.classList.toggle("hidden", state.page >= res.pages);
      if (hasGsap) ScrollTrigger.refresh();
    }
    const params = () => `?page=${state.page}&q=${encodeURIComponent(state.q)}&year=${encodeURIComponent(state.year)}&category=${encodeURIComponent(state.category)}`;

    registerFloat(data.albums);
    let loadingMore = false;
    const loadMoreAlbums = async () => {
      if (loadingMore || state.page >= state.pages) return;
      loadingMore = true; more.disabled = true;
      try { state.page++; const res = await api("/api/albums" + params()); state.pages = res.pages; appendRows(res.albums); updateStatus(res); }
      catch (e) { state.page--; toast(e.message, true); }
      finally { loadingMore = false; more.disabled = false; }
    };
    more.addEventListener("click", loadMoreAlbums);
    if ("IntersectionObserver" in window) new IntersectionObserver((en) => en.some((x) => x.isIntersecting) && loadMoreAlbums(), { rootMargin: "600px 0px" }).observe(more);
    let t;
    const refilter = () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        state = { page: 1, pages: 1, q: searchIn.value.trim(), year: yearSel?.value || "", category: catSel?.value || "", offset: 0 };
        rows.innerHTML = ""; status.textContent = "Recherche…";
        try { const res = await api("/api/albums" + params()); state.pages = res.pages; appendRows(res.albums); updateStatus(res); }
        catch (e) { toast(e.message, true); }
      }, 300);
    };
    searchIn.addEventListener("input", refilter);
    yearSel?.addEventListener("change", refilter);
    catSel?.addEventListener("change", refilter);

    // ─── Animations ───────────────────────────────────────────────────
    const bg = document.getElementById("hero-bg"), imgs = [...bg.querySelectorAll("img")];
    const loader = document.getElementById("loader");
    if (!animate) { loader?.remove(); document.querySelectorAll(".hero h1 .l span, .row .w span").forEach((s) => (s.style.transform = "none")); if (imgs[0]) imgs[0].style.opacity = 1; return; }

    preload(imgs.slice(0, 1).map((i) => i.src)).then(() => {
      const num = document.getElementById("loader-num"), obj = { v: 0 };
      gsap.timeline()
        .to(obj, { v: 100, duration: 1.1, ease: "power2.inOut", onUpdate: () => (num.textContent = pad(Math.round(obj.v))) })
        .to("#loader-bar", { width: "100%", duration: 1.1, ease: "power2.inOut" }, 0)
        .to(loader, { yPercent: -100, duration: .9, ease: "expo.inOut" }, "+=.1")
        .to(".hero h1 .l span", { y: 0, duration: 1.2, ease: "expo.out", stagger: .12 }, "-=.5")
        .from(".hero-kicker, .hero-foot > *", { y: 20, opacity: 0, duration: .8, stagger: .08, ease: "power3.out" }, "-=.8")
        .from(".topbar", { y: -20, opacity: 0, duration: .8 }, "-=.8")
        .set(loader, { display: "none" });
      if (imgs.length) {
        let i = 0;
        const show = (img) => gsap.fromTo(img, { opacity: 0, scale: 1.12 }, { opacity: 1, scale: 1, duration: 6, ease: "power1.out" });
        show(imgs[0]);
        if (imgs.length > 1) setInterval(() => { gsap.to(imgs[i], { opacity: 0, duration: 1.6 }); i = (i + 1) % imgs.length; show(imgs[i]); }, 5200);
      }
    });

    gsap.to(".hero-content", { yPercent: 25, opacity: .2, ease: "none", scrollTrigger: { trigger: ".hero", start: "top top", end: "bottom top", scrub: true } });
    gsap.to(bg, { yPercent: 18, ease: "none", scrollTrigger: { trigger: ".hero", start: "top top", end: "bottom top", scrub: true } });
    gsap.from(".section-head", { opacity: 0, y: 30, duration: 1, ease: "power3.out", stagger: .1, scrollTrigger: { trigger: ".index", start: "top 85%", once: true } });

    function revealRows(els) {
      els.forEach((row) => {
        gsap.to(row.querySelectorAll(".w span"), { y: 0, duration: 1, ease: "expo.out", stagger: .06, scrollTrigger: { trigger: row, start: "top 92%", once: true } });
        gsap.from(row.querySelectorAll(".n, .m"), { opacity: 0, x: -10, duration: .8, delay: .2, scrollTrigger: { trigger: row, start: "top 92%", once: true } });
      });
    }
    revealRows([...rows.children]);

    if (fine) {
      let mx = 0, my = 0, active = false, lastX = 0;
      const qx = gsap.quickTo(fl, "x", { duration: .6, ease: "power3" }), qy = gsap.quickTo(fl, "y", { duration: .6, ease: "power3" });
      const rot = gsap.quickTo(fl, "rotation", { duration: .8, ease: "power3" });
      addEventListener("mousemove", (e) => { mx = e.clientX; my = e.clientY; if (active) { qx(mx); qy(my); rot(gsap.utils.clamp(-8, 8, (mx - lastX) * .3)); lastX = mx; } });
      rows.addEventListener("mouseover", (e) => {
        const row = e.target.closest(".row"); if (!row) return;
        fl.querySelectorAll("img").forEach((im) => im.classList.toggle("on", im.dataset.id === row.dataset.id));
        if (!active) { active = true; gsap.set(fl, { x: mx, y: my }); gsap.to(fl, { opacity: 1, scale: 1, duration: .5, ease: "power3.out" }); }
      });
      rows.addEventListener("mouseleave", () => { active = false; gsap.to(fl, { opacity: 0, scale: .9, duration: .4 }); });
    }

    ScrollTrigger.matchMedia({
      "(min-width: 721px)": () => {
        const stripEl = document.getElementById("strip"); if (!stripEl) return;
        const trackEl = document.getElementById("strip-track");
        const dist = () => trackEl.scrollWidth - innerWidth;
        gsap.to(trackEl, { x: () => -dist(), ease: "none", scrollTrigger: { trigger: stripEl, start: "top top", end: () => "+=" + dist(), pin: true, scrub: 1, invalidateOnRefresh: true, anticipatePin: 1 } });
        gsap.utils.toArray(".card img").forEach((img) => gsap.fromTo(img, { xPercent: -8 }, { xPercent: 0, ease: "none", scrollTrigger: { trigger: stripEl, start: "top top", end: () => "+=" + dist(), scrub: 1 } }));
      },
      "(max-width: 720px)": () => {
        gsap.utils.toArray(".card").forEach((c) => gsap.from(c, { opacity: 0, y: 40, duration: 1, ease: "power3.out", scrollTrigger: { trigger: c, start: "top 90%", once: true } }));
      }
    });
    revealFoot();
    document.querySelectorAll(".card img, .row .thumb").forEach((im) => im.addEventListener("load", () => ScrollTrigger.refresh(), { once: true }));
  }

  function preload(srcs) {
    return Promise.race([
      Promise.all(srcs.map((s) => new Promise((r) => { const i = new Image(); i.onload = i.onerror = r; i.src = s; }))),
      new Promise((r) => setTimeout(r, 2000))
    ]);
  }

  // ═══════════════════════════════════════════════════════════════════
  // ALBUM
  // ═══════════════════════════════════════════════════════════════════
  function renderAlbum() {
    const data = initial("initial-data");
    if (!data) return;
    const album = data.album, photos = data.photos.slice();
    const total = album.count;
    const cols = [...document.querySelectorAll("#g-cols .g-col")];
    const more = document.getElementById("g-more"), gStatus = document.getElementById("g-status");
    let pageN = 1, loading = false;
    const ratio = (f) => { const [w, h] = f.style.aspectRatio.split("/").map(Number); return h / (w || 3); };
    const isMobile = () => innerWidth <= 720;
    let heights = cols.map((c) => [...c.querySelectorAll(".g-item")].reduce((h, f) => h + ratio(f), 0));

    // Mobile : une seule colonne dans l'ordre de l'album. Desktop : 3 colonnes équilibrées par hauteur.
    function relayout() {
      const figs = [...document.querySelectorAll("#g-cols .g-item")].sort((a, b) => +a.dataset.i - +b.dataset.i);
      const n = isMobile() ? 1 : cols.length;
      heights = cols.map(() => 0);
      figs.forEach((f) => { const c = n === 1 ? 0 : heights.indexOf(Math.min(...heights.slice(0, n))); heights[c] += ratio(f); cols[c].appendChild(f); });
      if (hasGsap) ScrollTrigger.refresh();
    }
    let wasMobile = isMobile();
    if (wasMobile) relayout();
    addEventListener("resize", () => { if (isMobile() !== wasMobile) { wasMobile = isMobile(); relayout(); } });

    // ─── Chargement progressif (jamais tout l'album d'un coup) ────────
    function appendPhotos(list) {
      const start = photos.length - list.length;
      const added = [];
      const n = isMobile() ? 1 : cols.length;
      list.forEach((p, k) => {
        const i = start + k;
        const c = n === 1 ? 0 : heights.indexOf(Math.min(...heights.slice(0, n)));
        heights[c] += p.h / (p.w || 3);
        const f = document.createElement("figure");
        f.className = "g-item"; f.dataset.i = i; f.style.aspectRatio = `${p.w}/${p.h}`;
        f.innerHTML = `<img src="${esc(p.thumb)}" alt="${esc(p.name)}" loading="lazy" decoding="async" width="${p.w}" height="${p.h}"><span class="num">${pad(i + 1)}</span><span class="cap">${esc(p.name)}</span>`;
        cols[c].appendChild(f); added.push(f);
      });
      if (animate) ScrollTrigger.batch(added, { start: "top 95%", once: true, batchMax: 12, onEnter: (b) => gsap.to(b, { clipPath: "inset(0% 0 0 0)", duration: 1.2, ease: "expo.out", stagger: .06 }) });
      else added.forEach((g) => (g.style.clipPath = "none"));
      if (hasGsap) ScrollTrigger.refresh();
    }
    async function loadMore() {
      if (loading || pageN >= data.pages) return;
      loading = true; more.disabled = true;
      try {
        const res = await api(`/api/albums/${encodeURIComponent(album.slug)}?page=${pageN + 1}`);
        pageN = res.page; photos.push(...res.photos); appendPhotos(res.photos);
        gStatus.textContent = `${photos.length} / ${total}`;
        more.classList.toggle("hidden", pageN >= res.pages);
        if (pageN >= res.pages) io?.disconnect();
      } catch (e) { toast(e.message, true); }
      finally { loading = false; more.disabled = false; }
    }
    more?.addEventListener("click", loadMore);
    // Charge automatiquement la page suivante quand on approche du bas
    const io = more && "IntersectionObserver" in window ? new IntersectionObserver((en) => en.some((x) => x.isIntersecting) && loadMore(), { rootMargin: "1200px 0px" }) : null;
    if (io && pageN < data.pages) io.observe(document.getElementById("g-more-wrap"));

    // ─── Partager ─────────────────────────────────────────────────────
    document.getElementById("share")?.addEventListener("click", async () => {
      const data = { title: `${album.title} — ${document.title.split(" — ").pop()}`, url: location.origin + album.url };
      try {
        if (navigator.share) await navigator.share(data);
        else { await navigator.clipboard.writeText(data.url); toast("Lien copié"); }
      } catch {}
    });

    // ─── Lightbox ─────────────────────────────────────────────────────
    const lb = document.getElementById("lightbox");
    const lbImg = lb.querySelector(".stage img"), lbBlur = lb.querySelector(".blur"), lbCount = lb.querySelector(".count"), lbDl = lb.querySelector(".lb-dl"), lbStrip = lb.querySelector(".strip");
    let cur = 0;
    const stageWidth = () => Math.max(innerWidth, innerHeight); // couvre le mode paysage/portrait
    const renderStrip = () => {
      const from = Math.max(0, cur - 30), to = Math.min(photos.length, cur + 31);
      lbStrip.innerHTML = photos.slice(from, to).map((p, k) => `<img src="${esc(p.thumb)}" data-i="${from + k}" class="${from + k === cur ? "cur" : ""}" alt="" loading="lazy">`).join("");
      lbStrip.querySelector(".cur")?.scrollIntoView({ inline: "center", block: "nearest" });
    };
    const show = async (i) => {
      // Si on dépasse ce qui est chargé, on charge la suite avant d'afficher
      if (i >= photos.length && pageN < data.pages) { await loadMore(); }
      cur = (i + photos.length) % photos.length;
      const p = photos[cur];
      lbImg.src = pickSize(p, stageWidth()); lbBlur.style.backgroundImage = `url("${p.thumb}")`;
      lbDl.href = p.original;
      lbCount.innerHTML = `${pad(cur + 1)} <span style="color:var(--red)">/</span> ${pad(total)}`;
      renderStrip();
      lb.classList.add("open"); document.body.style.overflow = "hidden";
      history.replaceState(null, "", `#photo-${cur + 1}`);
      [1, -1].forEach((d) => { const n = photos[(cur + d + photos.length) % photos.length]; if (n) { const pre = new Image(); pre.src = pickSize(n, stageWidth()); } });
    };
    const close = () => { lb.classList.remove("open"); document.body.style.overflow = ""; history.replaceState(null, "", location.pathname); };
    document.querySelector(".gallery")?.addEventListener("click", (e) => { const f = e.target.closest(".g-item"); if (f) show(+f.dataset.i); });
    lbStrip.addEventListener("click", (e) => e.target.dataset.i && show(+e.target.dataset.i));
    lb.querySelector(".lb-close").onclick = close;
    lb.querySelector(".prev").onclick = () => show(cur - 1);
    lb.querySelector(".next").onclick = () => show(cur + 1);
    lb.querySelector(".stage").addEventListener("click", (e) => { if (e.target !== lbImg) close(); });
    document.addEventListener("keydown", (e) => {
      if (!lb.classList.contains("open")) return;
      if (e.key === "Escape") close(); if (e.key === "ArrowLeft") show(cur - 1); if (e.key === "ArrowRight") show(cur + 1);
    });
    let sx = 0;
    lb.addEventListener("touchstart", (e) => (sx = e.touches[0].clientX), { passive: true });
    lb.addEventListener("touchend", (e) => { const dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1)); });
    const m = location.hash.match(/^#photo-(\d+)$/);
    if (m && photos.length) show(Math.min(photos.length, +m[1]) - 1);

    // ─── Animations ───────────────────────────────────────────────────
    if (!animate) {
      document.querySelectorAll(".a-hero h1 .l span").forEach((s) => (s.style.transform = "none"));
      document.querySelectorAll(".g-item").forEach((g) => (g.style.clipPath = "none"));
      return;
    }
    gsap.timeline()
      .from(".a-hero .bg img", { scale: 1.35, duration: 2.2, ease: "expo.out" }, 0)
      .to(".a-hero h1 .l span", { y: 0, duration: 1.2, ease: "expo.out", stagger: .12 }, .2)
      .from(".a-hero .back, .a-hero .lede, .a-hero .side > *", { y: 20, opacity: 0, duration: .8, stagger: .07, ease: "power3.out" }, .6);
    gsap.to(".a-hero .bg img", { yPercent: 20, scale: 1.05, ease: "none", scrollTrigger: { trigger: ".a-hero", start: "top top", end: "bottom top", scrub: true } });
    gsap.to(".a-hero .in", { yPercent: 30, opacity: 0, ease: "none", scrollTrigger: { trigger: ".a-hero", start: "40% top", end: "bottom top", scrub: true } });
    ScrollTrigger.batch(".g-item", { start: "top 95%", once: true, batchMax: 12, onEnter: (b) => gsap.to(b, { clipPath: "inset(0% 0 0 0)", duration: 1.2, ease: "expo.out", stagger: .06 }) });
    ScrollTrigger.matchMedia({
      "(min-width: 721px)": () => {
        const speeds = [0, -80, -40];
        gsap.utils.toArray(".g-col").forEach((c, i) => gsap.to(c, { y: speeds[i], ease: "none", scrollTrigger: { trigger: ".gallery", start: "top bottom", end: "bottom top", scrub: 1.2 } }));
      }
    });
    gsap.from(".g-head", { opacity: 0, y: 30, duration: 1, scrollTrigger: { trigger: ".g-head", start: "top 85%", once: true } });
    if (document.querySelector(".next-album")) gsap.from(".next-album .in", { y: 60, opacity: 0, duration: 1.2, ease: "expo.out", scrollTrigger: { trigger: ".next-album", start: "top 75%", once: true } });
    revealFoot();
  }
})();
