<?php
/**
 * Kurage Vibe-Cart — 管理画面（商品・メーカー・カテゴリ・顧客・売上）。2026-11-17 追加。
 *
 * ユーザー指示「ネット通販のシステムに必要な機能は全部実装しろ」。それまで管理画面は受注だけで、
 * 商品の変更は AI（MCP）かスクリプトでしかできなかった。
 *
 *   ?a=products      商品一覧（非表示・お問い合わせ品も含めて検索、メーカー・カテゴリ・状態で絞り込み、一括操作）
 *   ?a=product&id=   商品の編集（価格・定価・在庫・状態・名前・型番・JAN・メーカー・カテゴリ・説明・画像・オプション）
 *   ?a=product       商品の新規登録（ID は 100001 から。おちゃのこネットのIDとぶつけない）
 *   ?a=price         価格の一括改定（メーカー・カテゴリ・検索語で絞り、%と端数処理 → 確認 → 反映）
 *   ?a=pcsv          商品CSVの書き出し（いまの絞り込みのまま）
 *   ?a=pimport       商品CSVの取り込み（id で突き合わせ、変わる所だけを確認してから反映）
 *   ?a=makers        メーカーの一覧・追加・名前/URL名/並び順の変更
 *   ?a=categories    カテゴリの一覧・追加・名前の変更
 *   ?a=customers     顧客一覧（注文のメールアドレスごと。注文回数・合計・最終注文日）
 *   ?a=stats         売上（月別・支払い方法別・よく売れた商品）
 *   ?a=plog          商品の変更履歴
 *
 * 商品を変えたら必ず kv_data/product_log.jsonl に「いつ・どの商品の・どの項目が・何から何へ」を残す。
 */
declare(strict_types=1);

const KV_PLOG = KV_DATA . '/product_log.jsonl';

/** 編集できる商品の項目と表示名。 */
function kv_pfields(): array
{
    return ['name' => '商品名', 'model_number' => '型番（社内記号付き）', 'gtin' => 'JANコード',
            'price' => '販売価格（税別）', 'list_price' => 'メーカー希望小売価格（税別）',
            'price_unspecified' => 'お問い合わせ品', 'stock' => '在庫数', 'stock_unlimited' => '在庫数を管理しない',
            'hidden' => '非表示', 'tax_reduce' => '軽減税率（8%）', 'maker_id' => 'メーカー', 'category_id' => 'カテゴリ',
            'description' => '商品説明（HTML）', 'image_url' => '画像', 'variations' => 'オプション'];
}

