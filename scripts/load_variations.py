#!/usr/bin/env python3
"""商品のオプション（おちゃのこネットの variations）を products.variations に入れる（2026-11-16）。

  /usr/bin/python3 scripts/load_variations.py --db <shop.sqlite>

列が無ければ足す。おちゃのこの API を全件なめて、DB にある商品のうちオプションのあるものだけ書く。
オプションの無い商品は NULL のまま（kv_variations() は空を返し、選択欄も出ない）。
"""
import argparse, json, sqlite3, sys
sys.path.insert(0, "/home/kojima/work/exdirect_net")
from ocnk_client import OcnkClient  # noqa: E402

ap = argparse.ArgumentParser(); ap.add_argument("--db", required=True); a = ap.parse_args()
con = sqlite3.connect(a.db)
cols = [r[1] for r in con.execute("PRAGMA table_info(products)")]
if "variations" not in cols:
    con.execute("ALTER TABLE products ADD COLUMN variations TEXT")
ids = {r[0] for r in con.execute("SELECT id FROM products")}
n = 0
for p in OcnkClient().iter_all("products", page_size=1000):
    v = p.get("variations") or {}
    if p["id"] in ids and v.get("options"):
        con.execute("UPDATE products SET variations=? WHERE id=?", (json.dumps(v, ensure_ascii=False), p["id"]))
        n += 1
con.commit()
print("オプションを入れた商品", n)
