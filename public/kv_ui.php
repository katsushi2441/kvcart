<?php
/**
 * Kurage Vibe-Cart — 共通のガワとCSS。
 *
 * ライトテーマ固定（白＋ティール #0a9a8f ＋濃紺 #12202f）。
 * スマホ幅で崩れないよう、グリッドは minmax(0,…)、表は横スクロール箱に入れる。
 */
declare(strict_types=1);

/** 正本の起点（https://www.exdirect.net）。KV_ORIGIN が無ければ開かれたドメイン＋KV_BASE。 */
function kv_base_url(): string
{
    return defined('KV_ORIGIN') ? rtrim(KV_ORIGIN, '/')
                                : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'exbridge.jp') . KV_BASE;
}

/** OGP・構造化データ用の絶対URL（img/… の相対パスは X や検索エンジンが読めない）。 */
function kv_abs(string $u): string
{
    if ($u === '') { return ''; }
    if (preg_match('#^https?://#', $u)) { return $u; }
    return kv_base_url() . '/' . ltrim($u, '/');
}

/** 代引きはすべて不可（2026-11-15 ユーザー確認）。おちゃのこ時代の説明に残る【代引可】を【代引不可】として出す。 */
function kv_clean_desc(string $html): string
{
    return str_replace('【代引可】', '【代引不可】', $html);
}

