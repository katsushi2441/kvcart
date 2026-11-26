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
        // **レンタルサーバーでは WAL を使わない。** WAL は -wal / -shm をディレクトリに
        // 作るので、書き込み権限が無いと読むだけで "attempt to write a readonly database"
        // になる（heteml で実際に踏んだ。2026-09-14）。DELETE ジャーナルなら読みだけで済む。
        try { $pdo->exec('PRAGMA journal_mode=DELETE'); } catch (PDOException $e) { /* 読み取り専用でも続行 */ }
        // 検索で全角・半角、英字の大小を区別しないため、SQL から kv_norm() を呼べるようにする（2026-11-16）
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('kvnorm', fn($s) => kv_norm((string)$s), 1);
        }
    }
    return $pdo;
}

/**
 * 検索用に文字をそろえる。英数字・記号・空白は半角、半角カナは全角、英字は小文字にする。
 * 「ＬＥＤ」「LED」「led」が同じになる。ハイフンに似た記号（－‐−―）も「-」にそろえる。
 */
function kv_norm(string $s): string
{
    $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    $s = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2212}", "\u{2015}"], '-', $s);
    return mb_strtolower($s, 'UTF-8');
}

function kv_url(string $path = ''): string
{
    return KV_BASE . '/' . ltrim($path, '/');
}


/** 商品画像のURL。自前に取り込んだものは相対パス（img/…）で入っている。
 *  取り込めなかった分は旧サイトの絶対URLのまま残しているので、そのまま返す。 */
function kv_img(?string $u): string
{
    $u = (string)$u;
    if ($u === '') { return ''; }
    if (strpos($u, 'http') === 0) { return $u; }
    return kv_url($u);
}


/**
 * お客様に見せる型番（2026-11-17）。DB の model_number は仕入先の社内記号付き（TMT- MMS16- OYA- など）で、
 * 発注先を見分けるために残すが、お客様の画面・メールには出さない。
 * 1) 型番がそのまま商品名にあればそのまま（マザーツールの MT-600 のように記号に見えて実は型番のもの）
 * 2) 先頭の記号を外した残りが商品名にあれば、それ
 * 3) どちらでもなければ空（型番は商品名の先頭に入っているので出さない）
 */
function kv_model_display($mn, $name): string
{
    $mn = trim((string)$mn); $name = (string)$name;
    if ($mn === '') { return ''; }
    // 比べるときは全角半角・大小・空白・ハイフン等の違いを無視する（FS-04N-MS と商品名の「FS-04N MS」は同じ）
    $n = static fn(string $s): string => preg_replace('/[^0-9a-z]/', '', strtolower(mb_convert_kana($s, 'as', 'UTF-8')));
    $nn = $n($name);
    if ($n($mn) !== '' && strpos($nn, $n($mn)) !== false) { return $mn; }
    // 記号の後ろが JAN（45/49 で始まる13桁）のものは型番ではないので出さない
    if (preg_match('/^[A-Z]{2,5}\d{0,2}-(.+)$/', $mn, $m) && !preg_match('/^4[59]\d{11}$/', $m[1])
        && $n($m[1]) !== '' && strpos($nn, $n($m[1])) !== false) { return $m[1]; }
    return '';
}

/** 商品の JAN（gtin が 8桁・13桁の数字のときだけ）。 */
function kv_jan(array $p): string
{
    $g = trim((string)($p['gtin'] ?? ''));
    return preg_match('/^(\d{8}|\d{13})$/', $g) ? $g : '';
}

/**
 * 商品ページの <title>。商品名から【送料無料】【破格値】のような飾りを外し、
 * JAN があって商品名に入っていなければ末尾に「JAN 4937…」を足す（JAN で検索する人向け・2026-11-17）。
 */
