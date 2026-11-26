<?php
/**
 * Google Merchant Center の商品フィード（/google-shopping.xml・2026-11-17）
 *
 * おちゃのこネット時代の Merchant Center は https://www.exdirect.net/gfeed.xml を取得していた。同じ URL でもこのフィードを出す（商品IDは同じ）。
 * Google ショッピング（無料リスティング・ショッピング タブ）に載せるため、Merchant Center にこの URL を
 * 「スケジュールされた取得」で登録する。形式は RSS 2.0 + g: 名前空間（Google の商品データ仕様）。
 *
 * - 載せるのは、表示中・価格あり・お問い合わせ品でない・画像のある商品（画像は Google の必須項目）
 * - 価格は税込（日本の表示の決まり）。送料は全国0円（北海道・沖縄・離島は Merchant Center の送料設定で別に決める）
 * - 在庫: 在庫数を管理しない直送品と在庫ありは in_stock、在庫切れでも受ける商品は backorder（入荷予定日は14日後）、
 *   受けない在庫切れは out_of_stock（kv_stock.php の設定に合わせる）
 * - 画像（2026-11-17 追記）: Google は 500×500 未満の画像を 2027-01-31 から表示しない。小さい画像は白地 800×800 に拡大した
 *   写しを gimg/<元の名前>.jpg に置き（kvcart-sales/gshop/img500/make500.py）、フィードだけそちらを使う。店の商品ページの画像は変えない。
 *   「画像はありません」「NO IMAGE」などの仮画像やメーカーロゴは Google が商品画像と認めない（不承認）ので、
 *   kv_data/gshop_noimage.json（ファイル名 => 理由）に載った画像の商品はフィードに入れない
 * - 1万点を超えるので、kv_data/gshop_feed.xml に作り置きし、商品が更新されたか6時間たったら作り直す
 */
declare(strict_types=1);

function kv_gshop_text(string $s, int $max): string
{
    $s = html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '<br/>', '</p>'], "\n", $s)), ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/【[^】]*】/u', ' ', $s);
    // 旧サイトから引き継いだ定型文で、直送の X-Direct には当てはまらないもの（「国内の倉庫から宅配便で…」）は外す
    $s = preg_replace('/[^\n。]*国内の倉庫から宅配便で[^\n。]*。?/u', '', $s);
    $s = trim(preg_replace('/[ \t\x{3000}]+/u', ' ', preg_replace('/\s*\n\s*/u', "\n", $s)));
    // 制御文字は XML に入れられない
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

