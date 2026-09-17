<?php

declare(strict_types=1);

namespace PayFlow\Support;

use RuntimeException;

final class View
{
    /**
     * 极简模板渲染：把关联数组 extract 后 include 视图文件。
     */
    public static function render(string $view, array $data = []): string
    {
        $file = PAYFLOW_ROOT . '/views/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
