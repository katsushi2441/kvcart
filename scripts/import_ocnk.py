#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""おちゃのこネット(ocnk) API から、移行対象メーカーの商品を SQLite へ取り込む。

なぜ全件でないか:
  exdirect.net の商品は46,658件あるが、移行するのは exbridge.jp/xdirect/ に掲載している
  14メーカーぶん（実測5,736件）だけ。残りのURLは「取り扱い終了」ページで受ける（404にしない）。

**商品IDはおちゃのこネットのものをそのまま主キーにする。**
`/product/11366` というURLを1文字も変えないための設計で、ここを振り直してはいけない。

  cd /home/kojima/work/kvcart
  /usr/bin/python3 scripts/import_ocnk.py --build   # 取り込み（API→SQLite）
  /usr/bin/python3 scripts/import_ocnk.py --stats   # 中身の確認
"""
import argparse
import json
import os
import re
import sqlite3
import sys
import time

sys.path.insert(0, "/home/kojima/work/exdirect_net")
from ocnk_client import OcnkClient  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "public", "kv_data", "shop.sqlite")

# exbridge.jp/xdirect/ に掲載している14メーカー。group_id は実測で確定（2026-09-14）
MAKERS = {
    54:  ("seiwa",      "精和産業"),
    116: ("okuda",      "をくだ屋技研"),
    83:  ("mothertool", "マザーツール"),
    212: ("hirano",     "平野製作所"),
    904: ("denryo",     "電菱"),
    649: ("sanyo",      "山洋電気"),
    49:  ("fujic",      "富士コンプレッサー"),
    463: ("zao",        "蔵王産業"),
    961: ("gsyuasa",    "GSユアサ"),
    794: ("suntec",     "サンテックコーポレーション"),
    905: ("powertite",  "未来舎"),
    306: ("prestar",    "上杉輸送機製作所"),
    738: ("fksystem",   "Fksystem"),
    856: ("maruyama",   "マルヤマエクセル"),
}

SCHEMA = """
-- レンタルサーバーで読み取り専用エラーになるため WAL は使わない（DELETE のまま）

-- 商品。id は**おちゃのこネットの商品ID**をそのまま使う（URL互換の要）
CREATE TABLE IF NOT EXISTS products (
  id            INTEGER PRIMARY KEY,
  name          TEXT NOT NULL,
  model_number  TEXT,
  gtin          TEXT,
  price         INTEGER,
  price_unspecified INTEGER DEFAULT 0,
  list_price    INTEGER,
  stock         INTEGER,
  stock_unlimited INTEGER DEFAULT 0,
  hidden        INTEGER DEFAULT 0,
  category_id   INTEGER,
  maker_id      INTEGER,
  description   TEXT,
  image_url     TEXT,
  tax_reduce    INTEGER DEFAULT 0,
  updated_at    TEXT,
  imported_at   TEXT
);
CREATE INDEX IF NOT EXISTS ix_products_maker ON products(maker_id);
CREATE INDEX IF NOT EXISTS ix_products_cat   ON products(category_id);
CREATE INDEX IF NOT EXISTS ix_products_model ON products(model_number);

-- 全文検索（商品名・型番）。FTS5 が無い環境でも LIKE で動くので必須ではない
CREATE TABLE IF NOT EXISTS categories (
  id        INTEGER PRIMARY KEY,
  name      TEXT NOT NULL,
  parent_id INTEGER,
  parent_name TEXT
);
CREATE TABLE IF NOT EXISTS makers (
  id    INTEGER PRIMARY KEY,   -- おちゃのこネットの group_id
  slug  TEXT UNIQUE NOT NULL,  -- exbridge.jp/xdirect/maker.php?maker=<slug> と同じ
  name  TEXT NOT NULL,
  sort  INTEGER DEFAULT 0
);

