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
    if (mb_stripos($name, $mn) !== false) { return $mn; }
    // 記号の後ろが JAN（45/49 で始まる13桁）のものは型番ではないので出さない
    if (preg_match('/^[A-Z]{2,5}\d{0,2}-(.+)$/', $mn, $m) && !preg_match('/^4[59]\d{11}$/', $m[1])
        && mb_stripos($name, $m[1]) !== false) { return $m[1]; }
    return '';
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
        // 空白で区切った語は全部を含むものを探す。全角・半角、英字の大小は区別しない
        $terms = preg_split('/\s+/u', trim(kv_norm((string)$f['q']))) ?: [];
        $useFn = method_exists(kv_db(), 'sqliteCreateFunction');
        foreach (array_slice(array_filter($terms, 'strlen'), 0, 5) as $t) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%';
            $w[] = $useFn ? "(kvnorm(name) LIKE ? ESCAPE '\\' OR kvnorm(model_number) LIKE ? ESCAPE '\\')"
                          : "(name LIKE ? ESCAPE '\\' OR model_number LIKE ? ESCAPE '\\')";
            $a[] = $like;
            $a[] = $like;
        }
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

/**
 * メール送信。mail() と同じ引数で呼べる。
 * 設定に KV_SMTP（['host'=>'ssl://smtp.example.jp:465','user'=>..,'pass'=>..]）があれば、
 * 店のアドレスで認証して SMTP で送る（SPF・DKIM が通り、Gmail で迷惑メールにされにくい）。
 * 無ければレンタルサーバーの mail() で送る。
 */
function kv_mail(string $to, string $subject, string $body, string $headers = ''): bool
{
    if (!defined('KV_SMTP') || !is_array(KV_SMTP) || empty(KV_SMTP['host'])) {
        return @mail($to, $subject, $body, $headers);
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
    $rcpts = array_filter(array_map('trim', explode(',', $to)));
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
             . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
             . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $msg . ".\r\n");
        $ok = in_array((int)substr($read(), 0, 3), [250], true);
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
