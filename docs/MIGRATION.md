# exdirect.net の移行手順

## いまの状態

| | |
|---|---|
| 現行 | おちゃのこネット（www.exdirect.net）・商品46,658件・受注23,718件 |
| デモ | https://exbridge.jp/exdirect/ （noindex・商品5,736件） |
| 移行対象 | exbridge.jp/xdirect/ に掲載の14メーカー **5,736件** |
| 移行しない | 40,922件（URLは生かして「取り扱い終了」を返す） |

## ブックマークを切らないための決まり

**商品IDはおちゃのこネットのものをそのまま使う。** `/product/11366` の `11366` が
お客様のブックマークと検索結果に載っている数字なので、振り直してはいけない。

| URL | デモ | 本番（切替後） |
|---|---|---|
| 商品 | `/exdirect/product/11366` | `/product/11366` |
| グループ | `/exdirect/product-group/54` | `/product-group/54` |
| カテゴリ | `/exdirect/product-list/214` | `/product-list/214` |
| 固定 | `/exdirect/info` | `/info` |

切替は **`kv_config.php` の `KV_BASE` を `''` にするだけ**。
`.htaccess` の `RewriteBase` も `/` に直す。

## ドメイン切替の手順

1. exdirect.net のドキュメントルートに `public/` の中身を置く（`kv_data/` ごと）
2. `kv_config.php` を作り、`KV_BASE` を `''`、振込先・会社情報・管理パスワードを入れる
3. `.htaccess` の `RewriteBase` を `/` にする
4. `kv_data/` を **777**、`shop.sqlite` を **666** にする（書き込めないと注文を受けられない）
5. `<?php echo PHP_VERSION;` を1本置いて **PHP8で動いているか実測**する
6. 旧URLを10本ほど実際に踏んで、同じ商品が出ることを確かめる
7. おちゃのこネットの契約を止める

## 実際の切替（2026-11-15 決定）: デモのフォルダをそのまま exdirect.net に向ける

heteml の独自ドメイン設定で exdirect.net / www.exdirect.net の行き先を **`/web/exbridge_jp/exdirect`（デモと同じフォルダ）** にする。
データ（kv_data）が1つなので、デモからの注文も本番の注文も同じ DB に入る。

そのために本番の2ファイルを「アクセスされたドメインで切り替わる」形にした（2026-11-15 配置済み・デモの動作は確認済み）:

- `kv_config.php`: `define('KV_BASE', preg_match('/(^|\.)exdirect\.net$/i', $_SERVER['HTTP_HOST'] ?? '') ? '' : '/exdirect');`
- `.htaccess`: `RewriteBase` を書かず、最後の振り分けをドメインで分ける
  ```apache
  RewriteCond %{HTTP_HOST} (^|\.)exdirect\.net$ [NC]
  RewriteRule ^(.*)$ /index.php [QSA,L]
  RewriteRule ^(.*)$ /exdirect/index.php [QSA,L]
  ```

切替前の本番ファイルは `outputs/*.server-before-domain` に退避（outputs は Git 管理外）。

## オプション（おちゃのこネットの「オプション」）2026-11-16

- products.variations に API の variations を JSON のまま持つ（scripts/load_variations.py・取り込み時も保存）。
- 商品ページに選択欄、カートのキーは「商品ID|選んだ番号」。注文明細の商品名に「（吐出口：右方向吐出タイプ）」を付けて残す。
- **組み合わせ別の価格（variations.data[].price）は本体価格より優先される。** 本体価格を改定したら、
  組み合わせ別の価格も同じ式で直すこと（2026-11-16 平野製作所の10%値上げを 11039・39649 の組み合わせにも適用）。
  品切れの組み合わせ（stock 0）は選べない。
（同じ所で止まらないために）

- **`AddHandler php8.2-script` は exbridge.jp では404になる。** 契約ごとに使える書式が違う。
  `php-script` が安全（exbridge.jp では PHP 8.3.33 になる）。
  `.php` だけ404で `.txt` は200、という症状が出たらこれを疑う。
- **WAL を使わない。** `PRAGMA journal_mode=WAL` は `-wal` / `-shm` をディレクトリに作るので、
  書き込み権限が無いと**読むだけで** `attempt to write a readonly database` になる。
- **`kv_config.php` は遅延読み込みにしない。** `KV_BASE` を `kv_lib.php` も使うので、
  後回しにすると `KV_BASE=''` が先に定義され、サブパス配置が全部404になる。
- **`PATH_INFO` は環境によって有ったり無かったりする。** どちらから取っても最後に
  必ず `KV_BASE` を剥がす。
- **`session_start()` は出力より前に。** `kv_head()` の後だとクッキーが出ず、CSRFが毎回外れる。

## データの取り込み

```bash
cd /home/kojima/work/kvcart
/usr/bin/python3 scripts/import_ocnk.py --build   # おちゃのこAPI → SQLite
/usr/bin/python3 scripts/import_ocnk.py --stats   # 確認
```

APIの資格情報は `/home/kojima/work/exdirect_net/.env`（`OCNK_API_TOKEN`）。
取り込み時に、商品説明の先頭にあった**楽天・Amazonへの誘導リンクを除去**している
（自社ECなのに他所へ送っていたため）。

## 決済

- **銀行振込**は最初から動く
- **Stripe** は `kv_config.php` の `$KV_STRIPE` に鍵を入れたときだけボタンが出る。
  Stripeアカウントは**まだ作っていない**（kbilling・kpaylink・kappstore はすべて PayPal＋振込で、
  ワークスペース全体に Stripe の鍵は1つも無い）。作成後にキーを入れる。
