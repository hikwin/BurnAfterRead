<?php
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = $_POST['content'] ?? '';
    $maxViews = intval($_POST['max_views'] ?? 1);
    $expireHours = intval($_POST['expire_hours'] ?? 24);
    
    if (empty($content)) {
        $error = '请输入文本内容';
    } elseif ($expireHours > 72 || $expireHours < 1) {
        $error = '过期时间必须在1-72小时之间';
    } elseif ($maxViews < 1) {
        $error = '访问次数必须大于0';
    } else {
        if (isset($_SESSION['captcha_lock_until']) && time() < $_SESSION['captcha_lock_until']) {
            $error = '功能已锁定，请稍后重试';
        } elseif (empty($_POST['captcha_token']) || empty($_POST['captcha_input'])) {
            $error = '请完成验证码验证';
        } elseif (!isset($_SESSION['captcha_token']) || $_POST['captcha_token'] !== $_SESSION['captcha_token']) {
            $error = '验证码已失效，请刷新后重试';
        } elseif (!isset($_SESSION['captcha_expires']) || time() > $_SESSION['captcha_expires']) {
            $error = '验证码已过期，请刷新后重试';
        } elseif (!isset($_SESSION['captcha_code']) || strcasecmp(trim($_POST['captcha_input']), $_SESSION['captcha_code']) !== 0) {
            $_SESSION['captcha_attempts'] = ($_SESSION['captcha_attempts'] ?? 0) + 1;
            if ($_SESSION['captcha_attempts'] >= 5) { $_SESSION['captcha_lock_until'] = time() + 60; }
            $error = '验证码错误';
        } else {
            $_SESSION['captcha_attempts'] = 0;
            unset($_SESSION['captcha_code']);
            unset($_SESSION['captcha_expires']);
            unset($_SESSION['captcha_token']);
        }

        if (!empty($error)) {
            // 验证失败不继续创建
        } else {
        $db = initDatabase();

        // 检查是否使用短提取码
        $useShortCode = isset($_POST['use_short_code']) && $_POST['use_short_code'] === '1';
        $codeLength = $useShortCode ? 4 : 8; // 默认8位，短码4位

        // 生成唯一提取码
        do {
            $code = generateCode($codeLength);
            $stmt = $db->prepare("SELECT id FROM messages WHERE code = ?");
            $stmt->bindValue(1, $code, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray();
        } while ($exists);
        
        // 加密内容
        $encryptedContent = encrypt($content);
        
        // 计算过期时间
        $expiresAt = date('Y-m-d H:i:s', time() + ($expireHours * 3600));
        
        // 插入数据库
        $stmt = $db->prepare("INSERT INTO messages (code, encrypted_content, max_views, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bindValue(1, $code, SQLITE3_TEXT);
        $stmt->bindValue(2, $encryptedContent, SQLITE3_TEXT);
        $stmt->bindValue(3, $maxViews, SQLITE3_INTEGER);
        $stmt->bindValue(4, $expiresAt, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
            $baseUrl = ($isHttps ? 'https' : 'http') . 
                       '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $viewUrl = $baseUrl . '/view.php?code=' . $code;
            $message = "消息创建成功！";
            $hostHeader = $_SERVER['HTTP_HOST'];
            $schemePrefix = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'ssl://' : '';
            $hostOnly = strpos($hostHeader, ':') !== false ? substr($hostHeader, 0, strpos($hostHeader, ':')) : $hostHeader;
            $port = strpos($hostHeader, ':') !== false ? intval(substr($hostHeader, strpos($hostHeader, ':')+1)) : ($schemePrefix ? 443 : 80);
            $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/cleanup.php';
            $fp = @fsockopen($schemePrefix.$hostOnly, $port, $errno, $errstr, 1);
            if ($fp) {
                stream_set_timeout($fp, 1);
                $out = "GET " . $path . "?ts=" . time() . " HTTP/1.1\r\n" .
                       "Host: " . $hostHeader . "\r\n" .
                       "Connection: Close\r\n\r\n";
                fwrite($fp, $out);
                fclose($fp);
            }
        } else {
            $error = '创建失败，请重试';
        }
        
        $db->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>阅读后即焚 - 匿名消息</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #111827;
        }
        
        .container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.08);
            padding: 32px;
            max-width: 640px;
            width: 100%;
        }
        
        h1 {
            color: #111827;
            margin-bottom: 8px;
            font-size: 28px;
            letter-spacing: -0.02em;
        }
        
        .subtitle {
            color: #6b7280;
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 150px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f9fafb;
        }
        
        textarea:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
            background: #ffffff;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f9fafb;
        }
        
        input[type="number"]:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
            background: #ffffff;
        }
        
        .btn {
            background: #0ea5e9;
            color: #ffffff;
            border: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            display: inline-block;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            background: #0284c7;
            box-shadow: 0 8px 16px rgba(2, 132, 199, 0.2);
        }
        
        .btn:active {
            transform: translateY(0);
            background: #0ea5e9;
            box-shadow: none;
        }
        .key-press {
            transform: scale(0.98);
            box-shadow: none !important;
            filter: brightness(0.95);
        }
        .btn-secondary {
            background: #6b7280;
            color: #ffffff;
        }
        .btn-secondary:hover {
            background: #4b5563;
            box-shadow: 0 8px 16px rgba(75, 85, 99, 0.2);
        }
        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 10px;
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(17, 24, 39, 0.25);
            width: 520px;
            max-width: 92vw;
            padding: 20px;
        }
        .modal-header {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 12px;
        }
        .captcha-area {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
        }
        .captcha-img {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f9fafb;
            width: 100%;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .modal-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 10px;
        }
        .captcha-input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 0.06em;
            background: linear-gradient(180deg, #f9fafb 0%, #ffffff 100%);
            box-shadow: inset 0 1px 2px rgba(17, 24, 39, 0.06);
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .captcha-input:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2), inset 0 1px 2px rgba(17, 24, 39, 0.08);
            background: #ffffff;
        }
        .captcha-input::placeholder {
            color: #9ca3af;
        }
        
        .message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            white-space: pre-line;
            word-break: break-all;
        }
        .share {
            margin-bottom: 20px;
        }
        .share-row {
            display: flex;
            align-items: center;
            gap: 8px;
            word-break: break-all;
        }
        .share-row a {
            color: #0ea5e9;
            text-decoration: none;
        }
        .share-row a:hover {
            text-decoration: underline;
        }
        .btn-sm {
            background: #0ea5e9;
            color: #ffffff;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }
        .btn-sm:hover {
            transform: translateY(-1px);
            background: #0284c7;
            box-shadow: 0 6px 12px rgba(2, 132, 199, 0.2);
        }
        #qrcode-share {
            margin-top: 12px;
            text-align: center;
            display: none;
        }
        #qrcode-share canvas, #qrcode-share img {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px;
            background: #ffffff;
        }
        
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            color: #004085;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 13px;
        }
        /* Toggle Switch CSS */
        .toggle-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e5e7eb;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        input:checked + .slider {
            background-color: #0ea5e9;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        .short-code-warning {
            display: none;
            font-size: 12px;
            color: #b45309;
            background: #fffbeb;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #fef3c7;
            margin-bottom: 12px;
            line-height: 1.5;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>🔥 阅读后即焚</h1>
        <p class="subtitle">创建匿名消息，设置访问次数和过期时间</p>
        
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php if (isset($viewUrl)): ?>
                <div class="share">
                    <div class="share-row">
                        <span>访问链接:</span>
                        <a href="<?php echo htmlspecialchars($viewUrl); ?>" target="_blank" id="access-link"><?php echo htmlspecialchars($viewUrl); ?></a>
                        <button type="button" class="btn-sm" id="copy-link-btn">复制</button>
                        <button type="button" class="btn-sm" id="gen-qr-btn">生成二维码</button>
                    </div>
                    <div class="share-row">
                        <span>访问码:</span>
                        <span id="access-code"><?php echo htmlspecialchars($code); ?></span>
                        <button type="button" class="btn-sm" id="copy-code-btn">复制</button>
                    </div>
                    <div id="qrcode-share"></div>
                </div>
                <script>
                    (function(){
                        var btn = document.getElementById('copy-link-btn');
                        if (!btn) return;
                        btn.addEventListener('click', function(){
                            var text = document.getElementById('access-link').textContent;
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).then(function(){
                                    btn.textContent = '已复制';
                                    setTimeout(function(){ btn.textContent = '复制'; }, 1500);
                                }, function(){
                                    var textarea = document.createElement('textarea');
                                    textarea.value = text;
                                    document.body.appendChild(textarea);
                                    textarea.select();
                                    try { document.execCommand('copy'); } catch (e) {}
                                    document.body.removeChild(textarea);
                                    btn.textContent = '已复制';
                                    setTimeout(function(){ btn.textContent = '复制'; }, 1500);
                                });
                            } else {
                                var textarea = document.createElement('textarea');
                                textarea.value = text;
                                document.body.appendChild(textarea);
                                textarea.select();
                                try { document.execCommand('copy'); } catch (e) {}
                                document.body.removeChild(textarea);
                                btn.textContent = '已复制';
                                setTimeout(function(){ btn.textContent = '复制'; }, 1500);
                            }
                        });

                        var codeBtn = document.getElementById('copy-code-btn');
                        if (codeBtn) {
                            codeBtn.addEventListener('click', function(){
                                var text = document.getElementById('access-code').textContent;
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(text).then(function(){
                                        codeBtn.textContent = '已复制';
                                        setTimeout(function(){ codeBtn.textContent = '复制'; }, 1500);
                                    }, function(){
                                        var textarea = document.createElement('textarea');
                                        textarea.value = text;
                                        document.body.appendChild(textarea);
                                        textarea.select();
                                        try { document.execCommand('copy'); } catch (e) {}
                                        document.body.removeChild(textarea);
                                        codeBtn.textContent = '已复制';
                                        setTimeout(function(){ codeBtn.textContent = '复制'; }, 1500);
                                    });
                                } else {
                                    var textarea = document.createElement('textarea');
                                    textarea.value = text;
                                    document.body.appendChild(textarea);
                                    textarea.select();
                                    try { document.execCommand('copy'); } catch (e) {}
                                    document.body.removeChild(textarea);
                                    codeBtn.textContent = '已复制';
                                    setTimeout(function(){ codeBtn.textContent = '复制'; }, 1500);
                                }
                            });
                        }

                        var qrBtn = document.getElementById('gen-qr-btn');
                        var qrDiv = document.getElementById('qrcode-share');
                        if (qrBtn && qrDiv) {
                            var viewUrl = '<?php echo htmlspecialchars($viewUrl, ENT_QUOTES); ?>';
                            qrBtn.addEventListener('click', function(){
                                qrDiv.style.display = 'block';
                                qrDiv.innerHTML = '';
                                if (typeof QRCode !== 'undefined') {
                                    new QRCode(qrDiv, {
                                        text: viewUrl,
                                        width: 256,
                                        height: 256,
                                        colorDark: '#111827',
                                        colorLight: '#ffffff',
                                        correctLevel: QRCode.CorrectLevel.M
                                    });
                                } else {
                                    qrDiv.innerHTML = '<p style="color: #ef4444;">二维码库未加载</p>';
                                }
                            });
                        }
                    })();
                </script>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="content">消息内容 *</label>
                <textarea id="content" name="content" required placeholder="输入您要发送的文本内容..."><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="max_views">最大访问次数</label>
                    <input type="number" id="max_views" name="max_views" value="<?php echo isset($_POST['max_views']) ? htmlspecialchars($_POST['max_views']) : '1'; ?>" min="1" required>
                </div>
                
                <div class="form-group">
                    <label for="expire_hours">过期时间（小时）</label>
                    <input type="number" id="expire_hours" name="expire_hours" value="<?php echo isset($_POST['expire_hours']) ? htmlspecialchars($_POST['expire_hours']) : '24'; ?>" min="1" max="72" required>
                </div>
            </div>
            
            <div class="btn-row">
                <button type="button" class="btn" id="create-btn">创建消息</button>
                <a href="view.php" class="btn btn-secondary" style="text-align:center;">提取信息</a>
            </div>
        </form>
        
        <div class="info">
            <strong>提示：</strong>消息内容在数据库中已加密存储。访问次数用完后或过期后，消息将自动删除。
        </div>
        <div class="modal-overlay" id="captchaModal" aria-hidden="true">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="captchaTitle" tabindex="-1">
                <div class="modal-header" id="captchaTitle">验证身份</div>
                
                <div class="toggle-wrapper">
                    <span>使用短提取码 (4位)</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="shortCodeSwitch">
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="short-code-warning" id="shortCodeWarning">
                    ⚠️ <strong>注意：</strong> 短提取码容易被暴力破解，仅建议用于非敏感、公开分享的消息。敏感内容请务必保持关闭。
                </div>

                <div class="captcha-area">
                    <div class="captcha-img"><img id="captchaImage" alt="captcha" style="max-width:100%;max-height:100%;display:block" /></div>
                    <button type="button" class="btn-sm" id="refreshCaptcha">刷新验证码</button>
                </div>
                <div class="form-group">
                    <label for="captchaInput">输入验证码</label>
                    <input type="text" id="captchaInput" class="captcha-input" placeholder="请输入验证码" autocomplete="off" maxlength="6">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" id="confirmCreate">确认创建</button>
                    <button type="button" class="btn btn-secondary" id="cancelCreate">取消</button>
                </div>
            </div>
        </div>
    </div>
