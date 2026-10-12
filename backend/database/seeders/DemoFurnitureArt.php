<?php

namespace Database\Seeders;

use GdImage;

/**
 * Draws simple, friendly furniture pictures for demo listings, so a local try looks like a shop
 * and not a wall of grey boxes. Real listings always use the seller's own photos.
 */
class DemoFurnitureArt
{
    private const W = 1600;

    private const H = 1200;

    private const FLOOR = 900;

    /** Wall colors, cycled per listing. */
    private const WALLS = [[238, 231, 221], [226, 233, 228], [236, 226, 222], [229, 230, 236], [240, 234, 214], [225, 232, 236]];

    private const COLORS = [
        'grey' => [128, 130, 134], 'emerald' => [38, 110, 84], 'dark brown' => [84, 58, 40], 'tan' => [176, 128, 82],
        'walnut' => [110, 76, 52], 'white' => [242, 240, 236], 'natural' => [204, 168, 122], 'honey' => [196, 140, 70],
        'espresso' => [72, 50, 38], 'light oak' => [212, 180, 136], 'beige' => [214, 196, 168], 'mahogany' => [116, 52, 40],
        'black' => [48, 48, 52], 'cream' => [236, 226, 204], 'brown' => [124, 86, 56], 'blue' => [70, 110, 160],
    ];

    private GdImage $img;

    /** @var array{int,int,int} */
    private array $main;

    public function jpeg(string $name, ?string $color, int $index): string
    {
        $this->img = imagecreatetruecolor(self::W, self::H);
        $this->main = self::COLORS[mb_strtolower((string) $color)] ?? [150, 112, 80];
        $this->room(self::WALLS[$index % count(self::WALLS)]);

        $n = mb_strtolower($name);
        match (true) {
            str_contains($n, 'patio') => $this->patio(),
            str_contains($n, 'bunk') => $this->bunkBed(),
            str_contains($n, 'mattress') => $this->mattress(),
            str_contains($n, 'bed') => $this->bed(),
            str_contains($n, 'sofa'), str_contains($n, 'sectional') => $this->sofa(1),
            str_contains($n, 'armchair') => $this->sofa(0),
            str_contains($n, 'wardrobe') => $this->wardrobe(),
            str_contains($n, 'dresser'), str_contains($n, 'filing') => $this->dresser(str_contains($n, 'filing')),
            str_contains($n, 'bookshelf') => $this->shelf(false),
            str_contains($n, 'cabinet') => $this->shelf(true),
            str_contains($n, 'desk') => $this->desk(str_contains($n, 'kids')),
            str_contains($n, 'office chair') => $this->officeChair(800),
            str_contains($n, 'dining set') => $this->diningSet(),
            str_contains($n, 'chair') => $this->chair(800, 1),
            str_contains($n, 'bench') => $this->bench(),
            default => $this->table(str_contains($n, 'coffee')),
        };

        // Draw large and scale down: GD has no anti-aliasing for filled shapes.
        $out = imagecreatetruecolor(800, 600);
        imagecopyresampled($out, $this->img, 0, 0, 0, 0, 800, 600, self::W, self::H);
        ob_start();
        imagejpeg($out, null, 86);
        imagedestroy($out);
        imagedestroy($this->img);

        return (string) ob_get_clean();
    }

    private function room(array $wall): void
    {
        for ($y = 0; $y < self::FLOOR; $y++) {
            $k = $y / self::FLOOR * 10;
            imageline($this->img, 0, $y, self::W, $y, $this->rgb($this->shift($wall, (int) (6 - $k))));
        }
        $floor = $this->shift($wall, -26);
        for ($y = self::FLOOR; $y < self::H; $y++) {
            imageline($this->img, 0, $y, self::W, $y, $this->rgb($this->shift($floor, (int) (-($y - self::FLOOR) / 20))));
        }
        // Skirting board where wall meets floor; the corners stay empty for card badges and buttons.
        imagefilledrectangle($this->img, 0, self::FLOOR - 6, self::W, self::FLOOR, $this->rgb($this->shift($wall, -40)));
    }

    private function shadow(int $cx, int $w): void
    {
        imagefilledellipse($this->img, $cx, self::FLOOR + 20, $w, 46, $this->rgba([0, 0, 0], 95));
    }

