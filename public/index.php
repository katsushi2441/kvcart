<?php
/**
 * Kurage Vibe-Cart — 店のフロント（1本のルーター）。
 *
 * **おちゃのこネットと同じURLを出す。** ここを変えるとブックマークと検索インデックスが切れる。
 *   /product/<数値ID>     商品詳細（IDは移行元のまま）
 *   /product-group/<ID>   グループ（メーカー）
 *   /product-list/<ID>    カテゴリ
 *   /page/<n>             固定ページ
 *   /info /help /contact  固定ページ
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
    if (preg_match('#^/page/(\d+)$#', $path, $m)) { kv_page_static('page' . $m[1]); return; }
    switch ($path) {
        case '/':         kv_page_top(); return;
        case '/makers':   kv_page_makers(); return;
        case '/search':   kv_page_search($page); return;
        case '/cart':     kv_page_cart(); return;
        case '/checkout': kv_page_checkout(); return;
        case '/thanks':   kv_page_thanks(); return;
        case '/info':
        case '/help':
        case '/contact':  kv_page_static(ltrim($path, '/')); return;
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
            '/');
    echo '<div class="wrap">';
    echo '<h1>' . kv_e(kv_shop('name')) . '</h1>'
       . '<p class="src">' . kv_e(kv_shop('tagline')) . '　／　取扱 '
       . number_format($s['products']) . '点・' . $s['makers'] . 'メーカー</p>';
    echo '<h2>メーカーから探す</h2><div class="makers">';
    foreach (kv_makers() as $mk) {
        echo '<a href="' . kv_url('product-group/' . (int)$mk['id']) . '">' . kv_e($mk['name'])
           . ' <span class="src">' . number_format((int)$mk['cnt']) . '</span></a>';
    }
    echo '</div>';
    echo '<h2>新着</h2><div class="grid">';
    foreach ($r['items'] as $p) { kv_card($p); }
    echo '</div></div>';
    kv_footer();
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
    $desc = trim(mb_substr(strip_tags((string)$p['description']), 0, 140));
    $jsonld = [
        '@context' => 'https://schema.org', '@type' => 'Product',
        'name' => $p['name'], 'sku' => $p['model_number'] ?: (string)$p['id'],
        'image' => $p['image_url'] ?: null,
        'brand' => $mk ? ['@type' => 'Brand', 'name' => $mk['name']] : null,
        'offers' => $incl === null ? null : [
            '@type' => 'Offer', 'price' => (string)$incl, 'priceCurrency' => 'JPY',
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
    echo '<div class="img">' . ($p['image_url'] ? '<img src="' . kv_e(kv_img($p['image_url'])) . '" alt="">' : '') . '</div>';
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
        echo '<form method="post" action="' . kv_url('cart') . '">'
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
        echo '<h2>商品説明</h2><div class="panel desc">' . $p['description'] . '</div>';
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

function kv_page_static(string $key): void
{
    $pages = kv_static_pages();
    $pg = $pages[$key] ?? null;
    if (!$pg) {
        http_response_code(404);
        kv_head('ページが見つかりません', '', '/' . $key, ['noindex' => true]);
        echo '<div class="wrap"><h1>ページが見つかりません</h1></div>';
        kv_footer();
        return;
    }
    kv_head($pg['title'], $pg['desc'] ?? '', '/' . $key);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › '
       . kv_e($pg['title']) . '</nav><h1>' . kv_e($pg['title']) . '</h1>'
       . '<div class="panel desc">' . $pg['html'] . '</div></div>';
    kv_footer();
}

/** 固定ページ。おちゃのこネットの /info /help /page/N を引き継ぐ。 */
function kv_static_pages(): array
{
    $bank = nl2br(kv_e(kv_shop('bank')));
    return [
        'info' => ['title' => '特定商取引法に基づく表記', 'desc' => '販売事業者・支払方法・送料・返品について。',
            'html' => '<table class="t">'
                . '<tr><th>販売事業者</th><td>' . kv_e(kv_shop('company')) . '</td></tr>'
                . '<tr><th>所在地</th><td>' . kv_e(kv_shop('address')) . '</td></tr>'
                . '<tr><th>連絡先</th><td>' . kv_e(kv_shop('email')) . ' ' . kv_e(kv_shop('tel')) . '</td></tr>'
                . '<tr><th>支払方法</th><td>銀行振込（前払い）' . (kv_stripe_ready() ? '／クレジットカード' : '') . '</td></tr>'
                . '<tr><th>商品の引渡時期</th><td>ご入金確認後に発送します。お取り寄せ品は納期をご案内します。</td></tr>'
                . '<tr><th>返品・交換</th><td>商品の不良・誤配送は当社負担で交換します。お客様都合の返品は未開封のものに限ります。</td></tr>'
                . '<tr><th>送料</th><td>' . kv_e(kv_shop('ship_note')) . '</td></tr></table>'],
        'help' => ['title' => 'ご利用案内', 'desc' => 'ご注文からお届けまでの流れ。',
            'html' => '<h2>ご注文の流れ</h2><ol>'
                . '<li>商品をカートに入れて、お客様情報を入力します</li>'
                . '<li>注文確認メールが届きます（振込先を記載します）</li>'
                . '<li>お振込みを確認したら発送します</li></ol>'
                . '<h2>お支払い</h2><p>銀行振込（前払い）です。'
                . (kv_stripe_ready() ? 'クレジットカードもご利用いただけます。' : '')
                . '</p><div class="panel">' . $bank . '</div>'],
        'contact' => ['title' => 'お問い合わせ', 'desc' => '商品・納期・お見積りについて。',
            'html' => '<p>商品の在庫・納期・お見積りはお気軽にどうぞ。'
                . '掲載していない商品もお取り寄せできる場合があります。</p>'
                . '<p>メール: <a href="mailto:' . kv_e(kv_shop('email')) . '">' . kv_e(kv_shop('email')) . '</a>'
                . (kv_shop('tel') ? '<br>電話: ' . kv_e(kv_shop('tel')) : '') . '</p>'],
    ];
}
