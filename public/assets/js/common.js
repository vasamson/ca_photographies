// ─── Utilitaires partagés (site public + admin) ─────────────────────────
(function () {
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  const fmtDate = (iso) => {
    if (!iso) return "";
    const d = new Date(iso + (iso.length === 10 ? "T12:00:00" : ""));
    return isNaN(d) ? iso : d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
  };

  const fmtSize = (b) => { const u = ["o", "Ko", "Mo", "Go", "To"]; let i = 0; b = +b || 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(1).replace(".", ",") : b) + " " + u[i]; };

  const slugify = (s) => String(s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 80);

  function toast(msg, isErr) {
    let t = document.querySelector(".toast");
    if (!t) { t = document.createElement("div"); t.className = "toast"; document.body.appendChild(t); }
    t.textContent = msg; t.classList.toggle("err", !!isErr); t.classList.add("show");
    clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove("show"), isErr ? 5000 : 3200);
  }

  // Appel API JSON. `csrf` est ajouté par l'admin sur les méthodes mutantes.
  let csrf = "";
  async function api(path, { method = "GET", body, raw, signal } = {}) {
    const headers = { Accept: "application/json" };
    if (method !== "GET") headers["X-CSRF-Token"] = csrf;
    if (body !== undefined && !raw) headers["Content-Type"] = "application/json";
    const r = await fetch(path, { method, headers, body: raw ? body : (body !== undefined ? JSON.stringify(body) : undefined), credentials: "same-origin", signal });
    let data = null;
    try { data = await r.json(); } catch {}
    if (!r.ok) { const e = new Error(data?.error || `Erreur ${r.status}`); e.status = r.status; e.data = data; throw e; }
    return data;
  }
  api.setCsrf = (t) => (csrf = t);

  // Lit le JSON embarqué par le serveur
  const initial = (id) => { try { return JSON.parse(document.getElementById(id)?.textContent || "null"); } catch { return null; } };

  // Choisit l'URL la mieux adaptée à l'écran parmi photo.sizes (triées croissantes) ; original en dernier recours
  function pickSize(photo, targetWidth) {
    const want = Math.ceil(targetWidth * Math.min(devicePixelRatio || 1, 2));
    const s = (photo.sizes || []).find((x) => x.w >= want) || (photo.sizes || [])[photo.sizes.length - 1];
    return s ? s.url : photo.original;
  }

  window.CA = { esc, fmtDate, fmtSize, slugify, toast, api, initial, pickSize };
})();
