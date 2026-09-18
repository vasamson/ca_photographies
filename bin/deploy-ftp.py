#!/usr/bin/env python3
"""
Envoie l'application sur EX2 en FTPS (TLS explicite), depuis le poste de travail.
Lit les accès dans .env.deploy (jamais commité). N'envoie ni .env, ni storage/, ni archive/.

Usage :
  python3 bin/deploy-ftp.py                 # miroir app/ bin/ database/ docs/ public/ + README/.env.example
  python3 bin/deploy-ftp.py --env .env.production   # envoie la configuration serveur (fichier local, non commité)
  python3 bin/deploy-ftp.py --only public   # un seul dossier
  python3 bin/deploy-ftp.py --docroot /home/caphotog/public_html   # phase 3 : copie public/ dans le DocumentRoot
"""
import os, sys, ssl, ftplib, pathlib, posixpath

ROOT = pathlib.Path(__file__).resolve().parent.parent
REMOTE_APP = "/ca_photographies"  # chemin vu par le compte FTP (enfermé dans /home/caphotog)
DIRS = ["app", "bin", "database", "docs", "public"]
FILES = ["README.md", ".env.example"]
SKIP = {".DS_Store"}

def env():
    d = {}
    for line in (ROOT / ".env.deploy").read_text().splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1); d[k.strip()] = v.strip()
    return d

def connect(e):
    ctx = ssl.create_default_context()
    ftp = ftplib.FTP_TLS(context=ctx, timeout=60)
    ftp.connect(e["EX2_HOST"], int(e.get("EX2_PORT", 21)))
    ftp.login(e["EX2_USERNAME"], e["EX2_PASSWORD"])
    ftp.prot_p()
    return ftp

def mkdirs(ftp, remote):
    parts = remote.strip("/").split("/"); cur = ""
    for p in parts:
        cur += "/" + p
        try: ftp.mkd(cur)
        except ftplib.error_perm: pass  # existe déjà

def put_tree(ftp, local: pathlib.Path, remote: str, stats):
    mkdirs(ftp, remote)
    for item in sorted(local.iterdir()):
        if item.name in SKIP or item.name.startswith("._"): continue
        r = posixpath.join(remote, item.name)
        if item.is_dir(): put_tree(ftp, item, r, stats)
        else:
            with open(item, "rb") as fh: ftp.storbinary(f"STOR {r}", fh)
            stats[0] += 1; stats[1] += item.stat().st_size
            print(f"  ↑ {r}")

def main():
    args = sys.argv[1:]
    e = env(); ftp = connect(e)
    print(f"Connecté à {e['EX2_HOST']} ({e['EX2_USERNAME']})")
    stats = [0, 0]
    if "--docroot" in args:
        dest = args[args.index("--docroot") + 1]
        print(f"Copie de public/ vers {dest} (bascule du domaine)")
        put_tree(ftp, ROOT / "public", dest, stats)
    else:
        only = args[args.index("--only") + 1] if "--only" in args else None
        for d in DIRS:
            if only and d != only: continue
            put_tree(ftp, ROOT / d, posixpath.join(REMOTE_APP, d), stats)
        if not only:
            for f in FILES:
                with open(ROOT / f, "rb") as fh: ftp.storbinary(f"STOR {posixpath.join(REMOTE_APP, f)}", fh)
                stats[0] += 1; print(f"  ↑ {REMOTE_APP}/{f}")
            for d in ["storage", "storage/uploads", "storage/backups", "storage/logs"]:
                mkdirs(ftp, posixpath.join(REMOTE_APP, d))
        if "--env" in args:
            src = args[args.index("--env") + 1] if len(args) > args.index("--env") + 1 and not args[args.index("--env") + 1].startswith("--") else ".env"
            with open(ROOT / src, "rb") as fh: ftp.storbinary(f"STOR {REMOTE_APP}/.env", fh)
            print(f"  ↑ {REMOTE_APP}/.env  (configuration serveur)")
    ftp.quit()
    print(f"Terminé : {stats[0]} fichier(s), {stats[1]/1024:.0f} Ko.")

if __name__ == "__main__":
    main()