function kv_product_title(array $p, ?string $maker_name = null): string
{
    $t = trim(preg_replace('/[\s　]+/u', ' ', preg_replace('/【[^】]*】/u', ' ', (string)$p['name'])));
    if ($t === '') { $t = (string)$p['name']; }

    // 買う人は「型番」か「メーカー名＋品名」で探す（exdirect.net の検索クリックの86%が商品ページに直接・2026-11-27 実測）。
    // 型番を先頭に1回だけ置き、同じ型番・同じ語の繰り返しを外し、メーカー名が無ければ足す。
    $norm = static fn(string $s): string => preg_replace('/[^0-9a-z\p{Han}\p{Hiragana}\p{Katakana}ー]/u', '',
        mb_strtolower(mb_convert_kana($s, 'asKV', 'UTF-8')));
    $mdl = kv_model_display($p['model_number'] ?? '', $t);
    $words = explode(' ', $t);
    // 先頭に出すのは英字を含む型番だけ（数字だけの社内番号は先頭に出さない）。出すときは商品名の中の書き方のまま。
    // 商品名の先頭がすでに型番の形（英字と数字を含む）なら動かさない（型番の欄に仕入先の記号が入っている商品がある）。
    $movable = $mdl !== '' && preg_match('/[A-Za-z]/', $mdl)
        && !preg_match('/(?=.*[A-Za-zＡ-Ｚａ-ｚ])(?=.*[0-9０-９])/u', $words[0] ?? '');
    $out = []; $seen = []; $orig = '';
    foreach ($words as $w) {
        $k = $norm($w);
        if ($movable && $k !== '' && $k === $norm($mdl)) {
            if ($orig === '' || !preg_match('/[０-９Ａ-Ｚａ-ｚ]/u', $w) && preg_match('/[０-９Ａ-Ｚａ-ｚ]/u', $orig)) { $orig = $w; }
            continue;
        }
        if ($k !== '' && mb_strlen($k) >= 2 && isset($seen[$k])) { continue; }
        if ($k !== '') { $seen[$k] = true; }
        $out[] = $w;
    }
    if ($orig !== '') { array_unshift($out, $orig); $t = implode(' ', $out); }
    elseif ($out) { $t = implode(' ', $out); }
    if ($maker_name === null && !empty($p['maker_id'])) {
        $m = kv_maker((int)$p['maker_id']);
        $maker_name = $m ? (string)$m['name'] : '';
    }
    // 商品名にメーカー名（頭の3文字＝「ヤマダ」「蔵王産」など）が入っていなければ足す
    $mn = $maker_name !== null ? $norm($maker_name) : '';
    if ($mn !== '' && strpos($norm($t), mb_substr($mn, 0, 3)) === false) { $t .= ' ' . $maker_name; }

    $jan = kv_jan($p);
    if ($jan !== '' && strpos($t, $jan) === false) { $t .= ' JAN ' . $jan; }
    return $t;
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
/** カートでは買えず、お問い合わせで受ける商品か（price_unspecified=1。メーカーで販売中止になった品など・2026-11-16）。価格は表示する。 */
function kv_inquiry_only(array $p): bool
{
    return (int)($p['price_unspecified'] ?? 0) === 1;
}

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
 * 売れた商品（2026-11-19）。[商品ID => 点数]（直近1年の注文×10＋直近5年の注文）。
 * おちゃのこ時代の分は kv_data/sales_history.json、この店の注文は orders から足す。
 * 一覧・関連商品で売れた商品を先に並べ、店の中のリンクを売れる商品に集めるため
 * （検索の流入が売れない商品に偏り、売れる商品が弱かった。kvcart-sales/seo_sold_20261119）。
 */
function kv_sold_counts(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [];
    // 直近1年は重く（1回=10点）、直近5年は軽く（1回=1点）。5年分は kv_data/sold_rank_5y.json（{"商品ID": 回数}・
    // kvcart-sales/seo_sold_20261119 でおちゃのこの全受注から作る・2026-11-19 ユーザー指示「4〜5年前でもさかのぼれるだけ」）
    $f5 = __DIR__ . '/kv_data/sold_rank_5y.json';
    if (is_file($f5)) {
        foreach ((json_decode((string)file_get_contents($f5), true) ?: []) as $pid => $n) { $c[(int)$pid] = (int)$n; }
    }
    $f = __DIR__ . '/kv_data/sales_history.json';
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        foreach (($j['items'] ?? []) as $pid => $it) { $c[(int)$pid] = ($c[(int)$pid] ?? 0) + 10 * (int)($it['orders'] ?? 1); }
    }
    try {
        $st = kv_db()->prepare('SELECT i.product_id pid, COUNT(DISTINCT o.id) n FROM order_items i JOIN orders o ON o.id=i.order_id'
                               . ' WHERE o.cancelled=0 AND o.created_at>=? GROUP BY i.product_id');
        $st->execute([date('Y-m-d', strtotime('-365 days'))]);
        foreach ($st->fetchAll() as $r) { $c[(int)$r['pid']] = ($c[(int)$r['pid']] ?? 0) + 10 * (int)$r['n']; }
    } catch (Throwable $e) { /* 注文の表がまだ無い */ }
    return $c;
}

