<?php
/** セッションとCSRF。店（index.php）と管理（admin.php）の両方が使うので独立させる。
 *  **session_start() は必ず出力より前に呼ぶ。** 後だとクッキーが出ずCSRFが毎回外れる。 */
declare(strict_types=1);

function kv_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(['lifetime' => 0, 'path' => (defined('KV_BASE') && KV_BASE !== '') ? KV_BASE : '/',
                                   'httponly' => true, 'samesite' => 'Lax',
                                   'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
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
