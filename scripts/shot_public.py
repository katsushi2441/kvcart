#!/usr/bin/env python3
"""公開ページの一部（CSS セレクタの要素）を撮る。ブログの画像用（2026-11-22）。
  /usr/bin/python3 shot_public.py <URL> '<セレクタ>' <出力.png> [幅=1280] [上下の余白px=0] [下に足すpx=0] [何番目=0]
使い捨てのプロファイルの headless Chrome で開く（共有の chrome-profile は使わない）。
"""
import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

url, sel, out = sys.argv[1], sys.argv[2], sys.argv[3]
width = int(sys.argv[4]) if len(sys.argv) > 4 else 1280
pad = int(sys.argv[5]) if len(sys.argv) > 5 else 0
below = int(sys.argv[6]) if len(sys.argv) > 6 else 0   # 見出しの下の本文も入れたいとき
nth = int(sys.argv[7]) if len(sys.argv) > 7 else 0     # 同じセレクタの何番目か（0から）
prof = tempfile.mkdtemp(prefix="kv-pshot-")
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

    cmd("Emulation.setDeviceMetricsOverride", {"width": width, "height": 1000, "deviceScaleFactor": 2, "mobile": width < 800})
    cmd("Page.navigate", {"url": url})
    time.sleep(5)
    if os.environ.get("SHOT_HIDE_FIXED"):
        # 画面に固定表示のボタン（「Kurage.AIに相談」など）が本文に重なって写るので隠す（考察ショートの素材用・11/22）
        cmd("Runtime.evaluate", {"expression": "document.querySelectorAll('body *').forEach(e=>{const p=getComputedStyle(e).position; if(p==='fixed'||p==='sticky') e.style.setProperty('display','none','important')})"})
    r = cmd("Runtime.evaluate", {"expression": f"(()=>{{const e=document.querySelectorAll({json.dumps(sel)})[{nth}]; if(!e) return null; e.scrollIntoView({{block:'center'}}); const b=e.getBoundingClientRect(); return [b.left+scrollX,b.top+scrollY,b.width,b.height];}})()", "returnByValue": True})
    box = r["result"].get("value")
    if not box:
        sys.exit("要素が見つかりません: " + sel)
    x, y, w, h = box
    clip = {"x": max(0, x - pad), "y": max(0, y - pad), "width": min(width, w + pad * 2), "height": h + pad * 2 + below, "scale": 1}
    png = cmd("Page.captureScreenshot", {"format": "png", "clip": clip, "captureBeyondViewport": True})["data"]
    open(out, "wb").write(base64.b64decode(png))
    print("ok", out, int(clip["width"]), "x", int(clip["height"]))
finally:
    proc.terminate()
    proc.wait(timeout=10)
    shutil.rmtree(prof, ignore_errors=True)
