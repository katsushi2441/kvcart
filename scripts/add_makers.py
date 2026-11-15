#!/usr/bin/env python3
"""移行するメーカーを後から足す（2026-11-16）。

  /usr/bin/python3 scripts/add_makers.py --db <shop.sqlite> "タクミナ=takumina" "タカラ工業=takara" ...

import_ocnk.py --build は14社を全部作り直すので、そのあとに直した価格（平野製作所の値上げ・電菱の価格改定など）が
おちゃのこの価格で上書きされてしまう。ここでは**指定したメーカーの商品だけを足し**、既存の商品には触らない。

- メーカーは、おちゃのこの商品の groups の名前（括弧より前）で一致させ、group_id をそのまま makers.id にする
- 足した商品は retired_products（取り扱い終了の控え）から消す（URLが「終了」から「販売中」に戻る）
- 商品画像は旧サイトから落として img/<ハッシュ>.jpg で持つ。www.exdirect.net は切り替え済みなので、
  おちゃのこのサーバー（210.224.191.42）を直接指定して取る
"""
import argparse
import hashlib
import io
import os
import re
import sqlite3
import subprocess
import sys
import time

sys.path.insert(0, "/home/kojima/work/exdirect_net")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ocnk_client import OcnkClient  # noqa: E402
from import_ocnk import clean_description  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG = os.path.join(ROOT, "public", "img")
OCNK_IP = "210.224.191.42"


def base(name: str) -> str:
    return re.split(r"[（(]", name or "")[0].strip()


def fetch_image(url: str) -> str:
    """旧サイトの画像を落として img/<ハッシュ>.jpg にする。失敗したら '' を返す。"""
    from PIL import Image
    fn = hashlib.sha1(url.encode()).hexdigest()[:16] + ".jpg"
    out = os.path.join(IMG, fn)
    if os.path.exists(out):
        return "img/" + fn
    host = re.match(r"https?://([^/]+)", url).group(1)
    r = subprocess.run(["curl", "-s", "-f", "--max-time", "30", "--resolve", f"{host}:443:{OCNK_IP}", url],
                       capture_output=True)
    if r.returncode != 0 or len(r.stdout) < 200:
        return ""
    try:
        im = Image.open(io.BytesIO(r.stdout)).convert("RGB")
    except Exception:
        return ""
    im.thumbnail((800, 800))
    im.save(out, "JPEG", quality=85, optimize=True)
    return "img/" + fn


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--db", required=True)
    ap.add_argument("makers", nargs="+", help="メーカー名=slug")
    a = ap.parse_args()
    want = dict(m.split("=", 1) for m in a.makers)
    con = sqlite3.connect(a.db)
    cur = con.cursor()
    c = OcnkClient()
    found, cats, gids = [], {}, {}
    for p in c.iter_all("products", page_size=1000):
        for g in (p.get("groups") or []):
            if base(g.get("name")) in want:
                gids[base(g["name"])] = g["id"]
                found.append((p, g["id"]))
                cat = p.get("category") or {}
                if cat.get("id"):
                    cats[cat["id"]] = (cat.get("name"), cat.get("parent_id"), cat.get("parent_name"))
                break
    print("見つかったメーカー:", gids)
    missing = set(want) - set(gids)
    if missing:
        sys.exit(f"おちゃのこに見つからないメーカー: {missing}")
    for name, gid in gids.items():
        if cur.execute("SELECT 1 FROM makers WHERE id=?", (gid,)).fetchone():
            sys.exit(f"{name}（{gid}）はもう登録済み")
        sort = (cur.execute("SELECT COALESCE(MAX(sort),0) FROM makers").fetchone()[0] or 0) + 1
        cur.execute("INSERT INTO makers (id,slug,name,sort) VALUES (?,?,?,?)", (gid, want[name], name, sort))
    now = time.strftime("%Y-%m-%dT%H:%M:%S")
    added = skipped = img_ok = img_ng = 0
    for p, gid in found:
        if cur.execute("SELECT 1 FROM products WHERE id=?", (p["id"],)).fetchone():
            skipped += 1
            continue
        imgs = p.get("images") or []
        src = next((i.get("url") for i in imgs if i.get("main")), None) or (imgs[0].get("url") if imgs else None)
        img = ""
        if src:
            img = fetch_image(src)
            if img:
                img_ok += 1
            else:
                img_ng += 1
        cat = p.get("category") or {}
        cur.execute(
            "INSERT INTO products (id,name,model_number,gtin,price,price_unspecified,list_price,stock,"
            "stock_unlimited,hidden,category_id,maker_id,description,image_url,tax_reduce,updated_at,imported_at)"
            " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            (p["id"], p.get("name"), p.get("model_number"), p.get("gtin"), p.get("price"),
             1 if p.get("price_unspecified") else 0, p.get("list_price"), p.get("stock"),
             1 if p.get("stock_unlimited") else 0, 1 if p.get("hidden") else 0,
             cat.get("id"), gid, clean_description(((p.get("description") or {}).get("text")) or ""),
             img, 1 if p.get("tax_reduce") else 0, p.get("updated_at"), now))
        cur.execute("DELETE FROM retired_products WHERE id=?", (p["id"],))
        added += 1
    for cid, (cname, pid, pname) in cats.items():
        cur.execute("INSERT OR IGNORE INTO categories (id,name,parent_id,parent_name) VALUES (?,?,?,?)",
                    (cid, cname, pid, pname))
    con.commit()
    print(f"足した商品 {added} / もうあった {skipped} / 画像 取れた {img_ok}・取れない {img_ng}")
    for name, gid in gids.items():
        n, h = cur.execute("SELECT COUNT(*), SUM(hidden) FROM products WHERE maker_id=?", (gid,)).fetchone()
        print(f"  {name}（group {gid}・/maker/{want[name]}）: {n}件（非表示 {h}）")


if __name__ == "__main__":
    main()