/** ORDER BY の先頭に置く「売れた回数の多い順」（商品IDは整数だけなのでそのまま埋め込む）。 */
function kv_sold_order(string $col = 'id'): string
{
    $c = kv_sold_counts();
    if (!$c) { return ''; }
    $w = [];
    foreach ($c as $pid => $n) { $w[] = 'WHEN ' . (int)$pid . ' THEN ' . (int)$n; }
    return 'CASE ' . $col . ' ' . implode(' ', $w) . ' ELSE 0 END DESC, ';
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
    $base_w = $w; $base_a = $a;
    $terms = [];
    if (!empty($f['q'])) {
        $terms = kv_search_terms((string)$f['q']);
        [$w, $a] = kv_search_where($terms, $base_w, $base_a);
    }
    $sql = 'FROM products WHERE ' . implode(' AND ', $w);
    $st = kv_db()->prepare('SELECT COUNT(*) ' . $sql);
    $st->execute($a);
    $total = (int)$st->fetchColumn();
    // 0件なら、つながった語を分類名・メーカー名で分けて探し直す（「エアレス塗装機」→「エアレス」「塗装機」・2026-11-27）
    if ($total === 0 && $terms) {
        $split = kv_search_split($terms);
        if ($split !== $terms) {
            [$w2, $a2] = kv_search_where($split, $base_w, $base_a);
            $sql2 = 'FROM products WHERE ' . implode(' AND ', $w2);
            $st = kv_db()->prepare('SELECT COUNT(*) ' . $sql2);
            $st->execute($a2);
            $t2 = (int)$st->fetchColumn();
            if ($t2 > 0) { $sql = $sql2; $a = $a2; $total = $t2; }
        }
    }
    // それでも0件で語が2つ以上なら、いちばん絞り込める1語（当たる中で件数が最も少ない語）で探す
    // （「ベビーカステラ 機械」→「ベビーカステラ」、「スプレーガン 塗装」→「スプレーガン」）
    if ($total === 0 && count($terms) > 1) {
        $best = null;
        foreach (array_unique(array_merge($terms, kv_search_split($terms))) as $t) {
            [$w3, $a3] = kv_search_where([$t], $base_w, $base_a);
            $st = kv_db()->prepare('SELECT COUNT(*) FROM products WHERE ' . implode(' AND ', $w3));
            $st->execute($a3);
            $c3 = (int)$st->fetchColumn();
            if ($c3 > 0 && ($best === null || $c3 < $best[0])) { $best = [$c3, $w3, $a3]; }
        }
        if ($best) { $total = $best[0]; $sql = 'FROM products WHERE ' . implode(' AND ', $best[1]); $a = $best[2]; }
    }
    $page = max(1, $page);
    $off = ($page - 1) * $per;
    $st = kv_db()->prepare('SELECT id,name,model_number,price,list_price,stock,stock_unlimited,'
                           . 'image_url,maker_id,category_id,tax_reduce ' . $sql
                           . ' ORDER BY ' . kv_sold_order() . 'id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)$off);
    $st->execute($a);
    return ['items' => $st->fetchAll(), 'total' => $total, 'page' => $page,
            'per' => $per, 'pages' => (int)ceil($total / $per)];
}

/**
 * 検索語を分ける。全角・半角、英字の大小は区別しない。
 * 「業務用」「通販」「価格」などは商品名にほとんど入らず、付けると目当ての商品まで落ちるので外す
 * （ほかの語が残るときだけ。X-Direct の検索で「高圧洗浄機 業務用」「〇〇 価格」と打つ人がいる・2026-11-27）。
 */
function kv_search_terms(string $q): array
{
    $terms = array_values(array_filter(preg_split('/\s+/u', trim(kv_norm($q))) ?: [], 'strlen'));
    $stop = ['業務用', '業務', '通販', '価格', '値段', '料金', '安い', '激安', '格安', '最安', '最安値', '購入', '販売', '通信販売', 'おすすめ', '人気', '新品', '正規品', 'ネット', 'の'];
    $kept = array_values(array_filter($terms, static fn($t) => !in_array($t, $stop, true)));
    if (!$kept) { return array_slice($terms, 0, 5); }
    // 語の後ろに付いた「業務用」「通販」も外す（「洗浄機業務用」→「洗浄機」）
    $kept = array_map(static fn($t) => preg_replace('/(業務用|通販|の通販|価格)$/u', '', $t) ?: $t, $kept);
    // 検索する人の言い方 → 商品名での言い方（商品名に出てこない言い方で0件になっていたもの）
    $syn = ['ワッフルメーカー' => 'ワッフル', 'たい焼き器' => 'たい焼', 'たいやき器' => 'たい焼', 'たこ焼き器' => 'たこ焼', 'たこ焼器' => 'たこ焼',
            '芯取り' => '芯抜き', '芯とり' => '芯抜き', 'たまねぎ' => '玉ねぎ', 'タマネギ' => '玉ねぎ', '玉葱' => '玉ねぎ',
            'スプレイガン' => 'スプレーガン', 'ジェネレーター' => '発電機', 'エアコンプレッサー' => 'コンプレッサー'];
    $kept = array_map(static fn($t) => $syn[$t] ?? $t, $kept);
    return array_slice($kept, 0, 5);
}

/** 検索語ごとに、商品名・型番・JAN・分類名・メーカー名のどれかに含むものを探す条件を作る。 */
function kv_search_where(array $terms, array $w, array $a): array
{
    $useFn = method_exists(kv_db(), 'sqliteCreateFunction');
    $n = static fn(string $col) => $useFn ? "kvnorm($col)" : $col;
    foreach ($terms as $t) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%';
        // JAN（gtin）でも引けるようにする（2026-11-17）。分類名・メーカー名でも引く（「ベビコン 日立」「塗装機 精和」・2026-11-27）
        $w[] = '(' . $n('name') . " LIKE ? ESCAPE '\\' OR " . $n('model_number') . " LIKE ? ESCAPE '\\' OR gtin LIKE ? ESCAPE '\\'"
             . ' OR category_id IN (SELECT id FROM categories WHERE ' . $n('name') . " LIKE ? ESCAPE '\\')"
             . ' OR maker_id IN (SELECT id FROM makers WHERE ' . $n('name') . " LIKE ? ESCAPE '\\'))";
        array_push($a, $like, $like, $like, $like, $like);
    }
    return [$w, $a];
}

