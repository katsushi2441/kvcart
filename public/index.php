<?php
/**
 * Kurage Vibe-Cart — 店のフロント（1本のルーター）。
 *
 * **おちゃのこネットと同じURLを出す。** ここを変えるとブックマークと検索インデックスが切れる。
 *   /product/<数値ID>     商品詳細（IDは移行元のまま）
 *   /product-group/<ID>   グループ（メーカー）
 *   /product-list/<ID>    カテゴリ
 *   /page/<n>             固定ページ（/page/1 店の案内・/page/16 FAQ）
 *   /info /help /contact  固定ページ
 *   /terms /privacy       ご利用規約・プライバシーポリシー（現行サイトに単独ページが無いので新設）
 *   /manual               店の運営マニュアル（noindex・管理画面から開く）
 *
 * 移行しなかった商品は **404を返さない**。「取り扱いを終了しました」を出して、
 * 同じメーカー・同じカテゴリの現行商品へ案内する（40,922件ぶんのブックマークを落とさない）。
 */
declare(strict_types=1);

require __DIR__ . '/kv_shop.php';
require __DIR__ . '/kv_session.php';
require __DIR__ . '/kv_lib.php';
require __DIR__ . '/kv_ui.php';
require __DIR__ . '/kv_order.php';
require __DIR__ . '/kv_cart.php';
require __DIR__ . '/kv_pages.php';

// **セッションは出力より前に始める。** kv_head() を呼んだ後に session_start() すると
// 「headers already sent」でクッキーが出ず、CSRFが毎回外れる（実測で踏んだ）。
kv_session();

// パスの取り出し。**環境によって PATH_INFO が来たり来なかったりする**ので、
// どちらから取っても最後に必ず KV_BASE を剥がす。
// （PATH_INFO があるときに剥がし忘れて、サブパス配置だけ全部404になった。2026-09-14）
$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '' && isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
}
if (KV_BASE !== '' && strpos($path, KV_BASE) === 0) {
    $path = substr($path, strlen(KV_BASE));
}
$path = '/' . trim($path, '/');
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    kv_route($path, $page);
} catch (RuntimeException $e) {
    http_response_code(500);
    kv_head('準備中', '', $path, ['noindex' => true]);
    echo '<div class="wrap"><h1>準備中です</h1><div class="alert">' . kv_e($e->getMessage()) . '</div></div>';
    kv_footer();
}

function kv_route(string $path, int $page): void
{
    // ---- 商品詳細（最重要） ----
    if (preg_match('#^/product/(\d+)$#', $path, $m)) { kv_page_product((int)$m[1]); return; }
    // ---- グループ（メーカー） ----
    if (preg_match('#^/product-group/(\d+)$#', $path, $m)) { kv_page_group((int)$m[1], $page); return; }
    // ---- カテゴリ ----
    if (preg_match('#^/product-list/(\d+)$#', $path, $m)) { kv_page_category((int)$m[1], $page); return; }
    // ---- 固定ページ ----
    if (preg_match('#^/page/(\d+)$#', $path, $m)) { kv_page_static('page' . $m[1], $path); return; }
    switch ($path) {
        case '/sitemap.xml': kv_sitemap(); return;
        case '/robots.txt':  kv_robots(); return;
        case '/llms.txt':    kv_llms(); return;
        case '/':         kv_page_top(); return;
        case '/makers':   kv_page_makers(); return;
        case '/search':   kv_page_search($page); return;
        // 購入手続き。**おちゃのこネットと同じURL・同じ4段**にする
        case '/cart':          kv_page_cart(); return;
        case '/cart/customer': kv_page_customer(); return;   // STEP 1 購入者
        case '/cart/delivery': kv_page_delivery(); return;   // STEP 2 お届け先・お支払い
        case '/cart/confirm':  kv_page_confirm(); return;    // STEP 3 確認
        case '/cart/complete': kv_page_complete(); return;   // STEP 4 完了
        // 旧実装のURL。踏まれても落とさずカートへ返す
        case '/checkout':
        case '/thanks':        header('Location: ' . kv_url('cart')); return;
        case '/contact':  kv_page_contact(); return;
        case '/info':
        case '/help':
        case '/terms':
        case '/privacy':  kv_page_static(ltrim($path, '/'), $path); return;
        case '/manual':   kv_page_manual(); return;
    }
    // メーカーのslugでも引けるようにしておく（exbridge.jp/xdirect/ からの導線）
    if (preg_match('#^/maker/([a-z0-9_-]+)$#', $path, $m)) {
        $mk = kv_maker_by_slug($m[1]);
        if ($mk) { kv_page_group((int)$mk['id'], $page); return; }
    }
    http_response_code(404);
    kv_head('ページが見つかりません', '', $path, ['noindex' => true]);
    echo '<div class="wrap"><h1>ページが見つかりません</h1>'
       . '<p><a class="btn" href="' . kv_url('') . '">トップへ</a></p></div>';
    kv_footer();
}