function kv_aproduct(int $id): ?array
{
    $st = kv_db()->prepare('SELECT * FROM products WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** 商品の変更を記録する。$before が空なら新規。 */
function kv_plog(int $id, array $before, array $after, string $how): void
{
    $ch = [];
    foreach ($after as $k => $v) {
        $b = $before[$k] ?? null;
        if ((string)$b !== (string)$v) {
            $ch[$k] = [$k === 'description' ? mb_strimwidth((string)$b, 0, 60, '…') : $b,
                       $k === 'description' ? mb_strimwidth((string)$v, 0, 60, '…') : $v];
        }
    }
    if (!$ch && $before) { return; }
    @file_put_contents(KV_PLOG, json_encode(['at' => date('Y-m-d H:i:s'), 'id' => $id, 'how' => $how,
        'name' => mb_strimwidth((string)($after['name'] ?? $before['name'] ?? ''), 0, 50, '…'), 'changes' => $ch],
        JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/** 履歴を新しい順に読む（$id を渡せばその商品だけ）。 */
function kv_plog_read(int $id = 0, int $limit = 200): array
{
    if (!is_file(KV_PLOG)) { return []; }
    $lines = file(KV_PLOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
        $r = json_decode($lines[$i], true);
        if (is_array($r) && (!$id || (int)$r['id'] === $id)) { $out[] = $r; }
    }
    return $out;
}

/** 商品を更新する。$vals に入っている項目だけを変える（オプション等の他の項目を消さない）。 */
function kv_pupdate(int $id, array $vals, string $how): void
{
    $cur = kv_aproduct($id) ?: [];
    $vals = array_intersect_key($vals, kv_pfields());
    if (!$vals) { return; }
    $vals['updated_at'] = date('c');
    $set = implode(',', array_map(fn($k) => "$k=?", array_keys($vals)));
    kv_db()->prepare("UPDATE products SET $set WHERE id=?")->execute(array_merge(array_values($vals), [$id]));
    unset($vals['updated_at']);
    kv_plog($id, $cur, $vals, $how);
}

function kv_new_product_id(): int
{
    $db = kv_db();
    return max((int)$db->query('SELECT MAX(id) FROM products')->fetchColumn(),
               (int)$db->query('SELECT MAX(id) FROM retired_products')->fetchColumn(), 100000) + 1;
}

function kv_admin_categories_all(): array
{
    return kv_db()->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) cnt'
                          . ' FROM categories c ORDER BY COALESCE(parent_name,\'\'), name')->fetchAll();
}

function kv_admin_makers_all(): array
{
    return kv_db()->query('SELECT m.*, (SELECT COUNT(*) FROM products p WHERE p.maker_id=m.id) cnt,'
                          . ' (SELECT COUNT(*) FROM products p WHERE p.maker_id=m.id AND p.hidden=0) live'
                          . ' FROM makers m ORDER BY m.name')->fetchAll();
}

function kv_sel(string $name, array $opts, $now, string $blank = ''): string
{
    $h = '<select name="' . $name . '" class="in">';
    if ($blank !== '') { $h .= '<option value="">' . kv_e($blank) . '</option>'; }
    foreach ($opts as $v => $label) {
        $h .= '<option value="' . kv_e($v) . '"' . ((string)$now === (string)$v ? ' selected' : '') . '>' . kv_e($label) . '</option>';
    }
    return $h . '</select>';
}

function kv_admin_css(): void
{
    echo '<style>.in{padding:7px 8px;border:2px solid #cfdae4;border-radius:8px;font-size:14px;max-width:100%;box-sizing:border-box}'
       . '.ftbl{width:100%;border-collapse:collapse}.ftbl th{text-align:left;width:190px;padding:8px;vertical-align:top;font-size:13px;color:#33414d;background:#f4f7fa;border-bottom:1px solid #e3e9ee}'
       . '.ftbl td{padding:8px;border-bottom:1px solid #e3e9ee}.ftbl input.in,.ftbl select.in{width:100%}.ftbl textarea.in{width:100%}'
       . '.tag{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:#e8eef3;color:#33414d;margin-right:3px;white-space:nowrap}'
       . '.tag.red{background:#fde3e3;color:#9b1c1c}.tag.or{background:#fff1d6;color:#8a5200}.tag.gr{background:#def5e6;color:#14632f}'
       . '.thumb{width:48px;height:48px;object-fit:contain;background:#fff;border:1px solid #e3e9ee;border-radius:6px}'
       . '.up{color:#b42318}.down{color:#0f7a3a}.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px}'
       . '@media(max-width:640px){.ftbl th{width:auto;display:block;border:0;padding-bottom:0}.ftbl td{display:block}.ftbl tr{display:block;border-bottom:1px solid #e3e9ee}}</style>';
}

function kv_admin_page(string $title, string $nav): void
{
    kv_head($title, '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:1180px">';
    kv_admin_nav($nav);
    kv_admin_css();
    echo '<h1>' . kv_e($title) . '</h1>';
}

function kv_admin_end(): void
{
    echo '</div>';
    kv_footer();
}

// ---------------- 商品一覧 ----------------

/** 一覧・CSV・一括改定で共通の絞り込み。 */
function kv_admin_pfilter(array $g): array
{
    $w = ['1=1']; $a = [];
    if (!empty($g['maker_id']))    { $w[] = 'maker_id=?';    $a[] = (int)$g['maker_id']; }
    if (!empty($g['category_id'])) { $w[] = 'category_id=?'; $a[] = (int)$g['category_id']; }
    $st = (string)($g['st'] ?? '');
    if ($st === 'live')    { $w[] = 'hidden=0 AND COALESCE(price_unspecified,0)=0'; }
    if ($st === 'hidden')  { $w[] = 'hidden=1'; }
    if ($st === 'inquiry') { $w[] = 'COALESCE(price_unspecified,0)=1'; }
    if ($st === 'noprice') { $w[] = '(price IS NULL OR price=0)'; }
    if ($st === 'noimage') { $w[] = "(image_url IS NULL OR image_url='')"; }
    if ($st === 'nostock') { $w[] = 'COALESCE(stock_unlimited,0)=0 AND COALESCE(stock,0)<=0'; }
    $q = trim((string)($g['q'] ?? ''));
    if ($q !== '') {
        if (ctype_digit($q) && strlen($q) <= 6) { $w[] = 'id=?'; $a[] = (int)$q; }
        else {
            foreach (array_slice(array_filter(preg_split('/\s+/u', kv_norm($q)) ?: [], 'strlen'), 0, 5) as $t) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%';
                $w[] = "(kvnorm(name) LIKE ? ESCAPE '\\' OR kvnorm(model_number) LIKE ? ESCAPE '\\' OR gtin LIKE ? ESCAPE '\\')";
                array_push($a, $like, $like, $like);
            }
        }
    }
    return [implode(' AND ', $w), $a];
}

function kv_admin_products(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok() && !empty($_POST['bulk'])) {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $op = (string)$_POST['bulk'];
        $map = ['show' => ['hidden' => 0], 'hide' => ['hidden' => 1], 'inquiry' => ['price_unspecified' => 1], 'sell' => ['price_unspecified' => 0]];
        if (isset($map[$op])) { foreach ($ids as $id) { kv_pupdate($id, $map[$op], '一覧の一括操作'); } }
        header('Location: ?' . preg_replace('/(^|&)done=\d+/', '', (string)$_SERVER['QUERY_STRING']) . '&done=' . count($ids));
        exit;
    }
    [$where, $args] = kv_admin_pfilter($_GET);
    $per = 50; $page = max(1, (int)($_GET['p'] ?? 1));
    $st = kv_db()->prepare("SELECT COUNT(*) FROM products WHERE $where"); $st->execute($args);
    $total = (int)$st->fetchColumn();
    $order = ['new' => 'id DESC', 'upd' => 'updated_at DESC', 'price' => 'price DESC', 'pricea' => 'price ASC', 'name' => 'name'][$_GET['sort'] ?? 'upd'] ?? 'updated_at DESC';
    $st = kv_db()->prepare("SELECT id,name,model_number,price,list_price,price_unspecified,stock,stock_unlimited,hidden,image_url,maker_id,updated_at"
                           . " FROM products WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $rows = $st->fetchAll();
    $makers = []; foreach (kv_admin_makers_all() as $m) { $makers[$m['id']] = $m['name'] . '（' . $m['cnt'] . '）'; }
    $cats = []; foreach (kv_admin_categories_all() as $c) { if ($c['cnt']) { $cats[$c['id']] = ($c['parent_name'] ? $c['parent_name'] . ' › ' : '') . $c['name'] . '（' . $c['cnt'] . '）'; } }
    kv_admin_page('商品', 'products');
    if (!empty($_GET['done'])) { echo '<div class="note">' . (int)$_GET['done'] . '件を更新しました。</div>'; }
    $g = $_GET;
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="products">'
       . '<input class="in" name="q" value="' . kv_e($g['q'] ?? '') . '" placeholder="商品名・型番・JAN・商品ID" style="min-width:220px">'
       . kv_sel('maker_id', $makers, $g['maker_id'] ?? '', 'メーカー（すべて）')
       . kv_sel('category_id', $cats, $g['category_id'] ?? '', 'カテゴリ（すべて）')
       . kv_sel('st', ['live' => '販売中', 'hidden' => '非表示', 'inquiry' => 'お問い合わせ品', 'noprice' => '価格なし', 'noimage' => '画像なし', 'nostock' => '在庫0（在庫管理あり）'], $g['st'] ?? '', '状態（すべて）')
       . kv_sel('sort', ['upd' => '更新が新しい順', 'new' => 'IDが新しい順', 'price' => '価格が高い順', 'pricea' => '価格が安い順', 'name' => '商品名順'], $g['sort'] ?? 'upd')
       . '<button class="btn" type="submit">絞り込む</button>'
       . '<a class="btn gray" href="?a=product">＋ 新しい商品</a>'
       . '<a class="btn gray" href="?a=pcsv&' . kv_e(http_build_query(array_diff_key($g, ['a' => 1, 'p' => 1]))) . '">この条件でCSV</a>'
       . '<a class="btn gray" href="?a=price&' . kv_e(http_build_query(array_diff_key($g, ['a' => 1, 'p' => 1]))) . '">この条件で価格改定</a></form>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<div class="filters"><span class="src">チェックした商品を：</span>'
       . '<button class="btn gray" name="bulk" value="show">表示する</button><button class="btn gray" name="bulk" value="hide">非表示にする</button>'
       . '<button class="btn gray" name="bulk" value="inquiry">お問い合わせ品にする</button><button class="btn gray" name="bulk" value="sell">カートで買えるようにする</button></div>'
       . '<div class="scroll"><table class="t"><tr><th><input type="checkbox" onclick="for(const c of document.querySelectorAll(\'.ck\'))c.checked=this.checked"></th>'
       . '<th>画像</th><th>ID</th><th>商品名・型番</th><th>メーカー</th><th>税別</th><th>在庫</th><th>状態</th><th>更新</th></tr>';
    foreach ($rows as $p) {
        $tags = ($p['hidden'] ? '<span class="tag red">非表示</span>' : '<span class="tag gr">表示</span>')
              . ((int)$p['price_unspecified'] ? '<span class="tag or">お問い合わせ</span>' : '')
              . ((int)$p['price'] <= 0 ? '<span class="tag or">価格なし</span>' : '')
              . ($p['image_url'] ? '' : '<span class="tag">画像なし</span>');
        echo '<tr><td><input class="ck" type="checkbox" name="ids[]" value="' . (int)$p['id'] . '"></td>'
           . '<td>' . ($p['image_url'] ? '<img class="thumb" loading="lazy" src="' . kv_e(kv_img($p['image_url'])) . '" alt="">' : '') . '</td>'
           . '<td>' . (int)$p['id'] . '</td>'
           . '<td><a href="?a=product&id=' . (int)$p['id'] . '">' . kv_e(mb_strimwidth((string)$p['name'], 0, 70, '…')) . '</a><div class="src">' . kv_e($p['model_number']) . '</div></td>'
           . '<td class="src">' . kv_e(preg_replace('/（\d+）$/u', '', $makers[$p['maker_id']] ?? '')) . '</td>'
           . '<td style="text-align:right;white-space:nowrap">' . ((int)$p['price'] > 0 ? number_format((int)$p['price']) . '円' : '—')
           . ((int)$p['list_price'] > 0 ? '<div class="src">定価 ' . number_format((int)$p['list_price']) . '</div>' : '') . '</td>'
           . '<td style="text-align:right">' . ((int)$p['stock_unlimited'] ? '<span class="src">管理なし</span>' : (int)$p['stock']) . '</td>'
           . '<td>' . $tags . '</td><td class="src" style="white-space:nowrap">' . kv_e(substr((string)$p['updated_at'], 0, 10)) . '</td></tr>';
    }
    echo '</table></div></form>';
    kv_pager(['page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], '?' . http_build_query(array_diff_key($g, ['p' => 1, 'done' => 1])));
    kv_admin_end();
}

// ---------------- 商品の編集・新規 ----------------

/** 画像を受け取り img/<ハッシュ>.jpg にする（GD があれば長辺800pxに縮めて JPEG、無ければそのまま保存）。 */
function kv_admin_save_image(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return [null, '']; }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { return [null, '画像を受け取れませんでした（サイズが大きすぎる可能性があります）。']; }
    $raw = (string)file_get_contents($f['tmp_name']);
    $info = @getimagesizefromstring($raw);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) { return [null, '画像は JPEG・PNG・GIF・WebP で送ってください。']; }
    $hash = substr(sha1($raw), 0, 16);
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($raw))) {
        $w = imagesx($im); $h = imagesy($im); $s = min(1, 800 / max($w, $h));
        $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $fn = 'img/' . $hash . '.jpg';
        imagejpeg($out, KV_ROOT . '/' . $fn, 85);
    } else {
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $fn = 'img/' . $hash . '.' . $ext;
        file_put_contents(KV_ROOT . '/' . $fn, $raw);
    }
    return [$fn, ''];
}

/** オプション欄（項目名＋値を1行1つ）から variations の JSON を作る。項目と値が変わらなければ組み合わせ別の価格（data）を残す。 */
function kv_admin_variations_from_post(array $post, ?string $old): ?string
{
    $opts = [];
    for ($i = 1; $i <= 3; $i++) {
        $label = trim((string)($post['opt_label'][$i] ?? ''));
        $vals = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($post['opt_values'][$i] ?? '')) ?: []), 'strlen'));
        if ($label !== '' && $vals) { $opts['option' . (count($opts) + 1)] = ['label' => $label, 'values' => $vals]; }
    }
    if (!$opts) { return null; }
    $o = json_decode((string)$old, true);
    if (is_array($o) && ($o['options'] ?? null) == $opts) { return $old; }   // 変えていなければ元の文字列のまま（組み合わせ別の価格も残る）
    return json_encode(['options' => $opts, 'data' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function kv_admin_product(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $p = $id ? kv_aproduct($id) : null;
    if ($id && !$p) {
        $r = kv_retired($id);
        kv_admin_page('商品 ' . $id, 'products');
        echo '<div class="alert">商品 ' . $id . ' はありません。' . ($r ? '取り扱い終了の商品です（' . kv_e($r['name']) . '）。' : '') . '</div>';
        kv_admin_end();
        return;
    }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $do = (string)($_POST['do'] ?? 'save');
        if ($do === 'retire' && $p) {
            $mk = $p['maker_id'] ? kv_maker((int)$p['maker_id']) : null;
            kv_db()->prepare('INSERT OR REPLACE INTO retired_products (id,name,model_number,maker_name,category_id) VALUES (?,?,?,?,?)')
                   ->execute([$id, $p['name'], $p['model_number'], $mk['name'] ?? '', $p['category_id']]);
            kv_db()->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
            kv_plog($id, $p, ['retired' => 1], '取り扱い終了');
            header('Location: ?a=products&done=1');
            exit;
        }
        $v = [];
        foreach (['name', 'model_number', 'gtin', 'description'] as $k) { $v[$k] = trim((string)($_POST[$k] ?? '')); }
        foreach (['price', 'list_price', 'stock'] as $k) {
            $s = preg_replace('/[^\d]/', '', mb_convert_kana((string)($_POST[$k] ?? ''), 'n'));
            $v[$k] = $s === '' ? ($k === 'list_price' ? null : 0) : (int)$s;
        }
        foreach (['price_unspecified', 'stock_unlimited', 'hidden', 'tax_reduce'] as $k) { $v[$k] = empty($_POST[$k]) ? 0 : 1; }
        $v['maker_id'] = ($_POST['maker_id'] ?? '') === '' ? null : (int)$_POST['maker_id'];
        $v['category_id'] = ($_POST['category_id'] ?? '') === '' ? null : (int)$_POST['category_id'];
        $v['variations'] = kv_admin_variations_from_post($_POST, $p['variations'] ?? null);
        [$img, $ierr] = kv_admin_save_image($_FILES['image'] ?? []);
        if ($img) { $v['image_url'] = $img; }
        elseif (!empty($_POST['image_url_set'])) { $v['image_url'] = trim((string)$_POST['image_url_set']); }
        if (!empty($_POST['image_clear'])) { $v['image_url'] = ''; }
        if ($v['name'] === '') { $err = '商品名を入れてください。'; }
        elseif ($ierr) { $err = $ierr; }
        elseif (!$v['price_unspecified'] && $v['price'] <= 0) { $err = '販売価格を入れてください（価格を出さないなら「お問い合わせ品」にしてください）。'; }
        if (!$err) {
            if ($do === 'copy' || !$p) {
                $nid = kv_new_product_id();
                if ($do === 'copy' && !$img && empty($_POST['image_clear'])) { $v['image_url'] = $p['image_url'] ?? ''; }
                $cols = array_keys($v);
                kv_db()->prepare('INSERT INTO products (id,' . implode(',', $cols) . ',updated_at,imported_at) VALUES (?,'
                                 . implode(',', array_fill(0, count($cols), '?')) . ',?,?)')
                       ->execute(array_merge([$nid], array_values($v), [date('c'), date('c')]));
                kv_db()->prepare('DELETE FROM retired_products WHERE id=?')->execute([$nid]);
                kv_plog($nid, [], $v, $do === 'copy' ? '商品 ' . $id . ' を複製して新規' : '新規登録');
                header('Location: ?a=product&id=' . $nid . '&saved=new');
                exit;
            }
            kv_pupdate($id, $v, '商品の編集');
            header('Location: ?a=product&id=' . $id . '&saved=1');
            exit;
        }
        $p = array_merge($p ?: [], $v);
    }
    $p = $p ?: ['name' => '', 'model_number' => '', 'gtin' => '', 'price' => '', 'list_price' => '', 'price_unspecified' => 0, 'stock' => 0,
                'stock_unlimited' => 1, 'hidden' => 0, 'tax_reduce' => 0, 'maker_id' => $_GET['maker_id'] ?? '', 'category_id' => '', 'description' => '',
                'image_url' => '', 'variations' => null];
    kv_admin_page($id ? '商品の編集（ID ' . $id . '）' : '新しい商品', 'products');
    if (!empty($_GET['saved'])) { echo '<div class="note">' . ($_GET['saved'] === 'new' ? '登録しました。' : '保存しました。') . ' 店の画面にすぐ出ます。</div>'; }
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    $makers = []; foreach (kv_admin_makers_all() as $m) { $makers[$m['id']] = $m['name']; }
    $cats = []; foreach (kv_admin_categories_all() as $c) { $cats[$c['id']] = ($c['parent_name'] ? $c['parent_name'] . ' › ' : '') . $c['name']; }
    $chk = fn($k) => !empty($p[$k]) ? ' checked' : '';
    $num = fn($k) => $p[$k] === null || $p[$k] === '' ? '' : (string)(int)$p[$k];
    echo '<p><a href="?a=products">← 商品一覧</a>' . ($id && !$p['hidden'] ? '　<a href="' . kv_url('product/' . $id) . '" target="_blank" rel="noopener">店で見る</a>' : '') . '</p>';
    echo '<form method="post" enctype="multipart/form-data" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<table class="ftbl">'
       . '<tr><th>商品名</th><td><input class="in" name="name" value="' . kv_e($p['name']) . '" required></td></tr>'
       . '<tr><th>型番（社内記号付き）</th><td><input class="in" name="model_number" value="' . kv_e($p['model_number']) . '"><div class="src">先頭の仕入先の記号（OYA- など）はお客様の画面には出ません。発注のときに使います。</div></td></tr>'
       . '<tr><th>JANコード</th><td><input class="in" name="gtin" value="' . kv_e($p['gtin']) . '" inputmode="numeric"></td></tr>'
       . '<tr><th>販売価格（税別）</th><td><input class="in" name="price" value="' . $num('price') . '" inputmode="numeric" style="max-width:200px"> 円'
       . ((int)$p['price'] > 0 ? '　<span class="src">税込 ' . number_format((int)floor((int)$p['price'] * ((int)$p['tax_reduce'] ? 1.08 : 1.10))) . '円</span>' : '') . '</td></tr>'
       . '<tr><th>メーカー希望小売価格（税別）</th><td><input class="in" name="list_price" value="' . $num('list_price') . '" inputmode="numeric" style="max-width:200px"> 円'
       . '<div class="src">販売価格より高いときだけ、商品ページに定価として出ます。空欄で出しません。</div></td></tr>'
       . '<tr><th>在庫</th><td><label><input type="checkbox" name="stock_unlimited" value="1"' . $chk('stock_unlimited') . '> 在庫数を管理しない（メーカー直送品はこちら）</label><br>'
       . '在庫数 <input class="in" name="stock" value="' . $num('stock') . '" inputmode="numeric" style="max-width:120px"></td></tr>'
       . '<tr><th>状態</th><td><label><input type="checkbox" name="hidden" value="1"' . $chk('hidden') . '> 非表示（店に出さない）</label><br>'
       . '<label><input type="checkbox" name="price_unspecified" value="1"' . $chk('price_unspecified') . '> お問い合わせ品（カートで買えず「価格・納期を問い合わせる」にする）</label><br>'
       . '<label><input type="checkbox" name="tax_reduce" value="1"' . $chk('tax_reduce') . '> 軽減税率（8%）</label></td></tr>'
       . '<tr><th>メーカー</th><td>' . kv_sel('maker_id', $makers, $p['maker_id'], '（なし）') . '</td></tr>'
       . '<tr><th>カテゴリ</th><td>' . kv_sel('category_id', $cats, $p['category_id'], '（なし）') . '</td></tr>'
       . '<tr><th>画像</th><td>' . ($p['image_url'] ? '<img src="' . kv_e(kv_img($p['image_url'])) . '" alt="" style="max-width:200px;height:auto;border:1px solid #e3e9ee;border-radius:8px;display:block;margin-bottom:6px">'
            . '<label><input type="checkbox" name="image_clear" value="1"> 画像を外す</label><br>' : '')
       . '<input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"> <span class="src">差し替えるときだけ選ぶ（長辺800pxに縮めて保存）</span><br>'
       . '<span class="src">または画像のURL：</span><input class="in" name="image_url_set" value="" placeholder="https://…（メーカーサイトの画像など）"></td></tr>';
    $vo = kv_variations($p);
    echo '<tr><th>オプション<div class="src" style="font-weight:normal">（吐出口・電圧・色など。お客様が選んでからカートに入れます）</div></th><td>';
    for ($i = 1; $i <= 3; $i++) {
        $o = $vo[$i - 1] ?? ['label' => '', 'values' => []];
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><input class="in" name="opt_label[' . $i . ']" value="' . kv_e($o['label']) . '" placeholder="項目名（例：電圧）" style="max-width:180px">'
           . '<textarea class="in" name="opt_values[' . $i . ']" rows="3" placeholder="選べる値を1行に1つ（例：100V）" style="flex:1;min-width:200px">' . kv_e(implode("\n", $o['values'])) . '</textarea></div>';
    }
    echo '</td></tr>'
       . '<tr><th>商品説明（HTML）</th><td><textarea class="in" name="description" rows="10">' . kv_e($p['description']) . '</textarea></td></tr>'
       . '</table><p style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" name="do" value="save" type="submit">' . ($id ? '保存する' : '登録する') . '</button>';
    if ($id) {
        echo '<button class="btn gray" name="do" value="copy" type="submit">この内容で別の商品として登録（複製）</button>'
           . '<button class="btn gray" name="do" value="retire" type="submit" formnovalidate onclick="return confirm(\'取り扱い終了にします。商品ページは「取り扱いを終了しました」になり、元に戻すには登録し直しが必要です。よろしいですか？\')">取り扱い終了にする</button>';
    }
    echo '</p></form>';
    if ($id) {
        echo '<h2>この商品の変更履歴</h2>';
        kv_admin_plog_table(kv_plog_read($id, 50), false);
    }
    kv_admin_end();
}

function kv_admin_plog_table(array $rows, bool $withName): void
{
    if (!$rows) { echo '<p class="src">まだ記録はありません（2026-11-17 から記録しています）。</p>'; return; }
    $names = kv_pfields();
    echo '<div class="scroll"><table class="t"><tr><th>日時</th>' . ($withName ? '<th>商品</th>' : '') . '<th>操作</th><th>変更</th></tr>';
    foreach ($rows as $r) {
        $ch = [];
        foreach (($r['changes'] ?? []) as $k => [$b, $a]) {
            $ch[] = kv_e($names[$k] ?? $k) . '：' . kv_e(is_scalar($b) ? (string)$b : '') . ' → <b>' . kv_e(is_scalar($a) ? (string)$a : '') . '</b>';
        }
        echo '<tr><td class="src" style="white-space:nowrap">' . kv_e($r['at']) . '</td>'
           . ($withName ? '<td><a href="?a=product&id=' . (int)$r['id'] . '">' . (int)$r['id'] . '</a> ' . kv_e($r['name'] ?? '') . '</td>' : '')
           . '<td class="src">' . kv_e($r['how']) . '</td><td style="font-size:13px">' . implode('<br>', array_map(fn($s) => mb_strimwidth($s, 0, 400, '…'), $ch)) . '</td></tr>';
    }
    echo '</table></div>';
}

function kv_admin_plog(): void
{
    kv_admin_page('商品の変更履歴', 'plog');
    if (!empty($_GET['done'])) { echo '<div class="note">' . (int)$_GET['done'] . '件の商品を更新しました。</div>'; }
    kv_admin_plog_table(kv_plog_read(0, 300), true);
    kv_admin_end();
}

// ---------------- 価格の一括改定 ----------------

function kv_admin_price(): void
{
    $g = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $pct = (float)str_replace(['％', '%', ' '], '', (string)($g['pct'] ?? ''));
    $round = (int)($g['round'] ?? 1);
    $target = (string)($g['target'] ?? 'price');
    $calc = function (int $v) use ($pct, $round): int {
        $n = $v * (1 + $pct / 100);
        return (int)(round($n / max(1, $round)) * max(1, $round));
    };
    $scope = array_intersect_key($g, ['q' => 1, 'maker_id' => 1, 'category_id' => 1, 'st' => 1]);
    [$where, $args] = kv_admin_pfilter($scope);
    $col = $target === 'list_price' ? 'list_price' : 'price';
    $st = kv_db()->prepare("SELECT id,name,model_number,price,list_price FROM products WHERE $where AND $col>0 ORDER BY id");
    $st->execute($args);
    $rows = $st->fetchAll();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok() && !empty($_POST['apply']) && $pct != 0.0) {
        $how = '価格の一括改定 ' . ($pct > 0 ? '+' : '') . $pct . '%（' . ($col === 'price' ? '販売価格' : '定価') . '・' . $round . '円単位）';
        $db = kv_db(); $db->beginTransaction();
        foreach ($rows as $r) { kv_pupdate((int)$r['id'], [$col => $calc((int)$r[$col])], $how); }
        $db->commit();
        header('Location: ?a=plog&done=' . count($rows));
        exit;
    }
    $makers = []; foreach (kv_admin_makers_all() as $m) { $makers[$m['id']] = $m['name'] . '（' . $m['cnt'] . '）'; }
    $cats = []; foreach (kv_admin_categories_all() as $c) { if ($c['cnt']) { $cats[$c['id']] = ($c['parent_name'] ? $c['parent_name'] . ' › ' : '') . $c['name']; } }
    kv_admin_page('価格の一括改定', 'price');
    echo '<p class="src">メーカーの値上げなどで、まとめて価格を変えます。「確認する」で変わる価格の一覧を出し、よければ「反映する」を押します。変更は商品の変更履歴に残ります。</p>'
       . '<form method="get" class="panel"><input type="hidden" name="a" value="price"><div class="filters">'
       . '<input class="in" name="q" value="' . kv_e($scope['q'] ?? '') . '" placeholder="商品名・型番（空欄で全部）">'
       . kv_sel('maker_id', $makers, $scope['maker_id'] ?? '', 'メーカー（すべて）')
       . kv_sel('category_id', $cats, $scope['category_id'] ?? '', 'カテゴリ（すべて）')
       . kv_sel('st', ['live' => '販売中だけ', 'hidden' => '非表示だけ'], $scope['st'] ?? '', '状態（すべて）')
       . '</div><div class="filters">' . kv_sel('target', ['price' => '販売価格（税別）を', 'list_price' => '定価（税別）を'], $target)
       . '<input class="in" name="pct" value="' . kv_e($g['pct'] ?? '') . '" placeholder="例：5 や -3" style="width:110px" inputmode="decimal"> ％ 変える　'
       . kv_sel('round', ['1' => '1円単位', '10' => '10円単位', '100' => '100円単位'], (string)$round) . ' で四捨五入'
       . '<button class="btn" type="submit">確認する</button></div></form>';
    if ($pct != 0.0 && $rows) {
        $sumb = 0; $suma = 0;
        echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
        foreach ($g as $k => $v) { if (in_array($k, ['q', 'maker_id', 'category_id', 'st', 'pct', 'round', 'target'], true)) { echo '<input type="hidden" name="' . kv_e($k) . '" value="' . kv_e($v) . '">'; } }
        echo '<p><b>' . count($rows) . '件</b>の' . ($col === 'price' ? '販売価格' : '定価') . 'を ' . ($pct > 0 ? '+' : '') . kv_e((string)$pct) . '% にします。'
           . '　<button class="btn" name="apply" value="1" type="submit" onclick="return confirm(\'' . count($rows) . '件の価格を変えます。よろしいですか？\')">反映する</button></p>'
           . '<div class="scroll"><table class="t"><tr><th>ID</th><th>商品名</th><th>いま</th><th>変更後</th><th>差</th>' . ($col === 'price' ? '<th>定価</th>' : '') . '</tr>';
        foreach (array_slice($rows, 0, 500) as $r) {
            $b = (int)$r[$col]; $a = $calc($b); $sumb += $b; $suma += $a;
            $warn = $col === 'price' && (int)$r['list_price'] > 0 && $a > (int)$r['list_price'] ? ' <span class="tag red">定価超え</span>' : '';
            echo '<tr><td>' . (int)$r['id'] . '</td><td>' . kv_e(mb_strimwidth((string)$r['name'], 0, 60, '…')) . '</td><td style="text-align:right">' . number_format($b)
               . '</td><td style="text-align:right"><b>' . number_format($a) . '</b>' . $warn . '</td><td style="text-align:right" class="' . ($a >= $b ? 'up' : 'down') . '">' . ($a >= $b ? '+' : '') . number_format($a - $b) . '</td>'
               . ($col === 'price' ? '<td class="src" style="text-align:right">' . ((int)$r['list_price'] > 0 ? number_format((int)$r['list_price']) : '') . '</td>' : '') . '</tr>';
        }
        echo '</table></div>' . (count($rows) > 500 ? '<p class="src">先頭500件だけ表示しています（反映は全件）。</p>' : '') . '</form>';
    } elseif ($pct != 0.0) {
        echo '<div class="alert">条件に合う商品がありません。</div>';
    }
    kv_admin_end();
}

