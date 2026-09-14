# Kurage Vibe-Cart（kvcart）

**PHPだけで動くECサイト。** 常駐プロセスもポートも要らない。レンタルサーバーにFTPで置けば動く。

Claude Code などのAIエージェントに「バイブコーディング」で商品登録・ページ生成・運用をさせることを
前提に設計している（MCP同梱）。

## なぜ作るか

exdirect.net（おちゃのこネット）を自前のECへ置き換えるため。
**商品ページのURLを1文字も変えない**のが最重要要件で、ブックマークと検索インデックスを引き継ぐ。

## URL互換（おちゃのこネットと同じ）

| URL | 中身 |
|---|---|
| `/product/<数値ID>` | 商品詳細。**IDはおちゃのこネットのものをそのまま使う** |
| `/product-group/<ID>` | グループ（メーカー） |
| `/product-list/<ID>` | カテゴリ |
| `/page/<n>` | 固定ページ |
| `/info` `/help` `/contact` | 固定ページ |

移行しない商品のURLも **404にしない**。「取り扱いを終了しました」を出し、
同じメーカー・同じカテゴリの現行商品へ案内する（ブックマークから来た人を落とさない）。

## 動く環境

heteml で実測（2026-09-14）:

| | |
|---|---|
| PHP | **8.2.33**（`.htaccess` に `AddHandler php8.2-script .php` が必須。無いと5.6になる） |
| DB | SQLite（pdo_sqlite / sqlite3 あり） |
| memory_limit | 128M |
| max_execution_time | **30秒**（重い処理は必ず分割する） |
| upload_max_filesize | 20M |

## 決済

- **銀行振込**（最初から）
- **Stripe**（アカウント作成後に有効化。キーが無ければボタンを出さない＝kbilling と同じ考え方）

## 受注管理

おちゃのこネットに合わせる。**単一ステータスではなく「受注・入金・発送・その他」の4系統を
個別にチェックする**作り（[公式マニュアル](https://www.ocnk.net/webmanual/index.php?action=show&cat=1)）。
チェックとメール送信が連動する。

## 構成

```
public/index.php      店（ルーティング）
public/admin.php      管理（受注・商品・ページ）
public/kv_lib.php     データアクセス（SQLite）
public/kv_ui.php      共通のガワ
public/kvcart_mcp.php MCP（1ファイル・stdio）
public/kv_data/       SQLite・画像（Web非公開）
scripts/import_ocnk.py おちゃのこAPI → SQLite
```

## デモ

https://exbridge.jp/exdirect/ （noindex。本番は exdirect.net へ移す）

管理画面は `/exdirect/admin.php`。

## MCP（バイブコーディングで運用する）

```bash
claude mcp add kvcart -- php /path/to/public/kvcart_mcp.php
```

8ツール。`kvcart_search_products` / `kvcart_get_product` / `kvcart_upsert_product` /
`kvcart_makers` / `kvcart_orders` / `kvcart_get_order` / `kvcart_set_order_flag` / `kvcart_stats`。

instructions に **「商品IDは /product/<id> というURLそのもの。既存商品のIDを変えてはいけない」**
と明記してある。金額・在庫は利用者が指示した値だけを書き、推測で埋めない約束。

## 移行手順

`docs/MIGRATION.md` を見る。ドメイン切替は `KV_BASE` を `''` にするだけ。