function kv_page_top(): void
{
    $s = kv_stats();
    $r = kv_products([], 1, 24);
    kv_head(kv_shop('name') . ' ' . kv_shop('tagline'),
            '機械工具・計測機器・高圧洗浄機・バッテリーなどを直送・格安で。取扱' . number_format($s['products']) . '点。',
            '/', ['jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'OnlineStore', 'name' => kv_shop('name'), 'url' => kv_base_url() . '/',
                 'logo' => kv_abs('img/brand/favicon.png'), 'email' => kv_shop('email'), 'telephone' => kv_shop('tel'),
                 'parentOrganization' => ['@type' => 'Organization', 'name' => kv_shop('company')]],
                ['@type' => 'WebSite', 'name' => kv_shop('name'), 'url' => kv_base_url() . '/',
                 'potentialAction' => ['@type' => 'SearchAction', 'target' => kv_base_url() . '/search?q={q}', 'query-input' => 'required name=q']],
            ]]]);
    echo '<div class="wrap">';
    // おちゃのこネットから自社システムへ移したことを、トップで一番目立たせる（2026-11-15 依頼）。
    // X-Direct（exdirect.net）だけのお知らせ。配布版を使う店には出さない
    if (defined('KV_ORIGIN') && strpos(KV_ORIGIN, 'exdirect.net') !== false) {
    echo '<section class="migrated">'
       . '<p class="badge">システム移行のお知らせ</p>'
       . '<h1>exdirect.net は、おちゃのこネットから<br>自社開発のECシステムへ移行しました</h1>'
       . '<p class="lead">' . kv_e(kv_shop('name')) . '（exdirect.net）は、ネットショップ作成サービス「おちゃのこネット」で運営してきた店を、'
       . '当社が開発したECシステム <a href="https://kappstore.exbridge.jp/app.php?id=754c7ddb5f1c8f26#ref=exdirect-top">Kurage Vibe-Cart</a> に移しました。</p>'
       . '<ul class="points">'
       . '<li><b>商品ページのURLはそのまま</b>ブックマークや検索結果から、これまでどおり開けます</li>'
       . '<li><b>商品番号もそのまま</b>/product/番号 の番号はおちゃのこネットのときと同じです</li>'
       . '<li><b>取扱 ' . number_format($s['products']) . '点・' . $s['makers'] . 'メーカー</b>' . kv_e(kv_shop('tagline')) . '</li>'
       . '</ul>'
       // 平行稼働中は旧店舗（おちゃのこネット）も開いている。移していない商品はそちらで買える（2026-11-15）
       . '<p class="oldshop"><b>旧店舗（おちゃのこネット版）も、しばらくのあいだ並行して営業しています。</b>'
       . 'こちらに無い商品は、旧店舗でお探しください。　'
       . '<a href="https://xdirect.ocnk.net/">旧店舗 xdirect.ocnk.net を開く</a></p>'
       . '</section>';
    } else {
        echo '<h1>' . kv_e(kv_shop('name')) . '</h1><p class="src">' . kv_e(kv_shop('tagline')) . '　／　取扱 '
           . number_format($s['products']) . '点・' . $s['makers'] . 'メーカー</p>';
    }
    echo '<h2>メーカーから探す</h2><div class="makers">';
    foreach (kv_makers() as $mk) {
        echo '<a href="' . kv_url('product-group/' . (int)$mk['id']) . '">' . kv_e($mk['name'])
           . ' <span class="src">' . number_format((int)$mk['cnt']) . '</span></a>';
    }
    echo '</div>';
    // 最近売れた商品・よく売れている商品（2026-11-16 ユーザー指示）
    [$recent, $popular] = kv_sales_lists(12);
    if ($recent) {
        echo '<h2>最近売れた商品</h2><div class="grid">';
        foreach ($recent as [$p, $it]) { kv_card($p, (int)substr($it['last'], 5, 2) . '月' . (int)substr($it['last'], 8, 2) . '日に注文'); }
        echo '</div>';
    }
    if ($popular) {
        echo '<h2>よく売れている商品</h2><p class="src">この1年の注文回数が多い順です。</p><div class="grid">';
        foreach ($popular as [$p, $it]) { kv_card($p, 'この1年で' . (int)$it['orders'] . '回注文'); }
        echo '</div>';
    }
    echo '<h2>新着</h2><div class="grid">';
    foreach ($r['items'] as $p) { kv_card($p); }
    echo '</div></div>';
    kv_footer();
}