    private function sofa(int $seats): void
    {
        $c = $this->main;
        $x1 = $seats ? 260 : 520;
        $x2 = $seats ? 1340 : 1080;
        $this->shadow(800, $x2 - $x1 + 40);
        $this->legs($x1 + 60, $x2 - 60, 860, 900, 14, $this->shift($c, -60));
        $this->round($x1 + 60, 470, $x2 - 60, 720, 40, $this->shift($c, -14));
        $this->round($x1 + 40, 640, $x2 - 40, 865, 30, $c);
        $cushions = $seats ? 3 : 1;
        $span = ($x2 - $x1 - 260) / $cushions;
        for ($i = 0; $i < $cushions; $i++) {
            $a = (int) ($x1 + 130 + $i * $span);
            $this->round($a + 6, 610, (int) ($a + $span - 6), 700, 22, $this->shift($c, 14));
        }
        $this->round($x1, 560, $x1 + 130, 865, 40, $this->shift($c, -6));
        $this->round($x2 - 130, 560, $x2, 865, 40, $this->shift($c, -6));
        $this->round(600, 520, 720, 610, 24, [232, 214, 170]);
    }

    private function bed(): void
    {
        $c = $this->main;
        $this->shadow(800, 1120);
        $this->round(300, 360, 1300, 880, 30, $c);
        $this->round(340, 400, 1260, 640, 22, $this->shift($c, 18));
        $this->legs(320, 1280, 870, 900, 18, $this->shift($c, -40));
        $this->round(250, 620, 1350, 830, 26, [246, 244, 238]);
        $this->round(250, 700, 1350, 840, 26, [118, 140, 150]);
        $this->round(380, 560, 720, 680, 40, [250, 249, 246]);
        $this->round(880, 560, 1220, 680, 40, [250, 249, 246]);
    }

    private function bunkBed(): void
    {
        $c = $this->main;
        $this->shadow(800, 980);
        foreach ([300, 1260] as $x) {
            $this->rect($x, 180, $x + 40, 900, $this->shift($c, -20));
        }
        foreach ([[380, 520], [760, 880]] as [$top, $bottom]) {
            $this->rect(340, $bottom - 70, 1260, $bottom - 30, $c);
            $this->round(350, $top + 90, 1250, $bottom - 70, 18, [244, 242, 236]);
            $this->round(350, $top + 140, 1250, $bottom - 70, 18, [86, 120, 170]);
            $this->round(380, $top + 60, 640, $top + 150, 30, [250, 249, 246]);
        }
        $this->rect(340, 300, 1260, 340, $c);
        for ($y = 420; $y < 860; $y += 80) {
            $this->rect(1130, $y, 1260, $y + 16, $this->shift($c, -20));
        }
    }

    private function mattress(): void
    {
        $this->shadow(800, 1080);
        $this->round(280, 640, 1320, 880, 40, [236, 236, 232]);
        $this->round(280, 640, 1320, 720, 40, [248, 248, 246]);
        for ($x = 380; $x < 1320; $x += 120) {
            for ($y = 680; $y < 860; $y += 70) {
                imagefilledellipse($this->img, $x, $y, 12, 12, $this->rgb([200, 200, 196]));
            }
        }
        $this->rect(280, 760, 1320, 770, [210, 210, 206]);
    }

    private function wardrobe(): void
    {
        $c = $this->main;
        $this->shadow(800, 760);
        $this->round(440, 180, 1160, 890, 12, $c);
        $this->rect(440, 860, 1160, 890, $this->shift($c, -40));
        $doors = 4;
        $w = (1160 - 440 - 40) / $doors;
        for ($i = 0; $i < $doors; $i++) {
            $a = (int) (460 + $i * $w);
            $this->round($a + 4, 200, (int) ($a + $w - 4), 845, 8, $this->shift($c, $i === 1 ? 26 : 8));
            if ($i === 1) {
                $this->round($a + 30, 240, (int) ($a + $w - 30), 800, 8, [206, 222, 228]);
            }
            $hx = $i % 2 ? $a + 20 : (int) ($a + $w - 24);
            $this->rect($hx, 470, $hx + 6, 570, $this->shift($c, -70));
        }
    }

