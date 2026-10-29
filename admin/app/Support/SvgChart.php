<?php

namespace App\Support;

/** Builds a smooth line/area path for the dashboard sales chart (no JS library needed). */
final class SvgChart
{
    /**
     * @param  list<int>  $values
     * @return array{line: string, area: string, points: list<array{x: float, y: float}>, max: int, ticks: list<int>}
     */
    public static function build(array $values, int $width, int $height, int $padTop = 10, int $padBottom = 0): array
    {
        $max = self::niceMax(max($values ?: [0]));
        $n = count($values);
        $usable = $height - $padTop - $padBottom;
        $points = [];
        foreach ($values as $i => $v) {
            $x = $n > 1 ? $i * $width / ($n - 1) : $width / 2;
            $y = $padTop + $usable - ($max > 0 ? $v / $max * $usable : 0);
            $points[] = ['x' => round($x, 2), 'y' => round($y, 2)];
        }

        $line = '';
        foreach ($points as $i => $p) {
            if ($i === 0) {
                $line .= "M{$p['x']},{$p['y']}";
                continue;
            }
            // Catmull-Rom -> cubic Bézier, tension 0.5; clamp control points to avoid overshooting below 0.
            $p0 = $points[$i - 2] ?? $points[$i - 1];
            $p1 = $points[$i - 1];
            $p3 = $points[$i + 1] ?? $p;
            $bottom = $height - $padBottom;
            $c1x = round($p1['x'] + ($p['x'] - $p0['x']) / 6, 2);
            $c1y = round(min($bottom, $p1['y'] + ($p['y'] - $p0['y']) / 6), 2);
            $c2x = round($p['x'] - ($p3['x'] - $p1['x']) / 6, 2);
            $c2y = round(min($bottom, $p['y'] - ($p3['y'] - $p1['y']) / 6), 2);
            $line .= " C{$c1x},{$c1y} {$c2x},{$c2y} {$p['x']},{$p['y']}";
        }

        $last = end($points) ?: ['x' => 0];
        $first = $points[0] ?? ['x' => 0];
        $base = $height - $padBottom;
        $area = $line." L{$last['x']},{$base} L{$first['x']},{$base} Z";

        $ticks = [];
        for ($i = 4; $i >= 0; $i--) {
            $ticks[] = (int) ($max * $i / 4);
        }

        return ['line' => $line, 'area' => $area, 'points' => $points, 'max' => $max, 'ticks' => $ticks];
    }

    /** Rounds the axis maximum up to a friendly number (in paise). */
    private static function niceMax(int $value): int
    {
        if ($value <= 0) {
            return 100000; // ₹1,000 empty-state scale
        }
        $magnitude = 10 ** (strlen((string) $value) - 1);
        foreach ([1, 2, 2.5, 4, 5, 8, 10] as $step) {
            if ($value <= $step * $magnitude) {
                return (int) ($step * $magnitude);
            }
        }

        return 10 * $magnitude;
    }
}