/**
 * 売れた記録（2026-11-16）。おちゃのこ時代の分は kv_data/sales_history.json（scripts/build_sales_history.py が作る）、
 * この店に入った注文は orders・order_items からその場で足す。お客さまの情報は使わない。
 * 返り値: [最近売れた順, 注文回数の多い順]。どちらも [商品, ['last'=>日付,'orders'=>回数,'qty'=>個数]] の並び。
 * 今この店で売っている（表示している）商品だけを出す。
 */
function kv_sales_lists(int $n = 12): array
{
    $items = [];
    $f = __DIR__ . '/kv_data/sales_history.json';
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        foreach (($j['items'] ?? []) as $pid => $it) { $items[(int)$pid] = $it; }
    }
    try {
        $since = date('Y-m-d', strtotime('-365 days'));
        $st = kv_db()->prepare("SELECT i.product_id pid, MAX(substr(o.created_at,1,10)) last, COUNT(DISTINCT o.id) n, SUM(i.qty) q"
            . " FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.cancelled=0 AND o.created_at>=? GROUP BY i.product_id");
        $st->execute([$since]);
        foreach ($st->fetchAll() as $r) {
            $pid = (int)$r['pid'];
            $it = $items[$pid] ?? ['last' => '', 'orders' => 0, 'qty' => 0];
            $it['last'] = max((string)$it['last'], (string)$r['last']);
            $it['orders'] += (int)$r['n'];
            $it['qty'] += (int)$r['q'];
            $items[$pid] = $it;
        }
    } catch (Throwable $e) { /* まだ注文の表が無いときは、おちゃのこの分だけ */ }
    if (!$items) { return [[], []]; }
    $ids = array_keys($items);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = kv_db()->prepare("SELECT * FROM products WHERE id IN ($in) AND hidden=0");
    $st->execute($ids);
    $rows = [];
    foreach ($st->fetchAll() as $p) { $rows[] = [$p, $items[(int)$p['id']]]; }
    $recent = $rows;
    usort($recent, fn($a, $b) => strcmp($b[1]['last'], $a[1]['last']));
    $popular = $rows;
    usort($popular, fn($a, $b) => [$b[1]['orders'], $b[1]['qty'], $b[1]['last']] <=> [$a[1]['orders'], $a[1]['qty'], $a[1]['last']]);
    return [array_slice($recent, 0, $n), array_slice($popular, 0, $n)];
}

