<?php
/** セッションとCSRF。店（index.php）と管理（admin.php）の両方が使うので独立させる。
 *  **session_start() は必ず出力より前に呼ぶ。** 後だとクッキーが出ずCSRFが毎回外れる。 */
declare(strict_types=1);

function kv_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // ログイン（管理画面）とカートを長く保つ（2026-11-17 ユーザー指示「ログインの有効期限長くして」）。
        // 既定 365日。kv_config.php で KV_SESSION_DAYS を決めれば変えられる（0 でブラウザを閉じるまで）。
        // PHP の既定（操作が無いと約24分で消える・共有の保存先は他のアプリの掃除で消える）を避けるため、
        // 保存先を kv_data/sessions（外から見えない）に分け、残す時間も同じ日数にする。
        $days = defined('KV_SESSION_DAYS') ? (int)KV_SESSION_DAYS : 365;
        $life = $days > 0 ? $days * 86400 : 0;
        if ($life > 0) {
            @ini_set('session.gc_maxlifetime', (string)$life);
            $dir = (defined('KV_DATA') ? KV_DATA : __DIR__ . '/kv_data') . '/sessions';
            if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
            if (is_dir($dir) && is_writable($dir)) { session_save_path($dir); }
        }
        session_set_cookie_params(['lifetime' => $life, 'path' => (defined('KV_BASE') && KV_BASE !== '') ? KV_BASE : '/',
                                   'httponly' => true, 'samesite' => 'Lax',
                                   'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
        if ($life > 0 && mt_rand(1, 200) === 1) { kv_session_sweep(); }
    }
}

/**
 * 中身の無いセッションのファイルを掃除する。
 * 保存期間を365日にしたら、クローラーの1回ごとに空（0バイト）か CSRF だけ（45バイト）のファイルができ、
 * 1日6万個ずつたまって heteml のファイル数の上限（100万）に当たった（2026-11-22）。
 * ログイン・カートの中身が入ったもの（46バイト以上）は消さない。1日以上前のものだけ・1回に最大3000個。
 */
function kv_session_sweep(): void
{
    $dir = (defined('KV_DATA') ? KV_DATA : __DIR__ . '/kv_data') . '/sessions';
    $h = @opendir($dir);
    if (!$h) { return; }
    $old = time() - 86400; $n = 0;
    while (($f = readdir($h)) !== false && $n < 3000) {
        if (strncmp($f, 'sess_', 5) !== 0) { continue; }
        $p = $dir . '/' . $f;
        $st = @stat($p);
        if ($st && $st['size'] < 46 && $st['mtime'] < $old && $p !== $dir . '/sess_' . session_id()) {
            if (@unlink($p)) { $n++; }
        }
    }
    closedir($h);
}

function kv_csrf(): string
{
    kv_session();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}

function kv_csrf_ok(): bool
{
    kv_session();
    return !empty($_POST['csrf']) && hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$_POST['csrf']);
}