function kv_gshop_build(string $path): array
{
    $db = kv_db();
    $makers = []; foreach ($db->query('SELECT id,name FROM makers') as $m) { $makers[(int)$m['id']] = $m['name']; }
    // Google の商品カテゴリ。おちゃのこネット時代のフィード（gfeed.xml）で商品ごとに付けていたものを引き継ぐ（kv_data/gshop_gpc.json: 商品ID => カテゴリ）
    $gpc = is_file(KV_DATA . '/gshop_gpc.json') ? (json_decode((string)file_get_contents(KV_DATA . '/gshop_gpc.json'), true) ?: []) : [];
    $cats = []; foreach ($db->query('SELECT id,name,parent_name FROM categories') as $c) { $cats[(int)$c['id']] = trim(($c['parent_name'] ? $c['parent_name'] . ' > ' : '') . $c['name']); }
    // これまでに売れた商品（custom_label_0 = sold）。広告をこの印の商品だけに絞るため（2026-11-18）。
    // 移行前のショップの受注は kv_data/sold_products.json（{"商品ID": 回数}）、移行後はこのシステムの受注（キャンセル除く）
    $sold = is_file(KV_DATA . '/sold_products.json') ? (json_decode((string)file_get_contents(KV_DATA . '/sold_products.json'), true) ?: []) : [];
    try {
        foreach ($db->query('SELECT DISTINCT i.product_id FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.cancelled=0') as $r) { $sold[(string)$r['product_id']] = 1; }
    } catch (Throwable $e) { /* 受注の表がまだ無いショップ */ }
    $noimg = is_file(KV_DATA . '/gshop_noimage.json') ? (json_decode((string)file_get_contents(KV_DATA . '/gshop_noimage.json'), true) ?: []) : [];
    // 商品ごとの上書き（2026-11-19）: kv_data/gshop_override.json = {"商品ID": {"image": "gimg/p123.jpg", "color": "ブラック"}}
    // image は Merchant Center で「汎用的な画像」「画像に宣伝文」「画像が小さい」と不承認になった商品に、メーカー公式の大きい写真を使うため（店の商品ページの画像は変えない）
    // 広告に出さないメーカー（custom_label_1 = noad）。在庫0・納期お問い合わせで移したメーカーは、無料掲載には載せて広告からは外す（2026-11-20 ユーザー指示）
    // kv_data/gshop_noad_makers.json = {"メーカーID": "メーカー名"}
    $noad = is_file(KV_DATA . '/gshop_noad_makers.json') ? (json_decode((string)file_get_contents(KV_DATA . '/gshop_noad_makers.json'), true) ?: []) : [];
    // 商品ごとに広告から外す指定（2026-11-20）。kv_data/gshop_noad_products.json = {"商品ID": "メモ"}
    $noadP = is_file(KV_DATA . '/gshop_noad_products.json') ? (json_decode((string)file_get_contents(KV_DATA . '/gshop_noad_products.json'), true) ?: []) : [];
    $ovr = is_file(KV_DATA . '/gshop_override.json') ? (json_decode((string)file_get_contents(KV_DATA . '/gshop_override.json'), true) ?: []) : [];
    $base = rtrim(defined('KV_ORIGIN') ? KV_ORIGIN : kv_site_url(''), '/');
    $tmp = $path . '.tmp';
    $fh = fopen($tmp, 'w');
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
        . '<title>' . $x(kv_shop('name')) . '</title><link>' . $x($base . '/') . '</link><description>' . $x(kv_shop('tagline')) . "</description>\n");
    $n = 0; $skip = ['画像なし' => 0, '仮画像' => 0, '拡大画像を使用' => 0, '売れた商品' => 0];
    $bo = date('Y-m-d', strtotime('+14 days')) . 'T00:00+0900';
    $st = $db->query("SELECT * FROM products WHERE hidden=0 AND price>0 AND COALESCE(price_unspecified,0)=0 ORDER BY id");
    while ($p = $st->fetch()) {
        if ((string)$p['image_url'] === '') { $skip['画像なし']++; continue; }
        $imgName = basename((string)parse_url((string)$p['image_url'], PHP_URL_PATH));
        if (isset($noimg[$imgName])) { $skip['仮画像']++; continue; }
        $big = 'gimg/' . pathinfo($imgName, PATHINFO_FILENAME) . '.jpg';
        $img = is_file(__DIR__ . '/' . $big) ? $base . '/' . $big : kv_abs((string)$p['image_url']);
        $ov = $ovr[(string)$p['id']] ?? [];
        if (!empty($ov['image']) && is_file(__DIR__ . '/' . $ov['image'])) { $img = $base . '/' . $ov['image']; }
        if ($img !== kv_abs((string)$p['image_url'])) { $skip['拡大画像を使用']++; }
        $mk = $makers[(int)$p['maker_id']] ?? '';
        // 商品ページの題名と同じ形（型番を先頭に1回・繰り返しを外す・メーカー名を足す）。JAN は g:gtin で渡すので題名からは外す（2026-11-27）
        $title = kv_gshop_text(preg_replace('/ JAN \d{8,13}$/', '', kv_product_title($p, $mk)), 150);
        $mdl = kv_model_display($p['model_number'], $p['name']);
        $desc = kv_gshop_text((string)$p['description'], 4000);
        if (mb_strlen($desc) < 30) {
            $desc = trim($mk . 'の' . ($cats[(int)$p['category_id']] ?? '商品') . '「' . $title . '」' . ($mdl !== '' ? '（型番 ' . $mdl . '）' : '')
                  . '。メーカー・仕入先から直送の新品正規品です。送料は原則無料。' . ($desc !== '' ? ' ' . $desc : ''));
        }
        $st2 = kv_stock_state($p);
        $avail = $st2 === 'out' ? (kv_stock_orderable($p) ? 'backorder' : 'out_of_stock') : 'in_stock';
        $jan = kv_jan($p);
        $incl = kv_price_incl($p);
        $item = '<item><g:id>' . (int)$p['id'] . '</g:id><title>' . $x($title) . '</title><description>' . $x($desc) . '</description>'
              . '<link>' . $x($base . '/product/' . (int)$p['id']) . '</link><g:image_link>' . $x($img) . '</g:image_link>'
              . '<g:availability>' . $avail . '</g:availability>' . ($avail === 'backorder' ? '<g:availability_date>' . $bo . '</g:availability_date>' : '')
              . '<g:price>' . (int)$incl . ' JPY</g:price><g:condition>new</g:condition>'
              . ($mk !== '' ? '<g:brand>' . $x($mk) . '</g:brand>' : '')
              . ($jan !== '' ? '<g:gtin>' . $jan . '</g:gtin>' : '')
              . ($mdl !== '' ? '<g:mpn>' . $x($mdl) . '</g:mpn>' : '')
              . ($jan === '' && ($mdl === '' || $mk === '') ? '<g:identifier_exists>no</g:identifier_exists>' : '')
              . (!empty($gpc[(string)$p['id']]) ? '<g:google_product_category>' . $x($gpc[(string)$p['id']]) . '</g:google_product_category>' : '')
              . (!empty($ov['color']) ? '<g:color>' . $x($ov['color']) . '</g:color>' : '')
              . (isset($cats[(int)$p['category_id']]) ? '<g:product_type>' . $x(($mk !== '' ? '' : '') . $cats[(int)$p['category_id']]) . '</g:product_type>' : '')
              . (isset($sold[(string)$p['id']]) ? '<g:custom_label_0>sold</g:custom_label_0>' : '<g:custom_label_0>unsold</g:custom_label_0>')
              . (isset($noad[(string)(int)$p['maker_id']]) || isset($noadP[(string)(int)$p['id']]) ? '<g:custom_label_1>noad</g:custom_label_1>' : '<g:custom_label_1>ad</g:custom_label_1>')
              // メーカーごとの納期（受注生産など）。Merchant Center の配送設定の準備日数より優先される
              . (($lt = kv_product_leadtime($p)) ? '<g:min_handling_time>' . (int)($lt['min'] ?? $lt['max']) . '</g:min_handling_time><g:max_handling_time>' . (int)$lt['max'] . '</g:max_handling_time>' : '')
              . '<g:shipping><g:country>JP</g:country><g:price>0 JPY</g:price></g:shipping>'
              . "</item>\n";
        if (isset($sold[(string)$p['id']])) { $skip['売れた商品']++; }
        fwrite($fh, $item);
        $n++;
    }
    fwrite($fh, "</channel></rss>\n");
    fclose($fh);
    rename($tmp, $path);
    @file_put_contents($path . '.json', json_encode(['built' => date('c'), 'items' => $n, 'skipped' => $skip], JSON_UNESCAPED_UNICODE));
    return ['items' => $n, 'skipped' => $skip];
}

function kv_page_gshop_feed(): void
{
    $path = KV_DATA . '/gshop_feed.xml';
    $last = (string)kv_db()->query('SELECT MAX(updated_at) FROM products')->fetchColumn();
    $age = is_file($path) ? time() - filemtime($path) : PHP_INT_MAX;
    // 取り扱い終了（商品の削除）は updated_at を動かさないので、商品の変更記録の更新でも作り直す（2026-11-21）
    $plog = KV_DATA . '/product_log.jsonl';
    // 広告から外す指定（メーカー・商品）を変えたときも作り直す（2026-11-26 指定を足してもフィードが6時間変わらなかった）
    $newer = false;
    foreach ([$plog, KV_DATA . '/gshop_noad_makers.json', KV_DATA . '/gshop_noad_products.json'] as $f) {
        if (is_file($f) && is_file($path) && filemtime($f) > filemtime($path)) { $newer = true; }
    }
    if ($age > 6 * 3600 || ($last !== '' && strtotime($last) > filemtime($path)) || $newer) {
        @set_time_limit(120);
        kv_gshop_build($path);
    }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
}