function kv_page_makers(): void
{
    kv_head('メーカー一覧', '取り扱いメーカーの一覧です。', '/makers');
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › メーカー一覧</nav>'
       . '<h1>メーカー一覧</h1><div class="scroll"><table class="t">'
       . '<tr><th>メーカー</th><th>取扱点数</th></tr>';
    foreach (kv_makers() as $mk) {
        echo '<tr><td><a href="' . kv_url('product-group/' . (int)$mk['id']) . '">' . kv_e($mk['name'])
           . '</a></td><td>' . number_format((int)$mk['cnt']) . '</td></tr>';
    }
    echo '</table></div></div>';
    kv_footer();
}

function kv_page_group(int $id, int $page): void
{
    $mk = kv_maker($id);
    if (!$mk) {
        // 移行していないグループのURL。404にせず一覧へ案内する
        http_response_code(404);
        kv_head('このグループは現在ご覧いただけません', '', '/product-group/' . $id, ['noindex' => true]);
        echo '<div class="wrap"><h1>このグループは現在ご覧いただけません</h1>'
           . '<p>取り扱いメーカーを見直しました。いまのお取り扱いは下記です。</p>'
           . '<p><a class="btn" href="' . kv_url('makers') . '">メーカー一覧を見る</a></p></div>';
        kv_footer();
        return;
    }
    $r = kv_products(['maker_id' => $id], $page, 24);
    kv_head($mk['name'] . 'の商品一覧',
            $mk['name'] . 'の取扱商品' . number_format($r['total']) . '点。直送・格安で。',
            '/product-group/' . $id);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › '
       . '<a href="' . kv_url('makers') . '">メーカー</a> › ' . kv_e($mk['name']) . '</nav>'
       . '<h1>' . kv_e($mk['name']) . '</h1>'
       . '<p class="src">' . number_format($r['total']) . '点</p><div class="grid">';
    foreach ($r['items'] as $p) { kv_card($p); }
    echo '</div>';
    kv_pager($r, kv_url('product-group/' . $id));
    echo '</div>';
    kv_footer();
}

function kv_page_category(int $id, int $page): void
{
    $cat = kv_category($id);
    $r = kv_products(['category_id' => $id], $page, 24);
    if (!$cat && $r['total'] === 0) {
        http_response_code(404);
        kv_head('このカテゴリは現在ご覧いただけません', '', '/product-list/' . $id, ['noindex' => true]);
        echo '<div class="wrap"><h1>このカテゴリは現在ご覧いただけません</h1>'
           . '<p><a class="btn" href="' . kv_url('makers') . '">メーカーから探す</a></p></div>';
        kv_footer();
        return;
    }
    $name = $cat['name'] ?? 'カテゴリ';
    kv_head($name . 'の商品一覧', $name . 'の取扱商品' . number_format($r['total']) . '点。',
            '/product-list/' . $id);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › '
       . ($cat['parent_name'] ?? '') . ($cat['parent_name'] ? ' › ' : '') . kv_e($name) . '</nav>'
       . '<h1>' . kv_e($name) . '</h1>'
       . '<p class="src">' . number_format($r['total']) . '点</p><div class="grid">';
    foreach ($r['items'] as $p) { kv_card($p); }
    echo '</div>';
    kv_pager($r, kv_url('product-list/' . $id));
    echo '</div>';
    kv_footer();
}

