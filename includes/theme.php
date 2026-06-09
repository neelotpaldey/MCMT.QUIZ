<?php
// developed by @neelotpal.dey

function themeInitScript(): void
{
    echo "<script>(function(){var t=localStorage.getItem('exam_theme');if(t!=='light'&&t!=='dark'){t='dark';}document.documentElement.setAttribute('data-theme',t);})();</script>\n";
}

function themeStylesheet(): void
{
    echo '<link rel="stylesheet" href="' . htmlspecialchars(BASE_URL) . '/assets/css/theme.css">' . "\n";
}

function themeScript(): void
{
    echo '<script src="' . htmlspecialchars(BASE_URL) . '/assets/js/theme.js" defer></script>' . "\n";
}

function themeToggleButton(string $class = ''): void
{
    $class = trim('theme-toggle ' . $class);
    echo '<button type="button" class="' . htmlspecialchars($class) . '" data-theme-toggle aria-label="Toggle dark or light mode" title="Toggle theme">';
    echo '<span class="theme-toggle-icon" aria-hidden="true">🌙</span>';
    echo '<span class="theme-toggle-label">Dark</span>';
    echo '</button>';
}
