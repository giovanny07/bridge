<?php

namespace GlpiPlugin\Bridge\Page;

final class Ui
{
    public static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function sectionCard(string $icon, string $title): string
    {
        return '<div class="bridge-section-card">'
            . '<div class="bridge-section-title">'
            . '<i class="ti ' . self::h($icon) . '"></i>' . self::h($title)
            . '</div>';
    }

    public static function checkbox(string $id, string $name, string $label, string $icon, bool $checked): string
    {
        $checkedAttr = $checked ? ' checked' : '';

        return '<div class="form-check">'
            . '<input class="form-check-input" type="checkbox" id="' . self::h($id) . '" name="' . self::h($name) . '" value="1"' . $checkedAttr . '>'
            . '<label class="form-check-label small" for="' . self::h($id) . '">'
            . '<i class="ti ' . self::h($icon) . ' me-1 text-muted"></i>' . $label
            . '</label>'
            . '</div>';
    }

    public static function statCard(string $icon, string $color, int|string $value, string $label, string $columnClass = 'col-6 col-md-3'): void
    {
        echo '<div class="' . self::h($columnClass) . '">';
        echo '<div class="card border-0 shadow-sm h-100 bridge-stat-card">';
        echo '<div class="card-body py-3 d-flex align-items-center gap-3">';
        echo '<div class="bridge-stat-icon text-' . self::h($color) . '">';
        echo '<i class="ti ti-' . self::h($icon) . '"></i>';
        echo '</div>';
        echo '<div>';
        echo '<div class="fw-bold fs-4 lh-1">' . self::h($value) . '</div>';
        echo '<div class="text-muted small mt-1">' . self::h($label) . '</div>';
        echo '</div>';
        echo '</div></div></div>';
    }

    public static function metricPill(string $label, int|string $value): void
    {
        echo '<span class="badge bg-secondary-subtle text-secondary border bridge-metric-pill">';
        echo self::h($label) . ': <strong>' . self::h($value) . '</strong>';
        echo '</span>';
    }

    public static function liveMetric(string $label, string $id): void
    {
        echo '<div class="col-6 col-md-3">';
        echo '<div class="d-flex align-items-center justify-content-between gap-2 border rounded px-2 py-1 bg-white">';
        echo '<span class="text-muted text-truncate">' . self::h($label) . '</span>';
        echo '<strong class="font-monospace" id="' . self::h($id) . '">0</strong>';
        echo '</div></div>';
    }
}
