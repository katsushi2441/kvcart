#!/usr/bin/env python3
"""kappstore で配る kvcart.zip を作る（2026-11-15）。

  /usr/bin/python3 scripts/build_package.py   →  outputs/kvcart.zip

- 入れるのは Git で管理しているファイルだけ（kv_config.php・.env・本番DBは入らない）。
- 自社の店（X-Direct）の商品画像 public/img/*.jpg と、Search Console の確認ファイルは入れない。
- DB は「表の形だけ・中身0件」の shop.sqlite を新しく作って入れる（本番の商品・受注は入れない）。
- 作ったあと、旧zipとのファイル一覧の差を表示する（販売ページに書いた機能が消えていないかを見るため）。
"""
import fnmatch
import os
import sqlite3
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "outputs" / "kvcart.zip"
EXCLUDE = ["public/img/*.jpg", "public/google*.html", "public/[0-9a-f]*[0-9a-f].txt",  # 32桁の .txt は当店の IndexNow の鍵
           "outputs/*", ".gitignore"]


def files() -> list:
    ls = subprocess.run(["git", "-C", str(ROOT), "ls-files"], capture_output=True, text=True, check=True).stdout.split()
    return [f for f in ls if not any(fnmatch.fnmatch(f, p) for p in EXCLUDE)]


def empty_db(path: Path) -> None:
    src = sqlite3.connect(ROOT / "public" / "kv_data" / "shop.sqlite")
    dst = sqlite3.connect(path)
    for (sql,) in src.execute("SELECT sql FROM sqlite_master WHERE type IN ('table','index') AND sql IS NOT NULL "
                              "AND name NOT LIKE 'sqlite_%'"):
        dst.execute(sql)
    dst.commit()
    for (t,) in dst.execute("SELECT name FROM sqlite_master WHERE type='table'"):
        assert dst.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0] == 0
    dst.close()


def main():
    old = set()
    if OUT.exists():
        old = {n for n in zipfile.ZipFile(OUT).namelist() if not n.endswith("/")}
        OUT.rename(OUT.with_suffix(".zip.prev"))
    fl = files()
    with tempfile.TemporaryDirectory() as td:
        db = Path(td) / "shop.sqlite"
        empty_db(db)
        with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as z:
            for f in fl:
                z.write(ROOT / f, "kvcart/" + f)
            z.write(db, "kvcart/public/kv_data/shop.sqlite")
    new = {n for n in zipfile.ZipFile(OUT).namelist() if not n.endswith("/")}
    print(f"{OUT}  {OUT.stat().st_size:,} bytes  {len(new)} files")
    if old:
        print("旧zipから消えたもの:", sorted(old - new) or "なし")
        print("新しく入ったもの:", sorted(new - old))
    leaked = [n for n in new if fnmatch.fnmatch(n, "*kv_config.php") or n.endswith(".env")]
    if leaked:
        sys.exit(f"設定ファイルが入っています: {leaked}")


if __name__ == "__main__":
    main()
