<?php

/**
 * Generates PLACEHOLDER PWA icons and favicons (navy square, gold ring, white "M").
 *
 * These are clearly temporary stand-ins until production raven icons exist
 * (see assets/branding/ASSET-MANIFEST.md). They are not cropped from the brand board.
 *
 * Usage: php frontend/tools/generate-placeholder-icons.php
 * Requires the GD extension with FreeType and a TrueType font (DejaVu Serif Bold by default).
 */

declare(strict_types=1);

$outputDirectory = dirname(__DIR__) . '/public/assets/icons';
$fontPath = $argv[1] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf';

if (!is_file($fontPath)) {
    fwrite(STDERR, "Font not found: $fontPath\n");
    exit(1);
}

/**
 * Draws one icon.
 *
 * @param int  $sizeInPixels Width and height.
 * @param bool $isMaskable   Maskable icons keep content inside the central 80% "safe zone".
 */
function drawPlaceholderIcon(int $sizeInPixels, bool $isMaskable, string $fontPath, string $targetFile): void
{
    $icon = imagecreatetruecolor($sizeInPixels, $sizeInPixels);
    imageantialias($icon, true);
    $navyColour = imagecolorallocate($icon, 0x0b, 0x1a, 0x2b);
    $goldColour = imagecolorallocate($icon, 0xd4, 0xaf, 0x7c);
    $offWhiteColour = imagecolorallocate($icon, 0xe6, 0xe9, 0xee);
    imagefill($icon, 0, 0, $navyColour);

    $contentScale = $isMaskable ? 0.62 : 0.8;
    $centre = intdiv($sizeInPixels, 2);
    $ringDiameter = (int) round($sizeInPixels * $contentScale);

    // Gold ring, a nod to the circular element of the Muninn logo (skipped at favicon sizes).
    if ($sizeInPixels >= 48) {
        imagesetthickness($icon, max(2, (int) round($sizeInPixels / 40)));
        imagearc($icon, $centre, $centre, $ringDiameter, $ringDiameter, 0, 360, $goldColour);
    }

    // White serif "M" centred inside the ring.
    $fontSize = $ringDiameter * 0.42;
    $textBox = imagettfbbox($fontSize, 0, $fontPath, 'M');
    $textWidth = $textBox[2] - $textBox[0];
    $textHeight = $textBox[1] - $textBox[7];
    $textX = (int) round($centre - $textWidth / 2 - $textBox[0]);
    $textY = (int) round($centre + $textHeight / 2 - $textBox[1]);
    imagettftext($icon, $fontSize, 0, $textX, $textY, $offWhiteColour, $fontPath, 'M');

    imagepng($icon, $targetFile, 9);
    imagedestroy($icon);
}

$iconsToGenerate = [
    ['icon-512.png', 512, false],
    ['icon-192.png', 192, false],
    ['icon-maskable-512.png', 512, true],
    ['apple-touch-icon.png', 180, false],
    ['favicon-32.png', 32, false],
    ['favicon-16.png', 16, false],
];

foreach ($iconsToGenerate as [$fileName, $sizeInPixels, $isMaskable]) {
    drawPlaceholderIcon($sizeInPixels, $isMaskable, $fontPath, $outputDirectory . '/' . $fileName);
    echo "Wrote $fileName\n";
}
