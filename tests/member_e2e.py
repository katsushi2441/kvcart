#!/usr/bin/env python3
"""会員機能の通し試験（2026-11-22）。tests/router_member.php で立てた試験サーバー（本番 DB の写し）に対して流す。
メールは送らず outputs/member_test/mail.log に書き出される（php -d sendmail_path=tee）。試験用の会員は架空のアドレス。
  /usr/bin/python3 tests/member_e2e.py http://127.0.0.1:18999
"""
import http.cookiejar
import html
import os
import re
import sqlite3
import sys
import urllib.parse
import urllib.request

BASE = sys.argv[1].rstrip("/")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "outputs/member_test/shop.sqlite")
MAIL = os.path.join(ROOT, "outputs/member_test/mail.log")
OK = []


def check(cond, msg):
    print(("OK  " if cond else "NG  ") + msg)
    OK.append(bool(cond))


class Browser:
    def __init__(self):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def req(self, path, data=None):
        url = path if path.startswith("http") else BASE + path
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        for _ in range(5):
            try:
                r = self.op.open(urllib.request.Request(url, data=body))
                return url, r.read().decode("utf-8", "replace")
            except urllib.error.HTTPError as e:
                if e.code in (301, 302, 303):
                    url = urllib.parse.urljoin(url, e.headers["Location"])
                    body = None
                    continue
                return url, e.read().decode("utf-8", "replace")
        return url, ""

    def csrf(self, page):
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', page)
        return m.group(1) if m else ""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def last_link():
    s = open(MAIL, encoding="utf-8", errors="replace").read() if os.path.exists(MAIL) else ""
    import base64
    links = re.findall(r"/member/auth\?t=([0-9a-f]{48})", s)
    if not links:   # 本文は base64 のことがある
        for chunk in re.findall(r"\n\n([A-Za-z0-9+/=\r\n]{40,})", s):
            try:
                links += re.findall(r"/member/auth\?t=([0-9a-f]{48})", base64.b64decode(chunk).decode("utf-8", "replace"))
            except Exception:
                pass
    return links[-1] if links else None