// ---------------- 商品CSV ----------------

const KV_PCSV_COLS = ['id', 'name', 'model_number', 'gtin', 'price', 'list_price', 'price_unspecified', 'stock', 'stock_unlimited',
                      'hidden', 'tax_reduce', 'maker_id', 'category_id', 'image_url'];

function kv_admin_pcsv(): void
{
    [$where, $args] = kv_admin_pfilter($_GET);
    $st = kv_db()->prepare('SELECT ' . implode(',', KV_PCSV_COLS) . " FROM products WHERE $where ORDER BY id");
    $st->execute($args);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="products_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel で文字化けさせない
    fputcsv($out, KV_PCSV_COLS);
    while ($r = $st->fetch()) { fputcsv($out, array_map(fn($c) => $r[$c], KV_PCSV_COLS)); }
    fclose($out);
}

function kv_admin_pimport(): void
{
    $tmp = KV_DATA . '/pimport_' . preg_replace('/\W/', '', session_id()) . '.json';
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok() && !empty($_POST['apply']) && is_file($tmp)) {
        $plan = json_decode((string)file_get_contents($tmp), true) ?: [];
        $db = kv_db(); $db->beginTransaction();
        foreach ($plan as $id => $vals) { kv_pupdate((int)$id, $vals, 'CSVの取り込み'); }
        $db->commit(); @unlink($tmp);
        header('Location: ?a=plog&done=' . count($plan));
        exit;
    }
    $plan = []; $skipped = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok() && !empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $raw = (string)file_get_contents($_FILES['csv']['tmp_name']);
        if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win'); }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
        $head = array_map('trim', fgetcsv($fh) ?: []);
        if (!in_array('id', $head, true)) { $msg = '1行目に「id」の列が要ります（「この条件でCSV」で書き出したファイルを直して戻すのが確実です）。'; }
        else {
            $editable = array_diff(KV_PCSV_COLS, ['id']);
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < count($head)) { $row = array_pad($row, count($head), ''); }
                $r = array_combine($head, array_slice($row, 0, count($head)));
                $id = (int)$r['id']; $cur = $id ? kv_aproduct($id) : null;
                if (!$cur) { $skipped[] = $r['id']; continue; }
                $ch = [];
                foreach ($editable as $c) {
                    if (!array_key_exists($c, $r)) { continue; }
                    $v = trim((string)$r[$c]);
                    if (in_array($c, ['price', 'list_price', 'stock', 'maker_id', 'category_id'], true)) { $v = $v === '' ? null : (int)preg_replace('/[^\d-]/', '', $v); }
                    if (in_array($c, ['price_unspecified', 'stock_unlimited', 'hidden', 'tax_reduce'], true)) { $v = (int)$v ? 1 : 0; }
                    if ((string)$v !== (string)$cur[$c]) { $ch[$c] = $v; }
                }
                if ($ch) { $plan[$id] = $ch; }
            }
            file_put_contents($tmp, json_encode($plan, JSON_UNESCAPED_UNICODE));
        }
    }
    kv_admin_page('商品CSVの取り込み', 'pimport');
    echo '<p class="src">商品一覧の「この条件でCSV」で書き出したファイルを Excel などで直して、ここから戻します。id で商品を突き合わせ、<b>変わった項目だけ</b>を確認してから反映します。'
       . '列は ' . kv_e(implode(', ', KV_PCSV_COLS)) . '（要らない列は消してかまいません。id は必須）。新しい商品の追加は商品画面からどうぞ。</p>'
       . '<form method="post" enctype="multipart/form-data" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<input type="file" name="csv" accept=".csv,text/csv"> <button class="btn" type="submit">確認する</button></form>';
    if ($msg) { echo '<div class="alert">' . kv_e($msg) . '</div>'; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$msg && empty($_POST['apply'])) {
        if ($skipped) { echo '<div class="alert">見つからない id が ' . count($skipped) . '件ありました（取り込みません）：' . kv_e(implode(', ', array_slice($skipped, 0, 20))) . '</div>'; }
        if (!$plan) { echo '<div class="note">変わる所はありませんでした。</div>'; }
        else {
            $names = kv_pfields();
            echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><p><b>' . count($plan) . '件</b>の商品が変わります。'
               . '　<button class="btn" name="apply" value="1" type="submit">反映する</button></p><div class="scroll"><table class="t"><tr><th>ID</th><th>変わる項目</th></tr>';
            foreach (array_slice($plan, 0, 300, true) as $id => $ch) {
                $cur = kv_aproduct((int)$id);
                echo '<tr><td><a href="?a=product&id=' . (int)$id . '">' . (int)$id . '</a></td><td style="font-size:13px">';
                foreach ($ch as $k => $v) { echo kv_e($names[$k] ?? $k) . '：' . kv_e((string)$cur[$k]) . ' → <b>' . kv_e((string)$v) . '</b><br>'; }
                echo '</td></tr>';
            }
            echo '</table></div></form>';
        }
    }
    kv_admin_end();
}

