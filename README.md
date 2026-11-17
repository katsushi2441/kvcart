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
- **クレジットカード（Stripe の請求書払い）**：注文時には引き落とさない。在庫・送料を確かめてから、管理画面の
  「カード払いの請求書を送る」で確定した金額の請求書を作り、お支払いページのリンクをメールで送る。
  入金は webhook（`public/stripe_webhook.php`・`invoice.paid`）で「入金」チェックが自動で付く。
  鍵（制限付きキー rk_ と whsec_）を `kv_config.php` に入れたときだけカードの選択肢が出る。実装は `public/kv_stripe.php`

## 受注管理

おちゃのこネットに合わせる。**単一ステータスではなく「受注・入金・発送・その他」の4系統を
個別にチェックする**作り（[公式マニュアル](https://www.ocnk.net/webmanual/index.php?action=show&cat=1)）。
チェックとメール送信が連動する。

## 商品・お店の管理（管理画面）

2026-11-17 から、受注のほかに次の画面があります（`public/kv_admin_catalog.php`）。どれも管理画面のメニューから開けます。

- **商品**：非表示・お問い合わせ品も含めて検索（商品名・型番・JAN・商品ID）、メーカー・カテゴリ・状態（販売中／非表示／お問い合わせ品／価格なし／画像なし／在庫0）で絞り込み、チェックして一括で表示・非表示・お問い合わせ品に
- **商品の編集・新規登録・複製**：価格・定価・在庫・状態・商品名・型番・JAN・メーカー・カテゴリ・説明・画像（アップロードで長辺800pxに縮小、またはURL）・オプション（項目と値。お客様が選んでからカートに入れる）。取り扱い終了にすると、商品ページは404にせず「取り扱いを終了しました」と同じメーカーの商品の案内になる
- **価格の一括改定**：メーカー・カテゴリ・検索語で絞り、販売価格か定価を ±% で、1円／10円／100円単位で四捨五入。変わる価格の一覧（定価を超えるものに印）を見てから反映
- **商品CSV**：いまの絞り込みのままCSVで書き出し、Excel等で直して取り込み。id で突き合わせ、変わる項目だけを確認してから反映
- **メーカー**・**カテゴリ**：追加・名前・URL名・並び順の変更
- **顧客**：注文のメールアドレスごとに、注文回数・合計・最終注文日（リピーター表示・CSV）
- **売上**：月別の売上・入金済み・発送済み、支払い方法別、直近90日によく売れた商品、未入金・未発送の件数
- **商品の変更履歴**：画面・一括改定・CSV取り込みで変えた内容を「いつ・どの商品の・どの項目が・何から何へ」で残す（`kv_data/product_log.jsonl`）

## 構成

```
public/index.php      店（ルーティング）
public/admin.php      管理（受注・代理入力・メールテンプレート）
public/kv_admin_catalog.php 管理（商品・価格の一括改定・商品CSV・メーカー・カテゴリ・顧客・売上・変更履歴）
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