function kv_page_search(int $page): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $r = $q === '' ? ['items' => [], 'total' => 0, 'page' => 1, 'per' => 24, 'pages' => 0]
                   : kv_products(['q' => $q], $page, 24);
    kv_head($q === '' ? '商品検索' : '「' . $q . '」の検索結果',
            '商品名・型番で検索できます。', '/search', ['noindex' => true]);
    echo '<div class="wrap"><h1>' . ($q === '' ? '商品検索' : '「' . kv_e($q) . '」の検索結果') . '</h1>';
    if ($q !== '') { echo '<p class="src">' . number_format($r['total']) . '件</p>'; }
    echo '<div class="grid">';
    foreach ($r['items'] as $p) { kv_card($p); }
    echo '</div>';
    if ($q !== '' && $r['total'] === 0) {
        echo '<div class="note">見つかりませんでした。型番の一部だけで探すと見つかることがあります。'
           . '<a href="' . kv_url('makers') . '">メーカーから探す</a>こともできます。</div>';
    }
    kv_pager($r, kv_url('search') . '?q=' . rawurlencode($q));
    echo '</div>';
    kv_footer();
}

function kv_page_product(int $id): void
{
    $p = kv_product($id);
    if (!$p) { kv_page_retired($id); return; }
    $mk = $p['maker_id'] ? kv_maker((int)$p['maker_id']) : null;
    $cat = $p['category_id'] ? kv_category((int)$p['category_id']) : null;
    $incl = kv_price_incl($p);
    $desc = trim(mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(kv_clean_desc((string)$p['description'])))), 0, 140));
    $jsonld = [
        '@context' => 'https://schema.org', '@type' => 'Product',
        'name' => $p['name'], 'sku' => $p['model_number'] ?: (string)$p['id'],
        'image' => $p['image_url'] ? kv_abs((string)$p['image_url']) : null,
        'url' => kv_base_url() . '/product/' . $id,
        'brand' => $mk ? ['@type' => 'Brand', 'name' => $mk['name']] : null,
        'offers' => $incl === null ? null : [
            '@type' => 'Offer', 'price' => (string)$incl, 'priceCurrency' => 'JPY',
            'url' => kv_base_url() . '/product/' . $id, 'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => kv_shop('company')],
            'availability' => (int)$p['stock_unlimited'] === 1 || (int)$p['stock'] > 0
                ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        ],
    ];
    kv_head($p['name'], $desc, '/product/' . $id,
            ['og_type' => 'product', 'image' => $p['image_url'], 'jsonld' => array_filter($jsonld)]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a>'
       . ($mk ? ' › <a href="' . kv_url('product-group/' . (int)$mk['id']) . '">' . kv_e($mk['name']) . '</a>' : '')
       . ($cat ? ' › <a href="' . kv_url('product-list/' . (int)$cat['id']) . '">' . kv_e($cat['name']) . '</a>' : '')
       . '</nav>';
    echo '<h1>' . kv_e($p['name']) . '</h1><div class="pd">';
    echo '<div class="img">' . ($p['image_url'] ? '<img src="' . kv_e(kv_img($p['image_url'])) . '" alt="' . kv_e($p['name']) . '">' : '') . '</div>';
    echo '<div><div class="panel">';
    if ($p['model_number']) { echo '<p class="src">型番 ' . kv_e($p['model_number']) . '</p>'; }
    if ($incl === null) {
        echo '<p class="price-big"><small>価格はお問い合わせください</small></p>';
    } else {
        if ($p['list_price'] && (int)$p['list_price'] > (int)$p['price']) {
            echo '<p class="strike">メーカー希望小売価格 ' . number_format((int)$p['list_price']) . '円</p>';
        }
        echo '<p class="price-big">' . number_format($incl) . '円<small> 税込（本体 '
           . number_format((int)$p['price']) . '円）</small></p>';
    }
    $in = ((int)$p['stock_unlimited'] === 1) || ((int)$p['stock'] > 0);
    echo '<p class="src">' . ($in ? '在庫あり／お取り寄せ' : '在庫を確認します') . '</p>';
    if ($incl !== null) {
        // オプション（おちゃのこネットの「オプション」と同じ。選ばないとカートに入らない）
        $opt_html = '';
        $vjson = json_decode((string)($p['variations'] ?? ''), true);
        foreach (kv_variations($p) as $i => $o) {
            $opt_html .= '<label class="src" style="display:block;margin:0 0 8px">' . kv_e($o['label'])
                . '<br><select name="opt[' . $i . ']" required style="width:100%;max-width:360px;padding:8px;border:2px solid #cfdae4;border-radius:8px">'
                . '<option value="">選択してください</option>';
            foreach ($o['values'] as $j => $val) {
                $extra = '';
                if (count(kv_variations($p)) === 1) {   // 項目が1つのときだけ、組み合わせ別の価格・品切れを横に出す
                    foreach (($vjson['data'] ?? []) as $d) {
                        if ((string)($d['option1'] ?? '') !== $val) { continue; }
                        if (empty($d['stock_unlimited']) && $d['stock'] !== null && (int)$d['stock'] <= 0) { $extra = '（品切れ）'; }
                        elseif ($d['price'] !== null) { $extra = '（' . number_format((int)floor((int)$d['price'] * 1.1)) . '円 税込）'; }
                    }
                }
                $opt_html .= '<option value="' . $j . '"' . ($extra === '（品切れ）' ? ' disabled' : '') . '>'
                          . kv_e($val . $extra) . '</option>';
            }
            $opt_html .= '</select></label>';
        }
        if ($opt_html !== '' && !empty($_GET['need_option'])) {
            $opt_html = '<div class="alert">オプションを選んでから「カートに入れる」を押してください。</div>' . $opt_html;
        }
        echo '<form method="post" action="' . kv_url('cart') . '">' . $opt_html
           . '<input type="hidden" name="add" value="' . (int)$p['id'] . '">'
           . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
           . '<label class="src">数量 <input type="number" name="qty" value="1" min="1" max="99" '
           . 'style="width:70px;padding:7px;border:2px solid #cfdae4;border-radius:8px"></label> '
           . '<button class="btn" type="submit">カートに入れる</button></form>';
    } else {
        echo '<p><a class="btn ghost" href="' . kv_url('contact') . '?pid=' . (int)$p['id'] . '">この商品について問い合わせる</a></p>';
    }
    echo '<p class="src" style="margin-top:12px">' . kv_e(kv_shop('ship_note')) . '</p>';
    echo '</div></div></div>';
    if ($p['description']) {
        echo '<h2>商品説明</h2><div class="panel desc">' . kv_clean_desc((string)$p['description']) . '</div>';
    }
    echo '</div>';
    kv_footer();
}

