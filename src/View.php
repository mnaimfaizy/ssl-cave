<?php

declare(strict_types=1);

final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        $templateFile = dirname(__DIR__) . '/templates/' . $template . '.php';
        $layoutFile = dirname(__DIR__) . '/templates/' . $layout . '.php';
        if (!is_readable($templateFile)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $content = ob_get_clean() ?: '';

        if ($layout !== '' && is_readable($layoutFile)) {
            require $layoutFile;
            return;
        }

        echo $content;
    }
}
