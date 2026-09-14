<?php
/**
 * Kurage Vibe-Cart — データアクセス（SQLite・PDO）
 *
 * 設計の要:
 *   - **商品IDはおちゃのこネットのものをそのまま使う。** /product/11366 というURLを
 *     1文字も変えないための決まりで、ここを振り直すとブックマークと検索インデックスが全部切れる。
 *   - 移行しなかった商品は retired_products に名前だけ残す。/product/<id> で404を出さず、
 *     「取り扱いを終了しました」＋同メーカーの現行商品へ案内するため。
 *   - 常駐プロセスを使わない。レンタルサーバーにFTPで置けば動く（heteml 実測 PHP 8.2.33）。
 */
declare(strict_types=1);

if (!defined('KV_ROOT')) { define('KV_ROOT', __DIR__); }
if (!defined('KV_DATA')) { define('KV_DATA', KV_ROOT . '/kv_data'); }
if (!defined('KV_DB'))   { define('KV_DB', KV_DATA . '/shop.sqlite'); }

// 公開時のベースパス。デモは /exdirect、独自ドメインへ移したら '' にするだけで
// おちゃのこネットと同じURL（/product/11366）になる。
if (!defined('KV_BASE')) { define('KV_BASE', getenv('KV_BASE') !== false ? getenv('KV_BASE') : ''); }

function kv_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_file(KV_DB)) {
            throw new RuntimeException('商品データがまだありません: ' . KV_DB);
        }
        $pdo = new PDO('sqlite:' . KV_DB, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
    }
    return $pdo;
}

function kv_url(string $path = ''): string
{
    return KV_BASE . '/' . ltrim($path, '/');
}

function kv_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function kv_yen($n): string
{
    if ($n === null || $n === '') { return '価格はお問い合わせください'; }
    return number_format((int)$n) . '円';
}

/** 税込価格。おちゃのこネットの price は税抜。軽減税率は tax_reduce で分ける。 */
function kv_price_incl(array $p): ?int
{
    if ($p['price'] === null || (int)$p['price'] === 0) { return null; }
    $rate = ((int)($p['tax_reduce'] ?? 0) === 1) ? 1.08 : 1.10;
    return (int)floor((int)$p['price'] * $rate);
}