/**
 * 移行しなかった商品のURL。**404を返さない。**
 * 旧サイトのブックマーク・検索結果から来た人を、同じメーカーの現行商品へ渡す。
 */
function kv_page_retired(int $id): void
{
    $r = kv_retired($id);
    http_response_code(410);   // Gone。「無い」ではなく「終了した」を正しく伝える
    if (!$r) {
        kv_head('この商品は見つかりません', '', '/product/' . $id, ['noindex' => true]);
        echo '<div class="wrap"><h1>この商品は見つかりません</h1>'
           . '<p><a class="btn" href="' . kv_url('makers') . '">メーカーから探す</a></p></div>';
        kv_footer();
        return;
    }
    $sug = kv_suggest($r, 12);
    kv_head(($r['name'] ?: 'この商品') . '（取り扱い終了）',
            'この商品は取り扱いを終了しました。同じメーカーの取扱商品をご案内します。',
            '/product/' . $id, ['noindex' => true]);
    echo '<div class="wrap"><h1>' . kv_e($r['name'] ?: 'この商品') . '</h1>'
       . '<div class="note"><b>この商品は取り扱いを終了しました。</b><br>'
       . 'お探しいただきありがとうございます。取り扱いメーカーを見直したため、この商品はご購入いただけません。'
       . ($r['model_number'] ? '<br>型番: ' . kv_e($r['model_number']) : '')
       . ($r['maker_name'] ? '<br>メーカー: ' . kv_e($r['maker_name']) : '')
       . '</div>';
    if ($sug) {
        // 同メーカー→同カテゴリ→親カテゴリ→現行商品、の順で出している（kv_suggest）
        $same = $r['maker_name'] && kv_maker_name_exists((string)$r['maker_name']);
        echo '<h2>' . ($same ? kv_e($r['maker_name']) . 'の取扱商品' : 'いまお取り扱いしている商品') . '</h2><div class="grid">';
        foreach ($sug as $p) { kv_card($p); }
        echo '</div>';
    }
    echo '<p style="margin-top:20px"><a class="btn" href="' . kv_url('makers') . '">メーカー一覧を見る</a> '
       . '<a class="btn ghost" href="' . kv_url('contact') . '?pid=' . $id . '">この商品について問い合わせる</a></p>'
       . '</div>';
    kv_footer();
}

