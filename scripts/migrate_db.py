#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""既存の shop.sqlite に足りない列を足す。**既存データは消さない。**

  /usr/bin/python3 scripts/migrate_db.py
"""
import os, sqlite3, sys
DB = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "public", "kv_data", "shop.sqlite")
ADD = {
    "orders": [("name_kana","TEXT"),("department","TEXT"),("fax","TEXT"),("dm","INTEGER DEFAULT 0"),
               ("ship_to","TEXT"),("ship_name","TEXT"),("ship_zip","TEXT"),
               ("ship_address","TEXT"),("ship_tel","TEXT")],
}
con = sqlite3.connect(DB)
for tbl, cols in ADD.items():
    have = {r[1] for r in con.execute(f"PRAGMA table_info({tbl})")}
    if not have:
        print(f"  {tbl} はまだありません（初回アクセスで作られます）"); continue
    for name, typ in cols:
        if name not in have:
            con.execute(f"ALTER TABLE {tbl} ADD COLUMN {name} {typ}")
            print(f"  {tbl}.{name} を追加")
con.commit(); con.close()
print("  完了:", DB)
