#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""商品画像を旧サイトから落として、このシステムで持つ。

なぜ自前で持つか:
  いまは image_url が www.exdirect.net を指している。おちゃのこネットの契約を止めた瞬間に
  全商品の画像が消える。移行の前に自分のサーバーへ取り込んでおく必要がある。

やること:
  1. products.image_url を重複を除いて落とす（1,905枚）
  2. 長辺800pxに縮めて JPEG で保存（元は540×360程度だが、大きいものもあるため）
  3. products.image_url を相対パス `img/<ハッシュ>.jpg` に書き換える
  4. 落とせなかったものは元のURLのまま残す（画像が消えるより、旧サイトを見に行く方がまし）

  cd /home/kojima/work/kvcart
  /usr/bin/python3 scripts/fetch_images.py --run
  /usr/bin/python3 scripts/fetch_images.py --stats
"""
import argparse
import hashlib
import io
import os
import sqlite3
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "public", "kv_data", "shop.sqlite")
IMG_DIR = os.path.join(ROOT, "public", "img")
UA = {"User-Agent": "Mozilla/5.0 (compatible; kvcart-migration; +https://exbridge.jp/)"}
MAX_EDGE = 800


def name_for(url: str) -> str:
    """元URLから決まる名前。**同じ画像を2度落とさないため**にハッシュを使う。"""
    return hashlib.sha1(url.encode("utf-8")).hexdigest()[:16] + ".jpg"


def fetch_one(url: str):
    dest = os.path.join(IMG_DIR, name_for(url))
    if os.path.exists(dest) and os.path.getsize(dest) > 0:
        return (url, "skip", None)
    try:
        req = urllib.request.Request(url, headers=UA)
        with urllib.request.urlopen(req, timeout=40) as r:
            raw = r.read()
        if len(raw) < 200:
            return (url, "too_small", None)
        from PIL import Image
        im = Image.open(io.BytesIO(raw))
        im = im.convert("RGB")
        if max(im.size) > MAX_EDGE:
            ratio = MAX_EDGE / max(im.size)
            im = im.resize((int(im.width * ratio), int(im.height * ratio)), Image.LANCZOS)
        im.save(dest, "JPEG", quality=85, optimize=True)
        return (url, "ok", im.size)
    except Exception as e:  # noqa: BLE001
        return (url, f"err:{e.__class__.__name__}", None)


def run(workers: int, limit: int | None):
    os.makedirs(IMG_DIR, exist_ok=True)
    con = sqlite3.connect(DB)
    # **ファイル名が無いURLは「画像が無い商品」。** おちゃのこネットのAPIは画像が無いと
    # ディレクトリまでのURL（…/product/）を返してくる。落としに行っても無駄なので先に空にする。
    n = con.execute("UPDATE products SET image_url='' WHERE image_url LIKE 'http%/'").rowcount
    con.commit()
    if n:
        print(f"  画像が無い商品 {n:,}件（URLにファイル名が無い）を空にしました")
    urls = [r[0] for r in con.execute(
        "SELECT DISTINCT image_url FROM products WHERE image_url LIKE 'http%'")]
    if limit:
        urls = urls[:limit]
    print(f"  落とす画像 {len(urls):,} 枚（同時 {workers}）")
    t0 = time.time()
    res = {}
    done = 0
    with ThreadPoolExecutor(max_workers=workers) as ex:
        for url, st, size in ex.map(fetch_one, urls):
            res[url] = st
            done += 1
            if done % 200 == 0:
                ok = sum(1 for v in res.values() if v in ("ok", "skip"))
                print(f"  {done:,}/{len(urls):,} 成功 {ok:,}  ({time.time()-t0:.0f}秒)")
    ok = [u for u, v in res.items() if v in ("ok", "skip")]
    ng = {u: v for u, v in res.items() if v not in ("ok", "skip")}
    # 落とせたものだけ相対パスに差し替える
    cur = con.cursor()
    for u in ok:
        cur.execute("UPDATE products SET image_url=? WHERE image_url=?", ("img/" + name_for(u), u))
    con.commit()
    con.close()
    print(f"\n  成功 {len(ok):,} / 失敗 {len(ng):,}  ({time.time()-t0:.0f}秒)")
    if ng:
        from collections import Counter
        print("  失敗の内訳:", dict(Counter(ng.values()).most_common(5)))
        print("  ※失敗した分は元のURLのまま残しています（画像が消えるよりまし）")
    total = sum(os.path.getsize(os.path.join(IMG_DIR, f)) for f in os.listdir(IMG_DIR))
    print(f"  保存先 {IMG_DIR} / {len(os.listdir(IMG_DIR)):,}枚 / {total/1024/1024:.1f}MB")


def stats():
    con = sqlite3.connect(DB)

    def q(sql, *a):
        return con.execute(sql, a).fetchone()[0]

    print(f"  商品 {q('SELECT COUNT(*) FROM products'):,}")
    print(f"  自前の画像 {q('SELECT COUNT(*) FROM products WHERE image_url LIKE ?', 'img/%'):,}")
    print(f"  旧サイト参照のまま {q('SELECT COUNT(*) FROM products WHERE image_url LIKE ?', 'http%'):,}")
    print(f"  画像なし {q('SELECT COUNT(*) FROM products WHERE image_url IS NULL OR image_url = ?', ''):,}")
    if os.path.isdir(IMG_DIR):
        fs = os.listdir(IMG_DIR)
        total = sum(os.path.getsize(os.path.join(IMG_DIR, f)) for f in fs)
        print(f"  ファイル {len(fs):,}枚 / {total/1024/1024:.1f}MB")
    con.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--run", action="store_true")
    ap.add_argument("--workers", type=int, default=8)
    ap.add_argument("--limit", type=int)
    ap.add_argument("--stats", action="store_true")
    a = ap.parse_args()
    if a.run:
        run(a.workers, a.limit)
    if a.stats or not a.run:
        stats()
