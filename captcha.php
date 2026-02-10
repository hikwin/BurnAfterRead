<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Random length 4-5 to allow larger characters
$len = rand(4,5);
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
$code = '';
for ($i=0; $i<$len; $i++) { $code .= $chars[random_int(0, strlen($chars)-1)]; }

$token = bin2hex(random_bytes(16));
$_SESSION['captcha_code'] = $code;
$_SESSION['captcha_expires'] = time() + 60;
$_SESSION['captcha_token'] = $token;
$_SESSION['captcha_attempts'] = 0;
setcookie('captcha_t', $token, [ 'expires' => time()+60, 'path' => '/', 'samesite' => 'Lax', 'httponly' => false ]);

// Always output SVG for better scaling and font size control
header('Content-Type: image/svg+xml');

$w = 260; 
$h = 100;
$gradId = bin2hex(random_bytes(4));

// 1. Background Noise (Lines)
$noise = '';
for ($i=0;$i<35;$i++) {
  $x1 = rand(0,$w); $y1 = rand(0,$h); $x2 = rand(0,$w); $y2 = rand(0,$h);
  $c = 'rgba('.rand(140,200).','.rand(140,200).','.rand(140,200).',0.7)';
  $noise .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$c.'" stroke-width="'.rand(1,2).'" />';
}

// 2. Dots
$dots = '';
for ($i=0;$i<800;$i++) {
  $dx = rand(0,$w); $dy = rand(0,$h); $r = rand(1,2);
  $c = 'rgba('.rand(160,220).','.rand(160,220).','.rand(160,220).',0.7)';
  $dots .= '<circle cx="'.$dx.'" cy="'.$dy.'" r="'.$r.'" fill="'.$c.'" />';
}

// 3. Curves/Paths
$paths = '';
for ($i=0;$i<8;$i++) {
  $x1 = rand(0,$w); $y1 = rand(0,$h);
  $cx1 = rand(0,$w); $cy1 = rand(0,$h);
  $cx2 = rand(0,$w); $cy2 = rand(0,$h);
  $x2 = rand(0,$w); $y2 = rand(0,$h);
  $c = 'rgba('.rand(120,180).','.rand(120,180).','.rand(120,180).',0.8)';
  $paths .= '<path d="M '.$x1.' '.$y1.' C '.$cx1.' '.$cy1.', '.$cx2.' '.$cy2.', '.$x2.' '.$y2.'" stroke="'.$c.'" stroke-width="'.rand(1,2).'" fill="none" />';
}

// 4. Geometric Shapes (Interference)
$shapes = '';
for ($i=0;$i<10;$i++) {
    $cx = rand(0,$w); $cy = rand(0,$h);
    $r = rand(10, 30);
    $points = [];
    $sides = rand(3, 5); // triangles or heavier polygons
    for ($j=0; $j<$sides; $j++) {
        $ang = 2 * M_PI * $j / $sides;
        $px = $cx + $r * cos($ang);
        $py = $cy + $r * sin($ang);
        $points[] = "$px,$py";
    }
    // Semi-transparent fills
    $c = 'rgba('.rand(180,220).','.rand(180,220).','.rand(180,220).',0.5)';
    $shapes .= '<polygon points="'.implode(' ', $points).'" fill="'.$c.'" />';
}

// 5. Invisible Traps (Option 5: Anti-Scraper)
$traps = '';
$trapChars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
for ($i=0; $i<20; $i++) {
    $tc = $trapChars[rand(0, strlen($trapChars)-1)];
    $bx = rand(0, $w);
    $by = rand(0, $h);
    // Invisible text that machines might read but humans won't see
    $traps .= '<text x="'.$bx.'" y="'.$by.'" opacity="0" font-size="'.rand(20,40).'">'.$tc.'</text>';
}

// 6. Letters (Option 2: Overlapping)
$letters = '';

// Reduced spacing to 32px (was 45px) to force overlapping
$charWidth = 32; 
$totalWidth = $len * $charWidth;
$startX = ($w - $totalWidth) / 2 + 20;

$palette = [
    [100,149,237], [110,190,110], [220,160,110], [220,110,110], 
    [180,140,230], [100,200,220], [210,110,120], [140,150,160], [130,130,130]
];

for ($i=0; $i<strlen($code); $i++) {
    $angle = rand(-20,20); // Increased rotation slightly
    $size = rand(42,56);   // Vary size
    $x = $startX + ($i * $charWidth);
    $y = rand(65, 75);     // Randomize baseline slightly
    
    $base = $palette[array_rand($palette)];
    $r = max(0, min(255, $base[0] + rand(-20, 20)));
    $g = max(0, min(255, $base[1] + rand(-20, 20)));
    $b = max(0, min(255, $base[2] + rand(-20, 20)));
    $color = sprintf('#%02x%02x%02x', $r, $g, $b);
    
    // style text-shadow helps humans read overlapping text better than machines
    $letters .= '<text x="'.$x.'" y="'.$y.'" font-size="'.$size.'" font-family="Segoe UI, Arial Black, Arial, sans-serif" font-weight="bold" fill="'.$color.'" transform="rotate('.$angle.' '.$x.' '.$y.')" style="text-shadow: 1px 1px 0px rgba(255,255,255,0.4);">'.$code[$i].'</text>';
}

// Option 1: Distortion Filter Definition
$filterId = 'dist'.bin2hex(random_bytes(2));
$distortionFilter = '<filter id="'.$filterId.'" x="-20%" y="-20%" width="140%" height="140%">'
                  . '<feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="2" result="noise"/>'
                  . '<feDisplacementMap in="SourceGraphic" in2="noise" scale="5" />'
                  . '</filter>';

// Wrap letters in distorted group
$lettersLayer = '<g filter="url(#'.$filterId.')">'.$letters.'</g>';

// 7. Interference Text (Overlay)
$interferenceText = '';
$fakeChars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
for ($i=0; $i<15; $i++) {
    $char = $fakeChars[rand(0, strlen($fakeChars)-1)];
    $fx = rand(0, $w);
    $fy = rand(0, $h);
    $fsize = rand(12, 22);
    $fangle = rand(-45, 45);
    $fc = 'rgba('.rand(100,200).','.rand(100,200).','.rand(100,200).',0.4)';
    $interferenceText .= '<text x="'.$fx.'" y="'.$fy.'" font-size="'.$fsize.'" fill="'.$fc.'" transform="rotate('.$fangle.' '.$fx.' '.$fy.')" font-family="Arial, sans-serif" pointer-events="none">'.$char.'</text>';
}

echo '<?xml version="1.0" encoding="UTF-8"?>' .
     '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" style="background-color: #f3f4f6; color-scheme: light;">'
    .'<defs>'
        .'<linearGradient id="g'.$gradId.'" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#f3f4f6"/><stop offset="100%" stop-color="#e5e7eb"/></linearGradient>'
        .$distortionFilter
    .'</defs>'
    .'<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="#f3f4f6" rx="8" ry="8" />'
    .$noise.$dots.$paths.$shapes
    .$traps         // Option 5: Invisible traps inserted before real text
    .$lettersLayer  // Option 1 & 2: Distorted and overlapped real text
    .$interferenceText
    .'</svg>';
?>