// ---------------- メーカー ----------------

function kv_admin_makers(): void
{
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $name = trim((string)($_POST['name'] ?? '')); $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $sort = (int)($_POST['sort'] ?? 0); $id = (int)($_POST['id'] ?? 0);
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) { $err = 'メーカー名と、URLに使う英小文字の名前（例：anest-iwata）を入れてください。'; }
        else {
            $dup = kv_db()->prepare('SELECT id FROM makers WHERE slug=? AND id<>?'); $dup->execute([$slug, $id]);
            if ($dup->fetchColumn()) { $err = 'その URL名（' . $slug . '）はほかのメーカーが使っています。'; }
            elseif ($id) { kv_db()->prepare('UPDATE makers SET name=?, slug=?, sort=? WHERE id=?')->execute([$name, $slug, $sort, $id]); header('Location: ?a=makers&saved=1'); exit; }
            else {
                $nid = max((int)kv_db()->query('SELECT MAX(id) FROM makers')->fetchColumn(), 100000) + 1;
                kv_db()->prepare('INSERT INTO makers (id,slug,name,sort) VALUES (?,?,?,?)')->execute([$nid, $slug, $name, $sort]);
                header('Location: ?a=makers&saved=1'); exit;
            }
        }
    }
    kv_admin_page('メーカー', 'makers');
    if (!empty($_GET['saved'])) { echo '<div class="note">保存しました。</div>'; }
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    echo '<p class="src">URL名は /maker/〇〇 になります。<b>索引に載ったメーカーの URL名を変えると、そのページの検索順位がリセットされます</b>。</p>';
    $form = fn(array $m) => '<form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
        . '<input type="hidden" name="id" value="' . (int)($m['id'] ?? 0) . '"><input class="in" name="name" value="' . kv_e($m['name'] ?? '') . '" placeholder="メーカー名" style="width:180px">'
        . '<input class="in" name="slug" value="' . kv_e($m['slug'] ?? '') . '" placeholder="URL名（英小文字）" style="width:160px">'
        . '<input class="in" name="sort" value="' . (int)($m['sort'] ?? 0) . '" style="width:70px" title="並び順"><button class="btn gray" type="submit">' . (!empty($m['id']) ? '保存' : '追加') . '</button></form>';
    echo '<div class="panel"><b>メーカーを追加</b>' . $form([]) . '</div>';
    echo '<div class="scroll"><table class="t"><tr><th>ID</th><th>名前・URL名・並び順</th><th>商品</th><th></th></tr>';
    foreach (kv_admin_makers_all() as $m) {
        echo '<tr><td>' . (int)$m['id'] . '</td><td>' . $form($m) . '</td><td style="white-space:nowrap">' . (int)$m['live'] . ' / ' . (int)$m['cnt'] . '件</td>'
           . '<td style="white-space:nowrap"><a href="?a=products&maker_id=' . (int)$m['id'] . '">商品</a>　<a href="' . kv_url('maker/' . $m['slug']) . '" target="_blank" rel="noopener">店で見る</a></td></tr>';
    }
    echo '</table></div>';
    kv_admin_end();
}

