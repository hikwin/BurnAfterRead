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
    <title>阅后即焚 - 安全消息传输</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-hover: #4338ca;
            --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --input-bg: #f8fafc;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --radius-input: 8px;
            --radius-card: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text-main);
        }

        .container {
            width: 100%;
            max-width: 580px;
            margin: auto;
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
            background: linear-gradient(to right, #4f46e5, #ec4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-lg);
            padding: 32px;
            border: 1px solid rgba(255,255,255,0.7);
        }

        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 14px;
            color: var(--text-main);
        }

        textarea {
            width: 100%;
            padding: 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-input);
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 140px;
            background: var(--input-bg);
            transition: all 0.2s;
            line-height: 1.6;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        textarea::placeholder {
            color: #94a3b8;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        input[type="number"], input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-input);
            background: var(--input-bg);
            transition: all 0.2s;
            font-size: 14px;
        }

        input[type="number"]:focus, input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 12px 24px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-input);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #fff;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #f8fafc;
            color: var(--text-main);
            border-color: #cbd5e1;
            box-shadow: var(--shadow-sm);
        }

        .btn-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        /* Success / Error */
        .alert {
            padding: 16px;
            border-radius: var(--radius-input);
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #d1fae5;
            flex-direction: column;
            text-align: center;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fee2e2;
        }

        .alert-info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #dbeafe;
            margin-top: 24px;
            font-size: 13px;
        }

        /* Result Box */
        .result-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin: 16px 0;
            padding: 12px;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            word-break: break-all;
            color: #4f46e5;
            text-align: left;
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: none;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .modal-overlay.active {
            opacity: 1;
        }

        .modal {
            background: #fff;
            width: 90%;
            max-width: 440px;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: scale(0.95);
            transition: transform 0.2s ease;
        }

        .modal-overlay.active .modal {
            transform: scale(1);
        }

        .modal-header {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            text-align: center;
        }

        .captcha-row {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .captcha-img-container {
            flex: 1;
            height: 80px;
            background: #f1f5f9;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .captcha-img-container img {
            max-width: 100%;
            max-height: 100%;
        }

        /* Switch */
        .switch-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin-bottom: 16px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
        }
        
        .switch input { opacity: 0; width: 0; height: 0; }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(18px);
        }

        #qrcode-container {
            margin-top: 16px;
            display: none;
            justify-content: center;
        }
        #qrcode-container img, #qrcode-container canvas {
            padding: 8px;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        @media (max-width: 640px) {
            body { padding: 16px; }
            .card { padding: 24px; }
            .form-row { grid-template-columns: 1fr; }
            .btn-row { grid-template-columns: 1fr; }
            .btn-secondary { margin-top: 12px; order: 1; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>BurnAfterRead</h1>
        <p>创建具有访问限制和过期时间的加密消息</p>
    </div>

    <div class="card">
        <?php if ($message): ?>
            <div class="alert alert-success">
                <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">🎉 <?php echo htmlspecialchars($message); ?></div>
                <div style="font-size: 13px; opacity: 0.8;">请将下方链接分享给接收人</div>
                
                <?php if (isset($viewUrl)): ?>
                    <div class="result-box" id="result-url"><?php echo htmlspecialchars($viewUrl); ?></div>
                    
                    <div class="actions">
                        <button class="btn btn-sm" id="btn-copy-url" style="width: auto; height: 39px; margin: 0 !important; order: 0 !important; border: 1px solid transparent;">复制链接</button>
                        <button class="btn btn-sm btn-secondary" id="btn-show-qr" style="width: auto; height: 39px; margin: 0 !important; order: 0 !important;">二维码</button>
                    </div>

                    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px dashed #d1fae5; font-size: 13px; text-align: center;">
                        <span style="color: #64748b;">提取码: </span>
                        <strong id="result-code" style="color: var(--text-main); font-family: monospace; font-size: 15px; margin: 0 4px;"><?php echo htmlspecialchars($code); ?></strong>
                        <button class="btn-sm btn-secondary" id="btn-copy-code" style="border:none; background: transparent; color: var(--primary-color); cursor: pointer; text-decoration: underline; padding: 0;">复制</button>
                    </div>

                    <div id="qrcode-container"></div>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="index.php" class="btn btn-secondary" style="display: inline-block; width: auto;">创建新消息</a>
            </div>

            <script>
                function copyText(text, btn) {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(() => showCopied(btn));
                    } else {
                        const el = document.createElement('textarea');
                        el.value = text;
                        document.body.appendChild(el);
                        el.select();
                        document.execCommand('copy');
                        document.body.removeChild(el);
                        showCopied(btn);
                    }
                }
                function showCopied(btn) {
                    if (!btn.dataset.originalText) {
                        btn.dataset.originalText = btn.innerText;
                    }
                    btn.innerText = '已复制!';
                    
                    if (btn.dataset.timer) {
                        clearTimeout(btn.dataset.timer);
                    }
                    
                    btn.dataset.timer = setTimeout(() => {
                        btn.innerText = btn.dataset.originalText;
                        delete btn.dataset.timer; // Clean up
                    }, 2000);
                }

                document.getElementById('btn-copy-url').onclick = function() {
                    copyText(document.getElementById('result-url').innerText, this);
                };
                document.getElementById('btn-copy-code').onclick = function() {
                    copyText(document.getElementById('result-code').innerText, this);
                };
                document.getElementById('btn-show-qr').onclick = function() {
                    const qr = document.getElementById('qrcode-container');
                    if (qr.style.display === 'flex') {
                        qr.style.display = 'none';
                    } else {
                        qr.style.display = 'flex';
                        if (qr.innerHTML === '') {
                            new QRCode(qr, {
                                text: '<?php echo htmlspecialchars($viewUrl ?? '', ENT_QUOTES); ?>',
                                width: 160,
                                height: 160
                            });
                        }
                    }
                };
            </script>

        <?php else: ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    ⚠️ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="content">消息内容</label>
                    <textarea id="content" name="content" required placeholder="在此输入需要安全传输的文本信息..."><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="max_views">允许访问次数</label>
                        <input type="number" id="max_views" name="max_views" value="<?php echo isset($_POST['max_views']) ? htmlspecialchars($_POST['max_views']) : '1'; ?>" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="expire_hours">过期时间 (小时)</label>
                        <input type="number" id="expire_hours" name="expire_hours" value="<?php echo isset($_POST['expire_hours']) ? htmlspecialchars($_POST['expire_hours']) : '24'; ?>" min="1" max="72" required>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="button" class="btn" id="create-init-btn">创建加密消息</button>
                    <a href="view.php" class="btn btn-secondary">提取消息</a>
                </div>
            </form>

            <div class="alert alert-info">
                🔒 <strong>安全提示：</strong> 所有消息均经过端到端加密存储。一旦达到访问次数或过期时间，数据将被永久物理删除，无法恢复。
            </div>

        <?php endif; ?>
    </div>
    
    <div style="text-align: center; margin-top: 32px; color: #94a3b8; font-size: 12px;">
        &copy; <?php echo date('Y'); ?> BurnAfterRead · 安全 · 匿名 · 只有一次
    </div>
</div>

<!-- CAPTCHA MODAL -->
<div class="modal-overlay" id="captchaModal">
    <div class="modal">
        <div class="modal-header">安全验证</div>
        
        <div class="switch-row">
            <span style="font-size: 14px; font-weight: 500;">使用短提取码 (4位)</span>
            <label class="switch">
                <input type="checkbox" id="shortCodeSwitch">
                <span class="slider"></span>
            </label>
        </div>
        <div id="shortCodeWarning" style="display:none; color: #b45309; background: #fffbeb; padding: 10px; font-size: 12px; margin-bottom: 16px; border-radius: 6px;">
            ⚠️ 短提取码 (4位) 容易被暴力破解，仅建议用于非敏感内容分享。
        </div>

        <div class="captcha-row">
            <div class="captcha-img-container" id="captchaImgBox" title="点击刷新验证码">
                <img id="captchaImage" src="" alt="验证码">
            </div>
            <button type="button" class="btn btn-secondary" style="width: auto; padding: 0 16px;" onclick="loadCaptcha()">刷新</button>
        </div>
        
        <div class="form-group">
            <input type="text" id="captchaInput" placeholder="输入上图字符" autocomplete="off" maxlength="6" style="text-align: center; font-weight: bold; letter-spacing: 2px;">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 24px;">
            <button type="button" class="btn btn-secondary" id="modal-cancel" style="width: 100%; margin: 0 !important; order: 0 !important;">取消</button>
            <button type="button" class="btn" id="modal-confirm" style="width: 100%; margin: 0 !important; order: 0 !important; border: 1px solid transparent;">确认创建</button>
        </div>
    </div>
</div>

<script>
    function getCookie(name){
        var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
        return m ? decodeURIComponent(m[2]) : '';
    }

    function loadCaptcha(){
        document.getElementById('captchaImage').src = 'captcha.php?ts=' + Date.now();
    }

    const modal = document.getElementById('captchaModal');
    const createInitBtn = document.getElementById('create-init-btn');
    const cancelBtn = document.getElementById('modal-cancel');
    const confirmBtn = document.getElementById('modal-confirm');
    const switchEl = document.getElementById('shortCodeSwitch');

    if (createInitBtn) {
        createInitBtn.addEventListener('click', () => {
            const content = document.getElementById('content').value.trim();
            if (!content) {
                alert('请输入文本内容');
                return;
            }
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
            loadCaptcha();
            document.getElementById('captchaInput').value = '';
            document.getElementById('captchaInput').focus();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            modal.classList.remove('active');
            setTimeout(() => modal.style.display = 'none', 200);
        });
    }

    if (switchEl) {
        switchEl.addEventListener('change', function() {
            document.getElementById('shortCodeWarning').style.display = this.checked ? 'block' : 'none';
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', () => {
            const token = getCookie('captcha_t');
            const input = document.getElementById('captchaInput').value;
            const useShortCode = switchEl.checked ? '1' : '0';

            if (!token || !input) {
                alert('请输入验证码');
                return;
            }

            const form = document.querySelector('form[method="POST"]');
            const t = document.createElement('input'); t.type='hidden'; t.name='captcha_token'; t.value=token;
            const i = document.createElement('input'); i.type='hidden'; i.name='captcha_input'; i.value=input;
            const s = document.createElement('input'); s.type='hidden'; s.name='use_short_code'; s.value=useShortCode;

            form.appendChild(t);
            form.appendChild(i);
            form.appendChild(s);
            form.submit();
        });
    }

    // Modal close on click outside
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            cancelBtn.click();
        }
    });

    // Enter key support in modal
    document.getElementById('captchaInput')?.addEventListener('keyup', (e) => {
        if (e.key === 'Enter') confirmBtn.click();
    });
</script>

</body>
</html>
