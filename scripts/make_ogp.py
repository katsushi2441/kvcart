#!/usr/bin/env python3
"""OGP / kappstore 商品画像 1200×630。

**kjishin/scripts/make_ogp.py と同じ型で作る。** Kurageシリーズの商品カードが並んだときに
揃って見えるよう、配色・余白・マスコットの位置・帯の形を合わせている。
（ライトテーマ・中央寄せ・文字大きく・マスコット入り・成長する数字は焼き込まない）

  /usr/bin/python3 scripts/make_ogp.py  →  outputs/listing/card.png
"""
import os

from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MASCOT = "/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png"
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FM = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"


def build() -> Image.Image:
    img = Image.new("RGB", (W, H), "#ffffff")
    dr = ImageDraw.Draw(img, "RGBA")
    dr.ellipse([-180, -240, 480, 380], fill=(230, 244, 242, 255))
    dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(240, 246, 246, 255))

    mascot = None
    if os.path.exists(MASCOT):
        mascot = Image.open(MASCOT).convert("RGBA")
        mh = 300
        mascot = mascot.resize((int(mascot.width * mh / mascot.height), mh))
    cx = 520 if mascot else W // 2

    f_badge = ImageFont.truetype(FM, 26)
    f_h = ImageFont.truetype(FB, 62)
    f_h2 = ImageFont.truetype(FB, 46)
    f_s = ImageFont.truetype(FR, 28)
    f_pill = ImageFont.truetype(FM, 24)
    f_brand = ImageFont.truetype(FM, 30)

    badge = "PHPだけで動くECサイト・MCP同梱"
    bw = dr.textlength(badge, font=f_badge) + 40
    dr.rounded_rectangle([cx - bw / 2, 92, cx + bw / 2, 140], radius=24,
                         fill="#e6f4f2", outline="#bfe3de")
    dr.text((cx, 116), badge, font=f_badge, fill="#0a726b", anchor="mm")

    dr.text((cx, 220), "URLを変えずに、", font=f_h, fill="#12202f", anchor="mm")
    dr.text((cx, 300), "ECを乗り換える。", font=f_h2, fill="#0a9a8f", anchor="mm")
    dr.text((cx, 372), "常駐プロセスもポートも要らない。FTPで置くだけ。", font=f_s, fill="#5d6b7a", anchor="mm")
    dr.text((cx, 412), "AIに任せて商品登録も運用もできます。", font=f_s, fill="#5d6b7a", anchor="mm")

    pills = ["商品URLを保つ", "銀行振込・Stripe", "受注管理つき"]
    gap = 14
    widths = [dr.textlength(p, font=f_pill) + 34 for p in pills]
    total = sum(widths) + gap * (len(pills) - 1)
    x = cx - total / 2
    for p, w in zip(pills, widths):
        dr.rounded_rectangle([x, 446, x + w, 490], radius=22, fill="#ffffff", outline="#bfe3de")
        dr.text((x + w / 2, 468), p, font=f_pill, fill="#0a726b", anchor="mm")
        x += w + gap

    dr.rounded_rectangle([cx - 230, 512, cx + 230, 572], radius=16, fill="#0a9a8f")
    dr.text((cx, 542), "Kurage Vibe-Cart", font=f_brand, fill="#ffffff", anchor="mm")

    if mascot:
        img.paste(mascot, (W - mascot.width - 40, H - mascot.height - 30), mascot)
    dr.text((40, H - 40), "exbridge.jp/exdirect/",
            font=ImageFont.truetype(FR, 22), fill="#5d6b7a", anchor="lm")
    return img


if __name__ == "__main__":
    img = build()
    out = os.path.join(ROOT, "outputs", "listing", "card.png")
    os.makedirs(os.path.dirname(out), exist_ok=True)
    img.save(out, optimize=True)
    print(out, img.size)
