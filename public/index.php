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
require __DIR__ . '/kv_stripe.php';
require __DIR__ . '/kv_pages.php';
require __DIR__ . '/kv_feed.php';   // Google ショッピングの商品フィード
require __DIR__ . '/kv_guide.php';  // 選び方ガイド（/guide・2026-11-19）

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
// おちゃのこネット時代の URL を、同じ中身のページとして出す（2026-11-18）。
// 検索結果に残っているスマホ用の /phone/… と、/product-group/<ID>/0/photo?page=2 のような並べ替え付きの形。
// リダイレクトはせず、そのまま表示する。canonical は各ページが /product/<ID> などの正規の形で出す。
if (preg_match('#^/phone(/.*)?$#', $path, $m)) { $path = $m[1] ?? '/'; if ($path === '') { $path = '/'; } }
if (preg_match('#^/(product-group|product-list)/(\d+)/.+$#', $path, $m)) { $path = '/' . $m[1] . '/' . $m[2]; }
if ($path === '/category') { $path = '/makers'; }
$page = max(1, (int)($_GET['p'] ?? ($_GET['page'] ?? 1)));

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
    // ---- 選び方ガイド（2026-11-19） ----
    if ($path === '/guide') { kv_page_guide_index(); return; }
    if (preg_match('#^/guide/([a-z0-9-]+)$#', $path, $m)) { kv_page_guide($m[1]); return; }
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
        case '/cart/pay':      kv_page_pay(); return;        // カード決済のやり直し
        case '/member':        kv_page_member(); return;     // マイページ（kv_member.php・2026-11-22）
        case '/member/login':
        case '/member-login':  kv_page_member_login(); return;   // /member-login はおちゃのこネット時代の会員ログインのURL
        case '/member/auth':   kv_page_member_auth(); return;    // メールのログイン用リンク
        // 旧実装のURL。踏まれても落とさずカートへ返す
        case '/checkout':
        case '/thanks':        header('Location: ' . kv_url('cart')); return;
        case '/contact':  kv_page_contact(); return;
        case '/restock':  kv_page_restock(); return;   // 再入荷お知らせの登録（kv_stock.php）
        case '/google-shopping.xml':   // Google Merchant Center の商品フィード（kv_feed.php）
        case '/gfeed.xml':   kv_page_gshop_feed(); return;   // gfeed.xml はおちゃのこネット時代に Merchant Center へ登録していたURL（11/16 の切り替えで404 になり Google ショッピングから消えた）
        case '/info':
        case '/help':
        case '/terms':
        case '/privacy':  kv_page_static(ltrim($path, '/'), $path); return;
        case '/manual':   kv_page_manual(); return;
        case '/supplier': kv_page_supplier(); return;   // 商材募集（X-Direct のときだけ）
        case '/why-low-price': kv_page_why_low_price(); return;   // Amazon・楽天・Yahoo!ショッピングより安くできる理由（2026-11-17）
        case '/business-systems': kv_page_systems(); return;      // 業務システム（Kurage App Store へつなぐ・2026-11-17）
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
            ] + (kv_common_faq_ld() ? [2 => ['@type' => 'FAQPage', 'mainEntity' => kv_common_faq_ld()]] : [])]]);
    echo '<div class="wrap">';
    // おちゃのこネットから自社システムへ移したことを、トップで一番目立たせる（2026-11-15 依頼）。
    // X-Direct（exdirect.net）だけのお知らせ。配布版を使う店には出さない
    if (defined('KV_ORIGIN') && strpos(KV_ORIGIN, 'exdirect.net') !== false) {
    // 語句のまとまり（.ph）の途中では改行しない。行末がばらつかないように（2026-11-23 ユーザー指摘「文字の長さ・改行位置・行数がばらばら」）
    $ph = static fn(array $a): string => implode('', array_map(static fn($t) => '<span class="ph">' . $t . '</span>', $a));
    echo '<section class="migrated">'
       . '<p class="badge">システム移行のお知らせ</p>'
       . '<h1>' . $ph(['exdirect.net は、', 'おちゃのこネットから', '自社開発の', 'ECシステムへ', '移行しました']) . '</h1>'
       // 移行の理由＝AI駆動経営（2026-11-23 ユーザー指示の説明）
       . '<p class="lead">' . $ph(['株式会社エクスブリッジは、', '会社の業務を', 'AIが主体となって行う', '「AI駆動経営」を', '目指しています。',
            'その一歩として、', 'ネット通販の業務を', 'AIが完全に操作できる', 'システムへ移すため、', 'ECシステム&nbsp;',
            '<a href="https://kappstore.exbridge.jp/app.php?id=754c7ddb5f1c8f26#ref=exdirect-top">Kurage Vibe-Cart</a> を',
            '自社で開発し、', kv_e(kv_shop('name')) . '（exdirect.net）を、', 'ネットショップ作成サービス', '「おちゃのこネット」から', '移行しました。']) . '</p>'
       // 数字（取扱点数）は出さない。AIエージェントがすべて操作できることを一番に（2026-11-23 ユーザー指示）
       // 3つの枠は見出し1行・説明2行でそろえる（2026-11-23 ユーザー指摘「3つ並べるなら揃えないと汚い」）
       . '<ul class="points">'
       . '<li><b>AIがすべて操作</b><span>商品登録・デザイン・受発注・</span><span>問い合わせ対応まで</span></li>'
       . '<li><b>URLはそのまま</b><span>ブックマークや検索結果から、</span><span>これまでどおり開けます</span></li>'
       . '<li><b>商品番号もそのまま</b><span>おちゃのこネットのときと</span><span>同じ番号で探せます</span></li>'
       . '</ul>'
       // 平行稼働中は旧店舗（おちゃのこネット）も開いている。移していない商品はそちらで買える（2026-11-15）
       . '<p class="oldshop"><b>' . $ph(['旧店舗（おちゃのこネット版）も、', 'しばらくのあいだ', '並行して営業しています。']) . '</b>'
       . '<span class="t">' . $ph(['こちらに無い商品は、', '旧店舗でお探しください。']) . '</span>'
       . '<a href="https://xdirect.ocnk.net/">旧店舗 xdirect.ocnk.net を開く</a></p>'
       . '</section>'
       // 開発する人と運営するAIを絵で見せる（2026-11-23 ユーザー指示: X のアイコンと Kurage のキャラクターで）。
       // 3つの枠は見出し・名前・説明2行でそろえる。画像は縦横比を保つ（height:auto・object-fit:contain）
       . '<section class="aiteam">'
       . '<h2>X-Direct をつくる人と、動かすAI</h2>'
       . '<div class="aiteam-row">'
       . '<div class="aiteam-card">'
       . '<img src="' . kv_url('img/brand/bittensorman-x.jpg') . '" alt="AIエンジニア BittensorMan の X のアイコン" width="120" height="120" loading="lazy" class="face">'
       . '<p class="role">AIエンジニア</p><p class="who">BittensorMan</p>'
       . '<p class="what"><span>Kurage Vibe-Cart を</span><span>開発しています</span></p>'
       . '<a class="xlink" href="https://x.com/xb_bittensor" rel="noopener" target="_blank">@xb_bittensor</a>'
       . '</div>'
       . '<div class="aiteam-arrow"><span>開発</span></div>'
       . '<div class="aiteam-card sys">'
       . '<p class="sysname">Kurage<br>Vibe-Cart</p>'
       . '<p class="role">ECシステム</p><p class="who">X-Direct の土台</p>'
       . '<p class="what"><span>AIがすべて操作できる</span><span>自社開発のECシステム</span></p>'
       . '<a class="xlink" href="https://kappstore.exbridge.jp/app.php?id=754c7ddb5f1c8f26#ref=exdirect-aiteam">くわしく見る</a>'
       . '</div>'
       . '<div class="aiteam-arrow rev"><span>運営</span></div>'
       . '<div class="aiteam-card">'
       . '<img src="' . kv_url('img/brand/kurage-mascot.webp') . '" alt="AIエージェント Kurage のキャラクター" width="106" height="120" loading="lazy" class="mascot">'
       . '<p class="role">AIエージェント</p><p class="who">Kurage</p>'
       . '<p class="what"><span>商品登録・受発注・</span><span>問い合わせ対応を運営</span></p>'
       . '<a class="xlink" href="https://kurage.exbridge.jp/#ref=exdirect-aiteam" rel="noopener" target="_blank">Kurage を見る</a>'
       . '</div>'
       . '</div>'
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
    // 人気商品＝Google でよく探されている販売中の商品（2026-11-17 ユーザー指示）。
    // kv_data/search_popular.json（店ごとに Search Console の数字から作る）が無い店では出さない
    $sp = kv_search_popular(16);
    if ($sp) {
        echo '<h2>人気商品</h2><p class="src">検索でよく探されている商品です。</p><div class="grid">';
        foreach ($sp as $p) { kv_card($p, '人気'); }
        echo '</div>';
    }
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
    echo '</div>';
    // 業務システム（Kurage App Store へつなぐ・2026-11-17）。kv_data/systems.json が無い店では出さない
    $sys = kv_systems();
    if ($sys) {
        echo '<h2>業務システムも、買い切りで</h2><p class="src">運営会社（株式会社エクスブリッジ）の業務システムです。月額なし・保守契約なしで使えます。'
           . '<a href="' . kv_url('business-systems') . '">すべて見る</a></p><div class="blogs">';
        foreach (array_slice($sys['items'], 0, 4) as $x) {
            echo '<a class="blog" href="' . kv_e(str_replace('#ref=exdirect-systems', '#ref=exdirect-top-systems', $x['url'])) . '">'
               . '<img src="' . kv_e($x['image']) . '" alt="" loading="lazy" width="1200" height="630">'
               . '<span class="d">' . kv_e($x['kind']) . '・' . number_format((int)$x['price']) . '円（税込）</span><span class="t">' . kv_e($x['name']) . '</span></a>';
        }
        echo '</div>';
    }
    // ブログの新着（2026-11-17 ユーザー指示「トップの下の方でいいので」）。フィードが無い店では出さない
    $blog = kv_blog_latest(6);
    if ($blog) {
        echo '<h2>ブログの新着</h2><p class="src">運営会社（株式会社エクスブリッジ）のブログです。</p><div class="blogs">';
        foreach ($blog as $b) {
            echo '<a class="blog" href="' . kv_e($b['link'] . '#ref=exdirect-top-blog') . '">'
               . ($b['img'] !== '' ? '<img src="' . kv_e($b['img']) . '" alt="" loading="lazy" width="1200" height="630">' : '')
               . '<span class="d">' . kv_e($b['date']) . '</span><span class="t">' . kv_e($b['title']) . '</span></a>';
        }
        echo '</div>';
    }
    echo '</div>';
    kv_footer();
}

