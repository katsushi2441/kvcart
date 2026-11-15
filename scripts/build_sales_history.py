#!/usr/bin/env python3
"""おちゃのこネットの受注から、商品ごとの「売れた記録」を作る（2026-11-16）。

  /usr/bin/python3 scripts/build_sales_history.py [--days 365] [--deploy]

出力: public/kv_data/sales_history.json
  {"generated_at": "...", "since": "YYYY-MM-DD", "items": {"<商品ID>": {"last": "YYYY-MM-DD", "orders": 回数, "qty": 個数}}}

トップページの「最近売れた商品」「よく売れている商品」の元。kvcart に入った注文（orders テーブル）は
PHP 側でその場で足すので、ここはおちゃのこ時代の分だけ。お客さまの情報は持たない（商品ID・日付・回数・個数だけ）。
キャンセルした受注は数えない。--deploy で本番の kv_data に置く。
"""
import argparse
import collections
import datetime
import json
import os
import sys

sys.path.insert(0, "/home/kojima/work/exdirect_net")
from ocnk_client import OcnkClient  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "public", "kv_data", "sales_history.json")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=365)
    ap.add_argument("--deploy", action="store_true")
    a = ap.parse_args()
    today = datetime.date.today()
    since = (today - datetime.timedelta(days=a.days)).isoformat()
    c = OcnkClient()
    orders, mx = [], None
    while True:
        r = c.get_orders(limit=1000, since=since, until=(today + datetime.timedelta(days=1)).isoformat(), max_id=mx)
        d = r.get("data", [])
        if not d:
            break
        orders += d
        if len(d) < 1000:
            break
        mx = min(int(o["id"]) for o in d) - 1
    items = collections.defaultdict(lambda: {"last": "", "orders": 0, "qty": 0})
    for o in orders:
        if o.get("cancelled"):
            continue
        day = o["created_at"][:10]
        sh = o.get("shipping")
        seen = set()
        for s in (sh if isinstance(sh, list) else [sh]):
            ps = s.get("products") or []
            for p in (ps if isinstance(ps, list) else [ps]):
                pid = str(int(p.get("product_id") or 0))
                if pid == "0":
                    continue
                it = items[pid]
                it["last"] = max(it["last"], day)
                it["qty"] += int(p.get("quantity") or 0)
                if pid not in seen:
                    it["orders"] += 1
                    seen.add(pid)
    data = {"generated_at": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
            "since": since, "source": "ocnk", "items": items}
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False)
    print(f"受注 {len(orders)} 件 / 商品 {len(items)} 種類 → {OUT}")
    if a.deploy:
        import ftplib
        env = {}
        for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
            if "=" in ln and not ln.lstrip().startswith("#"):
                k, v = ln.strip().split("=", 1)
                env[k] = v.strip().strip('"').strip("'")
        ftp = ftplib.FTP(env["FTP_HOST"], timeout=60)
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        ftp.cwd("/web/exbridge_jp/exdirect/kv_data")
        with open(OUT, "rb") as f:
            ftp.storbinary("STOR sales_history.json.new", f)
        try:
            ftp.delete("sales_history.json")
        except Exception:
            pass
        ftp.rename("sales_history.json.new", "sales_history.json")
        ftp.quit()
        print("本番に置いた")


if __name__ == "__main__":
    main()