def main():
    db = sqlite3.connect(DB)
    db.row_factory = sqlite3.Row
    # 試験の会員とおちゃのこ時代の注文（カートに入れられる商品を1つ選ぶ）
    p = db.execute("SELECT id,name FROM products WHERE hidden=0 AND price>0 AND (variations IS NULL OR variations='' OR variations NOT LIKE '%option1%') "
                   "AND (inquiry_only IS NULL OR inquiry_only=0) ORDER BY id LIMIT 1").fetchone() if 'inquiry_only' in [r[1] for r in db.execute('pragma table_info(products)')] else \
        db.execute("SELECT id,name FROM products WHERE hidden=0 AND price>0 AND (variations IS NULL OR variations='' OR variations NOT LIKE '%option1%') ORDER BY id LIMIT 1").fetchone()
    email = "kv-member-test@example.invalid"
    for t in ("member_addresses", "member_tokens"):
        db.execute(f"DELETE FROM {t} WHERE member_id IN (SELECT id FROM members WHERE email LIKE 'kv-%@example.invalid')")
    db.execute("DELETE FROM member_past_orders WHERE email LIKE 'kv-%@example.invalid'")
    db.execute("DELETE FROM members WHERE email LIKE 'kv-%@example.invalid'")
    db.execute("DELETE FROM member_login_log")
    db.execute("INSERT INTO members (email,name,name_kana,company,department,zip,pref,address1,address2,address3,tel,fax,dm,source,created_at) "
               "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'ocnk','2020-01-01 00:00:00')",
               (email, "試験 太郎", "シケン タロウ", "試験株式会社", "総務部", "460-0008", "愛知県", "名古屋市中区栄", "1-2-3", "試験ビル5F", "052-000-0000", ""))
    mid = db.execute("SELECT id FROM members WHERE email=?", (email,)).fetchone()[0]
    db.execute("INSERT INTO member_addresses (member_id,name,company,zip,pref,address1,address2,address3,tel,akey,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
               (mid, "現場 次郎", "試験工業 現場事務所", "100-0001", "東京都", "千代田区千代田1-1", "", "", "03-0000-0000", "k1", "2024-05-01 00:00:00"))
    db.execute("INSERT OR REPLACE INTO member_past_orders (email,ocnk_order_id,ordered_at,total,items,ship_name,ship_address) VALUES (?,?,?,?,?,'','')",
               (email, 999999901, "2024-05-01 10:00:00", 1100, f'[{{"product_id":{p["id"]},"name":"試験の商品","qty":2,"variations":false}}]'))
    db.commit()

    b = Browser()
    # 1. ログイン（登録の無いアドレスでも同じ案内・メールは送らない）
    n0 = os.path.getsize(MAIL) if os.path.exists(MAIL) else 0
    _, pg = b.req("/member/login")
    _, pg = b.req("/member/login", {"csrf": b.csrf(pg), "email": "kv-nobody@example.invalid", "next": "member"})
    check("ログイン用のリンクをお送りしました" in pg, "登録の無いアドレスでも同じ案内を出す")
    check((os.path.getsize(MAIL) if os.path.exists(MAIL) else 0) == n0, "登録の無いアドレスにはメールを送らない")
    _, pg = b.req("/member/login")
    _, pg = b.req("/member/login", {"csrf": b.csrf(pg), "email": email.upper(), "next": "member"})
    tok = last_link()
    check(tok is not None, "会員にはログイン用のリンクを送る（大文字で入れても）")
    # 2. リンクを開いただけでは使わない → 押してログイン
    _, pg = b.req("/member/auth?t=" + tok)
    check("ログインする" in pg, "リンクを開くと「ログインする」ボタンが出る")
    url, pg = b.req("/member/auth", {"csrf": b.csrf(pg), "t": tok})
    check("マイページ" in pg and "試験 太郎" in pg, "押すとログインしてマイページへ")
    check("2024-05-01" in pg and "この注文と同じ商品をカートに入れる" in pg, "おちゃのこ時代の注文が履歴に出る")
    check("現場 次郎" in pg, "保存した届け先が出る")
    # 3. 同じリンクはもう使えない
    b2 = Browser()
    _, pg2 = b2.req("/member/auth?t=" + tok)
    check("このリンクは使えません" in pg2, "使ったリンクは2回目は使えない")
    # 4. 同じ注文をカートへ
    m = re.search(r'name="act" value="reorder"><input type="hidden" name="src" value="ocnk"><input type="hidden" name="id" value="(\d+)"', pg)
    url, pg = b.req("/member", {"csrf": b.csrf(pg), "act": "reorder", "src": "ocnk", "id": m.group(1)})
    check(url.endswith("/cart") and f'name="qty_of[{p["id"]}]" value="2"' in pg, "「同じ商品をカートに」で数量2でカートに入る")
    # 5. レジ: ご登録の情報が入っている
    _, pg = b.req("/cart/customer")
    check('value="試験 太郎"' in pg and 'value="名古屋市中区栄"' in pg and "会員としてログインしています" in pg, "レジでご登録の情報が入っている")
    form = {k: html.unescape(v) for k, v in re.findall(r'<input type="(?:text|email|tel)" name="(\w+)" value="([^"]*)"', pg)}
    form.update({"csrf": b.csrf(pg), "pref": "愛知県", "dm": "0", "_member_save": "1", "tel": "052-111-1111"})
    url, pg = b.req("/cart/customer", form)
    check("現場 次郎 様へ届ける" in pg or "現場 次郎" in pg, "お届け先の画面で保存した届け先を選べる")
    aid = re.search(r'value="addr:(\d+)"', pg).group(1)
    url, pg = b.req("/cart/delivery", {"csrf": b.csrf(pg), "ship_to": "addr:" + aid, "payment": "bank", "note": ""})
    check("千代田区千代田1-1" in pg, "確認画面に選んだ届け先が出る")
    url, pg = b.req("/cart/confirm", {"csrf": b.csrf(pg)})
    check("ご注文ありがとうございます" in pg, "注文できる")
    o = db.execute("SELECT * FROM orders ORDER BY id DESC LIMIT 1").fetchone()
    check(o["member_id"] == mid and o["ship_address"].startswith("東京都千代田区"), "注文が会員につながり、届け先が入る")
    check(db.execute("SELECT tel FROM members WHERE id=?", (mid,)).fetchone()[0] == "052-111-1111", "「この内容に更新する」でご登録の電話番号が変わる")
    check(db.execute("SELECT last_used_at FROM member_addresses WHERE id=?", (aid,)).fetchone()[0] is not None, "使った届け先の日付が付く（並びの先頭になる）")
    _, pg = b.req("/member")
    check(o["code"] in pg, "マイページの履歴にこの店の注文が出る")
    # 6. ログアウト
    _, pg = b.req("/member", {"csrf": b.csrf(pg), "act": "logout"})
    _, pg = b.req("/member")
    check("ログイン用のリンクを受け取る" in pg, "ログアウトするとマイページは見られない")

    # 7. 初めてのお客様: 会員登録しながら注文し、別の届け先を保存
    g = Browser()
    _, pg = g.req("/product/%d" % p["id"])
    g.req("/cart", {"csrf": g.csrf(pg), "add": p["id"], "qty": 1})
    _, pg = g.req("/cart/customer")
    check("以前ご注文いただいた方・会員の方" in pg and "会員登録する" in pg, "ゲストのレジにログイン欄と「会員登録する」が出る")
    guest = "kv-new-guest@example.invalid"
    _, pg = g.req("/cart/customer", {"csrf": g.csrf(pg), "name": "新規 花子", "name_kana": "シンキ ハナコ", "company": "", "department": "",
                                      "zip": "530-0001", "pref": "大阪府", "address1": "大阪市北区梅田", "address2": "2-2-2", "address3": "",
                                      "email": guest, "tel": "06-0000-0000", "fax": "", "dm": "0", "_member_save": "1"})
    check("このお届け先をマイページに保存" in pg, "会員登録を選ぶと、別の届け先を保存する欄が出る")
    _, pg = g.req("/cart/delivery", {"csrf": g.csrf(pg), "ship_to": "other", "s_name": "倉庫 三郎", "s_zip": "541-0041", "s_pref": "大阪府",
                                      "s_address1": "大阪市中央区北浜", "s_address2": "3-3", "s_address3": "", "s_tel": "06-1111-1111",
                                      "_addr_save": "1", "payment": "bank", "note": ""})
    _, pg = g.req("/cart/confirm", {"csrf": g.csrf(pg)})
    nm = db.execute("SELECT * FROM members WHERE email=?", (guest,)).fetchone()
    check(nm is not None and nm["source"] == "kvcart" and nm["name"] == "新規 花子", "ゲストが会員登録できる")
    check(nm is not None and db.execute("SELECT COUNT(*) FROM member_addresses WHERE member_id=?", (nm["id"],)).fetchone()[0] == 1, "別の届け先が保存される")
    # 8. ゲストが既存会員のアドレスで注文しても、会員の情報は書き換えない
    h = Browser()
    _, pg = h.req("/product/%d" % p["id"])
    h.req("/cart", {"csrf": h.csrf(pg), "add": p["id"], "qty": 1})
    _, pg = h.req("/cart/customer")
    _, pg = h.req("/cart/customer", {"csrf": h.csrf(pg), "name": "なりすまし", "zip": "999-9999", "pref": "沖縄県", "address1": "那覇市",
                                      "address2": "9", "email": email, "tel": "000", "dm": "0", "_member_save": "1"})
    _, pg = h.req("/cart/delivery", {"csrf": h.csrf(pg), "ship_to": "same", "payment": "bank", "note": ""})
    h.req("/cart/confirm", {"csrf": h.csrf(pg)})
    check(db.execute("SELECT name FROM members WHERE id=?", (mid,)).fetchone()[0] == "試験 太郎", "ログインしていない人が会員の情報を書き換えられない")
    # 9. 送りすぎを止める（同じアドレスへ1時間に3通まで）
    for _ in range(4):
        _, pg = h.req("/member/login")
        h.req("/member/login", {"csrf": h.csrf(pg), "email": guest, "next": "member"})
    sent = db.execute("SELECT SUM(sent) FROM member_login_log WHERE email=?", (guest,)).fetchone()[0]
    check(sent == 3, f"同じアドレスへは1時間に3通まで（{sent}通）")
    # 10. カートの引き継ぎ: ブラウザAでカートに入れてリンクを頼み、ブラウザBで開く → Bのカートに入る
    db.execute("DELETE FROM member_login_log")
    db.commit()
    A = Browser()
    _, pg = A.req("/product/%d" % p["id"])
    A.req("/cart", {"csrf": A.csrf(pg), "add": p["id"], "qty": 3})
    _, pg = A.req("/cart/customer")
    A.req("/member/login", {"csrf": A.csrf(pg), "email": email, "next": "cart/customer"})
    tok = last_link()
    B = Browser()
    _, pg = B.req("/member/auth?t=" + tok)
    url, pg = B.req("/member/auth", {"csrf": B.csrf(pg), "t": tok})
    _, pg = B.req("/cart")
    check(f'name="qty_of[{p["id"]}]" value="3"' in pg, "別のブラウザでリンクを開いても、カートの商品が引き継がれる（数量3）")
    # 同じブラウザで開いたときは2倍にならない
    _, pg = A.req("/member/login")
    A.req("/member/login", {"csrf": A.csrf(pg), "email": email, "next": "member"})
    tok = last_link()
    _, pg = A.req("/member/auth?t=" + tok)
    A.req("/member/auth", {"csrf": A.csrf(pg), "t": tok})
    _, pg = A.req("/cart")
    check(f'name="qty_of[{p["id"]}]" value="3"' in pg, "同じブラウザで開いても数量は2倍にならない（3のまま）")
    # 11. ヘッダーと FAQ
    _, pg = Browser().req("/")
    check("/member/login\"" in pg, "ヘッダーにログインが出る")
    _, pg = Browser().req("/member-login")
    check("ログイン用のリンクを受け取る" in pg, "おちゃのこ時代の /member-login でもログイン画面が出る")
    print("\n%d / %d 合格" % (sum(OK), len(OK)))
    sys.exit(0 if all(OK) else 1)


if __name__ == "__main__":
    main()