    private function dresser(bool $filing): void
    {
        $c = $this->main;
        [$x1, $x2, $top, $rows, $cols] = $filing ? [640, 960, 260, 4, 1] : [380, 1220, 470, 3, 2];
        $this->shadow(800, $x2 - $x1 + 60);
        $this->legs($x1 + 30, $x2 - 30, 860, 900, 16, $this->shift($c, -50));
        $this->round($x1, $top, $x2, 870, 14, $c);
        $this->rect($x1 - 14, $top - 10, $x2 + 14, $top + 18, $this->shift($c, -20));
        $h = (870 - $top - 50) / $rows;
        $w = ($x2 - $x1 - 40) / $cols;
        for ($r = 0; $r < $rows; $r++) {
            for ($k = 0; $k < $cols; $k++) {
                $a = (int) ($x1 + 20 + $k * $w);
                $b = (int) ($top + 36 + $r * $h);
                $this->round($a + 6, $b, (int) ($a + $w - 6), (int) ($b + $h - 12), 8, $this->shift($c, 14));
                $this->rect((int) ($a + $w / 2 - 40), (int) ($b + $h / 2 - 12), (int) ($a + $w / 2 + 40), (int) ($b + $h / 2 - 2), $this->shift($c, -70));
            }
        }
        if (! $filing) {
            imagefilledellipse($this->img, 520, 400, 90, 120, $this->rgb([90, 140, 110]));
            $this->rect(500, 420, 540, 470, [214, 196, 168]);
            $this->round(980, 360, 1100, 470, 10, [244, 240, 232]);
        }
    }

    private function shelf(bool $glass): void
    {
        $c = $this->main;
        [$x1, $x2] = $glass ? [520, 1080] : [560, 1040];
        $this->shadow(800, $x2 - $x1 + 60);
        $this->round($x1, 200, $x2, 890, 10, $c);
        $inside = $glass ? [214, 228, 232] : $this->shift($c, -30);
        $this->rect($x1 + 26, 226, $x2 - 26, 860, $inside);
        $books = [[176, 70, 52], [56, 96, 132], [214, 170, 80], [78, 120, 92], [150, 120, 160], [230, 222, 206]];
        for ($s = 0; $s < 5; $s++) {
            $y = 226 + $s * 127;
            $this->rect($x1 + 26, $y + 112, $x2 - 26, $y + 127, $c);
            $x = $x1 + 50;
            $i = $s;
            while ($x < $x2 - 90) {
                $bw = 26 + ($i * 7) % 22;
                $bh = $glass ? 50 + ($i * 13) % 30 : 70 + ($i * 11) % 34;
                if ($glass) {
                    imagefilledellipse($this->img, $x + 20, $y + 112 - (int) ($bh / 2), 44, $bh, $this->rgb($this->shift($books[$i % 6], 30)));
                    $x += 90;
                } else {
                    $this->rect($x, $y + 112 - $bh, $x + $bw, $y + 112, $books[$i % 6]);
                    $x += $bw + 4 + ($i % 4 === 0 ? 40 : 0);
                }
                $i++;
            }
        }
        if ($glass) {
            imageline($this->img, (int) (($x1 + $x2) / 2), 226, (int) (($x1 + $x2) / 2), 860, $this->rgb($c));
        }
    }

    private function table(bool $coffee): void
    {
        $c = $this->main;
        $top = $coffee ? 720 : 560;
        $this->shadow(800, 900);
        $this->legs(420, 1180, $top + 30, 900, 26, $this->shift($c, -24));
        $this->round(320, $top, 1280, $top + 44, 18, $c);
        $this->rect(320, $top + 34, 1280, $top + 44, $this->shift($c, -30));
        if ($coffee) {
            $this->round(540, 660, 660, 720, 12, [214, 196, 168]);
            imagefilledellipse($this->img, 980, 690, 90, 60, $this->rgb([238, 236, 230]));
        } else {
            imagefilledellipse($this->img, 800, 530, 120, 70, $this->rgb([238, 236, 230]));
            imagefilledellipse($this->img, 800, 460, 70, 110, $this->rgb([96, 146, 112]));
        }
    }

    private function diningSet(): void
    {
        $this->chair(360, -1);
        $this->chair(1240, 1);
        $this->table(false);
    }

    private function chair(int $cx, int $facing): void
    {
        $c = $this->main;
        $this->shadow($cx, 260);
        $back = $facing >= 0 ? $cx + 70 : $cx - 110;
        $this->round($back, 380, $back + 40, 880, 12, $this->shift($c, -10));
        $this->round($cx - 110, 620, $cx + 110, 680, 16, $c);
        $this->legs($cx - 100, $cx + 100, 670, 900, 16, $this->shift($c, -40));
        $this->round($facing >= 0 ? $cx + 40 : $cx - 120, 400, $facing >= 0 ? $cx + 120 : $cx - 40, 640, 18, $this->shift($c, 18));
    }

