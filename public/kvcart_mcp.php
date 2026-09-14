<?php
/**
 * Kurage Vibe-Cart — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * Claude Code / Codex / Claude Desktop から、このECサイトを「バイブコーディングで運用」するための橋。
 * 商品の登録・更新、固定ページの作成、受注の確認と処理まで、チャットから直接できる。
 *
 * 設置:
 *   claude mcp add kvcart -- php /path/to/public/kvcart_mcp.php
 *   Codex は ~/.codex/config.toml に
 *     [mcp_servers.kvcart]
 *     command = "php"
 *     args = ["/path/to/public/kvcart_mcp.php"]
 *
 * 設計（kdbagent・kaimom・klcrm・kjishin と同じ約束）:
 *   - **製品本体の関数をそのまま呼ぶ薄い橋**。ここで別のロジックを作らない。
 *   - **商品IDは勝手に振り直さない。** /product/<id> というURLがブックマークされているので、
 *     IDを変えるとそのお客様がたどり着けなくなる。新規登録のときだけ採番する。
 *   - **金額と在庫はAIが推測で入れてはいけない。** 呼び出し側が明示した値だけを書く。
 *   - 受注の「発送済み」などは実際の作業が終わってから立てる。AIが先に立てない。
 *   - notes を必ず返し、AIが数字だけ抜いて断言しないようにする。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('KVCART_MCP_VERSION', '1.0.0');

$dir = __DIR__;
foreach (['kv_shop.php', 'kv_lib.php', 'kv_order.php'] as $f) {
    if (!is_file("$dir/$f")) { fwrite(STDERR, "$f が同じフォルダにありません\n"); exit(1); }
}
ob_start();
require "$dir/kv_shop.php";
require "$dir/kv_lib.php";
require "$dir/kv_order.php";
ob_end_clean();

if (!function_exists('kv_db')) { fwrite(STDERR, "kvcart を読み込めませんでした\n"); exit(1); }

$NOTES = [
    'このECは /product/<商品ID> というURLでブックマークされています。**商品IDは変えないでください。**',
    '金額・在庫・型番は、利用者が指示した値だけを書いてください。推測で埋めてはいけません。',
    '「発送済み」などの処理は、実際の作業が終わってから立ててください。',
];

function j($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); }
function err($m) { return [false, j(['error' => $m])]; }

// ---------------- ツール ----------------

function t_search(array $a)
{
    $r = kv_products(['q' => (string)($a['q'] ?? ''), 'maker_id' => $a['maker_id'] ?? null],
                     (int)($a['page'] ?? 1), min(50, max(1, (int)($a['limit'] ?? 20))));
    $out = array_map(function ($p) {
        return ['id' => (int)$p['id'], 'name' => $p['name'], 'model_number' => $p['model_number'],
                'price_excl' => (int)$p['price'], 'price_incl' => kv_price_incl($p),
                'stock' => (int)$p['stock'], 'url' => '/product/' . (int)$p['id']];
    }, $r['items']);
    return [true, j(['total' => $r['total'], 'page' => $r['page'], 'items' => $out, 'notes' => $GLOBALS['NOTES']])];
}

function t_get(array $a)
{
    $id = (int)($a['id'] ?? 0);
    $p = kv_product($id);
    if (!$p) {
        $r = kv_retired($id);
        if ($r) {
            return [true, j(['retired' => true, 'id' => $id, 'name' => $r['name'],
                             'maker_name' => $r['maker_name'],
                             'note' => 'この商品は移行対象外です。/product/' . $id . ' は「取り扱い終了」を返します。'])];
        }
        return err("商品が見つかりません: $id");
    }
    $p['price_incl'] = kv_price_incl($p);
    $p['url'] = '/product/' . $id;
    return [true, j(['product' => $p, 'notes' => $GLOBALS['NOTES']])];
}

function t_upsert(array $a)
{
    $id = isset($a['id']) ? (int)$a['id'] : 0;
    $name = trim((string)($a['name'] ?? ''));
    if ($id <= 0 && $name === '') { return err('新規登録には name が要ります'); }
    $db = kv_db();
    if ($id > 0) {
        $cur = kv_product($id);
        if (!$cur) { return err("商品 $id が見つかりません。新規なら id を省いてください（IDは自動採番）"); }
    } else {
        // **既存IDとぶつからない採番。** おちゃのこネットのIDは1289〜99954なので、
        // 新規は 100001 から振って、移行済みのURLと衝突させない。
        $max = (int)$db->query('SELECT MAX(id) FROM products')->fetchColumn();
        $maxr = (int)$db->query('SELECT MAX(id) FROM retired_products')->fetchColumn();
        $id = max($max, $maxr, 100000) + 1;
        $cur = [];
    }
    $cols = ['name', 'model_number', 'gtin', 'price', 'list_price', 'stock', 'stock_unlimited',
             'hidden', 'category_id', 'maker_id', 'description', 'image_url', 'tax_reduce'];
    // 新規のときの既定値。**hidden を NULL のままにすると店に出ない**
    // （kv_product は hidden=0 で絞るので、NULL は一致しない。実測で踏んだ）
    $def = ['hidden' => 0, 'stock' => 0, 'stock_unlimited' => 0, 'tax_reduce' => 0,
            'price' => 0, 'list_price' => 0];
    $vals = [];
    foreach ($cols as $c) {
        $vals[$c] = array_key_exists($c, $a) ? $a[$c]
                  : (array_key_exists($c, $cur) && $cur[$c] !== null ? $cur[$c] : ($def[$c] ?? null));
    }
    if (trim((string)$vals['name']) === '') { return err('name が空です'); }
    $db->prepare('INSERT OR REPLACE INTO products (id,' . implode(',', $cols)
        . ',updated_at,imported_at) VALUES (?,' . implode(',', array_fill(0, count($cols), '?')) . ',?,?)')
       ->execute(array_merge([$id], array_values($vals),
                             [date('c'), $cur['imported_at'] ?? date('c')]));
    return [true, j(['ok' => true, 'id' => $id, 'url' => '/product/' . $id,
                     'created' => empty($cur),
                     'notes' => array_merge($GLOBALS['NOTES'],
                        ['登録した内容は店にすぐ出ます。公開前に /product/' . $id . ' を目で確かめてください。'])])];
}

function t_makers(array $a)
{
    return [true, j(['makers' => kv_makers(), 'notes' => $GLOBALS['NOTES']])];
}

function t_orders(array $a)
{
    $f = [];
    foreach (['q', 'from', 'to'] as $k) { if (isset($a[$k])) { $f[$k] = (string)$a[$k]; } }
    foreach (array_keys(KV_FLAGS) as $k) {
        if (isset($a['f_' . $k])) { $f['f_' . $k] = (string)(int)$a['f_' . $k]; }
    }
    $r = kv_orders($f, (int)($a['page'] ?? 1), min(100, max(1, (int)($a['limit'] ?? 30))));
    $out = array_map(function ($o) {
        $s = [];
        foreach (KV_FLAGS as $k => $label) { $s[$label] = (int)$o['f_' . $k] === 1; }
        return ['id' => (int)$o['id'], 'code' => $o['code'], 'created_at' => $o['created_at'],
                'name' => $o['name'], 'company' => $o['company'], 'total' => (int)$o['total'],
                'payment' => $o['payment'], 'cancelled' => (int)$o['cancelled'] === 1,
                '処理状況' => $s];
    }, $r['items']);
    return [true, j(['total' => $r['total'], 'orders' => $out,
                     'notes' => ['処理状況は「受注・入金・発送・その他」を個別に持ちます（おちゃのこネットと同じ）。']])];
}

function t_order(array $a)
{
    $id = (int)($a['id'] ?? 0);
    $o = kv_order($id);
    if (!$o) { return err("受注が見つかりません: $id"); }
    $s = [];
    foreach (KV_FLAGS as $k => $label) {
        $s[$label] = ['完了' => (int)$o['f_' . $k] === 1, '日時' => $o[$k . '_at']];
    }
    return [true, j(['order' => $o, '処理状況' => $s,
                     'items' => kv_order_items($id), 'logs' => kv_order_logs($id),
                     'notes' => $GLOBALS['NOTES']])];
}

function t_order_flag(array $a)
{
    $id = (int)($a['id'] ?? 0);
    $flag = (string)($a['flag'] ?? '');
    if (!kv_order($id)) { return err("受注が見つかりません: $id"); }
    if (!isset(KV_FLAGS[$flag])) {
        return err('flag は ' . implode(' / ', array_keys(KV_FLAGS)) . ' のいずれかです');
    }
    $on = !isset($a['on']) || (bool)$a['on'];
    kv_order_flag($id, $flag, $on, !empty($a['send_mail']));
    return [true, j(['ok' => true, 'id' => $id, 'flag' => KV_FLAGS[$flag], 'on' => $on,
                     'mail' => !empty($a['send_mail']),
                     'notes' => ['実際の作業（入金確認・発送）が済んでから立ててください。']])];
}

function t_stats(array $a)
{
    $s = kv_stats();
    $db = kv_db();
    try {
        kv_order_schema();
        $s['orders'] = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $s['orders_unpaid'] = (int)$db->query('SELECT COUNT(*) FROM orders WHERE f_paid=0 AND cancelled=0')->fetchColumn();
        $s['orders_unshipped'] = (int)$db->query('SELECT COUNT(*) FROM orders WHERE f_paid=1 AND f_shipped=0 AND cancelled=0')->fetchColumn();
    } catch (Exception $e) { /* 受注がまだ無い */ }
    $s['base_url'] = KV_BASE;
    return [true, j(['stats' => $s, 'notes' => $GLOBALS['NOTES']])];
}

