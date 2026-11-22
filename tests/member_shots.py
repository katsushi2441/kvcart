#!/usr/bin/env python3
"""会員の画面を幅ごとに撮って、横にはみ出していないかを数える（2026-11-22）。
試験サーバー（tests/router_member.php）で、試験の会員としてログインして撮る。
  /usr/bin/python3 tests/member_shots.py http://127.0.0.1:18999/exdirect
出力: outputs/member_test/shots/<page>-<幅>.png
"""
import base64
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from member_e2e import Browser, last_link  # noqa: E402

BASE = sys.argv[1].rstrip("/")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "outputs/member_test/shots")
EMAIL = "kv-member-test@example.invalid"
PRODUCT = sys.argv[2] if len(sys.argv) > 2 else "/"


def login_cookie():
    import member_e2e
    member_e2e.BASE = BASE
    b = Browser()
    _, pg = b.req("/member/login")
    b.req("/member/login", {"csrf": b.csrf(pg), "email": EMAIL, "next": "member"})
    tok = last_link()
    _, pg = b.req("/member/auth?t=" + tok)
    b.req("/member/auth", {"csrf": b.csrf(pg), "t": tok})
    _, pg = b.req("/member")
    m = re.search(r'name="act" value="reorder"><input type="hidden" name="src" value="(\w+)"><input type="hidden" name="id" value="(\d+)"', pg)
    b.req("/member", {"csrf": b.csrf(pg), "act": "reorder", "src": m.group(1), "id": m.group(2)})
    jar = b.op.handlers[[type(h).__name__ for h in b.op.handlers].index("HTTPCookieProcessor")].cookiejar
    return [{"name": c.name, "value": c.value, "domain": "127.0.0.1", "path": c.path} for c in jar]


def main():
    os.makedirs(OUT, exist_ok=True)
    cookies = login_cookie()
    prof = tempfile.mkdtemp(prefix="kv-shot-")
    proc = subprocess.Popen(["google-chrome", "--headless=new", "--no-sandbox", "--hide-scrollbars", f"--user-data-dir={prof}",
                             "--remote-debugging-port=0", "about:blank"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            f = os.path.join(prof, "DevToolsActivePort")
            if os.path.exists(f):
                port = open(f).read().split()[0]
                break
            time.sleep(0.2)
        page = next(t for t in json.load(urllib.request.urlopen(f"http://127.0.0.1:{port}/json")) if t["type"] == "page")
        ws = websocket.create_connection(page["webSocketDebuggerUrl"], timeout=60, suppress_origin=True)
        n = [0]

        def cmd(m, p=None):
            n[0] += 1
            ws.send(json.dumps({"id": n[0], "method": m, "params": p or {}}))
            while True:
                r = json.loads(ws.recv())
                if r.get("id") == n[0]:
                    return r.get("result", {})

        for c in cookies:
            cmd("Network.setCookie", c)
        bad = 0
        for name, path in [("member", "/member"), ("cart", "/cart"), ("customer", "/cart/customer"), ("login", "/member/login"), ("top", "/"), ("product", PRODUCT)]:
            for w in (320, 360, 390, 1280):
                cmd("Emulation.setDeviceMetricsOverride", {"width": w, "height": 900, "deviceScaleFactor": 1, "mobile": w < 800})
                cmd("Page.navigate", {"url": BASE + path})
                time.sleep(2.5)
                sw = cmd("Runtime.evaluate", {"expression": "document.documentElement.scrollWidth", "returnByValue": True})["result"]["value"]
                h = cmd("Runtime.evaluate", {"expression": "Math.min(document.documentElement.scrollHeight, 3200)", "returnByValue": True})["result"]["value"]
                cmd("Emulation.setDeviceMetricsOverride", {"width": w, "height": h, "deviceScaleFactor": 1, "mobile": w < 800})
                time.sleep(0.5)
                png = cmd("Page.captureScreenshot", {"format": "png"})["data"]
                open(os.path.join(OUT, f"{name}-{w}.png"), "wb").write(base64.b64decode(png))
                flag = "OK" if sw <= w else "はみ出し"
                bad += sw > w
                print(f"{flag} {name} 幅{w}: scrollWidth={sw}")
        print("はみ出し", bad)
    finally:
        proc.terminate()
        proc.wait(timeout=10)
        shutil.rmtree(prof, ignore_errors=True)


if __name__ == "__main__":
    main()
