<?php
/**
 * Google Merchant Center の商品フィード（/google-shopping.xml・2026-11-17）
 *
 * Google ショッピング（無料リスティング・ショッピング タブ）に載せるため、Merchant Center にこの URL を
 * 「スケジュールされた取得」で登録する。形式は RSS 2.0 + g: 名前空間（Google の商品データ仕様）。
 *
 * - 載せるのは、表示中・価格あり・お問い合わせ品でない・画像のある商品（画像は Google の必須項目）
 * - 価格は税込（日本の表示の決まり）。送料は全国0円（北海道・沖縄・離島は Merchant Center の送料設定で別に決める）
 * - 在庫: 在庫数を管理しない直送品と在庫ありは in_stock、在庫切れでも受ける商品は backorder（入荷予定日は14日後）、
 *   受けない在庫切れは out_of_stock（kv_stock.php の設定に合わせる）
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
    $cats = []; foreach ($db->query('SELECT id,name,parent_name FROM categories') as $c) { $cats[(int)$c['id']] = trim(($c['parent_name'] ? $c['parent_name'] . ' > ' : '') . $c['name']); }
    $base = rtrim(defined('KV_ORIGIN') ? KV_ORIGIN : kv_site_url(''), '/');
    $tmp = $path . '.tmp';
    $fh = fopen($tmp, 'w');
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
        . '<title>' . $x(kv_shop('name')) . '</title><link>' . $x($base . '/') . '</link><description>' . $x(kv_shop('tagline')) . "</description>\n");
    $n = 0; $skip = ['画像なし' => 0];
    $bo = date('Y-m-d', strtotime('+14 days')) . 'T00:00+0900';
    $st = $db->query("SELECT * FROM products WHERE hidden=0 AND price>0 AND COALESCE(price_unspecified,0)=0 ORDER BY id");
    while ($p = $st->fetch()) {
        if ((string)$p['image_url'] === '') { $skip['画像なし']++; continue; }
        $title = kv_gshop_text((string)$p['name'], 150);
        $mk = $makers[(int)$p['maker_id']] ?? '';
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
              . '<link>' . $x($base . '/product/' . (int)$p['id']) . '</link><g:image_link>' . $x(kv_abs((string)$p['image_url'])) . '</g:image_link>'
              . '<g:availability>' . $avail . '</g:availability>' . ($avail === 'backorder' ? '<g:availability_date>' . $bo . '</g:availability_date>' : '')
              . '<g:price>' . (int)$incl . ' JPY</g:price><g:condition>new</g:condition>'
              . ($mk !== '' ? '<g:brand>' . $x($mk) . '</g:brand>' : '')
              . ($jan !== '' ? '<g:gtin>' . $jan . '</g:gtin>' : '')
              . ($mdl !== '' ? '<g:mpn>' . $x($mdl) . '</g:mpn>' : '')
              . ($jan === '' && ($mdl === '' || $mk === '') ? '<g:identifier_exists>no</g:identifier_exists>' : '')
              . (isset($cats[(int)$p['category_id']]) ? '<g:product_type>' . $x(($mk !== '' ? '' : '') . $cats[(int)$p['category_id']]) . '</g:product_type>' : '')
              . '<g:shipping><g:country>JP</g:country><g:price>0 JPY</g:price></g:shipping>'
              . "</item>\n";
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
    if ($age > 6 * 3600 || ($last !== '' && strtotime($last) > filemtime($path))) {
        @set_time_limit(120);
        kv_gshop_build($path);
    }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
}