/**
 * つながった語を、分類名・メーカー名の言葉で分ける（「エアレス塗装機」→「エアレス」「塗装機」）。
 * 言葉の辞書は分類名（「切断・切削・曲げ」は「・」で分ける）とメーカー名の2文字以上。長い言葉から先に当てる。
 */
function kv_search_split(array $terms): array
{
    static $dict = null;
    if ($dict === null) {
        $dict = [];
        foreach (kv_db()->query('SELECT name FROM categories UNION SELECT name FROM makers') as $r) {
            foreach (preg_split('/[・\/／()（）\s]+/u', kv_norm((string)$r['name'])) ?: [] as $wd) {
                if (mb_strlen($wd) >= 2) { $dict[$wd] = true; }
            }
        }
        $dict = array_keys($dict);
        usort($dict, static fn($x, $y) => mb_strlen($y) - mb_strlen($x));
    }
    $out = [];
    foreach ($terms as $t) {
        $done = false;
        foreach ($dict as $wd) {
            $p = mb_strpos($t, $wd);
            if ($p === false || $wd === $t) { continue; }
            $before = mb_substr($t, 0, $p); $after = mb_substr($t, $p + mb_strlen($wd));
            foreach ([$before, $wd, $after] as $piece) { if (mb_strlen($piece) >= 2) { $out[] = $piece; } }
            $done = true; break;
        }
        if (!$done) { $out[] = $t; }
    }
    return array_slice(array_values(array_unique($out)), 0, 5);
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

/**
 * メール送信。mail() と同じ引数で呼べる。
 * 設定に KV_SMTP（['host'=>'ssl://smtp.example.jp:465','user'=>..,'pass'=>..]）があれば、
 * 店のアドレスで認証して SMTP で送る（SPF・DKIM が通り、Gmail で迷惑メールにされにくい）。
 * 無ければレンタルサーバーの mail() で送る。
 */
function kv_mail(string $to, string $subject, string $body, string $headers = '', string $cc = '', array $attach = []): bool
{
    $cc = trim(str_replace(["\r", "\n"], '', $cc));
    if ($cc !== '') { $headers = rtrim($headers) . "\r\nCc: " . $cc . "\r\n"; }
    // 添付（納品書・請求書・領収書の PDF など・2026-11-17）。[ファイル名 => 中身] を multipart/mixed で送る
    $raw = null;
    if ($attach) {
        $bd = 'kv' . bin2hex(random_bytes(8));
        $headers = preg_replace('/^Content-Type:[^\r\n]*(\r?\n)?/mi', '', $headers);
        $headers = rtrim($headers) . "\r\nContent-Type: multipart/mixed; boundary=\"$bd\"\r\n";
        $raw = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body), 76, "\r\n");
        foreach ($attach as $fn => $data) {
            $enc = '=?UTF-8?B?' . base64_encode((string)$fn) . '?=';
            $type = substr(strtolower((string)$fn), -4) === '.pdf' ? 'application/pdf' : 'application/octet-stream';
            $raw .= "--$bd\r\nContent-Type: $type; name=\"$enc\"\r\nContent-Transfer-Encoding: base64\r\n"
                  . "Content-Disposition: attachment; filename=\"$enc\"\r\n\r\n" . chunk_split(base64_encode((string)$data), 76, "\r\n");
        }
        $raw .= "--$bd--\r\n";
    }
    if (!defined('KV_SMTP') || !is_array(KV_SMTP) || empty(KV_SMTP['host'])) {
        return $raw === null ? @mail($to, $subject, $body, $headers)
                             : @mail($to, $subject, $raw, rtrim($headers) . "\r\nMIME-Version: 1.0\r\n");
    }
    $c = KV_SMTP;
    $from = $c['user'];
    $fp = @stream_socket_client($c['host'], $errno, $errstr, 20);
    if (!$fp) { return false; }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $out;
    };
    $cmd = function (string $s, array $ok) use ($fp, $read): bool {
        fwrite($fp, $s . "\r\n");
        return in_array((int)substr($read(), 0, 3), $ok, true);
    };
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $ok = in_array((int)substr($read(), 0, 3), [220], true)
        && $cmd('EHLO ' . $host, [250])
        && $cmd('AUTH LOGIN', [334])
        && $cmd(base64_encode($c['user']), [334])
        && $cmd(base64_encode($c['pass']), [235])
        && $cmd('MAIL FROM:<' . $from . '>', [250]);
    $rcpts = array_filter(array_map('trim', explode(',', $to . ($cc !== '' ? ',' . $cc : ''))));
    foreach ($rcpts as $r) {
        if (!$ok) { break; }
        $addr = preg_match('/<([^>]+)>/', $r, $m) ? $m[1] : $r;
        $ok = $cmd('RCPT TO:<' . str_replace(["\r", "\n"], '', $addr) . '>', [250, 251]);
    }
    if ($ok) { $ok = $cmd('DATA', [354]); }
    if ($ok) {
        $dom = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $msg = 'To: ' . $to . "\r\n"
             . 'Subject: ' . $subject . "\r\n"
             . 'Date: ' . date('r') . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dom . ">\r\n"
             . 'MIME-Version: 1.0' . "\r\n"
             . rtrim(str_replace(["\r\n", "\n"], "\r\n", $headers)) . "\r\n"
             . ($raw === null ? 'Content-Transfer-Encoding: base64' . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n")
                              : "\r\n" . $raw);
        fwrite($fp, $msg . ".\r\n");
        $ok = in_array((int)substr($read(), 0, 3), [250], true);
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