function kv_page_static(string $key, string $path = ''): void
{
    // canonical は **リクエストされたURLそのもの**を使う。key ('page16') を
    // そのまま '/' に足すと /page16 という存在しないURLを canonical に書いてしまう。
    $path = $path !== '' ? $path : '/' . $key;
    $pages = kv_static_pages();
    $pg = $pages[$key] ?? null;
    if (!$pg) {
        http_response_code(404);
        kv_head('ページが見つかりません', '', $path, ['noindex' => true]);
        echo '<div class="wrap"><h1>ページが見つかりません</h1>'
           . '<p><a class="btn" href="' . kv_url('') . '">トップへ</a></p></div>';
        kv_footer();
        return;
    }
    $ld = ($key === 'page16' && !empty($GLOBALS['kv_faq_ld']))
        ? ['jsonld' => ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $GLOBALS['kv_faq_ld']]] : [];
    kv_head($pg['title'], $pg['desc'] ?? '', $path, $ld);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ '
       . kv_e($pg['title']) . '</nav>';
    if (($pg['layout'] ?? '') === 'ocnk') {
        // 現行 exdirect.net と同じ見出し帯＋本文枠（.ptitle / .pmiddle）
        // 帯そのものを h1 にする（見た目は現行どおり・見出しは1つだけ）
        echo '<h1 class="ptitle">' . kv_e($pg['title']) . '</h1>'
           . '<div class="pmiddle">' . $pg['html'] . '</div>';
    } else {
        echo '<h1>' . kv_e($pg['title']) . '</h1>'
           . '<div class="panel desc">' . $pg['html'] . '</div>';
    }
    echo '</div>';
    kv_footer();
}


/** 正本の起点URL（https://www.exdirect.net）。KV_ORIGIN が無ければ、開かれたドメイン＋KV_BASE。 */
function kv_origin(): string
{
    return defined('KV_ORIGIN') ? rtrim(KV_ORIGIN, '/')
                                : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'exbridge.jp') . KV_BASE;
}

/**
 * サイトマップ（2026-11-15。exdirect.net を切り替えたら404になっていた）。
 * 出すのは表示できるページだけ（取り扱い終了の410は入れない）。lastmod は商品の更新日から取り、
 * 呼ぶたびに今日の日付を打たない（毎回 now だと Google が lastmod を信用しなくなる）。
 */
