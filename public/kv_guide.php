<?php
/**
 * 選び方ガイド（/guide/<slug>・2026-11-19）
 *
 * 型番を知らない人が使う品目の言葉（騒音計・照度計・紫外線強度計・レジまわり など）で検索に出るため、
 * また ChatGPT・Google の AI による概要に引用されやすくするため（AEO・GEO）、品目ごとの選び方を1ページにまとめ、
 * その店で売っている商品へ案内する。
 *
 * 中身は kv_data/guides/<slug>.json（プログラムを触らずに足せる）:
 *   {"slug","title","desc","lead","html","products":[商品ID],"faq":[{"q","a"}],"related_makers":[メーカーID],"checked_at"}
 * 本文（html）の中は店の中へのリンクだけ（外部リンクは置かない）。価格は書かない（商品カードが今の価格を出す）。
 */
declare(strict_types=1);

/** すべてのガイド（slug => データ）。 */
function kv_guides(): array
{
    static $g = null;
    if ($g !== null) { return $g; }
    $g = [];
    foreach (glob(KV_DATA . '/guides/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && !empty($d['slug']) && preg_match('/^[a-z0-9-]+$/', (string)$d['slug']) && !empty($d['title'])) {
            $g[(string)$d['slug']] = $d;
        }
    }
    ksort($g);
    return $g;
}

/** ガイドの一覧ページ（/guide）。 */
function kv_page_guide_index(): void
{
    $g = kv_guides();
    kv_head('商品の選び方ガイド', '計測機器・店舗機器などの選び方を、用途・規格・選ぶときの基準からまとめています。', '/guide');
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › 選び方ガイド</nav><h1>商品の選び方ガイド</h1>'
       . '<div class="panel"><ul>';
    foreach ($g as $s => $d) {
        echo '<li><a href="' . kv_url('guide/' . $s) . '">' . kv_e($d['title']) . '</a>'
           . (!empty($d['desc']) ? '<div class="src">' . kv_e($d['desc']) . '</div>' : '') . '</li>';
    }
    echo '</ul></div></div>';
    kv_footer();
}

/** ガイド1本（/guide/<slug>）。 */
function kv_page_guide(string $slug): void
{
    $d = kv_guides()[$slug] ?? null;
    $path = '/guide/' . $slug;
    if (!$d) {
        http_response_code(404);
        kv_head('ページが見つかりません', '', $path, ['noindex' => true]);
        echo '<div class="wrap"><h1>ページが見つかりません</h1><p><a class="btn" href="' . kv_url('guide') . '">選び方ガイドの一覧</a></p></div>';
        kv_footer();
        return;
    }
    $o = kv_origin();
    $faq = array_values(array_filter((array)($d['faq'] ?? []), fn($x) => !empty($x['q']) && !empty($x['a'])));
    $prods = [];
    foreach ((array)($d['products'] ?? []) as $pid) { if ($p = kv_product((int)$pid)) { $prods[] = $p; } }
    $date = (string)($d['checked_at'] ?? '');
    $graph = ['@context' => 'https://schema.org', '@graph' => array_values(array_filter([
        ['@type' => 'Article', 'headline' => (string)$d['title'], 'description' => (string)($d['desc'] ?? ''), 'url' => $o . $path,
         'datePublished' => $date ?: null, 'dateModified' => $date ?: null, 'inLanguage' => 'ja',
         'author' => ['@type' => 'Organization', 'name' => kv_shop('company')],
         'publisher' => ['@type' => 'Organization', 'name' => kv_shop('company')]],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'トップ', 'item' => $o . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '選び方ガイド', 'item' => $o . '/guide'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => (string)$d['title']]]],
        $faq ? ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => (string)$x['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string)$x['a']]], $faq)] : null,
        $prods ? ['@type' => 'ItemList', 'name' => (string)$d['title'] . '：' . kv_shop('name') . 'の取扱商品', 'itemListElement' => array_map(
            fn($p, $k) => ['@type' => 'ListItem', 'position' => $k + 1, 'url' => $o . '/product/' . (int)$p['id'], 'name' => (string)$p['name']],
            $prods, array_keys($prods))] : null,
    ]))];
    array_walk($graph['@graph'], function (&$n) { $n = array_filter($n, fn($v) => $v !== null); });
    kv_head((string)$d['title'], (string)($d['desc'] ?? ''), $path, ['jsonld' => $graph]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">トップ</a> › <a href="' . kv_url('guide') . '">選び方ガイド</a> › '
       . kv_e($d['title']) . '</nav><h1>' . kv_e($d['title']) . '</h1>'
       . (!empty($d['lead']) ? '<p class="lead">' . kv_e($d['lead']) . '</p>' : '')
       . '<div class="panel desc guide">' . (string)($d['html'] ?? '') . '</div>';
    if ($prods) {
        echo '<h2>' . kv_e(kv_shop('name')) . 'で扱っている機種</h2><div class="grid">';
        foreach ($prods as $p) { kv_card($p); }
        echo '</div>';
    }
    if ($faq) {
        echo '<h2>よくある質問</h2><div class="faq">';
        foreach ($faq as $x) { echo '<h3>' . kv_e($x['q']) . '</h3><p>' . kv_e($x['a']) . '</p>'; }
        echo '</div>';
    }
    $mk = [];
    foreach ((array)($d['related_makers'] ?? []) as $mid) { if ($m = kv_maker((int)$mid)) { $mk[] = $m; } }
    if ($mk) {
        echo '<p>' . implode('　', array_map(fn($m) => '<a href="' . kv_url('product-group/' . (int)$m['id']) . '">' . kv_e($m['name']) . 'の商品一覧</a>', $mk)) . '</p>';
    }
    echo '<p class="src">機種の選び方や在庫・納期は、商品ページのお問い合わせからお気軽にどうぞ。メーカーに確認してご案内します。'
       . ($date !== '' ? '（' . kv_e($date) . ' 時点）' : '') . '</p></div>';
    kv_footer();
}

/** メーカーの一覧ページ・商品ページに出す、関係するガイドへの案内。$mid: メーカーID、$pid: 商品ID（0 なら見ない）。 */
function kv_guide_links(int $mid, int $pid = 0): string
{
    $hit = [];
    foreach (kv_guides() as $s => $d) {
        $byP = $pid > 0 && in_array($pid, array_map('intval', (array)($d['products'] ?? [])), true);
        $byM = $pid === 0 && in_array($mid, array_map('intval', (array)($d['related_makers'] ?? [])), true);
        if ($byP || $byM) { $hit[$s] = $d; }
    }
    if (!$hit) { return ''; }
    return '<p class="src">選び方ガイド：' . implode('　', array_map(fn($s, $d) => '<a href="' . kv_url('guide/' . $s) . '">' . kv_e($d['title']) . '</a>',
        array_keys($hit), $hit)) . '</p>';
}