$TOOLS = [
    ['name' => 'kvcart_search_products',
     'description' => '商品を検索する。商品名・型番の部分一致、メーカー(maker_id)で絞れる。URLも返す。',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'q' => ['type' => 'string', 'description' => '商品名・型番の一部'],
         'maker_id' => ['type' => 'integer', 'description' => 'メーカーID（kvcart_makers で調べる）'],
         'page' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'description' => '既定20・最大50'],
     ], 'required' => []]],
    ['name' => 'kvcart_get_product',
     'description' => '商品を1件返す。移行対象外のIDなら retired=true を返す（そのURLは「取り扱い終了」ページになる）。',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'id' => ['type' => 'integer', 'description' => '商品ID（/product/<id> の数字）']], 'required' => ['id']]],
    ['name' => 'kvcart_upsert_product',
     'description' => '商品を登録・更新する。**id を渡すと更新、省くと新規（100001から採番）。** '
                    . '既存商品の id は絶対に変えないこと（URLがブックマークされている）。'
                    . '金額・在庫は利用者が指示した値だけを入れ、推測で埋めない。',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'id' => ['type' => 'integer', 'description' => '更新する商品ID。新規なら省く'],
         'name' => ['type' => 'string'], 'model_number' => ['type' => 'string'],
         'price' => ['type' => 'integer', 'description' => '税抜の本体価格'],
         'list_price' => ['type' => 'integer', 'description' => 'メーカー希望小売価格'],
         'stock' => ['type' => 'integer'], 'stock_unlimited' => ['type' => 'integer', 'description' => '1で在庫無制限'],
         'hidden' => ['type' => 'integer', 'description' => '1で非公開'],
         'maker_id' => ['type' => 'integer'], 'category_id' => ['type' => 'integer'],
         'description' => ['type' => 'string', 'description' => '商品説明（HTML可）'],
         'image_url' => ['type' => 'string'],
         'tax_reduce' => ['type' => 'integer', 'description' => '1で軽減税率8%'],
     ], 'required' => []]],
    ['name' => 'kvcart_makers',
     'description' => 'メーカー（グループ）の一覧と取扱点数を返す。/product-group/<id> のIDでもある。',
     'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []]],
    ['name' => 'kvcart_orders',
     'description' => '受注を一覧する。期間・キーワード・処理状況（f_received / f_paid / f_shipped / f_other）で絞れる。',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'q' => ['type' => 'string'], 'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
         'to' => ['type' => 'string'], 'f_paid' => ['type' => 'integer'], 'f_shipped' => ['type' => 'integer'],
         'f_received' => ['type' => 'integer'], 'f_other' => ['type' => 'integer'],
         'page' => ['type' => 'integer'], 'limit' => ['type' => 'integer'],
     ], 'required' => []]],
    ['name' => 'kvcart_get_order',
     'description' => '受注を1件、明細と履歴つきで返す。',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'id' => ['type' => 'integer']], 'required' => ['id']]],
    ['name' => 'kvcart_set_order_flag',
     'description' => '受注の処理状況を立てる／降ろす。受注・入金・発送・その他はそれぞれ独立している。'
                    . '**実際の作業が終わってから立てること。** send_mail を真にするとお客様へメールを送る。',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'id' => ['type' => 'integer'],
         'flag' => ['type' => 'string', 'description' => 'received / paid / shipped / other'],
         'on' => ['type' => 'boolean', 'description' => '既定true。falseで取り消し'],
         'send_mail' => ['type' => 'boolean', 'description' => 'お客様へメールを送るか'],
     ], 'required' => ['id', 'flag']]],
    ['name' => 'kvcart_stats',
     'description' => '取扱点数・メーカー数・受注数・未入金・未発送の件数を返す。',
     'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []]],
];

