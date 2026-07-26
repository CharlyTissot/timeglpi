<?php

namespace GlpiPlugin\Gestiontemps\Toolbox;

/**
 * Helpers de manipulation des durées.
 *
 * Toutes les durées sont stockées en secondes. Ces helpers gèrent le
 * formatage lisible et les conversions.
 */
class Time
{
    /**
     * Formate une durée (en secondes) en "Xh Ym" lisible.
     * Gère les valeurs négatives (débit de tirelire).
     */
    public static function human(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0 && $minutes > 0) {
            return sprintf('%s%dh %02dm', $sign, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%s%dh', $sign, $hours);
        }
        return sprintf('%s%dm', $sign, $minutes);
    }

    /**
     * Formate une durée en heures décimales (ex. 1.5 pour 1h30).
     */
    public static function decimalHours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    /**
     * Convertit des minutes en secondes.
     */
    public static function minutesToSeconds(int $minutes): int
    {
        return $minutes * 60;
    }

    /**
     * Calcule un pourcentage borné [0..100].
     */
    public static function percent(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return round(($part / $total) * 100, 1);
    }
}
