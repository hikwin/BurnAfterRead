<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$len = rand(4,6);
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
$code = '';
for ($i=0; $i<$len; $i++) { $code .= $chars[random_int(0, strlen($chars)-1)]; }
$token = bin2hex(random_bytes(16));
$_SESSION['captcha_code'] = $code;
$_SESSION['captcha_expires'] = time() + 60;
$_SESSION['captcha_token'] = $token;
$_SESSION['captcha_attempts'] = 0;
setcookie('captcha_t', $token, [ 'expires' => time()+60, 'path' => '/', 'samesite' => 'Lax', 'httponly' => false ]);
if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
  header('Content-Type: image/png');
  $w = 240; $h = 120;
  $img = imagecreatetruecolor($w, $h);
  $bgc = imagecolorallocate($img, 243, 244, 246);
  imagefilledrectangle($img, 0, 0, $w, $h, $bgc);
  for ($y=0;$y<$h;$y+=2) {
    $col = imagecolorallocate($img, 230+rand(0,10), 232+rand(0,10), 234+rand(0,10));
    imageline($img, 0, $y, $w, $y, $col);
  }
  for ($i=0;$i<20;$i++) {
    $c = imagecolorallocate($img, rand(120,200), rand(120,200), rand(120,200));
    imageline($img, rand(0,$w), rand(0,$h), rand(0,$w), rand(0,$h), $c);
  }
  for ($i=0;$i<600;$i++) {
    $c = imagecolorallocate($img, rand(150,220), rand(150,220), rand(150,220));
    imagesetpixel($img, rand(0,$w-1), rand(0,$h-1), $c);
  }
  for ($i=0;$i<10;$i++) {
    $c = imagecolorallocatealpha($img, rand(100,180), rand(100,180), rand(100,180), rand(60,100));
    imagearc($img, rand(0,$w), rand(0,$h), rand(30,120), rand(20,100), rand(0,360), rand(0,360), $c);
  }
  for ($i=0;$i<6;$i++) {
    $c = imagecolorallocatealpha($img, rand(80,160), rand(80,160), rand(80,160), rand(70,110));
    imagerectangle($img, rand(0,$w-20), rand(0,$h-20), rand(20,$w), rand(20,$h), $c);
  }
  $x = 20; $y = 70;
  $palette = [
    [17,94,163],   // blue
    [22,163,74],   // green
    [180,83,9],    // orange
    [220,38,38],   // red
    [147,51,234],  // purple
    [8,145,178],   // teal
    [190,24,34],   // crimson
    [31,41,55],    // slate
    [0,0,0]        // black
  ];
  for ($i=0; $i<strlen($code); $i++) {
    $base = $palette[array_rand($palette)];
    $r = max(0, min(255, $base[0] + rand(-25, 25)));
    $g = max(0, min(255, $base[1] + rand(-25, 25)));
    $b = max(0, min(255, $base[2] + rand(-25, 25)));
    $c = imagecolorallocate($img, $r, $g, $b);
    $jitterY = rand(-4,4);
    imagestring($img, 5, $x, $y-14+$jitterY, $code[$i], $c);
    $x += 30 + rand(0,6);
  }
  imagepng($img);
  imagedestroy($img);
  exit;
}
header('Content-Type: image/svg+xml');
$w = 240; $h = 120;
$gradId = bin2hex(random_bytes(4));
$noise = '';
for ($i=0;$i<30;$i++) {
  $x1 = rand(0,$w); $y1 = rand(0,$h); $x2 = rand(0,$w); $y2 = rand(0,$h);
  $c = 'rgba('.rand(120,200).','.rand(120,200).','.rand(120,200).',0.6)';
  $noise .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$c.'" stroke-width="1" />';
}
$dots = '';
for ($i=0;$i<700;$i++) {
  $dx = rand(0,$w); $dy = rand(0,$h); $r = rand(1,2);
  $c = 'rgba('.rand(150,220).','.rand(150,220).','.rand(150,220).',0.6)';
  $dots .= '<circle cx="'.$dx.'" cy="'.$dy.'" r="'.$r.'" fill="'.$c.'" />';
}
$paths = '';
for ($i=0;$i<8;$i++) {
  $x1 = rand(0,$w); $y1 = rand(0,$h);
  $cx1 = rand(0,$w); $cy1 = rand(0,$h);
  $cx2 = rand(0,$w); $cy2 = rand(0,$h);
  $x2 = rand(0,$w); $y2 = rand(0,$h);
  $c = 'rgba('.rand(100,180).','.rand(100,180).','.rand(100,180).',0.5)';
  $paths .= '<path d="M '.$x1.' '.$y1.' C '.$cx1.' '.$cy1.', '.$cx2.' '.$cy2.', '.$x2.' '.$y2.'" stroke="'.$c.'" stroke-width="1" fill="none" />';
}
$letters = '';
$startX = 20;
  for ($i=0; $i<strlen($code); $i++) {
    $angle = rand(-20,20);
    $size = rand(28,34);
    $x = $startX + $i*34; $y = 70;
  $palette = [
    [17,94,163], [22,163,74], [180,83,9], [220,38,38], [147,51,234], [8,145,178], [190,24,34], [31,41,55], [0,0,0]
  ];
  $base = $palette[array_rand($palette)];
  $r = max(0, min(255, $base[0] + rand(-25, 25)));
  $g = max(0, min(255, $base[1] + rand(-25, 25)));
  $b = max(0, min(255, $base[2] + rand(-25, 25)));
  $color = sprintf('#%02x%02x%02x', $r, $g, $b);
  $letters .= '<text x="'.$x.'" y="'.$y.'" font-size="'.$size.'" font-family="Segoe UI, Arial Black, Arial, sans-serif" fill="'.$color.'" transform="rotate('.$angle.' '.$x.' '.$y.')">'.$code[$i].'</text>';
}
echo '<?xml version="1.0" encoding="UTF-8"?>' .
     '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">'
    .'<defs><linearGradient id="g'.$gradId.'" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#f3f4f6"/><stop offset="100%" stop-color="#e5e7eb"/></linearGradient></defs>'
    .'<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="url(#g'.$gradId.')" />'
    .$noise.$dots.$paths.$letters.
    '</svg>';
?>