// ---------------- カテゴリ ----------------

function kv_admin_categories(): void
{
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $name = trim((string)($_POST['name'] ?? '')); $id = (int)($_POST['id'] ?? 0);
        $pid = ($_POST['parent_id'] ?? '') === '' ? null : (int)$_POST['parent_id'];
        $pname = null;
        if ($pid) {
            // 親カテゴリは categories に行が無く、子の parent_name にだけ名前があることが多い（おちゃのこネットの形）
            $pc = kv_category($pid);
            $pname = $pc['name'] ?? (kv_db()->query('SELECT parent_name FROM categories WHERE parent_id=' . (int)$pid . " AND parent_name<>'' LIMIT 1")->fetchColumn() ?: null);
        }
        if ($name === '') { $err = 'カテゴリ名を入れてください。'; }
        elseif ($id) {
            kv_db()->prepare('UPDATE categories SET name=?, parent_id=?, parent_name=? WHERE id=?')->execute([$name, $pid, $pname, $id]);
            kv_db()->prepare('UPDATE categories SET parent_name=? WHERE parent_id=?')->execute([$name, $id]);
            header('Location: ?a=categories&saved=1'); exit;
        } else {
            $nid = max((int)kv_db()->query('SELECT MAX(id) FROM categories')->fetchColumn(), 100000) + 1;
            kv_db()->prepare('INSERT INTO categories (id,name,parent_id,parent_name) VALUES (?,?,?,?)')->execute([$nid, $name, $pid, $pname]);
            header('Location: ?a=categories&saved=1'); exit;
        }
    }
    $all = kv_admin_categories_all();
    $parents = [];
    foreach ($all as $c) { if (!$c['parent_id']) { $parents[$c['id']] = $c['name']; } }
    foreach ($all as $c) { if ($c['parent_id'] && !isset($parents[$c['parent_id']]) && $c['parent_name']) { $parents[$c['parent_id']] = $c['parent_name']; } }
    asort($parents);
    kv_admin_page('カテゴリ', 'categories');
    if (!empty($_GET['saved'])) { echo '<div class="note">保存しました。</div>'; }
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    $form = fn(array $c) => '<form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
        . '<input type="hidden" name="id" value="' . (int)($c['id'] ?? 0) . '">' . kv_sel('parent_id', $parents, $c['parent_id'] ?? '', '（親なし）')
        . '<input class="in" name="name" value="' . kv_e($c['name'] ?? '') . '" placeholder="カテゴリ名" style="width:200px"><button class="btn gray" type="submit">' . (!empty($c['id']) ? '保存' : '追加') . '</button></form>';
    echo '<div class="panel"><b>カテゴリを追加</b>' . $form([]) . '</div>';
    echo '<div class="scroll"><table class="t"><tr><th>ID</th><th>親 › 名前</th><th>商品</th><th></th></tr>';
    foreach ($all as $c) {
        echo '<tr><td>' . (int)$c['id'] . '</td><td>' . $form($c) . '</td><td>' . (int)$c['cnt'] . '件</td>'
           . '<td style="white-space:nowrap"><a href="?a=products&category_id=' . (int)$c['id'] . '">商品</a>　<a href="' . kv_url('product-list/' . (int)$c['id']) . '" target="_blank" rel="noopener">店で見る</a></td></tr>';
    }
    echo '</table></div>';
    kv_admin_end();
}