/**
 * ブログの新着（RSS）。設定 shop.blog_feed（X-Direct は exbridge.jp の vibeblog）を1時間に1回だけ読み、kv_data/blog_feed.json に置く。
 * 読めなかったときは前回の分を出す（トップを遅くしない・壊さない）。サムネイルは shop.blog_image の {slug} を置き換えて作る。
 */
function kv_blog_latest(int $n = 6): array
{
    $feed = kv_shop('blog_feed');
    $imgPat = kv_shop('blog_image');
    if ($feed === '' && kv_is_xdirect()) {
        $feed = 'https://exbridge.jp/vibeblog/feed.xml';
        $imgPat = 'https://exbridge.jp/images/vibeblog/{slug}.png';
    }
    if ($feed === '') { return []; }
    $cache = __DIR__ . '/kv_data/blog_feed.json';
    $old = is_file($cache) ? (json_decode((string)file_get_contents($cache), true) ?: []) : [];
    $items = $old['items'] ?? [];
    if (!$items || (time() - (int)($old['at'] ?? 0)) > 3600) {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'user_agent' => 'kvcart-blog-feed']]);
        $xml = @file_get_contents($feed, false, $ctx);
        $x = $xml ? @simplexml_load_string($xml) : false;
        if ($x && isset($x->channel->item)) {
            $items = [];
            foreach ($x->channel->item as $it) {
                $link = (string)$it->link;
                $slug = preg_replace('/\.html$/', '', basename((string)parse_url($link, PHP_URL_PATH)));
                $ts = strtotime((string)$it->pubDate);
                $items[] = ['title' => trim((string)$it->title), 'link' => $link, 'date' => $ts ? date('Y年n月j日', $ts) : '',
                            'img' => ($imgPat !== '' && $slug !== '') ? str_replace('{slug}', $slug, $imgPat) : ''];
                if (count($items) >= 12) { break; }
            }
        }
        // 読めても読めなくても時刻は進める（失敗のたびに3秒待たせない）
        @file_put_contents($cache, json_encode(['at' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return array_slice($items, 0, $n);
}

/** トップの「人気商品」。kv_data/search_popular.json の並び順で、今売っている（表示している）商品だけを返す。 */
function kv_search_popular(int $n = 16): array
{
    $f = __DIR__ . '/kv_data/search_popular.json';
    if (!is_file($f)) { return []; }
    $d = json_decode((string)file_get_contents($f), true);
    $out = [];
    foreach (($d['items'] ?? []) as $it) {
        $p = kv_product((int)($it['id'] ?? 0));
        if ($p) { $out[] = $p; }
        if (count($out) >= $n) { break; }
    }
    return $out;
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

/**
 * メーカーページに出す事実（2026-11-17・SEO/AEO/GEO 強化）。すべて DB の実データから作る（推測で書かない）。
 * 分類ごとの点数・価格帯（税込）・価格を出していない点数・よく探されている型番（kv_data/maker_popular.json）。
 */
function kv_maker_facts(int $id): array
{
    $db = kv_db();
    $cats = $db->prepare('SELECT c.id, c.name, COUNT(*) AS n FROM products p JOIN categories c ON c.id=p.category_id
                           WHERE p.maker_id=? AND p.hidden=0 GROUP BY c.id ORDER BY n DESC');
    $cats->execute([$id]);
    $pr = $db->prepare('SELECT MIN(price) AS lo, MAX(price) AS hi, SUM(price IS NULL OR price=0) AS ask, COUNT(*) AS n
                         FROM products WHERE maker_id=? AND hidden=0');
    $pr->execute([$id]);
    $p = $pr->fetch() ?: [];
    $pop = [];
    $f = __DIR__ . '/kv_data/maker_popular.json';
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        foreach (($d['makers'][(string)$id] ?? []) as $it) {
            $q = kv_product((int)$it['id']);
            if ($q) { $pop[] = $q; }
            if (count($pop) >= 8) { break; }
        }
    }
    $rate = 1.10;
    return ['cats' => $cats->fetchAll(), 'n' => (int)($p['n'] ?? 0), 'ask' => (int)($p['ask'] ?? 0),
            'lo' => $p['lo'] ? (int)floor((int)$p['lo'] * $rate) : null, 'hi' => $p['hi'] ? (int)floor((int)$p['hi'] * $rate) : null,
            'popular' => $pop];
}

function kv_page_group(int $id, int $page): void
{
    $mk = kv_maker($id);
    // 旧サイトで同じメーカーが2つのグループに分かれていた場合など、空になったグループの URL を
    // 移したメーカーのページとして出す（kv_data/group_alias.json: {"316": 272}。canonical は移した先）
    if (!$mk && is_file(KV_DATA . '/group_alias.json')) {
        $al = json_decode((string)file_get_contents(KV_DATA . '/group_alias.json'), true) ?: [];
        if (isset($al[(string)$id]) && ($m2 = kv_maker((int)$al[(string)$id]))) { $id = (int)$al[(string)$id]; $mk = $m2; }
    }
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
    $fx = kv_maker_facts($id);
    $name = (string)$mk['name'];
    $shop = kv_shop('name');
    $catNames = array_map(fn($c) => (string)$c['name'], array_slice($fx['cats'], 0, 3));
    $catText = $catNames ? implode('、', $catNames) : '';
    $allAsk = $fx['n'] > 0 && $fx['ask'] === $fx['n'];
    $priceText = $allAsk ? '価格・納期はお問い合わせください'
               : ($fx['lo'] !== null ? '税込' . number_format($fx['lo']) . '円〜' . number_format($fx['hi']) . '円' : '');
    $popModels = array_values(array_filter(array_map(fn($q) => kv_model_display($q['model_number'], $q['name']), $fx['popular'])));
    // 冒頭の要約（AI の回答に引用されやすい、事実だけの短い文）
    $lead = $shop . 'では、' . $name . 'の' . ($catText !== '' ? $catText . 'などの' : '') . '商品を' . number_format($r['total']) . '点取り扱っています。'
          . ($allAsk ? '価格と納期は、数量と送り先をお知らせいただければご案内します。'
                     : ($priceText !== '' ? '価格は' . $priceText . 'です。' : ''))
          . 'メーカー・仕入先からの直送で、送料は原則無料です。';
    $title = $name . 'の商品一覧' . ($catText !== '' ? '（' . $catText . '）' : '') . ' ' . number_format($r['total']) . '点'
           . ($page > 1 ? '・' . $page . 'ページ目' : '');
    $desc = mb_substr($lead . ($popModels ? 'よく探されている型番: ' . implode('、', array_slice($popModels, 0, 5)) . '。' : ''), 0, 160);
    $path = '/product-group/' . $id . ($page > 1 ? '?p=' . $page : '');
    $faq = [
        [$name . 'の商品はどこで買えますか？', $shop . '（' . kv_origin() . '）で' . $name . 'の商品を' . number_format($r['total']) . '点取り扱っています。'
            . ($allAsk ? '価格・納期は商品ページの「価格・納期を問い合わせる」からお問い合わせください。' : '商品ページからご注文いただけます。')],
        ['送料はかかりますか？', kv_shop('ship_note')],
        ['納期はどのくらいですか？', 'ご注文・お問い合わせのあと、メーカー・仕入先に在庫と納期を確認してメールでご連絡します。'],
        ['Amazon・楽天・Yahoo!ショッピングより安くできるのはなぜですか？', kv_low_price_reason()],
        ['掲載されていない' . $name . 'の商品も買えますか？', '掲載していない型番も、お問い合わせいただければお見積もりします。'],
    ];
    $o = kv_origin();
    $jsonld = ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'CollectionPage', 'name' => $name . 'の商品一覧', 'url' => $o . $path, 'description' => $desc,
         'about' => ['@type' => 'Brand', 'name' => $name],
         'isPartOf' => ['@type' => 'WebSite', 'name' => $shop, 'url' => $o . '/']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'トップ', 'item' => $o . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'メーカー一覧', 'item' => $o . '/makers'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $o . '/product-group/' . $id]]],
        ['@type' => 'ItemList', 'numberOfItems' => (int)$r['total'], 'itemListElement' => array_values(array_map(
            fn($q, $k) => ['@type' => 'ListItem', 'position' => ($page - 1) * 24 + $k + 1, 'url' => $o . '/product/' . (int)$q['id'], 'name' => (string)$q['name']],
            $r['items'], array_keys($r['items'])))],
        ['@type' => 'FAQPage', 'mainEntity' => array_merge(array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq), kv_common_faq_ld())],
    ]];
    kv_head($title, $desc, $path, ['jsonld' => $jsonld]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › '
       . '<a href="' . kv_url('makers') . '">メーカー</a> › ' . kv_e($name) . '</nav>'
       . '<h1>' . kv_e($name) . 'の商品一覧</h1>';
    if ($page === 1) {
        echo '<p class="lead">' . kv_e($lead) . '</p>' . kv_guide_links($id);
        if ($fx['cats']) {
            echo '<h2>' . kv_e($name) . 'の取扱分類</h2><div class="makers">';
            foreach ($fx['cats'] as $c) {
                echo '<a href="' . kv_url('product-list/' . (int)$c['id']) . '">' . kv_e($c['name'])
                   . ' <span class="src">' . number_format((int)$c['n']) . '</span></a>';
            }
            echo '</div>';
        }
        if ($fx['popular']) {
            echo '<h2>よく探されている' . kv_e($name) . 'の商品</h2><div class="grid">';
            foreach ($fx['popular'] as $q) { kv_card($q); }
            echo '</div>';
        }
        echo '<h2>' . kv_e($name) . 'の全商品</h2>';
    }
    echo '<p class="src">' . number_format($r['total']) . '点' . ($page > 1 ? '（' . $page . 'ページ目）' : '') . '</p><div class="grid">';
    foreach ($r['items'] as $q) { kv_card($q); }
    echo '</div>';
    kv_pager($r, kv_url('product-group/' . $id));
    if ($page === 1) {
        echo '<h2>' . kv_e($name) . 'についてよくある質問</h2><div class="faq">';
        foreach ($faq as $x) { echo '<h3>' . kv_e($x[0]) . '</h3><p>' . kv_e($x[1]) . '</p>'; }
        echo '</div>';
    }
    echo '</div>';
    kv_footer();
}