-- 「移行しなかった商品」を覚えておく。/product/<id> で404を出さず、
-- 取り扱い終了＋同メーカーへの案内を返すために要る
CREATE TABLE IF NOT EXISTS retired_products (
  id           INTEGER PRIMARY KEY,
  name         TEXT,
  model_number TEXT,
  maker_name   TEXT,
  category_id  INTEGER
);
"""


def connect():
    os.makedirs(os.path.dirname(DB), exist_ok=True)
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA)
    return con


def clean_description(html: str) -> str:
    """アフィリエイト誘導（楽天・Amazonへ送る段落）を落とす。

    旧サイトは自社ECなのに他所へ送るリンクを商品説明の先頭に入れていた。
    自前ECではカートへ進んでほしいので、その段落だけ取り除く。
    """
    if not html:
        return ""
    html = re.sub(r'(?is)<p[^>]*>\s*<a[^>]*aixec\.exbridge\.jp/go\.php.*?</p>', '', html)
    html = re.sub(r'(?is)<p[^>]*>\s*<a[^>]*go\.php\?to=(rakuten|amazon).*?</p>', '', html)
    return html.strip()


def build():
    c = OcnkClient()
    con = connect()
    cur = con.cursor()
    cur.execute("DELETE FROM makers")
    for gid, (slug, name) in MAKERS.items():
        cur.execute("INSERT INTO makers (id,slug,name) VALUES (?,?,?)", (gid, slug, name))
    con.commit()

    now = time.strftime("%Y-%m-%dT%H:%M:%S")
    n = kept = retired = 0
    cats = {}
    t0 = time.time()
    for p in c.iter_all("products", page_size=1000):
        n += 1
        gids = [g.get("id") for g in (p.get("groups") or [])]
        mine = next((g for g in gids if g in MAKERS), None)
        cat = p.get("category") or {}
        if cat.get("id"):
            cats[cat["id"]] = (cat.get("name"), cat.get("parent_id"), cat.get("parent_name"))
        if mine is None:
            # 移行しない商品。URLを生かすために名前だけ控える
            cur.execute("INSERT OR REPLACE INTO retired_products (id,name,model_number,maker_name,category_id)"
                        " VALUES (?,?,?,?,?)",
                        (p["id"], p.get("name"), p.get("model_number"),
                         (p.get("groups") or [{}])[0].get("name") if p.get("groups") else None,
                         cat.get("id")))
            retired += 1
            continue
        imgs = p.get("images") or []
        img = next((i.get("url") for i in imgs if i.get("main")), None) or (imgs[0].get("url") if imgs else None)
        desc = clean_description(((p.get("description") or {}).get("text")) or "")
        cur.execute(
            "INSERT OR REPLACE INTO products (id,name,model_number,gtin,price,price_unspecified,"
            "list_price,stock,stock_unlimited,hidden,category_id,maker_id,description,image_url,"
            "tax_reduce,updated_at,imported_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            (p["id"], p.get("name"), p.get("model_number"), p.get("gtin"), p.get("price"),
             1 if p.get("price_unspecified") else 0, p.get("list_price"), p.get("stock"),
             1 if p.get("stock_unlimited") else 0, 1 if p.get("hidden") else 0,
             cat.get("id"), mine, desc, img, 1 if p.get("tax_reduce") else 0,
             p.get("updated_at"), now))
        kept += 1
        if n % 5000 == 0:
            con.commit()
            print(f"  {n:,}件走査 / 取り込み {kept:,} / 終了扱い {retired:,}  ({time.time()-t0:.0f}秒)")
    for cid, (cname, pid, pname) in cats.items():
        cur.execute("INSERT OR REPLACE INTO categories (id,name,parent_id,parent_name) VALUES (?,?,?,?)",
                    (cid, cname, pid, pname))
    con.commit()
    print(f"\n  走査 {n:,} / 取り込み {kept:,} / 取り扱い終了 {retired:,} / カテゴリ {len(cats):,}")
    print(f"  DB: {DB} ({os.path.getsize(DB):,} bytes)")
    con.close()


def stats():
    con = sqlite3.connect(DB)
    q = lambda s: con.execute(s).fetchall()  # noqa: E731
    print(f"  商品 {q('SELECT COUNT(*) FROM products')[0][0]:,}"
          f" / 取り扱い終了 {q('SELECT COUNT(*) FROM retired_products')[0][0]:,}"
          f" / カテゴリ {q('SELECT COUNT(*) FROM categories')[0][0]:,}")
    print("  メーカー別:")
    for slug, name, cnt in q("SELECT m.slug,m.name,COUNT(p.id) FROM makers m"
                             " LEFT JOIN products p ON p.maker_id=m.id GROUP BY m.id ORDER BY 3 DESC"):
        print(f"    {slug:12s} {cnt:5,}  {name}")
    print("  価格が入っていない商品:", q("SELECT COUNT(*) FROM products WHERE price IS NULL OR price=0")[0][0])
    print("  画像が無い商品:", q("SELECT COUNT(*) FROM products WHERE image_url IS NULL OR image_url=''")[0][0])
    con.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--build", action="store_true")
    ap.add_argument("--stats", action="store_true")
    a = ap.parse_args()
    if a.build:
        build()
    if a.stats or not a.build:
        stats()
