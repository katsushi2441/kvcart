#!/usr/bin/env python3
"""おちゃのこの商品を1件ずつ足す（メーカーまるごとではなく）。2026-11-16

  /usr/bin/python3 scripts/add_products.py --db <shop.sqlite> --maker "258=arimitsu=有光工業" 39914 [ID ...]

- 既にある商品には触らない（価格の改定などを上書きしない）
- メーカー（おちゃのこのグループ）が無ければ作る。名前は表示用に指定する
- 商品名の【激安】【セール】・説明のバナーは取り込み時に外す。オプション（variations）も入れる
- 画像は旧サイトから落として自分で持つ（add_makers.py と同じ）
- 足した商品は retired_products（取り扱い終了の控え）から消す
"""
import argparse
import json
import os
import sqlite3
import sys
import time

sys.path.insert(0, "/home/kojima/work/exdirect_net")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ocnk_client import OcnkClient  # noqa: E402
from import_ocnk import clean_description, clean_name  # noqa: E402
from add_makers import fetch_image  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--db", required=True)
    ap.add_argument("--maker", required=True, help="グループID=slug=表示名")
    ap.add_argument("ids", nargs="+", type=int)
    a = ap.parse_args()
    gid, slug, mname = a.maker.split("=", 2)
    gid = int(gid)
    con = sqlite3.connect(a.db)
    cur = con.cursor()
    if not cur.execute("SELECT 1 FROM makers WHERE id=?", (gid,)).fetchone():
        sort = (cur.execute("SELECT COALESCE(MAX(sort),0) FROM makers").fetchone()[0] or 0) + 1
        cur.execute("INSERT INTO makers (id,slug,name,sort) VALUES (?,?,?,?)", (gid, slug, mname, sort))
        print(f"メーカーを作った: {mname}（{gid}・/maker/{slug}）")
    c = OcnkClient()
    now = time.strftime("%Y-%m-%dT%H:%M:%S")
    for pid in a.ids:
        if cur.execute("SELECT 1 FROM products WHERE id=?", (pid,)).fetchone():
            print(pid, "もうある・触らない")
            continue
        d = c.get_product(pid)
        p = d.get("data", d)
        p = p[0] if isinstance(p, list) else p
        if gid not in [g.get("id") for g in (p.get("groups") or [])]:
            sys.exit(f"{pid} はグループ {gid} の商品ではない: {p.get('groups')}")
        imgs = p.get("images") or []
        src = next((i.get("url") for i in imgs if i.get("main")), None) or (imgs[0].get("url") if imgs else None)
        img = fetch_image(src) if src else ""
        cat = p.get("category") or {}
        if cat.get("id"):
            cur.execute("INSERT OR IGNORE INTO categories (id,name,parent_id,parent_name) VALUES (?,?,?,?)",
                        (cat["id"], cat.get("name"), cat.get("parent_id"), cat.get("parent_name")))
        v = p.get("variations") or {}
        cur.execute(
            "INSERT INTO products (id,name,model_number,gtin,price,price_unspecified,list_price,stock,"
            "stock_unlimited,hidden,category_id,maker_id,description,image_url,tax_reduce,updated_at,imported_at,variations)"
            " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            (p["id"], clean_name(p.get("name")), p.get("model_number"), p.get("gtin"), p.get("price"),
             1 if p.get("price_unspecified") else 0, p.get("list_price"), p.get("stock"),
             1 if p.get("stock_unlimited") else 0, 1 if p.get("hidden") else 0,
             cat.get("id"), gid, clean_description(((p.get("description") or {}).get("text")) or ""),
             img, 1 if p.get("tax_reduce") else 0, p.get("updated_at"), now,
             json.dumps(v, ensure_ascii=False) if v.get("options") else None))
        cur.execute("DELETE FROM retired_products WHERE id=?", (pid,))
        print(pid, clean_name(p.get("name")), p.get("price"), "画像", img or "なし",
              "オプション", len(((v.get("options") or {}).get("option1") or {}).get("values") or []))
    con.commit()


if __name__ == "__main__":
    main()
