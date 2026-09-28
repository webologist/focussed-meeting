<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Render a template inside a layout. Templates receive $data as variables and must escape output with e(). */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) return $content;
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = APP_PATH . '/Views/' . $template . '.php';
        if (!is_file($file)) throw new \RuntimeException("View not found: $template");
        extract($data, EXTR_SKIP);
        ob_start();
        try { include $file; }
        catch (\Throwable $e) { ob_end_clean(); throw $e; }
        return (string)ob_get_clean();
    }
}
