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

## 踏んだ罠（同じ所で止まらないために）

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