    private function officeChair(int $cx): void
    {
        $c = $this->main;
        $this->shadow($cx, 420);
        $this->round($cx - 150, 300, $cx + 150, 600, 60, $c);
        $this->round($cx - 170, 600, $cx + 170, 680, 30, $this->shift($c, 16));
        $this->rect($cx - 14, 680, $cx + 14, 830, [150, 150, 156]);
        foreach ([-200, -90, 90, 200] as $dx) {
            imageline($this->img, $cx, 830, $cx + $dx, 870, $this->rgb([120, 120, 126]));
            $this->rect(min($cx, $cx + $dx), 826, max($cx, $cx + $dx), 840, [120, 120, 126]);
            imagefilledellipse($this->img, $cx + $dx, 880, 34, 34, $this->rgb([60, 60, 64]));
        }
        $this->round($cx - 210, 540, $cx - 170, 620, 10, [80, 80, 86]);
        $this->round($cx + 170, 540, $cx + 210, 620, 10, [80, 80, 86]);
    }

    private function desk(bool $kids): void
    {
        $c = $this->main;
        [$x1, $x2, $top] = $kids ? [420, 1060, 640] : [260, 1340, 560];
        $this->shadow(($x1 + $x2) / 2, $x2 - $x1 + 40);
        $this->round($x1, $top, $x2, $top + 40, 10, $c);
        $this->rect($x1 + 20, $top + 40, $x1 + 60, 900, $this->shift($c, -24));
        $this->round($x2 - 320, $top + 40, $x2 - 20, 890, 8, $this->shift($c, -10));
        for ($i = 0; $i < 3; $i++) {
            $y = $top + 70 + $i * (int) ((850 - $top) / 3);
            $this->round($x2 - 300, $y, $x2 - 40, $y + (int) ((850 - $top) / 3) - 20, 6, $this->shift($c, 12));
            $this->rect($x2 - 200, $y + 30, $x2 - 140, $y + 40, $this->shift($c, -60));
        }
        $this->round($x1 + 160, $top - 220, $x1 + 460, $top - 30, 10, [52, 56, 62]);
        $this->rect($x1 + 290, $top - 30, $x1 + 330, $top, [52, 56, 62]);
        if ($kids) {
            $this->officeChair(1240);
        } else {
            imagefilledellipse($this->img, $x2 - 420, $top - 50, 70, 100, $this->rgb([96, 146, 112]));
        }
    }

    private function bench(): void
    {
        $c = $this->main;
        $this->shadow(800, 1080);
        $this->round(260, 640, 1340, 700, 14, $c);
        foreach ([300, 1220] as $x) {
            $this->round($x, 690, $x + 80, 900, 10, $this->shift($c, -24));
        }
        for ($x = 330; $x < 1300; $x += 60) {
            imagefilledellipse($this->img, $x, 670, 22, 22, $this->rgb($this->shift($c, -40)));
        }
        $this->round(280, 420, 1320, 560, 14, $this->shift($c, 8));
        for ($x = 340; $x < 1280; $x += 120) {
            $this->rect($x, 560, $x + 24, 640, $this->shift($c, -10));
        }
    }

    private function patio(): void
    {
        $this->chair(380, -1);
        $this->chair(1220, 1);
        $c = $this->main;
        $this->shadow(800, 520);
        imagefilledellipse($this->img, 800, 610, 520, 90, $this->rgb($c));
        $this->rect(782, 620, 818, 880, $this->shift($c, -30));
        imagefilledellipse($this->img, 800, 885, 200, 40, $this->rgb($this->shift($c, -30)));
        $this->rect(796, 220, 804, 600, [120, 120, 126]);
        imagefilledarc($this->img, 800, 260, 760, 220, 180, 360, $this->rgb([226, 120, 80]), IMG_ARC_PIE);
    }

    private function legs(int $x1, int $x2, int $top, int $bottom, int $w, array $color): void
    {
        $this->rect($x1, $top, $x1 + $w, $bottom, $color);
        $this->rect($x2 - $w, $top, $x2, $bottom, $color);
    }

    private function rect(int $x1, int $y1, int $x2, int $y2, array $color): void
    {
        imagefilledrectangle($this->img, $x1, $y1, $x2, $y2, $this->rgb($color));
    }

    private function round(int $x1, int $y1, int $x2, int $y2, int $r, array $color): void
    {
        $r = max(1, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
        $col = $this->rgb($color);
        imagefilledrectangle($this->img, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        imagefilledrectangle($this->img, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($this->img, $cx, $cy, $r * 2, $r * 2, $col);
        }
    }

    private function shift(array $color, int $by): array
    {
        return array_map(fn ($v) => max(0, min(255, $v + $by)), $color);
    }

    private function rgb(array $c): int
    {
        return imagecolorallocate($this->img, $c[0], $c[1], $c[2]);
    }

    private function rgba(array $c, int $alpha): int
    {
        return imagecolorallocatealpha($this->img, $c[0], $c[1], $c[2], $alpha);
    }
}