function out($m) { echo json_encode($m, JSON_UNESCAPED_UNICODE) . "\n"; flush(); }

$in = fopen('php://stdin', 'r');
while (($line = fgets($in)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    $rid = $req['id'] ?? null;
    $method = (string)($req['method'] ?? '');
    $params = $req['params'] ?? [];
    if ($rid === null && strpos($method, 'notifications/') === 0) { continue; }
    switch ($method) {
        case 'initialize':
            out(['jsonrpc' => '2.0', 'id' => $rid, 'result' => [
                'protocolVersion' => (string)($params['protocolVersion'] ?? '2024-11-05'),
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'kvcart', 'version' => KVCART_MCP_VERSION],
                'instructions' =>
                    'PHPだけで動くECサイト（Kurage Vibe-Cart）の運用窓口です。商品の登録・更新、'
                    . '受注の確認と処理ができます。'
                    . '**商品IDは /product/<id> というURLそのものです。既存商品のIDを変えてはいけません。**'
                    . '（お客様のブックマークと検索結果がそのIDを指しています）'
                    . '金額・在庫・型番は利用者が指示した値だけを書き、推測で埋めないでください。'
                    . '「発送済み」などの処理状況は、実際の作業が終わってから立ててください。'
                    . '受注の処理状況は「受注・入金・発送・その他」がそれぞれ独立しています。',
            ]]);
            break;
        case 'ping':
            out(['jsonrpc' => '2.0', 'id' => $rid, 'result' => new stdClass()]);
            break;
        case 'tools/list':
            out(['jsonrpc' => '2.0', 'id' => $rid, 'result' => ['tools' => $TOOLS]]);
            break;
        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $args = $params['arguments'] ?? [];
            $map = [
                'kvcart_search_products' => 't_search', 'kvcart_get_product' => 't_get',
                'kvcart_upsert_product' => 't_upsert', 'kvcart_makers' => 't_makers',
                'kvcart_orders' => 't_orders', 'kvcart_get_order' => 't_order',
                'kvcart_set_order_flag' => 't_order_flag', 'kvcart_stats' => 't_stats',
            ];
            try {
                if (isset($map[$name])) { [$ok, $text] = $map[$name]($args); }
                else { [$ok, $text] = err("使えないツールです: $name"); }
            } catch (Throwable $e) { [$ok, $text] = err($e->getMessage()); }
            out(['jsonrpc' => '2.0', 'id' => $rid,
                 'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => !$ok]]);
            break;
        default:
            if ($rid !== null) {
                out(['jsonrpc' => '2.0', 'id' => $rid,
                     'error' => ['code' => -32601, 'message' => "未対応のメソッド: $method"]]);
            }
    }
}
