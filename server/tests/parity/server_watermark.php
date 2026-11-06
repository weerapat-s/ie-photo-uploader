<?php
// php server_watermark.php in.jpg out.jpg logo.png position opacity colour
[$_, $in, $out, $logoPath, $position, $opacity, $colour] = $argv;
$image = new Imagick($in); $image->autoOrient(); $image->setIteratorIndex(0); $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
$logo = new Imagick($logoPath); $logo->setIteratorIndex(0); $logo->trimImage(0);
$tw = max(1, (int)round($image->getImageWidth() * 0.07)); $th = max(1, (int)round($logo->getImageHeight() * ($tw / $logo->getImageWidth())));
$logo->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1);
$paint = new Imagick(); $paint->newImage($tw, $th, new ImagickPixel($colour === 'white' ? 'white' : 'black')); $paint->setImageFormat('png');
$paint->setImageColorspace(Imagick::COLORSPACE_SRGB); $paint->compositeImage($logo, Imagick::COMPOSITE_COPYOPACITY, 0, 0); $logo = $paint;
$f = max(0.10, min(1.0, ((int)$opacity) / 100)); if ($f < 1.0) { $logo->evaluateImage(Imagick::EVALUATE_MULTIPLY, $f, Imagick::CHANNEL_ALPHA); }
$w = $image->getImageWidth(); $h = $image->getImageHeight(); $m = (int)round(min($w, $h) * 0.02);
$x = str_ends_with($position, '-left') ? $m : (str_ends_with($position, '-right') ? max(0, $w - $tw - $m) : max(0, (int)round(($w - $tw) / 2)));
$y = str_starts_with($position, 'top-') ? $m : (str_starts_with($position, 'bottom-') ? max(0, $h - $th - $m) : max(0, (int)round(($h - $th) / 2)));
$image->compositeImage($logo, Imagick::COMPOSITE_OVER, $x, $y);
$image->setImageFormat('png'); $image->writeImage($out);   // lossless so only the algorithm is compared
echo "$x $y $tw $th\n";
