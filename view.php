<?php
require_once 'config.php';

$code = $_GET['code'] ?? '';
$error = '';
$content = '';
$expired = false;
$maxViewsReached = false;
// 动态提示信息所需变量
$maxViewsInfo = null;
$currentViewsInfo = null;
$expiresAtInfo = null;
$remainingViewsInfo = null;

if (!empty($code)) {
    $db = initDatabase();
    
    $stmt = $db->prepare("SELECT encrypted_content, max_views, current_views, expires_at FROM messages WHERE code = ?");
    $stmt->bindValue(1, $code, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        $error = '提取码无效或消息不存在';
    } else {
        // 检查是否过期
        $expiresAt = strtotime($row['expires_at']);
        if (time() > $expiresAt) {
            $expired = true;
            $error = '消息已过期';
            // 删除过期消息
            $stmt = $db->prepare("DELETE FROM messages WHERE code = ?");
            $stmt->bindValue(1, $code, SQLITE3_TEXT);
            $stmt->execute();
        } elseif ($row['current_views'] >= $row['max_views']) {
            $maxViewsReached = true;
            $error = '消息访问次数已达上限';
            // 删除消息
            $stmt = $db->prepare("DELETE FROM messages WHERE code = ?");
            $stmt->bindValue(1, $code, SQLITE3_TEXT);
            $stmt->execute();
        } else {
            // 解密内容
            try {
                $content = decrypt($row['encrypted_content']);
                
                // 增加访问次数
                $newViews = $row['current_views'] + 1;
                $stmt = $db->prepare("UPDATE messages SET current_views = ? WHERE code = ?");
                $stmt->bindValue(1, $newViews, SQLITE3_INTEGER);
                $stmt->bindValue(2, $code, SQLITE3_TEXT);
                $stmt->execute();
                
                // 如果达到最大访问次数，删除消息
                if ($newViews >= $row['max_views']) {
                    $stmt = $db->prepare("DELETE FROM messages WHERE code = ?");
                    $stmt->bindValue(1, $code, SQLITE3_TEXT);
                    $stmt->execute();
                }

                // 供前端展示的动态信息
                $maxViewsInfo = (int)$row['max_views'];
                $currentViewsInfo = (int)$newViews;
                $expiresAtInfo = $row['expires_at'];
                $remainingViewsInfo = max(0, $maxViewsInfo - $currentViewsInfo);
            } catch (Exception $e) {
                $error = '解密失败';
            }
        }
    }
    
    $db->close();
}

// 清理过期消息（后台任务）
function cleanupExpiredMessages() {
    $db = initDatabase();
    $db->exec("DELETE FROM messages WHERE expires_at < datetime('now') OR current_views >= max_views");
    $db->close();
}

// 随机执行清理（10%概率）
if (rand(1, 10) === 1) {
    cleanupExpiredMessages();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>查看消息 | BurnAfterRead</title>
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
            --radius-input: 8px;
            --radius-card: 16px;
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
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
            max-width: 600px;
            margin: auto;
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 700;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-lg);
            padding: 32px;
            border: 1px solid rgba(255,255,255,0.7);
        }

        .message-content {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-input);
            padding: 20px;
            margin-bottom: 24px;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 15px;
            line-height: 1.6;
            min-height: 120px;
            color: var(--text-main);
            font-family: inherit;
        }

        .alert {
            padding: 16px;
            border-radius: var(--radius-input);
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fee2e2;
        }

        .alert-warning {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fef3c7;
        }

        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-input);
            background: var(--input-bg);
            transition: all 0.2s;
            font-size: 14px;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            margin-right: 12px;
        }

        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
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
        }

        #qrcode {
            margin-top: 24px;
            display: none;
            justify-content: center;
        }
        #qrcode img, #qrcode canvas {
            padding: 8px;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        
        .action-bar {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        @media (max-width: 600px) {
           .card { padding: 24px; }
           .action-bar { flex-direction: column; }
           .btn { width: 100%; margin-right: 0; margin-bottom: 12px; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 查看消息</h1>
        </div>
        
        <div class="card">
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
                
                <div class="form-group">
                    <label for="codeInput">请输入提取码</label>
                    <input type="text" id="codeInput" placeholder="例如: wXyZ1234" value="<?php echo htmlspecialchars($code); ?>">
                </div>
                <div class="action-bar">
                    <button class="btn" onclick="openWithCode()">查看消息</button>
                    <a href="index.php" class="btn btn-secondary">返回首页</a>
                </div>

            <?php elseif ($content): ?>
                
                <?php if ($maxViewsReached || $expired): ?>
                    <div class="alert alert-warning">⚠️ 此消息由于达到限制已被销毁</div>
                <?php else: ?>
                    <?php if ($maxViewsInfo === 1): ?>
                        <div class="alert alert-warning">⚠️ 警告：这是本消息最后一次显示。关闭页面后将无法再次查看。</div>
                    <?php else: ?>
                        <div class="alert alert-warning" style="font-size: 13px;">
                            ⚠️ 剩余查看次数: <?php echo htmlspecialchars($remainingViewsInfo); ?> 次 · 过期时间: <?php echo htmlspecialchars($expiresAtInfo); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="message-content"><?php echo htmlspecialchars($content); ?></div>
                
                <div class="action-bar">
                    <button class="btn btn-secondary" onclick="generateQRCode()">生成二维码</button>
                    <button class="btn btn-secondary" onclick="copyContent()">复制内容</button>
                    <a href="index.php" class="btn">创建新消息</a>
                </div>
                
                <div id="qrcode"></div>
                
            <?php else: ?>
                <div class="form-group">
                    <label for="codeInput">输入提取码</label>
                    <input type="text" id="codeInput" placeholder="请输入8位或4位提取码">
                </div>
                <div class="action-bar">
                    <button class="btn" onclick="openWithCode()">打开消息</button>
                    <a href="index.php" class="btn btn-secondary">返回首页</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 32px; color: #94a3b8; font-size: 12px;">
            &copy; <?php echo date('Y'); ?> BurnAfterRead
        </div>
    </div>
    
    <script>
        function generateQRCode() {
            const currentUrl = window.location.href;
            const qrcodeDiv = document.getElementById('qrcode');
            
            if (qrcodeDiv.style.display === 'flex') {
                qrcodeDiv.style.display = 'none';
                qrcodeDiv.innerHTML = '';
            } else {
                qrcodeDiv.style.display = 'flex';
                new QRCode(qrcodeDiv, {
                    text: currentUrl,
                    width: 180,
                    height: 180,
                    colorDark: '#1e293b',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }
        
        function openWithCode() {
            const input = document.getElementById('codeInput');
            const code = (input && input.value || '').trim();
            if (code) {
                window.location.href = 'view.php?code=' + encodeURIComponent(code);
            }
        }

        function copyContent() {
            const content = document.querySelector('.message-content').innerText;
            navigator.clipboard.writeText(content).then(() => {
                alert('内容已复制到剪贴板');
            }).catch(err => {
                const ta = document.createElement('textarea');
                ta.value = content;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                alert('内容已复制到剪贴板');
            });
        }
        
        // Enter key support
        document.getElementById('codeInput')?.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') openWithCode();
        });
    </script>
</body>
</html>