// ---------------- 顧客 ----------------

function kv_admin_customers(): void
{
    kv_order_schema();
    $q = trim((string)($_GET['q'] ?? ''));
    $w = "email<>''"; $a = [];
    if ($q !== '') { $w .= ' AND (name LIKE ? OR company LIKE ? OR email LIKE ? OR tel LIKE ? OR address LIKE ?)'; $a = array_fill(0, 5, '%' . $q . '%'); }
    $per = 50; $page = max(1, (int)($_GET['p'] ?? 1));
    $base = "FROM orders WHERE $w GROUP BY lower(email)";
    $st = kv_db()->prepare("SELECT COUNT(*) FROM (SELECT 1 $base)"); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = kv_db()->prepare("SELECT lower(email) em, MAX(name) name, MAX(company) company, MAX(tel) tel, MAX(address) address,"
        . " SUM(CASE WHEN cancelled=0 THEN 1 ELSE 0 END) n, SUM(CASE WHEN cancelled=0 THEN total ELSE 0 END) amt, MAX(created_at) last, MIN(created_at) first"
        . " $base ORDER BY last DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($a);
    kv_admin_page('顧客', 'customers');
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="customers"><input class="in" name="q" value="' . kv_e($q) . '" placeholder="名前・会社・メール・電話・住所">'
       . '<button class="btn" type="submit">探す</button><a class="btn gray" href="?a=ccsv&q=' . kv_e(rawurlencode($q)) . '">CSVで落とす</a></form>';
    echo '<p class="src">X-Direct（kvcart）で受けた注文のメールアドレスごとにまとめています。おちゃのこネット時代の顧客は含みません。</p>';
    echo '<div class="scroll"><table class="t"><tr><th>お名前・会社</th><th>メール・電話</th><th>住所</th><th>注文</th><th>合計（税込）</th><th>最終注文</th></tr>';
    foreach ($st->fetchAll() as $c) {
        echo '<tr><td>' . kv_e($c['name']) . ($c['company'] ? '<div class="src">' . kv_e($c['company']) . '</div>' : '') . '</td>'
           . '<td>' . kv_e($c['em']) . '<div class="src">' . kv_e($c['tel']) . '</div></td><td class="src">' . kv_e(mb_strimwidth((string)$c['address'], 0, 40, '…')) . '</td>'
           . '<td style="text-align:right"><a href="?a=orders&q=' . kv_e(rawurlencode($c['em'])) . '">' . (int)$c['n'] . '回</a>' . ((int)$c['n'] >= 2 ? ' <span class="tag gr">リピーター</span>' : '') . '</td>'
           . '<td style="text-align:right">' . number_format((int)$c['amt']) . '円</td><td class="src" style="white-space:nowrap">' . kv_e(substr((string)$c['last'], 0, 10)) . '</td></tr>';
    }
    echo '</table></div>';
    kv_pager(['page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], '?a=customers&q=' . rawurlencode($q));
    kv_admin_end();
}

function kv_admin_ccsv(): void
{
    kv_order_schema();
    $st = kv_db()->query("SELECT lower(email) email, MAX(name) name, MAX(company) company, MAX(tel) tel, MAX(zip) zip, MAX(address) address,"
        . " SUM(CASE WHEN cancelled=0 THEN 1 ELSE 0 END) orders, SUM(CASE WHEN cancelled=0 THEN total ELSE 0 END) total, MIN(created_at) first, MAX(created_at) last"
        . " FROM orders WHERE email<>'' GROUP BY lower(email) ORDER BY last DESC");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="customers_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['メール', 'お名前', '会社', '電話', '郵便番号', '住所', '注文回数', '合計（税込）', '初回注文', '最終注文']);
    while ($r = $st->fetch()) { fputcsv($out, array_values($r)); }
    fclose($out);
}

// ---------------- 売上 ----------------

function kv_admin_stats(): void
{
    kv_order_schema();
    $db = kv_db();
    $months = $db->query("SELECT substr(created_at,1,7) m, COUNT(*) n, SUM(total) amt, SUM(subtotal) sub,"
        . " SUM(CASE WHEN f_paid=1 THEN total ELSE 0 END) paid, SUM(CASE WHEN f_shipped=1 THEN 1 ELSE 0 END) shipped"
        . " FROM orders WHERE cancelled=0 GROUP BY m ORDER BY m DESC LIMIT 24")->fetchAll();
    $pay = $db->query("SELECT payment, COUNT(*) n, SUM(total) amt FROM orders WHERE cancelled=0 GROUP BY payment")->fetchAll();
    $from = date('Y-m-d', strtotime('-90 days'));
    $st = $db->prepare("SELECT i.product_id, MAX(i.name) name, SUM(i.qty) qty, SUM(i.amount) amt, COUNT(DISTINCT i.order_id) n"
        . " FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.cancelled=0 AND o.created_at>=? GROUP BY i.product_id ORDER BY amt DESC LIMIT 30");
    $st->execute([$from]);
    $top = $st->fetchAll();
    $cnt = $db->query("SELECT COUNT(*) total, SUM(cancelled) cancelled, SUM(CASE WHEN cancelled=0 AND f_paid=0 THEN 1 ELSE 0 END) unpaid,"
        . " SUM(CASE WHEN cancelled=0 AND f_paid=1 AND f_shipped=0 THEN 1 ELSE 0 END) toship FROM orders")->fetch();
    kv_admin_page('売上', 'stats');
    echo '<div class="filters"><span class="tag">注文 ' . (int)$cnt['total'] . '件</span><span class="tag red">キャンセル ' . (int)$cnt['cancelled'] . '件</span>'
       . '<a class="tag or" href="?a=orders&f_paid=0&cancelled=0">未入金 ' . (int)$cnt['unpaid'] . '件</a>'
       . '<a class="tag or" href="?a=orders&f_paid=1&f_shipped=0&cancelled=0">入金済み・未発送 ' . (int)$cnt['toship'] . '件</a></div>';
    echo '<h2>月別（キャンセルを除く）</h2><div class="scroll"><table class="t"><tr><th>月</th><th>注文</th><th>売上（税込）</th><th>うち入金済み</th><th>商品合計（税別）</th><th>発送済み</th></tr>';
    foreach ($months as $m) {
        echo '<tr><td>' . kv_e($m['m']) . '</td><td style="text-align:right">' . (int)$m['n'] . '件</td><td style="text-align:right">' . number_format((int)$m['amt']) . '円</td>'
           . '<td style="text-align:right">' . number_format((int)$m['paid']) . '円</td><td style="text-align:right">' . number_format((int)$m['sub']) . '円</td><td style="text-align:right">' . (int)$m['shipped'] . '件</td></tr>';
    }
    echo '</table></div><h2>支払い方法</h2><div class="scroll"><table class="t"><tr><th>方法</th><th>注文</th><th>売上（税込）</th></tr>';
    foreach ($pay as $p) { echo '<tr><td>' . kv_e(['bank' => '銀行振込', 'stripe' => 'クレジットカード'][$p['payment']] ?? $p['payment']) . '</td><td style="text-align:right">' . (int)$p['n'] . '件</td><td style="text-align:right">' . number_format((int)$p['amt']) . '円</td></tr>'; }
    echo '</table></div><h2>よく売れた商品（直近90日・税別）</h2><div class="scroll"><table class="t"><tr><th>商品</th><th>注文</th><th>数量</th><th>金額</th></tr>';
    foreach ($top as $t) {
        echo '<tr><td><a href="?a=product&id=' . (int)$t['product_id'] . '">' . kv_e(mb_strimwidth((string)$t['name'], 0, 70, '…')) . '</a></td><td style="text-align:right">' . (int)$t['n'] . '件</td>'
           . '<td style="text-align:right">' . (int)$t['qty'] . '</td><td style="text-align:right">' . number_format((int)$t['amt']) . '円</td></tr>';
    }
    echo '</table></div><p class="src">X-Direct（kvcart）で受けた注文だけを数えています。おちゃのこネット時代の注文は含みません。</p>';
    kv_admin_end();
}