function kv_product(int $id): ?array
{
    $st = kv_db()->prepare('SELECT * FROM products WHERE id=? AND hidden=0');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** 移行しなかった商品。ここに居れば「取り扱い終了」ページを出す（404にしない）。 */
function kv_retired(int $id): ?array
{
    $st = kv_db()->prepare('SELECT * FROM retired_products WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function kv_makers(): array
{
    return kv_db()->query('SELECT m.*, (SELECT COUNT(*) FROM products p WHERE p.maker_id=m.id AND p.hidden=0) AS cnt'
                          . ' FROM makers m ORDER BY cnt DESC')->fetchAll();
}

function kv_maker(int $id): ?array
{
    $st = kv_db()->prepare('SELECT * FROM makers WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function kv_maker_by_slug(string $slug): ?array
{
    $st = kv_db()->prepare('SELECT * FROM makers WHERE slug=?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function kv_category(int $id): ?array
{
    $st = kv_db()->prepare('SELECT * FROM categories WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * 商品を引く。$where は ['maker_id'=>54] のような単純な等値と、'q'（名前・型番の部分一致）。
 * 一覧は必ずページングする（5,736件を一度に出すとメモリ128Mで落ちる）。
 */
function kv_products(array $f = [], int $page = 1, int $per = 24): array
{
    $w = ['hidden=0'];
    $a = [];
    if (!empty($f['maker_id']))    { $w[] = 'maker_id=?';    $a[] = (int)$f['maker_id']; }
    if (!empty($f['category_id'])) { $w[] = 'category_id=?'; $a[] = (int)$f['category_id']; }
    if (!empty($f['q'])) {
        $w[] = '(name LIKE ? OR model_number LIKE ?)';
        $a[] = '%' . $f['q'] . '%';
        $a[] = '%' . $f['q'] . '%';
    }
    $sql = 'FROM products WHERE ' . implode(' AND ', $w);
    $st = kv_db()->prepare('SELECT COUNT(*) ' . $sql);
    $st->execute($a);
    $total = (int)$st->fetchColumn();
    $page = max(1, $page);
    $off = ($page - 1) * $per;
    $st = kv_db()->prepare('SELECT id,name,model_number,price,list_price,stock,stock_unlimited,'
                           . 'image_url,maker_id,category_id,tax_reduce ' . $sql
                           . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)$off);
    $st->execute($a);
    return ['items' => $st->fetchAll(), 'total' => $total, 'page' => $page,
            'per' => $per, 'pages' => (int)ceil($total / $per)];
}

/** 取り扱い終了の商品から、同じメーカー・同じカテゴリの現行商品を薦める。 */
function kv_suggest(array $retired, int $limit = 12): array
{
    $db = kv_db();
    $out = [];
    if (!empty($retired['maker_name'])) {
        $st = $db->prepare('SELECT id FROM makers WHERE name LIKE ?');
        $st->execute(['%' . $retired['maker_name'] . '%']);
        $mid = $st->fetchColumn();
        if ($mid) {
            $st = $db->prepare('SELECT id,name,model_number,price,image_url,tax_reduce FROM products'
                               . ' WHERE maker_id=? AND hidden=0 ORDER BY id DESC LIMIT ?');
            $st->execute([(int)$mid, $limit]);
            $out = $st->fetchAll();
        }
    }
    if (!$out && !empty($retired['category_id'])) {
        $st = $db->prepare('SELECT id,name,model_number,price,image_url,tax_reduce FROM products'
                           . ' WHERE category_id=? AND hidden=0 ORDER BY id DESC LIMIT ?');
        $st->execute([(int)$retired['category_id'], $limit]);
        $out = $st->fetchAll();
    }
    if (!$out && !empty($retired['category_id'])) {
        // 同じ親カテゴリまで広げる（「クランプ」が無くても「荷役・運搬機器」なら在る、を拾う）
        $st = $db->prepare('SELECT p.id,p.name,p.model_number,p.price,p.image_url,p.tax_reduce'
                           . ' FROM products p JOIN categories c ON c.id=p.category_id'
                           . ' WHERE c.parent_id=(SELECT parent_id FROM categories WHERE id=?)'
                           . ' AND p.hidden=0 ORDER BY p.id DESC LIMIT ?');
        $st->execute([(int)$retired['category_id'], $limit]);
        $out = $st->fetchAll();
    }
    if (!$out) {
        // それでも無ければ、いま扱っている商品を出す。**空のページを見せない**
        $st = $db->prepare('SELECT id,name,model_number,price,image_url,tax_reduce FROM products'
                           . ' WHERE hidden=0 ORDER BY id DESC LIMIT ?');
        $st->execute([$limit]);
        $out = $st->fetchAll();
    }
    return $out;
}

function kv_stats(): array
{
    $db = kv_db();
    return [
        'products' => (int)$db->query('SELECT COUNT(*) FROM products WHERE hidden=0')->fetchColumn(),
        'retired'  => (int)$db->query('SELECT COUNT(*) FROM retired_products')->fetchColumn(),
        'makers'   => (int)$db->query('SELECT COUNT(*) FROM makers')->fetchColumn(),
    ];
}

/** そのメーカー名が「いま取り扱っている14社」に居るか。見出しの出し分けに使う。 */
function kv_maker_name_exists(string $name): bool
{
    $st = kv_db()->prepare('SELECT COUNT(*) FROM makers WHERE name LIKE ?');
    $st->execute(['%' . $name . '%']);
    return (int)$st->fetchColumn() > 0;
}