function kv_head(string $title, string $desc = '', string $path = '/', array $extra = []): void
{
    // canonical の置き場所。kv_config.php で KV_ORIGIN（例 https://www.exdirect.net）を決めていれば、
    // どのドメイン（www無し・デモのサブパス）で開いても正本のURLを指す（2026-11-15）
    $url = defined('KV_ORIGIN') ? rtrim(KV_ORIGIN, '/') . $path
                                : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'exbridge.jp') . KV_BASE . $path;
    $site = kv_shop('name');
    $og = kv_abs((string)($extra['image'] ?? '')) ?: kv_abs('img/brand/og-default.png');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . kv_e($title) . '｜' . kv_e($site) . '</title>'
       . '<meta name="description" content="' . kv_e($desc) . '">'
       . '<link rel="canonical" href="' . kv_e($url) . '">'
       . '<link rel="icon" href="' . kv_e(kv_url('img/brand/favicon.png')) . '" type="image/png">'
       . '<link rel="apple-touch-icon" href="' . kv_e(kv_url('img/brand/favicon.png')) . '">'
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
    // アクセス記録（2026-11-16）。人は JavaScript で simpletrack.php に知らせる（ほかのサイトと同じ形）。X-Direct のときだけ
    if (kv_is_xdirect()) {
        echo '<script>(function(){var s=document.createElement("script");s.src="' . kv_url('simpletrack.php')
           . '?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s);})();</script>';
    }
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
.migrated{margin:18px 0 26px;padding:22px 24px;border-radius:16px;background:linear-gradient(135deg,#0a9a8f,#0b6fa8);color:#fff}.migrated .badge{display:inline-block;margin:0 0 10px;padding:4px 12px;border-radius:999px;background:#fff;color:#0b6fa8;font-weight:800;font-size:13px}.migrated h1{margin:0 0 12px;font-size:28px;line-height:1.4;color:#fff}.migrated .lead{margin:0 0 14px;font-size:15px;line-height:1.8}.migrated .lead a{color:#fff;font-weight:800;text-decoration:underline}.migrated .points{margin:0;padding:0;list-style:none;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.migrated .points li{background:rgba(255,255,255,.14);border-radius:10px;padding:10px 12px;font-size:13.5px;line-height:1.6}.migrated .points b{display:block;font-size:15px}.migrated .oldshop{margin:14px 0 0;padding:12px 14px;border-radius:10px;background:#fff;color:#12202f;font-size:14px;line-height:1.7}.migrated .oldshop b{display:block;color:#0b6fa8}.migrated .oldshop a{display:inline-block;margin-top:4px;color:#0a7d74;font-weight:800}@media (max-width:640px){.migrated{padding:18px 16px}.migrated h1{font-size:21px}.migrated h1 br{display:none}}
.cfaq{margin-top:28px;background:#f6f9fb;border-top:1px solid #e3eaef}.cfaq .inner{max-width:1100px;margin:0 auto;padding:18px 16px}.cfaq h2{font-size:17px;margin:0 0 10px}.cfaq details{background:#fff;border:1px solid #e3eaef;border-radius:10px;margin:0 0 8px;padding:10px 14px}.cfaq summary{cursor:pointer;font-weight:700;font-size:14.5px}.cfaq details p{margin:8px 0 0;line-height:1.8;font-size:14px}.blogs{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:8px 0 24px}.blog{display:block;background:#fff;border:1px solid #e3eaef;border-radius:12px;overflow:hidden;text-decoration:none;color:#17242b}.blog img{display:block;width:100%;height:auto;aspect-ratio:1200/630;object-fit:cover}.blog .d{display:block;padding:8px 12px 0;font-size:12px;color:#5b6b78}.blog .t{display:block;padding:2px 12px 12px;font-size:14px;font-weight:700;line-height:1.6}.wrap>.lead{font-size:15px;line-height:1.9;margin:6px 0 14px;max-width:900px}.faq h3{font-size:15px;margin:18px 0 4px}.faq p{margin:0 0 6px;line-height:1.8}.card{position:relative}.card .sold{position:absolute;top:8px;left:8px;z-index:1;background:#0a9a8f;color:#fff;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px}
.valuebar{background:#fff7e0;border-bottom:1px solid #f1dfa6;color:#4a3b00;font-size:13.5px;line-height:1.6}.valuebar .inner{max-width:1080px;margin:0 auto;padding:8px 16px;display:flex;gap:10px;align-items:baseline}.valuebar .inner>b{flex:0 0 auto;background:#e09a00;color:#fff;border-radius:999px;padding:1px 10px;font-size:12.5px}.valuebar span b{color:#8a5a00}@media (max-width:640px){.valuebar .inner{flex-direction:column;gap:4px;font-size:12.5px}}
.logo{display:flex;align-items:center;flex:0 0 auto}
.logo img{height:36px;width:auto;max-width:100%;object-fit:contain;display:block}
header.site .mascot{height:48px;width:auto;object-fit:contain;flex:0 0 auto}
@media (max-width:960px){header.site .inner{gap:8px 10px;padding:10px 16px}.logo{order:1}.logo img{height:30px}header.site .brand{order:2;flex:1 1 0;min-width:0;font-size:17px;white-space:normal}header.site .brand small{font-size:10px}header.site .mascot{order:3;height:40px}header.site .search{order:4;flex:1 1 calc(100% - 110px)}header.site .inner>.btn.ghost{order:5;flex:0 0 auto}}
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

/* --- おちゃのこネットの固定ページと同じ見え方にする ---
   /info /help は現行 exdirect.net の link_shop_table / help_page_N の作りに合わせる。
   色も向こうの値をそのまま使う（見出し帯 #1b7890・項目列 #BFE0F0 に文字 #336699・
   囲み #FFFFF0）。移行してもお客様が「同じ店」と分かるようにするため。 */
h1.ptitle{font-size:15px;margin:0}
.ptitle{background:#1b7890;color:#fff;font-size:15px;font-weight:700;padding:11px 18px;border-radius:8px 8px 0 0}
.pmiddle{background:#fff;border:1px solid #e5ebf1;border-top:0;border-radius:0 0 8px 8px;padding:8px 0 16px;font-size:14px;line-height:1.9}
.pmiddle a{color:#1b6fa8}
/* /info の2列表（左=項目・右=内容） */
.shoptbl{width:calc(100% - 28px);border-collapse:collapse;margin:10px 14px}
.shoptbl th::before{content:"❯";margin-right:4px}
.shoptbl th{width:180px;background:#BFE0F0;color:#336699;font-size:14px;font-weight:700;text-align:left;vertical-align:top;padding:14px 12px;border-bottom:2px solid #fff;border-right:2px solid #fff}
.shoptbl td{background:#F5F5F5;padding:14px;border-bottom:2px solid #fff;vertical-align:top}
.shoptbl ul{margin:0;padding-left:1.2em}
@media(max-width:640px){.shoptbl{width:auto;margin:10px}.shoptbl thead,.shoptbl tbody,.shoptbl tr,.shoptbl th,.shoptbl td{display:block;width:auto}.shoptbl th{border-right:0;border-bottom:0}}
/* /help のセクション（見出し帯 → 中身 → 区切り） */
.sechead{color:#336699;font-size:15px;font-weight:700;margin:16px 20px 0;padding:8px 5px 6px 15px}
.secbody{margin:0 20px;padding:10px 10px 0}
.secfoot{height:10px;background:#f9f7f4;margin:0 20px}
.subhead{font-size:14px;font-weight:700;border-bottom:1px solid #b5ccd2;margin:14px 0 6px;padding:8px 0 6px 12px}
.guide{padding:0 10px 8px}
.guide>b{display:block;margin-bottom:4px}
.pbox{background:#FFFFF0;border:1px solid #D4D0C8;margin:6px 6px 10px 0;padding:12px}
.pbox b{color:#336699}
.colon{padding:0 6px}
.warn{color:#d0021b;font-weight:700}
.taxtbl{border-collapse:collapse;margin:6px 10px 10px}
.taxtbl td{background:#FFFFF0;border:1px solid #D4D0C8;padding:8px 18px}
@media(max-width:640px){.sechead,.secbody,.secfoot{margin-left:10px;margin-right:10px}.secbody{padding:10px 0 0}}
</style>';
}

/** X-Direct（exdirect.net・デモ）で動いているか。店ごとの売り文句は、配布版を使う店には出さない。 */
function kv_is_xdirect(): bool
{
    return defined('KV_ORIGIN') && strpos(KV_ORIGIN, 'exdirect.net') !== false;
}

/**
 * 安さの理由の帯（2026-11-16 ユーザー指示: 全ページで「モールの出品手数料も、ネットショップサービスの利用料も
 * かからないから安い」を伝える）。ヘッダーの直下に出す。
 */
function kv_value_strip(): string
{
    if (!kv_is_xdirect()) { return ''; }
    return '<div class="valuebar"><div class="inner">'
         . '<b>安さの理由</b>'
         . '<span>Amazon・楽天・Yahoo!ショッピングのような<b>モールの出品手数料</b>も、'
         . 'ネットショップ作成サービスの<b>月額のシステム利用料</b>もかからない自社システムで運営。'
         . 'その分を価格に回して、メーカー直送でお安くお届けします。'
         . '<b>送料も原則無料</b>（北海道・沖縄・離島・海外発送などを除く）。'
         . ' <a href="' . kv_url('why-low-price') . '">くわしく</a></span></div></div>';
}

function kv_header(): void
{
    $q = kv_e($_GET['q'] ?? '');
    echo '<header class="site"><div class="inner">'
       . '<a class="logo" href="' . kv_url('') . '"><img src="' . kv_url('img/brand/exbridge-logo.svg') . '" width="205" height="44" alt="株式会社エクスブリッジ"></a>'
       . '<a class="brand" href="' . kv_url('') . '">' . kv_e(kv_shop('name'))
       . '<small>' . kv_e(kv_shop('tagline')) . '</small></a>'
       . '<form class="search" method="get" action="' . kv_url('search') . '">'
       . '<input type="text" name="q" value="' . $q . '" placeholder="商品名・型番・JANで検索" aria-label="商品検索">'
       . '<button class="btn" type="submit">検索</button></form>'
       . '<a class="btn ghost" href="' . kv_url('cart') . '">カート</a>'
       . '<img class="mascot" src="' . kv_url('img/brand/kurage-mascot.webp') . '" width="265" height="300" alt="Kurage">'
       . '</div></header>' . kv_value_strip();
}

/**
 * 「エクスブリッジについて」の欄（2026-11-16 ユーザー指示: 国会トラッカーと同じ会社概要を © の上に）。
 * 正本は /home/kojima/work/kurage_web/exbridge-about.html（{REF} は exb-about-exdirect）。
 * 売っている製品なので、当社の店（exdirect.net・exbridge.jp のデモ）で動いているときだけ出す。
 */
function kv_exb_about(): string
{
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/(^|\\.)exdirect\\.net$/', $h) && $h !== 'exbridge.jp') { return ''; }
    return '<section class="exb-about" style="max-width:1080px;margin:32px auto 0;padding:18px 16px 8px;border-top:1px solid #d6dde3;font-size:13.5px;line-height:1.75;color:#33414d;background:transparent;box-sizing:border-box;text-align:left"><h2 style="font-size:15px;line-height:1.5;margin:0 0 6px;color:#1f2d38;font-weight:700">エクスブリッジについて</h2><p style="margin:0 0 8px">このページは、名古屋のシステム開発会社・株式会社エクスブリッジが運営しています。AIを使って中小企業の業務システムを作り、保守契約・月額のシステム利用料・要件定義なしで使える買い切りの業務システムを <a href="https://kappstore.exbridge.jp/#ref=exb-about-exdirect">Kurage App Store</a> で販売しています。</p><dl style="display:grid;grid-template-columns:auto 1fr;gap:2px 12px;margin:0 0 8px"><dt style="font-weight:700">会社名</dt><dd style="margin:0">株式会社エクスブリッジ（EXBRIDGE, Inc.）</dd><dt style="font-weight:700">所在地</dt><dd style="margin:0">愛知県名古屋市瑞穂区内浜町34-9 305</dd><dt style="font-weight:700">設立</dt><dd style="margin:0">2004年4月</dd><dt style="font-weight:700">資本金</dt><dd style="margin:0">1億250万円</dd><dt style="font-weight:700">代表取締役</dt><dd style="margin:0">小嶋 篤</dd><dt style="font-weight:700">法人番号</dt><dd style="margin:0">4180001056508</dd><dt style="font-weight:700">事業</dt><dd style="margin:0">AIを使った業務システムの開発、Kurage App Store の運営、AI-IT顧問</dd></dl><p style="margin:0"><a href="https://exbridge.jp/profile.html#ref=exb-about-exdirect">会社概要</a>　<a href="https://exbridge.jp/vibeblog/2026-11-03-exbridge-yameta-3tsu-hoshu-getsugaku-youken.html#ref=exb-about-exdirect">エクスブリッジがやめた3つ</a>　<a href="https://exbridge.jp/system-kaihatsu-nagoya.html#ref=exb-about-exdirect">システム開発 名古屋</a>　<a href="https://exbridge.jp/homepage-seisaku-nagoya.html#ref=exb-about-exdirect">ホームページ制作 名古屋</a>　<a href="https://exbridge.jp/ai-it-komon.html#ref=exb-about-exdirect">AI-IT顧問</a>　<a href="https://exbridge.jp/contact.php#ref=exb-about-exdirect">お問い合わせ</a></p></section>';
}

/**
 * 全ページ共通のよくある質問（2026-11-17 ユーザー指示「安心安全な理由」「重要なことだけ」）。AEO/GEO 向け。
 * 事実だけを書く: カードは Stripe の画面で決済（当店はカード番号を受け取らない）・振込は在庫確認後・書類は PDF で発行・運営会社・正規品。
 * 会社の資本金はフッターにあるので、ここには書かない（二重にしない）。
 */
function kv_common_faq(): array
{
    if (!kv_is_xdirect()) { return []; }
    $soc = function_exists('kv_supplier_social') ? kv_supplier_social() : [];
    return [
        ['クレジットカードの情報は安全ですか？', 'カード決済は、決済会社Stripe（ストライプ）の決済画面で行います。当店はカード番号を受け取らず、保存もしません。通信はSSLで暗号化しています。'],
        ['先に代金を振り込む必要がありますか？', 'いいえ。ご注文のあと、メーカー・仕入先に在庫と納期を確認してから振込先をご案内します。在庫・納期をご確認いただいてからのお振込みです。'],
        ['領収書・見積書・請求書・納品書は発行できますか？', '発行できます。インボイス（適格請求書）に対応し、宛名・但し書きのご指定も承ります。商品は直送のため、書類はPDFでメールにてお送りします。大学・研究機関などの後払いにも対応しています。'],
        ['どんな会社が運営していますか？', '名古屋市の株式会社エクスブリッジ（2004年設立）が運営しています。東京大学・京都大学などの大学や研究機関にも納入しています。' . kv_common_faq_social(), $soc ? $soc['portal'] . '#ref=exdirect-faq' : ''],
        ['商品は正規品ですか？', 'メーカー・仕入先から直送する新品の正規品です。メーカー保証のある商品は、メーカーの保証が受けられます。'],
    ];
}

/**
 * 「どんな会社が」の答えに足す、X公式アカウントの信用（2026-11-17 ユーザー指示: 商材募集ページのように国会議員のフォロワー・フォロワーポータル）。
 * 数字は商材募集ページと同じ kv_data/supplier_social.json から。無ければ何も足さない。
 */
function kv_common_faq_social(): string
{
    $soc = function_exists('kv_supplier_social') ? kv_supplier_social() : [];
    if (!$soc) { return ''; }
    $c = $soc['counts'];
    return '信頼されているX公式アカウント @' . $soc['account']['handle'] . ' も運用しています。' . (int)$soc['account']['since'] . '年から運用し、フォロワーは'
         . number_format((int)$soc['account']['followers']) . '人。党首・大臣を含む国会議員' . (int)$c['giin'] . '人、首長' . (int)$c['shucho'] . '人、'
         . 'フォロワー1万人を超えるインフルエンサー' . (int)$c['influencers'] . '人、企業' . (int)$c['companies'] . '社にフォローされています（'
         . date('Y年n月', strtotime($soc['built'])) . '時点）。フォローしてくださっている方々は「Kurage フォロワーポータル」で紹介しています。';
}

/** 共通のよくある質問の FAQPage 用の要素（トップ・メーカーページの構造化データに入れる）。 */
function kv_common_faq_ld(): array
{
    return array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], kv_common_faq());
}

function kv_footer(): void
{
    $cf = kv_common_faq();
    if ($cf) {
        // 開閉式で全ページに出す（ページを長くしない。中身は検索エンジン・AI も読める）
        echo '<section class="cfaq"><div class="inner"><h2>安心してご利用いただくために</h2>';
        foreach ($cf as $x) {
            echo '<details><summary>' . kv_e($x[0]) . '</summary><p>' . kv_e($x[1])
               . (!empty($x[2]) ? ' <a href="' . kv_e($x[2]) . '">Kurage フォロワーポータルを見る</a>' : '') . '</p></details>';
        }
        echo '<p class="src"><a href="' . kv_url('page/16') . '">よくあるご質問をもっと見る</a> ・ <a href="' . kv_url('info') . '">特定商取引法表示</a></p></div></section>';
    }
    // 規約・プライバシーポリシーは **全ページから1クリックで開ける**ようにする
    echo '<footer class="site"><div class="inner">'
       . '<p><a href="' . kv_url('info') . '">特定商取引法表示</a>'
       . ' ・ <a href="' . kv_url('help') . '">ご利用案内</a>'
       . ' ・ <a href="' . kv_url('terms') . '">ご利用規約</a>'
       . ' ・ <a href="' . kv_url('privacy') . '">プライバシーポリシー</a>'
       . ' ・ <a href="' . kv_url('page/16') . '">よくあるご質問</a>'
       . ' ・ <a href="' . kv_url('contact') . '">お問い合わせ</a></p>'
       . '<p><a href="' . kv_url('page/1') . '">' . kv_e(kv_shop('name')) . 'について</a>'
       . ' ・ <a href="' . kv_url('makers') . '">メーカー一覧</a>'
       . (kv_is_xdirect() ? ' ・ <a href="' . kv_url('supplier') . '">商材募集（メーカー・問屋さまへ）</a>'
                          . ' ・ <a href="' . kv_url('why-low-price') . '">Amazon・楽天より安くできる理由</a>'
                          . (function_exists('kv_systems') && kv_systems() ? ' ・ <a href="' . kv_url('business-systems') . '">業務システム（買い切り）</a>' : '') : '') . '</p>'
       . kv_exb_about()
       . '<p>&copy; ' . date('Y') . ' ' . kv_e(kv_shop('company')) . '</p>'
       . ((($_SERVER['HTTP_HOST'] ?? '') === 'exbridge.jp') ? '<p style="font-size:13px">この店は Kurage Vibe-Cart のデモです。<a href="https://kappstore.exbridge.jp/app.php?id=754c7ddb5f1c8f26&amp;ref=exdirect" target="_blank" rel="noopener">PHPだけで動くECをオンプレミスで（商品ページ）</a></p>' : '')
       . '</div></footer></body></html>';
}

/** 商品カード1枚。一覧のどこでも同じ見た目にする。 */
function kv_card(array $p, string $note = ''): void
{
    $incl = kv_price_incl($p);
    echo '<div class="card">'
       . ($note !== '' ? '<div class="sold">' . kv_e($note) . '</div>' : '')
       . '<a class="thumb" href="' . kv_url('product/' . (int)$p['id']) . '">'
       . ($p['image_url'] ? '<img src="' . kv_e(kv_img($p['image_url'])) . '" alt="' . kv_e($p['name']) . '" loading="lazy">' : '')
       . '</a><div class="b">'
       . '<a class="nm" href="' . kv_url('product/' . (int)$p['id']) . '">' . kv_e($p['name']) . '</a>'
       . (($mdl = kv_model_display($p['model_number'], $p['name'])) !== '' ? '<div class="mn">' . kv_e($mdl) . '</div>' : '')
       . '<div class="pr">' . ($incl === null ? '<small>価格・納期はお問い合わせください</small>'
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
