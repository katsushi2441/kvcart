#!/usr/bin/env python3
"""おちゃのこネットの会員を kvcart へ移すための JSON を作る（2026-11-22）。

入力
  - 登録者CSV: おちゃのこの管理画面【受注管理】→【登録者一覧】→「CSVファイルのアップロード / ダウンロード」→
    購入回数・購入合計金額・最終購入日・アンケート・登録日時にチェック、住所を分割して「登録者情報をすべてダウンロードする」。
    （押しても画面は変わらず、ブラウザのダウンロードフォルダに customer.csv が黙って保存される。Shift_JIS）
  - 受注: おちゃのこ API（exdirect_net/ocnk_client.py）。会員のメールアドレスの注文から、届け先と注文の履歴を取る。

出力（個人情報なので outputs/ に置く。git に入らない）
  outputs/member_import/members.json  {members:[…], addresses:[…], past_orders:[…]}

使い方
  /usr/bin/python3 scripts/build_member_import.py <customer.csv>
  → scripts/import_members.php で heteml の DB に入れる（README の手順）
"""
from __future__ import annotations

import csv
import datetime as dt
import json
import os
import re
import sys
import unicodedata

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, "/home/kojima/work/exdirect_net")
from ocnk_client import OcnkClient  # noqa: E402

JST = dt.timezone(dt.timedelta(hours=9))


def em(s: str) -> str:
    return unicodedata.normalize("NFKC", s or "").strip().lower()


def zip7(s: str) -> str:
    d = re.sub(r"\D", "", unicodedata.normalize("NFKC", s or ""))
    return f"{d[:3]}-{d[3:]}" if len(d) == 7 else (s or "").strip()


def jst(s: str) -> str:
    if not s:
        return ""
    try:
        return dt.datetime.fromisoformat(s.replace("Z", "+00:00")).astimezone(JST).strftime("%Y-%m-%d %H:%M:%S")
    except ValueError:
        return s[:19].replace("T", " ")


def akey(*parts: str) -> str:
    return re.sub(r"[\s\-－ー‐]", "", unicodedata.normalize("NFKC", "".join(p or "" for p in parts))).lower()


def main():
    src = sys.argv[1]
    members = {}
    for r in csv.DictReader(open(src, encoding="cp932", errors="replace")):
        if (r.get("削除") or "").strip():
            continue
        e = em(r["E-MAIL"])
        if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", e):
            continue
        members[e] = {
            "email": e, "name": r["お名前"].strip(), "name_kana": r["フリガナ"].strip(),
            "company": r["法人名"].strip(), "department": r["部署名"].strip(),
            "zip": zip7(r["郵便番号"]), "pref": r["都道府県"].strip(),
            "address1": r["住所1"].strip(), "address2": r["住所2"].strip(), "address3": r["住所3"].strip(),
            "tel": r["電話番号"].strip(), "fax": r["FAX番号"].strip(),
            "dm": 1 if (r.get("メールマガジン配信") or "").strip() == "1" else 0,
            "ocnk_id": int(r["会員ID"]) if r["会員ID"].strip().isdigit() else None,
            "ocnk_sales_count": int(r["購入回数"] or 0), "ocnk_sales_total": int(r["購入合計金額"] or 0),
            "ocnk_last_order": (r["最終購入日時"] or "").strip(), "created_at": (r["登録日時"] or "").strip() + ":00",
        }
    print("会員", len(members), file=sys.stderr)

    addresses, past = [], []
    seen_addr = set()
    n = 0
    for o in OcnkClient().iter_all("orders", page_size=1000):
        n += 1
        if o.get("cancelled"):
            continue
        cu = o.get("customer") or {}
        e = em(cu.get("email") or "")
        m = members.get(e)
        if not m:
            continue
        own = akey(m["zip"], m["pref"], m["address1"], m["address2"], m["address3"])
        items, ships = [], []
        for sh in o.get("shipping") or []:
            for p in sh.get("products") or []:
                items.append({"product_id": int(p.get("product_id") or 0), "name": p.get("name") or "",
                              "model_number": p.get("model_number") or "", "qty": int(p.get("quantity") or 1),
                              "price": p.get("price"), "variations": bool(p.get("variations"))})
            rc = sh.get("recipient") or {}
            addr = (rc.get("address") or "").strip()
            if not addr:
                continue
            ships.append(f"{rc.get('prefecture') or ''}{addr} {rc.get('company_name') or ''} {rc.get('name') or ''}".strip())
            k = akey(zip7(rc.get("postal_code") or ""), rc.get("prefecture") or "", addr)
            # 会員ご本人の住所と同じ届け先は帳面に入れない（「ご購入者さまと同じ住所」で選べる）
            if k == own or akey(m["pref"], m["address1"], m["address2"], m["address3"]) == akey(rc.get("prefecture") or "", addr):
                continue
            if (e, k) in seen_addr:
                continue
            seen_addr.add((e, k))
            addresses.append({"email": e, "name": (rc.get("name") or "").strip(), "company": " ".join(
                x for x in [(rc.get("company_name") or "").strip(), (rc.get("department") or "").strip()] if x),
                "zip": zip7(rc.get("postal_code") or ""), "pref": (rc.get("prefecture") or "").strip(),
                "address1": addr, "address2": "", "address3": "", "tel": (rc.get("phone") or "").strip(),
                "last_used_at": jst(o.get("created_at") or "")})
        past.append({"email": e, "ocnk_order_id": int(o["id"]), "ordered_at": jst(o.get("created_at") or ""),
                     "total": int(o.get("total_price") or 0), "items": items,
                     "ship_address": " / ".join(ships)[:300]})
    print("受注", n, "会員の注文", len(past), "届け先", len(addresses), file=sys.stderr)
    out = os.path.join(ROOT, "outputs", "member_import")
    os.makedirs(out, exist_ok=True)
    path = os.path.join(out, "members.json")
    with open(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), "w", encoding="utf-8") as f:
        json.dump({"members": list(members.values()), "addresses": addresses, "past_orders": past}, f, ensure_ascii=False)
    print(path)


if __name__ == "__main__":
    main()
