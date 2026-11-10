<?php
declare(strict_types=1);

final class OrangeBar {
    public const FRESH_SECONDS = 60;

    public static function present(string $jpeg): bool {
        $size = @getimagesizefromstring($jpeg);
        if (!$size || $size[2] !== IMAGETYPE_JPEG || $size[0] * $size[1] > 10000000) {
            throw new RuntimeException('Capture indisponible.', 502);
        }
        $image = @imagecreatefromstring($jpeg);
        if (!$image) throw new RuntimeException('Capture indisponible.', 502);

        [$width, $height] = $size;
        $margin = (int)round($width * 0.03);
        $step = max(1, (int)ceil($width / 800));
        $samples = (int)ceil(($width - 2 * $margin) / $step);
        $threshold = (int)ceil($samples * 0.80);
        $gap = max(3, (int)round($samples * 0.02));
        $requiredRows = max(3, (int)round($height * 0.005));
        $rows = 0;
        for ($y = (int)($height * 0.025); $y < (int)($height * 0.30); ++$y) {
            $orange = 0;
            $span = 0;
            $start = 0;
            $last = -$gap - 1;
            for ($i = 0; $i < $samples; ++$i) {
                $pixel = imagecolorat($image, $margin + $i * $step, $y);
                $r = ($pixel >> 16) & 255;
                $g = ($pixel >> 8) & 255;
                $b = $pixel & 255;
                if ($r < 115 || $r - $g < 43 || $g - $b < 4 ||
                    $g - $b < 0.08 * ($r - $b) || $g - $b > 0.59 * ($r - $b)) continue;
                ++$orange;
                if ($i - $last > $gap) $start = $i;
                $last = $i;
                $span = max($span, $i - $start + 1);
            }
            $rows = min($span, $orange) >= $threshold ? $rows + 1 : 0;
            if ($rows >= $requiredRows) return true;
        }
        return false;
    }

    public static function status(bool $present, ?int $age): string {
        if ($age !== null && $age > self::FRESH_SECONDS) {
            return $present ? 'stale' : 'stale_missing';
        }
        if (!$present) return 'alarm';
        return $age === null ? 'unverified' : 'ok';
    }
}
