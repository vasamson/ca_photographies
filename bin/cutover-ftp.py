#!/usr/bin/env python3
"""
Bascule du domaine (phase 3) via FTPS : met WordPress de côté et installe le nouveau front dans public_html.
RIEN n'est supprimé : les fichiers WordPress sont RENOMMÉS (déplacés) vers ~/wordpress_archive.
Ne touche jamais à public_html/wp-content/uploads ni à public_html/photos.

Usage : python3 bin/cutover-ftp.py [--dry-run] [--rollback]
"""
import sys, ssl, ftplib, pathlib
ROOT = pathlib.Path(__file__).resolve().parent.parent
KEEP_ROOT = {"wp-content", "photos", "cgi-bin", ".well-known", ".", ".."}
KEEP_WPCONTENT = {"uploads", ".", ".."}
ARCHIVE = "/wordpress_archive"

def env():
    d = {}
    for l in (ROOT / ".env.deploy").read_text().splitlines():
        if "=" in l and not l.startswith("#"): k, v = l.split("=", 1); d[k.strip()] = v.strip()
    return d

def connect(e):
    f = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=120)
    f.connect(e["EX2_HOST"], int(e.get("EX2_PORT", 21))); f.login(e["EX2_USERNAME"], e["EX2_PASSWORD"]); f.prot_p(); return f

def names(ftp, path): return [n for n, _ in ftp.mlsd(path)]
def mkd(ftp, p):
    try: ftp.mkd(p)
    except ftplib.error_perm: pass

def move(ftp, src, dst, dry):
    print(f"  {src}  →  {dst}")
    if not dry: ftp.rename(src, dst)

def main():
    dry = "--dry-run" in sys.argv; rollback = "--rollback" in sys.argv
    ftp = connect(env())
    if rollback:
        print("Retour arrière : WordPress remis dans public_html")
        for n in names(ftp, "/public_html"):
            if n in (".", "..", "index.php", ".htaccess", "assets", "wp-content", "photos", "cgi-bin", ".well-known"): continue
        for n in ("index.php", ".htaccess"):
            if n in names(ftp, "/public_html"): move(ftp, f"/public_html/{n}", f"/public_html/{n}.newsite", dry)
        for n in names(ftp, ARCHIVE):
            if n in (".", "..", "wp-content"): continue
            move(ftp, f"{ARCHIVE}/{n}", f"/public_html/{n}", dry)
        for n in names(ftp, f"{ARCHIVE}/wp-content"):
            if n in (".", ".."): continue
            move(ftp, f"{ARCHIVE}/wp-content/{n}", f"/public_html/wp-content/{n}", dry)
        ftp.quit(); print("Terminé."); return

    mkd(ftp, ARCHIVE); mkd(ftp, f"{ARCHIVE}/wp-content")
    print("1. Racine de public_html → wordpress_archive (sauf wp-content, photos, cgi-bin, .well-known)")
    for n in names(ftp, "/public_html"):
        if n in KEEP_ROOT: continue
        move(ftp, f"/public_html/{n}", f"{ARCHIVE}/{n}", dry)
    print("2. wp-content → wordpress_archive/wp-content (sauf uploads)")
    for n in names(ftp, "/public_html/wp-content"):
        if n in KEEP_WPCONTENT: continue
        move(ftp, f"/public_html/wp-content/{n}", f"{ARCHIVE}/wp-content/{n}", dry)
    print("Reste dans public_html :", [n for n in names(ftp, "/public_html") if n not in (".", "..")])
    print("Reste dans wp-content :", [n for n in names(ftp, "/public_html/wp-content") if n not in (".", "..")])
    ftp.quit()

if __name__ == "__main__": main()
