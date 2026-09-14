<?php
/**
 * Kurage Vibe-Cart — 共通のガワとCSS。
 *
 * ライトテーマ固定（白＋ティール #0a9a8f ＋濃紺 #12202f）。
 * スマホ幅で崩れないよう、グリッドは minmax(0,…)、表は横スクロール箱に入れる。
 */
declare(strict_types=1);

function kv_head(string $title, string $desc = '', string $path = '/', array $extra = []): void
{
    $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'exbridge.jp') . KV_BASE . $path;
    $site = kv_shop('name');
    $og = $extra['image'] ?? '';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . kv_e($title) . '｜' . kv_e($site) . '</title>'
       . '<meta name="description" content="' . kv_e($desc) . '">'
       . '<link rel="canonical" href="' . kv_e($url) . '">'
       . '<meta property="og:type" content="' . ($extra['og_type'] ?? 'website') . '">'
       . '<meta property="og:site_name" content="' . kv_e($site) . '">'
       . '<meta property="og:title" content="' . kv_e($title) . '">'
       . '<meta property="og:description" content="' . kv_e($desc) . '">'
       . '<meta property="og:url" content="' . kv_e($url) . '">'
       . ($og ? '<meta property="og:image" content="' . kv_e($og) . '">' : '')
       . '<meta name="twitter:card" content="summary_large_image">';
    if (!empty($extra['jsonld'])) {
        echo '<script type="application/ld+json">'
           . json_encode($extra['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
           . '</script>';
    }
    if (!empty($extra['noindex'])) { echo '<meta name="robots" content="noindex">'; }
    echo kv_css() . '</head><body>';
    kv_header();
}

function kv_css(): string
{
    return '<style>
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:#f7f9fc;color:#12202f;font-family:-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.7}
a{color:#0a7d75}
.wrap{max-width:1080px;margin:0 auto;padding:18px 16px 64px}
header.site{background:#fff;border-bottom:1px solid #e5ebf1}
header.site .inner{max-width:1080px;margin:0 auto;padding:12px 16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.brand{font-weight:900;font-size:19px;color:#12202f;text-decoration:none;white-space:nowrap}
.brand small{display:block;font-weight:500;font-size:11px;color:#6b7a86}
.search{flex:1 1 260px;min-width:0;display:flex;gap:6px}
.search input{flex:1 1 auto;min-width:0;padding:9px 12px;font-size:16px;border:2px solid #cfdae4;border-radius:9px}
.search input:focus{outline:none;border-color:#0a9a8f}
.btn{display:inline-block;padding:10px 18px;font-size:15px;font-weight:800;color:#fff;background:#0a9a8f;border:0;border-radius:9px;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn.ghost{background:#fff;color:#0a7d75;border:2px solid #0a9a8f}
.btn.gray{background:#e7edf2;color:#3c4a55}
.btn[disabled]{opacity:.5;cursor:default}
nav.crumb{font-size:12.5px;color:#6b7a86;margin-bottom:10px}
h1{font-size:22px;margin:0 0 8px}
h2{font-size:17px;margin:24px 0 8px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,208px),1fr));gap:14px}
.card{background:#fff;border:1px solid #e5ebf1;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;min-width:0}
.card a.thumb{display:block;aspect-ratio:1/1;background:#fff;overflow:hidden}
.card a.thumb img{width:100%;height:100%;object-fit:contain;display:block}
.card .b{padding:10px 12px 12px;display:flex;flex-direction:column;gap:5px;flex:1}
.card .nm{font-size:13px;line-height:1.45;color:#12202f;text-decoration:none;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.card .mn{font-size:11.5px;color:#6b7a86}
.card .pr{font-size:16px;font-weight:800;margin-top:auto}
.card .pr small{font-size:11px;font-weight:500;color:#6b7a86}
.panel{background:#fff;border:1px solid #e5ebf1;border-radius:12px;padding:18px;margin-bottom:16px}
.note{background:#fffdf5;border:1px solid #e6d3a3;border-radius:10px;padding:14px;font-size:14px;margin:14px 0}
.alert{background:#fdf6f6;border:1px solid #e0b4b4;border-radius:10px;padding:14px;font-size:14px;margin:14px 0}
.src{font-size:12.5px;color:#6b7a86}
.pager{display:flex;gap:6px;flex-wrap:wrap;margin:20px 0;font-size:14px}
.pager a,.pager span{padding:7px 12px;border-radius:8px;border:1px solid #dfe8ee;background:#fff;text-decoration:none}
.pager .now{background:#0a9a8f;color:#fff;border-color:#0a9a8f;font-weight:700}
table.t{width:100%;border-collapse:collapse;font-size:14px}
table.t th,table.t td{border:1px solid #e3e9ec;padding:8px;text-align:left;vertical-align:top}
table.t th{background:#f5f8f9;white-space:nowrap}
.scroll{overflow-x:auto}
.pd{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px}
@media(max-width:720px){.pd{grid-template-columns:minmax(0,1fr)}}
.pd .img{background:#fff;border:1px solid #e5ebf1;border-radius:12px;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;overflow:hidden}
.pd .img img{max-width:100%;max-height:100%;object-fit:contain}
.price-big{font-size:28px;font-weight:900;color:#12202f}
.price-big small{font-size:13px;font-weight:500;color:#6b7a86}
.strike{color:#8a95a0;text-decoration:line-through;font-size:14px}
.desc{font-size:14.5px;overflow-wrap:anywhere}
.desc img{max-width:100%;height:auto}
.desc table{max-width:100%}
footer.site{border-top:1px solid #e5ebf1;background:#fff;margin-top:40px}
footer.site .inner{max-width:1080px;margin:0 auto;padding:20px 16px;font-size:13px;color:#5b6b76}
.makers{display:flex;flex-wrap:wrap;gap:8px;font-size:13.5px}
.makers a{background:#fff;border:1px solid #dfe8ee;border-radius:99px;padding:6px 13px;text-decoration:none}

/* おちゃのこネットと同じ購入手続きのステップ帯（STEP1 購入者 → 2 お届け先・お支払い → 3 確認 → 4 完了） */
.steps{display:flex;gap:0;margin:0 0 22px;font-size:13px;overflow-x:auto}
.steps .s{flex:1 1 0;min-width:120px;text-align:center;padding:10px 6px;background:#eef3f6;color:#6b7a86;border-right:2px solid #fff;position:relative}
.steps .s b{display:block;font-size:11.5px;letter-spacing:.06em}
.steps .s.now{background:#0a9a8f;color:#fff}
.steps .s.done{background:#d7ece9;color:#0a726b}
.steps .s:last-child{border-right:0}
/* 必須マーク（おちゃのこネットは「！」マーク） */
.req{display:inline-block;background:#bf0000;color:#fff;font-size:11px;font-weight:700;border-radius:3px;padding:1px 6px;margin-left:6px;vertical-align:middle}
.formtbl{width:100%;border-collapse:collapse;font-size:14px}
.formtbl th{background:#f5f8f9;border:1px solid #e3e9ec;padding:12px;text-align:left;width:34%;white-space:nowrap;vertical-align:top}
.formtbl td{border:1px solid #e3e9ec;padding:10px 12px}
.formtbl input[type=text],.formtbl input[type=email],.formtbl input[type=tel],.formtbl select,.formtbl textarea{width:100%;padding:9px 10px;font-size:16px;border:2px solid #cfdae4;border-radius:8px}
.formtbl .hint{font-size:12px;color:#6b7a86;margin-top:4px}
@media(max-width:640px){.formtbl th,.formtbl td{display:block;width:auto;border-bottom:0}.formtbl tr:last-child td{border-bottom:1px solid #e3e9ec}}
</style>';
}

function kv_header(): void
{
    $q = kv_e($_GET['q'] ?? '');
    echo '<header class="site"><div class="inner">'
       . '<a class="brand" href="' . kv_url('') . '">' . kv_e(kv_shop('name'))
       . '<small>' . kv_e(kv_shop('tagline')) . '</small></a>'
       . '<form class="search" method="get" action="' . kv_url('search') . '">'
       . '<input type="text" name="q" value="' . $q . '" placeholder="商品名・型番で検索" aria-label="商品検索">'
       . '<button class="btn" type="submit">検索</button></form>'
       . '<a class="btn ghost" href="' . kv_url('cart') . '">カート</a>'
       . '</div></header>';
}

function kv_footer(): void
{
    // 規約・プライバシーポリシーは **全ページから1クリックで開ける**ようにする
    echo '<footer class="site"><div class="inner">'
       . '<p><a href="' . kv_url('info') . '">特定商取引法表示</a>'
       . ' ・ <a href="' . kv_url('help') . '">ご利用案内</a>'
       . ' ・ <a href="' . kv_url('terms') . '">ご利用規約</a>'
       . ' ・ <a href="' . kv_url('privacy') . '">プライバシーポリシー</a>'
       . ' ・ <a href="' . kv_url('page/16') . '">よくあるご質問</a>'
       . ' ・ <a href="' . kv_url('contact') . '">お問い合わせ</a></p>'
       . '<p><a href="' . kv_url('page/1') . '">' . kv_e(kv_shop('name')) . 'について</a>'
       . ' ・ <a href="' . kv_url('makers') . '">メーカー一覧</a></p>'
       . '<p>&copy; ' . date('Y') . ' ' . kv_e(kv_shop('company')) . '</p>'
       . '</div></footer></body></html>';
}

/** 商品カード1枚。一覧のどこでも同じ見た目にする。 */
function kv_card(array $p): void
{
    $incl = kv_price_incl($p);
    echo '<div class="card">'
       . '<a class="thumb" href="' . kv_url('product/' . (int)$p['id']) . '">'
       . ($p['image_url'] ? '<img src="' . kv_e(kv_img($p['image_url'])) . '" alt="" loading="lazy">' : '')
       . '</a><div class="b">'
       . '<a class="nm" href="' . kv_url('product/' . (int)$p['id']) . '">' . kv_e($p['name']) . '</a>'
       . ($p['model_number'] ? '<div class="mn">' . kv_e($p['model_number']) . '</div>' : '')
       . '<div class="pr">' . ($incl === null ? '<small>価格はお問い合わせください</small>'
            : number_format($incl) . '円<small> 税込</small>') . '</div>'
       . '</div></div>';
}

function kv_pager(array $r, string $base): void
{
    if ($r['pages'] <= 1) { return; }
    $sep = strpos($base, '?') === false ? '?' : '&';
    echo '<div class="pager">';
    for ($i = max(1, $r['page'] - 3); $i <= min($r['pages'], $r['page'] + 3); $i++) {
        echo $i === $r['page'] ? '<span class="now">' . $i . '</span>'
                               : '<a href="' . kv_e($base . $sep . 'p=' . $i) . '">' . $i . '</a>';
    }
    echo '<span class="src" style="border:0;background:none">全' . number_format($r['total']) . '件</span>';
    echo '</div>';
}

/**
 * 購入手続きのステップ帯。**おちゃのこネットと同じ4段**にする。
 *   STEP 1 購入者 → STEP 2 お届け先・お支払い → STEP 3 確認 → STEP 4 完了
 * （www.exdirect.net の cart_step_table を実測して合わせた。2026-09-14）
 */
function kv_steps(int $now): void
{
    $steps = [1 => '購入者', 2 => 'お届け先・お支払い', 3 => '確認', 4 => '完了'];
    echo '<div class="steps">';
    foreach ($steps as $i => $label) {
        $cls = $i === $now ? ' now' : ($i < $now ? ' done' : '');
        echo '<div class="s' . $cls . '"><b>STEP ' . $i . '</b>' . kv_e($label) . '</div>';
    }
    echo '</div>';
}

/** 都道府県。おちゃのこネットと同じくプルダウンで選ばせる。 */
function kv_prefs(): array
{
    return ['北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県','茨城県','栃木県','群馬県',
            '埼玉県','千葉県','東京都','神奈川県','新潟県','富山県','石川県','福井県','山梨県','長野県',
            '岐阜県','静岡県','愛知県','三重県','滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県',
            '鳥取県','島根県','岡山県','広島県','山口県','徳島県','香川県','愛媛県','高知県','福岡県',
            '佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県','沖縄県'];
}