function kv_sitemap(): void
{
    $db = kv_db();
    $u = [];
    $u[] = ['/', (string)$db->query("SELECT MAX(updated_at) FROM products WHERE hidden=0")->fetchColumn()];
    foreach ($db->query("SELECT id, updated_at FROM products WHERE hidden=0 ORDER BY id") as $r) {
        $u[] = ['/product/' . (int)$r['id'], (string)$r['updated_at']];
    }
    foreach ($db->query("SELECT maker_id, MAX(updated_at) m FROM products WHERE hidden=0 AND maker_id IS NOT NULL GROUP BY maker_id") as $r) {
        $u[] = ['/product-group/' . (int)$r['maker_id'], (string)$r['m']];
    }
    foreach ($db->query("SELECT category_id, MAX(updated_at) m FROM products WHERE hidden=0 AND category_id IS NOT NULL GROUP BY category_id") as $r) {
        $u[] = ['/product-list/' . (int)$r['category_id'], (string)$r['m']];
    }
    // 固定ページは中身を変えた日（kv_pages.php を直したらここも直す）
    foreach (['/makers', '/info', '/help', '/terms', '/privacy', '/page/1', '/page/16'] as $sp) {
        $u[] = [$sp, '2026-11-15'];
    }
    header('Content-Type: application/xml; charset=utf-8');
    $o = kv_origin();
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($u as [$path, $mod]) {
        echo '<url><loc>' . htmlspecialchars($o . $path, ENT_XML1) . '</loc>'
           . ($mod !== '' ? '<lastmod>' . substr($mod, 0, 10) . '</lastmod>' : '') . "</url>\n";
    }
    echo "</urlset>\n";
}

/**
 * robots.txt。ChatGPT（GPTBot・OAI-SearchBot・ChatGPT-User）は許可する（2026-11-15 ユーザー判断
 * 「ChatGPT の答えに商品を出したい」）。名指しの節は * の節を置き換えるので、除外の指定も書き写す。
 * おちゃのこネットのときに拒否していた Bytespider・TikTokSpider・meta-externalagent は拒否のまま。
 */
function kv_robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $deny = "Disallow: /admin.php\nDisallow: /cart\nDisallow: /search\n";
    echo "User-agent: GPTBot\nUser-agent: OAI-SearchBot\nUser-agent: ChatGPT-User\n" . $deny . "\n"
       . "User-agent: Bytespider\nUser-Agent: TikTokSpider\nUser-agent: meta-externalagent\nDisallow: /\n\n"
       . "User-agent: *\n" . $deny . "\n"
       . 'Sitemap: ' . kv_origin() . "/sitemap.xml\n";
}

/** llms.txt（AIの検索・回答向けの案内。2026-11-15） */
function kv_llms(): void
{
    $s = kv_stats();
    $o = kv_origin();
    header('Content-Type: text/plain; charset=utf-8');
    echo '# ' . kv_shop('name') . "\n\n"
       . '> ' . kv_shop('company') . 'が運営する、機械工具・計測機器・厨房機器・バッテリー・インバータなどの通信販売。'
       . 'メーカー・仕入先から直送。取扱 ' . number_format($s['products']) . '点・' . $s['makers'] . "メーカー。\n\n"
       . "## 主なページ\n\n"
       . "- [メーカー一覧]($o/makers): 取り扱いメーカーと商品数\n"
       . "- [商品ページ]($o/product/{番号}): 型番・税込価格・メーカー希望小売価格・在庫・商品説明\n"
       . "- [ご利用案内]($o/help): 注文の流れ・送料・お支払い（銀行振込・クレジットカード）\n"
       . "- [特定商取引法表示]($o/info): 販売事業者・返品・お届け\n"
       . "- [よくあるご質問]($o/page/16)\n"
       . "- [お問い合わせ]($o/contact): 掲載していない商品の見積もりも受け付けます\n\n"
       . "## メーカー\n\n";
    foreach (kv_makers() as $mk) {
        echo '- [' . $mk['name'] . "の商品一覧]($o/product-group/" . (int)$mk['id'] . ")\n";
    }
    echo "\n## 注文について\n\n"
       . "- 注文後に在庫・納期・送料を確認してご連絡します。\n"
       . "- お支払いは銀行振込（前払い）と、クレジットカード（確認後にお送りするお支払いページから）。\n"
       . "- 価格は税込表示。商品ページの価格が最新です。\n";
}
