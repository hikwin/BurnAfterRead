<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', hash('sha256', 'your-secret-key-change-this-in-production-32chars!!', true));
}
$cfg = __DIR__ . '/db_config.php';
if (!file_exists($cfg)) {
    try {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $rand = '';
        for ($i = 0; $i < 16; $i++) { $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $fname = $rand . '.db';
        $full = __DIR__ . '/' . $fname;
        $h = fopen($full, 'c');
        if ($h === false) { throw new RuntimeException('无法创建数据库文件'); }
        fclose($h);
        if (DIRECTORY_SEPARATOR === '/' && function_exists('chmod')) { @chmod($full, 0600); }
        $cfgContent = "<?php\n" . "define('DB_PATH', __DIR__ . '/" . $fname . "');\n";
        $ok = file_put_contents($cfg, $cfgContent, LOCK_EX);
        if ($ok === false) { @unlink($full); throw new RuntimeException('无法写入配置文件'); }
    } catch (Throwable $e) {
        echo '初始化失败：' . htmlspecialchars($e->getMessage());
        exit;
    }
}
require_once $cfg;
// 初始化数据库
function initDatabase() {
    $db = new SQLite3(DB_PATH);
    
    $db->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        encrypted_content TEXT NOT NULL,
        max_views INTEGER DEFAULT 1,
        current_views INTEGER DEFAULT 0,
        expires_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_code ON messages(code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_expires_at ON messages(expires_at)");
    
    return $db;
}

// 加密函数
function encrypt($data) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

// 解密函数
function decrypt($data) {
    $data = base64_decode($data);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
}

// 生成随机提取码
function generateCode($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}
?>

