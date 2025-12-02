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
    <title>查看消息 - 阅读后即焚</title>
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
            margin-bottom: 24px;
            font-size: 28px;
            letter-spacing: -0.02em;
        }
        
        .message-content {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 16px;
            line-height: 1.7;
            min-height: 100px;
        }
        
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f9fafb;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
            background: #ffffff;
        }
        
        .btn {
            background: #0ea5e9;
            color: #ffffff;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
            margin-right: 10px;
            margin-top: 10px;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            background: #0284c7;
            box-shadow: 0 8px 16px rgba(2, 132, 199, 0.2);
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .btn-secondary:hover {
            box-shadow: 0 8px 16px rgba(107, 114, 128, 0.2);
        }
        
        #qrcode {
            margin-top: 20px;
            text-align: center;
            display: none;
        }
        
        #qrcode canvas, #qrcode img {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px;
            background: #ffffff;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>📖 查看消息</h1>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <div class="form-group">
                <label for="codeInput">输入提取码</label>
                <input type="text" id="codeInput" placeholder="例如: abcde">
            </div>
            <button class="btn" onclick="openWithCode()">打开消息</button>
            <a href="index.php" class="btn btn-secondary">返回首页</a>
        <?php elseif ($content): ?>
            <?php if ($maxViewsReached || $expired): ?>
                <div class="warning">⚠️ 此消息已被销毁</div>
            <?php else: ?>
                <?php if ($maxViewsInfo === 1): ?>
                    <div class="warning">⚠️ 此消息为一次性查看，关闭页面后将无法再次访问</div>
                <?php else: ?>
                    <div class="warning">⚠️ 此消息可再访问 <?php echo htmlspecialchars($remainingViewsInfo); ?> 次，过期时间：<?php echo htmlspecialchars($expiresAtInfo); ?></div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="message-content"><?php echo htmlspecialchars($content); ?></div>
            
            <button class="btn" onclick="generateQRCode()">生成二维码</button>
            <a href="index.php" class="btn btn-secondary">返回首页</a>
            
            <div id="qrcode"></div>
        <?php else: ?>
            <div class="form-group">
                <label for="codeInput">输入提取码</label>
                <input type="text" id="codeInput" placeholder="例如: abcde">
            </div>
            <button class="btn" onclick="openWithCode()">打开消息</button>
            <a href="index.php" class="btn btn-secondary">返回首页</a>
        <?php endif; ?>
    </div>
    
    <script>
        function generateQRCode() {
            const currentUrl = window.location.href;
            const qrcodeDiv = document.getElementById('qrcode');
            
            if (qrcodeDiv.style.display === 'none' || qrcodeDiv.style.display === '') {
                qrcodeDiv.innerHTML = '<p style="margin-bottom: 10px; color: #6b7280;">扫描二维码访问此消息</p>';
                qrcodeDiv.style.display = 'block';
                
                const holder = document.createElement('div');
                qrcodeDiv.appendChild(holder);
                
                if (typeof QRCode !== 'undefined') {
                    new QRCode(holder, {
                        text: currentUrl,
                        width: 256,
                        height: 256,
                        colorDark: '#111827',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    qrcodeDiv.innerHTML = '<p style="color: #ef4444;">二维码库未加载</p>';
                }
            } else {
                qrcodeDiv.style.display = 'none';
                qrcodeDiv.innerHTML = '';
            }
        }
        function openWithCode() {
            const input = document.getElementById('codeInput');
            const code = (input && input.value || '').trim();
            if (code) {
                window.location.href = 'view.php?code=' + encodeURIComponent(code);
            }
        }
    </script>
</body>
</html>

