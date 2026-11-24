#!/usr/bin/env python3
"""カードを預かる・預かったカードで再決済する（2026-11-24）の通し試験。**テスト用の鍵と複製DBの作業フォルダでだけ回す。**

  /usr/bin/python3 tests/stripe_card_save_e2e.py <作業フォルダ>

作業フォルダ = public/ の写し（kv_config.php に sk_test、kv_data/shop.sqlite は複製）。tests/stripe_card_save_test.php を置いておく。
1. php で決済待ちと Stripe の決済画面（テスト）を作る
2. 本物のブラウザ（Playwright の Chromium。共有の chrome-profile は使わない）でテストカードを入れ、3D セキュアのテスト画面で「完了」を押す
3. php で注文にして、カードの保存・金額を上げた off_session の再決済・確定を確かめる
   4000002760003184（毎回本人認証が要るカード）では、再決済が authentication_required になり案内が出ることを確かめる
4. 管理画面（php -S）で、再決済の欄と authentication_required の案内が出ることを確かめる
"""
import json, os, re, socket, subprocess, sys, time, urllib.parse, http.cookiejar, urllib.request
from playwright.sync_api import sync_playwright

W = os.path.abspath(sys.argv[1])
PHP = ["php", "-d", "sendmail_path=/bin/true"]


def php(*a):
    r = subprocess.run(PHP + ["stripe_card_save_test.php", *a], cwd=W, capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def pay(url, card):
    with sync_playwright() as p:
        br = p.chromium.launch(headless=True)
        pg = br.new_page(locale="ja-JP")
        pg.goto(url, wait_until="domcontentloaded")
        pg.wait_for_selector("#cardNumber", timeout=60000)
        # カードの欄が畳まれていることがあるので、カードを選ぶ
        acc = pg.query_selector("[data-testid=card-accordion-item-button]")
        if acc and acc.is_visible():
            acc.click()
        pg.fill("#cardNumber", card)
        pg.fill("#cardExpiry", "12 / 34")
        pg.fill("#cardCvc", "123")
        if pg.query_selector("#billingName"):
            pg.fill("#billingName", "TEST TARO")
        if pg.query_selector("#billingPostalCode") and pg.is_visible("#billingPostalCode"):
            pg.fill("#billingPostalCode", "4600008")
        pg.click("[data-testid=hosted-payment-submit-button]")
        # 3D セキュアのテスト画面（iframe の中の「完了」/ Complete）
        done = False
        for _ in range(60):
            if "checkout.stripe.com" not in pg.url:
                done = True
                break
            for fr in pg.frames:
                try:
                    b = fr.query_selector("#test-source-authorize-3ds") or fr.query_selector("button:has-text('Complete')") or fr.query_selector("button:has-text('完了')")
                    if b and b.is_visible():
                        b.click()
                except Exception:
                    pass
            time.sleep(1)
        print("  決済画面から戻った" if done else "  決済画面から戻らない: " + pg.url[:80])
        br.close()
        return done


def free_port():
    # 試験の間だけの一時的なサーバーなので、OS に空いている番号をもらう（常設のサービスではない）
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


ng = 0
for card, want in [("4242424242424242", "ok"), ("4000002760003184", "authreq")]:
    print(f"== {card} ({want})")
    rc, out = php("create")
    j = json.loads(out.strip().splitlines()[-1])
    print(f"  決済画面 {j['session']} {j['total']}円 customer_creation={j['customer_creation']} 種類={j['payment_method_types']}")
    print(f"  ボタンの横の文: {j['submit_text']}")
    if not pay(j["url"], card):
        ng += 1
        continue
    rc, out = php("finish", str(j["pid"]), want)
    print(out)
    ng += rc != 0

# 管理画面（ローカルの php -S）で、再決済の欄と authentication_required の案内を見る
oid = open(os.path.join(W, "authreq_oid.txt")).read().strip() if os.path.exists(os.path.join(W, "authreq_oid.txt")) else ""
if oid:
    port = free_port()
    srv = subprocess.Popen(PHP + ["-S", f"127.0.0.1:{port}", "router.php"], cwd=W, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(1.5)
        cj = http.cookiejar.CookieJar()
        op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
        B = f"http://127.0.0.1:{port}/admin.php"
        h = op.open(B).read().decode()
        t = re.search(r'name="csrf" value="([0-9a-f]+)"', h).group(1)
        op.open(B, urllib.parse.urlencode({"csrf": t, "user": "admin", "pw": "cardsave-test"}).encode()).read()
        h = op.open(f"{B}?a=order&id={oid}&reauth=authreq").read().decode()
        for label, s in [("再決済の欄", "預かったカードで再決済（Stripe）"), ("authentication_required の案内", "本人認証（3D セキュア）を求めたため"),
                         ("支払いページを送るボタン", 'name="stripe_invoice"'), ("前の仮売上の選び方", 'name="reauth_old" value="before"'),
                         ("確定の期限", "確定の期限は"), ("PHP のエラーなし", None)]:
            c = ("Fatal error" not in h and "Warning:" not in h and "Notice:" not in h) if s is None else (s in h)
            print(("OK " if c else "NG ") + "管理画面: " + label)
            ng += not c
        lst = op.open(f"{B}?a=orders").read().decode()
        c = "あと" in lst and "Fatal error" not in lst
        print(("OK " if c else "NG ") + "受注一覧: 仮売上の札に期限と残り日数")
        ng += not c
    finally:
        srv.terminate()
print("NG", ng)
sys.exit(1 if ng else 0)