/** カテゴリのページの要約に使う事実（メーカー別の点数・価格の幅）。 */
function kv_category_facts(int $id): array
{
    $db = kv_db();
    $mk = $db->prepare('SELECT m.id, m.name, COUNT(*) AS n FROM products p JOIN makers m ON m.id=p.maker_id
                         WHERE p.category_id=? AND p.hidden=0 GROUP BY m.id ORDER BY n DESC');
    $mk->execute([$id]);
    $pr = $db->prepare('SELECT MIN(NULLIF(price,0)) AS lo, MAX(price) AS hi, SUM(price IS NULL OR price=0) AS ask, COUNT(*) AS n
                         FROM products WHERE category_id=? AND hidden=0');
    $pr->execute([$id]);
    $p = $pr->fetch() ?: [];
    $rate = 1.10;
    return ['makers' => $mk->fetchAll(), 'n' => (int)($p['n'] ?? 0), 'ask' => (int)($p['ask'] ?? 0),
            'lo' => $p['lo'] ? (int)floor((int)$p['lo'] * $rate) : null, 'hi' => $p['hi'] ? (int)floor((int)$p['hi'] * $rate) : null];
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
    $name = (string)($cat['name'] ?? 'カテゴリ');
    $shop = kv_shop('name');
    $fx = kv_category_facts($id);
    $mkNames = array_map(fn($m) => (string)$m['name'], array_slice($fx['makers'], 0, 3));
    $mkText = $mkNames ? implode('・', $mkNames) : '';
    $allAsk = $fx['n'] > 0 && $fx['ask'] === $fx['n'];
    $priceText = $allAsk ? '' : ($fx['lo'] !== null ? '税込' . number_format($fx['lo']) . '円〜' . number_format($fx['hi']) . '円' : '');
    // 冒頭の要約（検索結果の説明文と AI の回答に使われやすい、事実だけの短い文）
    $lead = $shop . 'では、' . $name . 'を' . number_format($r['total']) . '点取り扱っています。'
          . ($mkText !== '' ? 'メーカーは' . $mkText . (count($fx['makers']) > 3 ? 'など' . count($fx['makers']) . '社' : '') . 'です。' : '')
          . ($priceText !== '' ? '価格は' . $priceText . 'です。' : '')
          . 'メーカー・仕入先からの直送で、送料は原則無料です。';
    $title = $name . 'の通販' . ($mkText !== '' ? '（' . $mkText . '）' : '') . ' ' . number_format($r['total']) . '点'
           . ($page > 1 ? '・' . $page . 'ページ目' : '');
    $desc = mb_substr($lead, 0, 160);
    $path = '/product-list/' . $id . ($page > 1 ? '?p=' . $page : '');
    $faq = [
        [$name . 'はどこで買えますか？', $shop . '（' . kv_origin() . '）で' . $name . 'を' . number_format($r['total']) . '点取り扱っています。'
            . ($mkText !== '' ? $mkText . 'などのメーカーの商品があります。' : '')],
        ['送料はかかりますか？', kv_shop('ship_note')],
        ['納期はどのくらいですか？', 'ご注文・お問い合わせのあと、メーカー・仕入先に在庫と納期を確認してメールでご連絡します。'],
        ['掲載されていない型番も買えますか？', '掲載していない型番も、お問い合わせいただければお見積もりします。'],
    ];
    $o = kv_origin();
    $crumb = [['@type' => 'ListItem', 'position' => 1, 'name' => 'トップ', 'item' => $o . '/']];
    $crumb[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $o . '/product-list/' . $id];
    $jsonld = ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'CollectionPage', 'name' => $name . 'の商品一覧', 'url' => $o . $path, 'description' => $desc,
         'isPartOf' => ['@type' => 'WebSite', 'name' => $shop, 'url' => $o . '/']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => $crumb],
        ['@type' => 'ItemList', 'numberOfItems' => (int)$r['total'], 'itemListElement' => array_values(array_map(
            fn($q, $k) => ['@type' => 'ListItem', 'position' => ($page - 1) * 24 + $k + 1, 'url' => $o . '/product/' . (int)$q['id'], 'name' => (string)$q['name']],
            $r['items'], array_keys($r['items'])))],
        ['@type' => 'FAQPage', 'mainEntity' => array_merge(array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq), kv_common_faq_ld())],
    ]];
    kv_head($title, $desc, $path, ['jsonld' => $jsonld]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › '
       . (!empty($cat['parent_name']) ? kv_e($cat['parent_name']) . ' › ' : '') . kv_e($name) . '</nav>'
       . '<h1>' . kv_e($name) . 'の商品一覧</h1>';
    if ($page === 1) {
        echo '<p class="lead">' . kv_e($lead) . '</p>';
        if ($fx['makers']) {
            echo '<h2>' . kv_e($name) . 'のメーカー</h2><div class="makers">';
            foreach ($fx['makers'] as $m) {
                echo '<a href="' . kv_url('product-group/' . (int)$m['id']) . '">' . kv_e($m['name'])
                   . ' <span class="src">' . number_format((int)$m['n']) . '</span></a>';
            }
            echo '</div>';
        }
        echo '<h2>' . kv_e($name) . 'の全商品</h2>';
    }
    echo '<p class="src">' . number_format($r['total']) . '点' . ($page > 1 ? '（' . $page . 'ページ目）' : '') . '</p><div class="grid">';
    foreach ($r['items'] as $p) { kv_card($p); }
    echo '</div>';
    kv_pager($r, kv_url('product-list/' . $id));
    if ($page === 1) {
        echo '<h2>' . kv_e($name) . 'についてよくある質問</h2><div class="faq">';
        foreach ($faq as $x) { echo '<h3>' . kv_e($x[0]) . '</h3><p>' . kv_e($x[1]) . '</p>'; }
        echo '</div>';
    }
    echo '</div>';
    kv_footer();
}

