#!/usr/bin/env python3
"""X-Direct（exdirect.net）の共有カード（既定の og:image）を作る（2026-11-15）。

  /usr/bin/python3 scripts/make_shop_og.py  →  public/img/brand/og-default.png

商品ページは商品の画像を使い、それ以外のページ（トップ・一覧・固定ページ）でこれを使う。
"""
import os, sqlite3
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
W, H = 1200, 630
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
MASCOT = os.path.join(ROOT, "public", "img", "brand", "kurage-mascot.webp")
LOGO = "/home/kojima/work/exbridge_jp/images/logo-mark-128.png"


def main():
    db = sqlite3.connect(os.path.join(ROOT, "public", "kv_data", "shop.sqlite"))
    n = db.execute("SELECT COUNT(*) FROM products WHERE hidden=0").fetchone()[0]
    mk = db.execute("SELECT COUNT(DISTINCT maker_id) FROM products WHERE hidden=0").fetchone()[0]
    img = Image.new("RGB", (W, H), "#ffffff")
    dr = ImageDraw.Draw(img)
    dr.rectangle([0, 0, W, 16], fill="#0a9a8f")
    dr.ellipse([-220, -260, 520, 380], fill="#e6f4f2")
    f = lambda p, s: ImageFont.truetype(p, s)
    x = 80
    logo = os.path.join(ROOT, "public", "img", "brand", "favicon.png")
    if os.path.exists(logo):
        lg = Image.open(logo).convert("RGBA")
        lg.thumbnail((96, 96))
        img.paste(lg, (x, 70), lg)
        dr.text((x + 112, 118), "株式会社エクスブリッジ", font=f(FR, 30), fill="#3d4b57", anchor="lm")
    dr.text((x, 270), "X-Direct", font=f(FB, 110), fill="#12202f", anchor="lm")
    dr.text((x, 370), "仕事に役立つアイテムを直送・格安で", font=f(FB, 46), fill="#0a7d74", anchor="lm")
    pills = [f"{mk}メーカー・{n:,}点", "メーカー直送", "銀行振込・カード"]
    px = x
    for p in pills:
        w = dr.textlength(p, font=f(FB, 30)) + 44
        dr.rounded_rectangle([px, 440, px + w, 496], radius=28, fill="#ffffff", outline="#a8ded8", width=2)
        dr.text((px + w / 2, 468), p, font=f(FB, 30), fill="#0a726b", anchor="mm")
        px += w + 16
    dr.text((x, 560), "www.exdirect.net", font=f(FR, 28), fill="#5d6b7a", anchor="lm")
    m = Image.open(MASCOT).convert("RGBA")
    m.thumbnail((230, 260))
    img.paste(m, (W - m.width - 40, 60), m)
    out = os.path.join(ROOT, "public", "img", "brand", "og-default.png")
    img.save(out, optimize=True)
    print(out, img.size, n, mk)


if __name__ == "__main__":
    main()