<script>
function getCookie(name){
    var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
    return m ? decodeURIComponent(m[2]) : '';
}
function loadCaptcha(){
    var img = document.getElementById('captchaImage');
    img.src = 'captcha.php?ts=' + Date.now();
}
document.getElementById('create-btn')?.addEventListener('click', function(){
    var overlay = document.getElementById('captchaModal');
    overlay.style.display = 'flex';
    overlay.setAttribute('aria-hidden', 'false');
    loadCaptcha();
    var modal = document.querySelector('#captchaModal .modal');
    var input = document.getElementById('captchaInput');
    setTimeout(function(){
        if (modal) modal.focus();
        if (input) input.focus();
    }, 0);
});
document.getElementById('cancelCreate')?.addEventListener('click', function(){
    var overlay = document.getElementById('captchaModal');
    overlay.style.display = 'none';
    overlay.setAttribute('aria-hidden', 'true');
});
document.getElementById('refreshCaptcha')?.addEventListener('click', function(){
    loadCaptcha();
    document.getElementById('captchaInput').value = '';
});

// Toggle Switch Logic
document.getElementById('shortCodeSwitch')?.addEventListener('change', function(){
    var warning = document.getElementById('shortCodeWarning');
    if (this.checked) {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
});

document.getElementById('confirmCreate')?.addEventListener('click', function(){
    var token = getCookie('captcha_t');
    var input = document.getElementById('captchaInput').value;
    var useShortCode = document.getElementById('shortCodeSwitch').checked ? '1' : '0';
    
    if (!token || !input) { return; }
    var form = document.querySelector('form[method="POST"]');
    var t = document.createElement('input'); t.type='hidden'; t.name='captcha_token'; t.value=token;
    var i = document.createElement('input'); i.type='hidden'; i.name='captcha_input'; i.value=input;
    var s = document.createElement('input'); s.type='hidden'; s.name='use_short_code'; s.value=useShortCode;
    
    form.appendChild(t); form.appendChild(i); form.appendChild(s);
    form.submit();
});

document.addEventListener('keydown', function(e){
    var overlay = document.getElementById('captchaModal');
    var modal = document.querySelector('#captchaModal .modal');
    if (!overlay || overlay.style.display !== 'flex') { return; }
    if (!overlay.contains(document.activeElement)) { return; }
    if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        var btn = document.getElementById('confirmCreate');
        if (btn) {
            btn.classList.add('key-press');
            setTimeout(function(){ btn.classList.remove('key-press'); }, 150);
            btn.click();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        var cancelBtn = document.getElementById('cancelCreate');
        if (cancelBtn) {
            cancelBtn.classList.add('key-press');
            setTimeout(function(){ cancelBtn.classList.remove('key-press'); }, 150);
            cancelBtn.click();
        }
    }
}, true);
</script>
</body>
</html>

