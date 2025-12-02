<?php
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
http_response_code(204);
$db = initDatabase();
$db->exec("DELETE FROM messages WHERE expires_at < datetime('now') OR current_views >= max_views");
$db->close();
exit;
?>
