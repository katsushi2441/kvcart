#!/usr/bin/env python3
"""本番（heteml /web/exbridge_jp/exdirect）へ public/ の PHP を置く（2026-11-24）。

  cd /home/kojima/work && set -a; . aixec/.env; set +a
  /usr/bin/python3 kvcart/scripts/deploy_php.py --tag cardsave [--db-backup] kv_stripe.php admin.php ...

決まりどおりに、次の順で行う。どれかが合わなければ何も置かずに止まる。
1. 本番のファイルが git の HEAD と同じ（md5）か確かめる（誰かが本番だけ直していたら上書きしない）
2. 本番のファイルの控えを kv_data/<名前>.bak-<日付>-<tag> に取る
   （公開フォルダに .php.bak を置くと PHP として動いてしまうので、外から読めない kv_data/ に置く）
3. --db-backup のときは DB の控え kv_data/shop.sqlite.bak-<日付>-<tag> を取る（取る間に本番の DB が変わらないことを MDTM で確かめる）
4. 置いて、読み戻して md5 が手元と同じか確かめる
"""
import argparse, datetime, ftplib, hashlib, os, subprocess, sys, io

ROOT = "/web/exbridge_jp/exdirect"
REPO = "/home/kojima/work/kvcart"

ap = argparse.ArgumentParser()
ap.add_argument("--tag", required=True)
ap.add_argument("--db-backup", action="store_true")
ap.add_argument("--base", default="HEAD", help="本番がこの版と同じときだけ置く（commit したあとに置くときは HEAD~1 など）")
ap.add_argument("files", nargs="+")
a = ap.parse_args()
day = datetime.datetime.now().strftime("%Y%m%d")
md5 = lambda b: hashlib.md5(b).hexdigest()

f = ftplib.FTP(); f.encoding = "utf-8"
f.connect(os.environ["FTP_HOST"], 21, timeout=60); f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
f.voidcmd("TYPE I"); f.cwd(ROOT)


def get(name):
    b = bytearray(); f.retrbinary("RETR " + name, b.extend); return bytes(b)


server = {}
for fn in a.files:
    head = subprocess.run(["git", "-C", REPO, "show", a.base + ":public/" + fn], capture_output=True).stdout
    server[fn] = get(fn)
    same = md5(server[fn]) == md5(head)
    print(f"{fn}: 本番と {a.base} {'同じ' if same else 'ちがう'}")
    if not same:
        print("本番だけ直されているので置かない"); f.quit(); sys.exit(3)

f.cwd(ROOT + "/kv_data")
for fn, b in server.items():
    bk = f"{fn}.bak-{day}-{a.tag}"
    f.storbinary("STOR " + bk, io.BytesIO(b))
    print(f"控え kv_data/{bk} {f.size(bk)} バイト")
if a.db_backup:
    st = (f.size("shop.sqlite"), f.sendcmd("MDTM shop.sqlite"))
    db = get("shop.sqlite")
    if (f.size("shop.sqlite"), f.sendcmd("MDTM shop.sqlite")) != st or len(db) != st[0]:
        print("DB を読む間に本番の DB が変わったので止める"); f.quit(); sys.exit(3)
    bk = f"shop.sqlite.bak-{day}-{a.tag}"
    f.storbinary("STOR " + bk, io.BytesIO(db))
    print(f"DB の控え kv_data/{bk} {f.size(bk)} バイト（元 {st[0]}）")
    if f.size(bk) != st[0]:
        print("DB の控えの大きさが合わない"); f.quit(); sys.exit(3)

f.cwd(ROOT)
for fn in a.files:
    local = open(os.path.join(REPO, "public", fn), "rb").read()
    f.storbinary("STOR " + fn, io.BytesIO(local))
    back = get(fn)
    print(f"置いた {fn}: md5 {'一致' if md5(back) == md5(local) else '不一致'}")
f.quit()