function kv_page_search(int $page): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $r = $q === '' ? ['items' => [], 'total' => 0, 'page' => 1, 'per' => 24, 'pages' => 0]
                   : kv_products(['q' => $q], $page, 24);
    kv_head($q === '' ? '商品検索' : '「' . $q . '」の検索結果',
            '商品名・型番・JANコードで検索できます。', '/search', ['noindex' => true]);
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
        // 商品名は【送料無料】などの飾りを外す（Google ショッピングの商品名の決まり・2026-11-17）
        'name' => trim(preg_replace('/\s+/u', ' ', preg_replace('/【[^】]*】/u', ' ', (string)$p['name']))) ?: $p['name'], 'sku' => $p['model_number'] ?: (string)$p['id'],
        'mpn' => kv_model_display($p['model_number'], $p['name']) ?: null,
        (strlen(kv_jan($p)) === 8 ? 'gtin8' : 'gtin13') => kv_jan($p) ?: null,
        'image' => $p['image_url'] ? kv_abs((string)$p['image_url']) : null,
        'url' => kv_base_url() . '/product/' . $id,
        'brand' => $mk ? ['@type' => 'Brand', 'name' => $mk['name']] : null,
        'offers' => $incl === null ? null : [
            '@type' => 'Offer', 'price' => (string)$incl, 'priceCurrency' => 'JPY',
            'url' => kv_base_url() . '/product/' . $id, 'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => kv_shop('company')],
            // 送料は原則無料（2026-11-16）。北海道(01)・沖縄(47)は別途送料の場合があるので、配送先から外す。
            // 離島は書き分けられないため「原則」。海外は配送先に入れない
            'shippingDetails' => kv_is_xdirect() ? [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => 0, 'currency' => 'JPY'],
                'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'JP',
                    'addressRegion' => array_map(fn($n) => sprintf('%02d', $n), range(2, 46))],
            ] : null,
            'availability' => kv_stock_schema_availability($p),   // 在庫数・在庫切れの扱いから（kv_stock.php）
            // 返品の決まり（ご利用案内・特商法表示と同じ：お客様都合の返品は不可。不良・相違は到着後7日以内に良品と交換）
            'hasMerchantReturnPolicy' => kv_is_xdirect() ? ['@type' => 'MerchantReturnPolicy', 'applicableCountry' => 'JP',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted'] : null,
        ],
    ];
    if (isset($jsonld['offers']) && is_array($jsonld['offers'])) { $jsonld['offers'] = array_filter($jsonld['offers'], fn($v) => $v !== null); }
    // 要約（2026-11-17 SEO/AEO/GEO）。メーカー・分類・型番・JAN・価格・送料を、事実だけで短く。検索結果の説明文と本文の冒頭に使う
    $m0 = kv_model_display($p['model_number'], $p['name']);
    $label = $m0 !== '' ? $m0 : trim(preg_replace('/【[^】]*】/u', '', (string)$p['name']));
    // かぎかっこの中は「商品名から型番・メーカー名・JAN・【】を除いたもの」（型番を二度書かない）
    $short = preg_replace('/【[^】]*】/u', ' ', (string)$p['name']);
    foreach (array_filter([$m0, $mk['name'] ?? '', kv_jan($p)]) as $w) { $short = str_ireplace($w, ' ', $short); }
    // 「PowerTite(未来舎)」からメーカー名を消すと「PowerTite( )」が残るので、空のかっこも消す（2026-11-20）
    $short = preg_replace('/[（(][\s　]*[)）]/u', ' ', $short);
    $short = trim(preg_replace('/[\s　]+/u', ' ', $short));
    if (mb_strlen($short) < 2 || mb_strlen($short) > 40) { $short = $label; }
    $shipShort = kv_is_xdirect() ? '送料は原則無料、メーカー・仕入先からの直送です。' : '';
    $summary = ($mk ? $mk['name'] . 'の' : '') . ($cat ? $cat['name'] . '「' : '「') . $short . '」'
             . ($m0 !== '' || kv_jan($p) !== '' ? '（' . trim(($m0 !== '' ? '型番 ' . $m0 : '') . (kv_jan($p) !== '' ? ($m0 !== '' ? '・' : '') . 'JAN ' . kv_jan($p) : '')) . '）' : '') . '。'
             . ($incl !== null ? kv_shop('name') . 'では税込' . number_format($incl) . '円（本体' . number_format((int)$p['price']) . '円）で販売しています。'
                               : '価格・納期はお問い合わせください。数量と送り先をお知らせいただければご案内します。')
             . $shipShort;
    $desc = mb_substr($summary . $desc, 0, 160);
    $jsonld['description'] = mb_substr($summary . $desc, 0, 300);
    if ($cat) { $jsonld['category'] = $cat['name']; }
    // その商品についてのよくある質問（価格・納期・送料だけ）
    $pfaq = [
        [$label . 'の価格はいくらですか？', $incl !== null ? '税込' . number_format($incl) . '円（本体' . number_format((int)$p['price']) . '円）です。'
                                                   : '価格はお問い合わせください。ご希望の数量と送り先をお知らせいただければ、価格と納期をご案内します。'],
        [$label . 'の納期はどのくらいですか？', ($lt0 = kv_product_leadtime($p)) && trim((string)($lt0['note'] ?? '')) !== ''
                ? (string)$lt0['note'] . '。ご注文のあと、メーカーに確認した納期をメールでご連絡します。'
                  . (function_exists('kv_stripe_ready') && kv_stripe_ready() ? 'クレジットカードは、ご注文の時点ではお支払い枠を確保する仮売上とし、在庫と納期を確かめて商品を手配してから売上を確定します。' : '')
                : 'ご注文・お問い合わせのあと、メーカー・仕入先に在庫と納期を確認してメールでご連絡します。メーカーの欠品などで納期が長くかかる場合は、ご注文のあと納期をメールでお知らせし、ご了承をいただいてから手配します。ご了承いただけない場合はキャンセルできます。'
                  . (function_exists('kv_stripe_ready') && kv_stripe_ready() ? 'クレジットカードは、ご注文の時点ではお支払い枠を確保する仮売上とし、在庫と納期を確かめて商品を手配してから売上を確定します。' : '')],
    ];
    if (kv_is_xdirect()) { $pfaq[] = [$label . 'の送料はかかりますか？', kv_shop('ship_note')]; }
    $o = kv_base_url();
    $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'トップ', 'item' => $o . '/']];
    if ($mk) { $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $mk['name'], 'item' => $o . '/product-group/' . (int)$mk['id']]; }
    if ($cat) { $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $cat['name'], 'item' => $o . '/product-list/' . (int)$cat['id']]; }
    $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => (string)$p['name']];
    $prodLd = array_filter($jsonld); unset($prodLd['@context']);
    $graph = ['@context' => 'https://schema.org', '@graph' => [
        $prodLd,
        ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $pfaq)],
    ]];
    kv_head(kv_product_title($p), $desc, '/product/' . $id,
            ['og_type' => 'product', 'image' => $p['image_url'], 'jsonld' => $graph]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a>'
       . ($mk ? ' › <a href="' . kv_url('product-group/' . (int)$mk['id']) . '">' . kv_e($mk['name']) . '</a>' : '')
       . ($cat ? ' › <a href="' . kv_url('product-list/' . (int)$cat['id']) . '">' . kv_e($cat['name']) . '</a>' : '')
       . '</nav>';
    echo '<h1>' . kv_e($p['name']) . '</h1><p class="lead">' . kv_e($summary) . '</p>' . kv_guide_links((int)$p['maker_id'], (int)$p['id']) . '<div class="pd">';
    echo '<div class="img">' . ($p['image_url'] ? '<img src="' . kv_e(kv_img($p['image_url'])) . '" alt="' . kv_e($p['name']) . '">' : '') . '</div>';
    echo '<div><div class="panel">';
    if (($mdl = kv_model_display($p['model_number'], $p['name'])) !== '') { echo '<p class="src">型番 ' . kv_e($mdl) . '</p>'; }
    if (kv_jan($p) !== '') { echo '<p class="src">JANコード ' . kv_e(kv_jan($p)) . '</p>'; }
    if ($incl === null) {
        // 価格を出さずに、価格と納期を問い合わせてもらう商品（古河電池・テラル・ウシオ電機など・2026-11-17）
        echo '<p class="price-big"><small>価格・納期はお問い合わせください</small></p>'
           . '<p class="src">ご希望の数量と送り先（都道府県・市区町村）をお知らせいただければ、価格と納期をご案内します。</p>';
    } else {
        if ($p['list_price'] && (int)$p['list_price'] > (int)$p['price']) {
            echo '<p class="strike">メーカー希望小売価格 ' . number_format((int)$p['list_price']) . '円</p>';
        }
        // 商品ごとの価格の見出し（kv_data/product_price_badge.json {"商品ID":"文言"}・2026-11-21 MX-46「期間限定 銀行振込のみの特別価格」）
        if (($bd = kv_product_price_badge($p)) !== '') {
            echo '<p class="pbadge">' . kv_e($bd) . '</p>';
        }
        echo '<p class="price-big">' . number_format($incl) . '円<small> 税込（本体 '
           . number_format((int)$p['price']) . '円）</small></p>';
        // 会員様限定・銀行振込で2%引き（kv_member.php・12/31まで）
        if (($mu = kv_member_campaign_unit_incl($p)) !== null) {
            echo '<p style="margin:4px 0 8px;padding:6px 10px;background:#e6f5f3;border:1px solid #9fd6cf;border-radius:8px;color:#064e48">'
               . '<b>会員様限定・銀行振込なら ' . number_format($mu) . '円</b>（税込・2%引き・' . kv_e(kv_member_campaign_end_label()) . 'まで）<br>'
               . '<span style="font-size:12.5px">ご注文の画面で自動で値引きします。会員登録はご注文のときにできます（パスワード不要）。'
               . (kv_member_current() ? '' : ' <a href="' . kv_url('member/login') . '">ログイン</a>') . '</span></p>';
        }
    }
    if (kv_is_xdirect() && $incl !== null) {
        echo '<p class="src" style="color:#8a5a00">モールの出品手数料・ネットショップサービスの利用料がかからない分、お安くしています。</p>';
    }
    if (($sl = kv_stock_label($p)) !== '') {
        echo '<p class="src"' . (kv_stock_state($p) === 'low' ? ' style="color:#b45309;font-weight:700"' : '') . '>' . kv_e($sl) . '</p>';
    }
    if ($incl !== null && ($lt = kv_product_leadtime($p)) && trim((string)($lt['note'] ?? '')) !== '') {
        echo '<p class="src" style="color:#b45309;font-weight:700">納期：' . kv_e((string)$lt['note']) . '</p>';
    }
    // お支払い方法の指定（メーカー・商品ごと・2026-11-20）
    if ($incl !== null && !kv_inquiry_only($p) && ($pr = kv_payment_rule($p)) !== 'all') {
        echo '<p class="src" style="color:#b45309;font-weight:700">お支払い方法：' . ($pr === 'bank' ? '銀行振込のみ（前払い）' : 'クレジットカードのみ') . '</p>';
    }
    // 全商品：直送なので欠品で遅れることがある・カードは注文時は仮売上、在庫確認後に確定（2026-11-19 ユーザー指示）
    if ($incl !== null && !kv_inquiry_only($p) && function_exists('kv_stripe_ready') && kv_stripe_ready()) {
        echo '<p class="src" style="color:#b45309">メーカーの欠品などで納期が長くかかる場合は、ご注文のあと納期をメールでお知らせし、ご了承をいただいてから手配します。ご了承いただけない場合はキャンセルできます（カードは仮売上を取り消すので、ご請求はありません）。クレジットカードは、ご注文の時点ではお支払い枠を確保する仮売上とし、在庫と納期を確かめて商品を手配してから売上を確定します。</p>';
    }
    if ($incl !== null && !kv_inquiry_only($p) && !kv_stock_orderable($p)) {
        // 在庫切れで注文を受け付けない設定のとき。カートの代わりに再入荷お知らせ（おちゃのこネットと同じ）
        echo '<div class="note"><b>' . kv_e(kv_stock_settings()['text_soldout']) . '</b></div>';
        if (kv_restock_enabled($p)) {
            echo !empty($_GET['restock']) ? '<div class="note">再入荷お知らせに登録しました。入荷したらメールでお知らせします。</div>'
               : '<form method="post" action="' . kv_url('restock') . '" class="panel"><b>再入荷お知らせ</b><p class="src">' . kv_e(kv_stock_settings()['restock_note']) . '</p>'
                 . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="pid" value="' . (int)$p['id'] . '">' . kv_form_guard_issue('restock')
                 . '<input type="email" name="email" required placeholder="メールアドレス" style="padding:8px;border:2px solid #cfdae4;border-radius:8px;max-width:280px;width:100%"> '
                 . '<button class="btn" type="submit">登録する</button></form>';
        }
    } elseif ($incl !== null && kv_inquiry_only($p)) {
        // 問い合わせ扱い（メーカーで販売中止など）。価格は出し、カートの代わりにお問い合わせ（2026-11-16）
        echo '<div class="note">メーカーでの販売が終了した商品です。在庫の有無・後継の機種は、お問い合わせください。</div>'
           . '<p><a class="btn" href="' . kv_url('contact') . '?pid=' . (int)$p['id'] . '">この商品について問い合わせる</a></p>';
    } elseif ($incl !== null) {
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
        // 選択肢の下の注意書き（variations の "note"）。選択肢に無い型式はお問い合わせへ（2026-11-20 有光 SG50-40）
        if ($opt_html !== '' && trim((string)($vjson['note'] ?? '')) !== '') {
            $opt_html .= '<p class="src" style="margin:0 0 10px;color:#b45309;font-weight:700">' . kv_e(trim((string)$vjson['note']))
                       . ' <a href="' . kv_url('contact') . '?pid=' . (int)$p['id'] . '">お問い合わせはこちら</a></p>';
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
        echo '<p><a class="btn" href="' . kv_url('contact') . '?pid=' . (int)$p['id'] . '">価格・納期を問い合わせる</a></p>';
    }
    echo '<p class="src" style="margin-top:12px">' . kv_e(kv_shop('ship_note')) . '</p>';
    echo kv_heavy_note();
    echo '</div></div></div>';
    // 基本情報（AI・検索エンジンが拾いやすい表。事実だけ）
    $rows = [['メーカー', $mk ? '<a href="' . kv_url('product-group/' . (int)$mk['id']) . '">' . kv_e($mk['name']) . '</a>' : ''],
             ['型番', kv_e($m0)], ['JANコード', kv_e(kv_jan($p))],
             ['分類', $cat ? '<a href="' . kv_url('product-list/' . (int)$cat['id']) . '">' . kv_e($cat['name']) . '</a>' : ''],
             ['価格', $incl !== null ? '税込' . number_format($incl) . '円（本体' . number_format((int)$p['price']) . '円）' : '価格・納期はお問い合わせください'],
             ['メーカー希望小売価格', $p['list_price'] && $incl !== null && (int)$p['list_price'] > (int)$p['price'] ? number_format((int)$p['list_price']) . '円' : ''],
             ['送料', kv_e(kv_shop('ship_note'))],
             ['お届け', kv_is_xdirect() ? 'メーカー・仕入先から直送（在庫・納期を確認してからご連絡します）' : ''],
             ['重量物', kv_is_xdirect() ? '車上渡し（荷降ろし機が必要）。' . kv_e(kv_heavy_text()) : '']];
    echo '<h2>基本情報</h2><div class="scroll"><table class="t spec">';
    foreach ($rows as [$k, $v]) { if ($v !== '') { echo '<tr><th>' . $k . '</th><td>' . $v . '</td></tr>'; } }
    echo '</table></div>';
    if ($p['description']) {
        echo '<h2>商品説明</h2><div class="panel desc">' . kv_clean_desc((string)$p['description']) . '</div>';
        // 商品説明は AI で作っている（2026-11-19 ユーザー指示）。誤りがありうることと、メーカーの資料で確かめてほしいことを説明のすぐ下に
        if (kv_is_xdirect()) { echo '<p class="descnote">※商品説明は、メーカーの公表資料などをもとに AI を使って作成しているため、誤りがある場合があります。ご注文の前に、メーカーのカタログや公式サイトで仕様をご確認ください。</p>'; }
    }
    echo '<h2>' . kv_e($label) . 'についてよくある質問</h2><div class="faq">';
    foreach ($pfaq as $x) { echo '<h3>' . kv_e($x[0]) . '</h3><p>' . kv_e($x[1]) . '</p>'; }
    echo '</div>';
    // 関連商品（同じメーカー・同じ分類 → 同じ分類 の順。内部リンク）
    $rel = kv_related($p, 8);
    if ($rel) {
        echo '<h2>関連商品</h2><div class="grid">';
        foreach ($rel as $q) { kv_card($q); }
        echo '</div>';
    }
    echo '</div>';
    kv_footer();
}

/** 関連商品。売れた商品を先に（2026-11-19）。同じメーカー・同じ分類から、足りなければ同じ分類から（自分は除く・表示している商品だけ）。 */
function kv_related(array $p, int $n = 8): array
{
    $db = kv_db(); $out = []; $seen = [(int)$p['id'] => true];
    $qs = [];
    if ($p['maker_id'] && $p['category_id']) { $qs[] = ['SELECT * FROM products WHERE hidden=0 AND maker_id=? AND category_id=? AND id<>? ORDER BY ' . kv_sold_order() . '(image_url<>"") DESC, id DESC LIMIT ?', [(int)$p['maker_id'], (int)$p['category_id'], (int)$p['id'], $n]]; }
    if ($p['category_id']) { $qs[] = ['SELECT * FROM products WHERE hidden=0 AND category_id=? AND id<>? ORDER BY ' . kv_sold_order() . '(image_url<>"") DESC, id DESC LIMIT ?', [(int)$p['category_id'], (int)$p['id'], $n * 2]]; }
    if ($p['maker_id']) { $qs[] = ['SELECT * FROM products WHERE hidden=0 AND maker_id=? AND id<>? ORDER BY ' . kv_sold_order() . '(image_url<>"") DESC, id DESC LIMIT ?', [(int)$p['maker_id'], (int)$p['id'], $n]]; }
    foreach ($qs as [$sql, $args]) {
        $st = $db->prepare($sql); $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            if (isset($seen[(int)$r['id']])) { continue; }
            $seen[(int)$r['id']] = true; $out[] = $r;
            if (count($out) >= $n) { return $out; }
        }
    }
    return $out;
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
       . (($mdl = kv_model_display($r['model_number'], $r['name'])) !== '' ? '<br>型番: ' . kv_e($mdl) : '')
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
    // 仮画像（「画像はありません」等）は画像として伝えない（kv_data/gshop_noimage.json は Google ショッピングと共通）
    $noimg = is_file(KV_DATA . '/gshop_noimage.json') ? (json_decode((string)file_get_contents(KV_DATA . '/gshop_noimage.json'), true) ?: []) : [];
    foreach ($db->query("SELECT id, updated_at, image_url FROM products WHERE hidden=0 ORDER BY id") as $r) {
        $im = (string)$r['image_url'];
        $why = (string)($noimg[basename((string)parse_url($im, PHP_URL_PATH))] ?? '');
        // 透かし・文字入りは Google ショッピングには出さないが、本物の商品写真なので画像検索には伝える
        $ok = $im !== '' && ($why === '' || strpos($why, '透かし') !== false || strpos($why, '文字') !== false);
        $u[] = ['/product/' . (int)$r['id'], (string)$r['updated_at'], $ok ? kv_abs($im) : ''];
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
    if (kv_is_xdirect()) { $u[] = ['/supplier', '2026-11-16']; $u[] = ['/why-low-price', '2026-11-17']; }
    if (kv_systems()) { $u[] = ['/business-systems', '2026-11-17']; }
    if (kv_guides()) {
        $u[] = ['/guide', max(array_map(fn($d) => (string)($d['checked_at'] ?? '2026-11-19'), kv_guides()))];
        foreach (kv_guides() as $gs => $gd) { $u[] = ['/guide/' . $gs, (string)($gd['checked_at'] ?? '2026-11-19')]; }
    }
    header('Content-Type: application/xml; charset=utf-8');
    $o = kv_origin();
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ($u as $row) {
        [$path, $mod] = $row; $img = $row[2] ?? '';
        echo '<url><loc>' . htmlspecialchars($o . $path, ENT_XML1) . '</loc>'
           . ($mod !== '' ? '<lastmod>' . substr($mod, 0, 10) . '</lastmod>' : '')
           . ($img !== '' ? '<image:image><image:loc>' . htmlspecialchars($img, ENT_XML1) . '</image:loc></image:image>' : '') . "</url>\n";
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
       . "モールの出品手数料や、ネットショップ作成サービスの月額のシステム利用料がかからない自社システムで運営しているため、その分を価格に回している。\n\n"
       . (kv_is_xdirect() ? "ネット通販の運用（商品登録・受発注・問い合わせ対応・集客）を人ではなくAIが行う自社開発のシステムで運営し、人件費と広告宣伝費を大幅に削減している。Amazon・楽天市場・Yahoo!ショッピングには出店していない。モールに払う販売手数料（売上の数%〜十数%）や広告費・ポイント原資がかからないので、その分を価格に回している（[安くできる理由]($o/why-low-price)）。\n\n" : '')
       . "## 主なページ\n\n"
       . "- [メーカー一覧]($o/makers): 取り扱いメーカーと商品数\n"
       . "- [商品ページ]($o/product/{番号}): 型番・税込価格・メーカー希望小売価格・在庫・商品説明\n"
       . "- [ご利用案内]($o/help): 注文の流れ・送料・お支払い（銀行振込・クレジットカード）\n"
       . "- [特定商取引法表示]($o/info): 販売事業者・返品・お届け\n"
       . "- [よくあるご質問]($o/page/16)\n"
       . "- [お問い合わせ]($o/contact): 掲載していない商品の見積もりも受け付けます\n"
       . (kv_is_xdirect() ? "- [商材募集]($o/supplier): 工具・機械・設備のメーカー・問屋さまの商材を募集（メーカー直送で販売）\n" : '')
       . (kv_systems() ? "- [業務システム]($o/business-systems): 運営会社の業務システム（受発注・EC・グループウェアなど、オンプレミス・買い切り）。購入は Kurage App Store\n" : '') . "\n"
       . implode('', array_map(function ($kind) use ($o) {
             $l = array_filter(kv_guides(), fn($gd) => ($gd['kind'] ?? 'guide') === $kind);
             return $l ? '## ' . kv_guide_kind_label($kind) . "\n\n" . implode('', array_map(fn($gs, $gd) => '- [' . $gd['title'] . "]($o/guide/$gs)" . ($kind === 'guide' && !empty($gd['desc']) ? ': ' . $gd['desc'] : '') . "\n", array_keys($l), $l)) . "\n" : '';
         }, ['guide', 'parts']))
       . "## メーカー\n\n";
    foreach (kv_makers() as $mk) {
        echo '- [' . $mk['name'] . "の商品一覧]($o/product-group/" . (int)$mk['id'] . ")\n";
    }
    if (kv_common_faq()) {
        echo "\n## 安心してご利用いただくために（よくある質問）\n\n";
        foreach (kv_common_faq() as $x) { echo '- ' . $x[0] . ' ' . $x[1] . "\n"; }
    }
    echo "\n## 注文について\n\n"
       . "- 注文後に在庫・納期・送料を確認してご連絡します。\n"
       . "- お支払いは銀行振込（前払い。在庫・納期の確認後に振込先をご案内）と、クレジットカード（ご注文時のお支払い画面から）。\n"
       . "- 価格は税込表示。商品ページの価格が最新です。\n";
}

/** 再入荷お知らせの登録（商品ページのフォームから）。スパム対策はお問い合わせと同じ（kv_form_guard_check）。 */
function kv_page_restock(): void
{
    $pid = (int)($_POST['pid'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pid && kv_csrf_ok()) {
        [$quiet, $spam, $err] = kv_form_guard_check('restock', '', '再入荷');
        if (!$quiet && $err === '') { kv_restock_register($pid, (string)($_POST['email'] ?? '')); }
    }
    header('Location: ' . kv_url('product/' . $pid) . '?restock=1');
}
