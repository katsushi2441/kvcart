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
    n = mk = 0
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
    dr.text((x, 250), "X-Direct", font=f(FB, 104), fill="#12202f", anchor="lm")
    dr.text((x, 342), "仕事に役立つアイテムを直送・格安で", font=f(FB, 42), fill="#0a7d74", anchor="lm")
    # AI駆動EC（2026-11-17 ユーザー指示「AI駆動EC Kurage Vibe-Cart AI完全対応EC を入れたい」）
    tag = "AI駆動EC"
    tw = dr.textlength(tag, font=f(FB, 32)) + 40
    dr.rounded_rectangle([x, 395, x + tw, 451], radius=12, fill="#12202f")
    dr.text((x + tw / 2, 423), tag, font=f(FB, 32), fill="#ffffff", anchor="mm")
    dr.text((x + tw + 18, 423), "Kurage Vibe-Cart｜AI完全対応EC", font=f(FB, 34), fill="#12202f", anchor="lm")
    # 点数は古くなるので出さない（看板は数字より言葉）
    pills = ["メーカー直送", "送料原則無料", "銀行振込・カード"]
    px = x
    for p in pills:
        w = dr.textlength(p, font=f(FB, 28)) + 40
        dr.rounded_rectangle([px, 478, px + w, 530], radius=26, fill="#ffffff", outline="#a8ded8", width=2)
        dr.text((px + w / 2, 504), p, font=f(FB, 28), fill="#0a726b", anchor="mm")
        px += w + 14
    dr.text((x, 578), "www.exdirect.net", font=f(FR, 28), fill="#5d6b7a", anchor="lm")
    m = Image.open(MASCOT).convert("RGBA")
    m.thumbnail((230, 260))
    img.paste(m, (W - m.width - 40, 60), m)
    out = os.path.join(ROOT, "public", "img", "brand", "og-default.png")
    img.save(out, optimize=True)
    print(out, img.size, n, mk)


if __name__ == "__main__":
    main()
