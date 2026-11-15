#!/usr/bin/env python3
"""電菱のディーラー向けプライスリスト（PDF）で kvcart の電菱商品の価格を改定する（2026-11-15）。

  /usr/bin/python3 scripts/reprice_denryo.py <price_list.pdf>          # 照合結果を表示するだけ
  /usr/bin/python3 scripts/reprice_denryo.py <price_list.pdf> --apply  # DB に書く

- 販売価格 = 納入単価(税別) × 1.08 + 1500 を下一桁で四捨五入
- 定価(list_price) = 標準単価(税別)
- kvcart の型番は「DRY-」＋型式。型式が完全に一致する商品だけを直す（推測で寄せない）
- PDF は結合セルのため、価格だけの行は直前の行と直後の行（J/K 型など）の両方の型式に当たる
"""
import re
import sqlite3
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "kv_data" / "shop.sqlite"
YEN = re.compile(r"¥([0-9,]+)")


def parse(pdf: str) -> dict:
    t = subprocess.run(["pdftotext", "-layout", pdf, "-"], capture_output=True, text=True, check=True).stdout
    lines = [ln.rstrip() for ln in t.splitlines()]
    out = {}
    for i, ln in enumerate(lines):
        ys = YEN.findall(ln)
        if len(ys) < 2:
            continue
        std, nonyu = (int(y.replace(",", "")) for y in ys[:2])
        head = ln.split("¥")[0].strip()
        if head:
            models = [head]
        else:  # 価格だけの行: 前後の行の型式
            models = [x.strip() for x in (lines[i - 1], lines[i + 1]) if x.strip() and "¥" not in x]
        for m in models:
            out[m] = (std, nonyu)
    return out


def sell(nonyu: int) -> int:
    v = nonyu * 108 + 150000  # ×100 の整数で計算
    return (v + 500) // 1000 * 10


def main():
    pdf = sys.argv[1]
    apply = "--apply" in sys.argv
    pl = parse(pdf)
    c = sqlite3.connect(DB)
    mid = c.execute("select id from makers where name='電菱'").fetchone()[0]
    rows = c.execute("select id, model_number, price, list_price, name from products where maker_id=?", (mid,)).fetchall()
    # 表記ゆれの吸収: PDF の「ST-2G(ﾌﾚｰﾑﾚｽ太陽電池)」の括弧書きを外す／kvcart の型番は「.」が「-」になっている（JR7-2-12 = JR7.2-12）
    norm = lambda s: re.sub(r"[^A-Z0-9]", "", re.sub(r"\(.*?\)", "", s).upper())
    by_norm = {}
    for k in pl:
        by_norm.setdefault(norm(k), []).append(k)
    hit, miss = [], []
    for pid, mn, price, lp, name in rows:
        m = (mn or "")[4:] if (mn or "").startswith("DRY-") else (mn or "")
        # 候補: 型番 → 商品名の先頭の型式（型番が旧型のまま名前だけ後継型になっている商品: SR1000T-124 など）
        key = None
        for cand in (m, (name or "").split()[0] if name else ""):
            if not cand:
                continue
            if cand in pl:
                key = cand; break
            ks = by_norm.get(norm(cand), [])
            if len(ks) == 1:  # 一意に決まるときだけ
                key = ks[0]; break
        if key:
            std, nonyu = pl[key]
            hit.append((pid, m, key, price, sell(nonyu), lp, std))
        else:
            miss.append((pid, mn, price))
    print(f"PDF の型式 {len(pl)} / kvcart の電菱 {len(rows)} / 一致 {len(hit)} / 一致しない {len(miss)}")
    for h in hit:
        if h[1] != h[2]:
            print("  表記ゆれで一致", h)
    for x in miss:
        print("  不一致", x)
    if apply:
        now = __import__("datetime").datetime.now().astimezone().isoformat(timespec="seconds")
        for pid, m, _, _, newp, _, std in hit:
            c.execute("update products set price=?, list_price=?, updated_at=? where id=?", (newp, std, now, pid))
        c.commit()
        print(f"書き込み {len(hit)} 件")


if __name__ == "__main__":
    main()
