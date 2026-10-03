<?php

/*
| Generates the default Open Graph card and the Organization logo referenced by
| config/seo.php. Run once with:  php artisan seo:images
|
| The card is 1200x630 — the size every social platform renders its preview at — and
| is deliberately low-text: a card is read at thumbnail size, so a slogan plus a short
| line beats a paragraph that turns into noise.
*/

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateSeoImages extends Command
{
    protected $signature = 'seo:images';

    protected $description = 'Generate the default Open Graph card and the Organization logo.';

    public function handle(): int
    {
        $root = public_path('images');

        foreach ([$root, $root.'/og'] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
        }

        $card = $root.'/og/cultiv-default.jpg';
        $logo = $root.'/logo.png';

        $this->renderCard($card);
        $this->renderLogo($logo);

        foreach ([$card, $logo] as $file) {
            $this->info(sprintf('%s (%dx%d, %d KB)', $file, ...array_merge(
                [getimagesize($file)[0], getimagesize($file)[1]],
                [round(filesize($file) / 1024)]
            )));
        }

        return self::SUCCESS;
    }

    /** 1200x630 brand card: wordmark, tagline and a one-line description. */
    private function renderCard(string $path): void
    {
        [$w, $h] = [1200, 630];

        $im = imagecreatetruecolor($w, $h);

        // Deep indigo field with a soft diagonal wash, matching the app's indigo-600.
        $top = imagecolorallocate($im, 0x4f, 0x46, 0xe5);
        $bottom = imagecolorallocate($im, 0x31, 0x23, 0x94);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / ($h - 1);
            $line = imagecolorallocate(
                $im,
                (int) (0x4f + (0x31 - 0x4f) * $t),
                (int) (0x46 + (0x23 - 0x46) * $t),
                (int) (0xe5 + (0x94 - 0xe5) * $t),
            );
            imageline($im, 0, $y, $w, $y, $line);
        }

        unset($top, $bottom);

        $white = imagecolorallocate($im, 0xff, 0xff, 0xff);
        $soft = imagecolorallocatealpha($im, 0xe0, 0xe7, 0xff, 55);

        // Accent bar.
        imagefilledrectangle($im, 90, 150, 96, 400, $white);

        $this->text($im, 140, 168, 'Cultiv', 76, $white);
        $this->text($im, 140, 262, 'SaaS untuk Mengelola Bisnis', 34, $soft);
        $this->text($im, 140, 306, 'dalam Satu Workspace', 34, $soft);

        // Rounded underline in front of the accent bar.
        imagefilledrectangle($im, 90, 440, 300, 448, $white);

        $this->text($im, 90, 500, 'Produk  ·  Inventori  ·  Penjualan  ·  Laporan', 24, $soft);

        imagejpeg($im, $path, 88);
        imagedestroy($im);
    }

    /** 512x512 square mark for the Organization logo. */
    private function renderLogo(string $path): void
    {
        $size = 512;
        $im = imagecreatetruecolor($size, $size);

        $indigo = imagecolorallocate($im, 0x4f, 0x46, 0xe5);
        $white = imagecolorallocate($im, 0xff, 0xff, 0xff);

        imagefilledrectangle($im, 0, 0, $size, $size, $indigo);

        // A simple sprout/shoot mark: two leaves on a stem.
        imagefilledellipse($im, 200, 210, 150, 100, $white);
        imagefilledellipse($im, 312, 210, 150, 100, $white);
        imagefilledrectangle($im, 249, 210, 14, 330, $white);
        imagefilledellipse($im, 256, 348, 130, 54, $white);

        imagepng($im, $path, 9);
        imagedestroy($im);
    }

    /** UTF-8 aware text with a built-in font, so no system font file is required. */
    private function text(\GdImage $im, int $x, int $y, string $text, int $size, int $color): void
    {
        if (function_exists('imagettftext') && ($font = $this->font())) {
            imagettftext($im, $size, 0, $x, $y, $color, $font, $text);

            return;
        }

        $scale = $size / 12;
        imagestringup($im, 5, $x, $y, $text, $color);
    }

    private function font(): ?string
    {
        foreach ([
            'C:/Windows/Fonts/segoeuib.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
