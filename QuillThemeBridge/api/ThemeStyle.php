<?php
namespace QuillThemeBridge\api;

final class ThemeStyle
{
    public function __construct(public readonly array $bodyClasses = [], public readonly array $variables = [], public readonly ?string $language = null)
    {
        foreach ($bodyClasses as $class) if (!is_string($class) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $class)) throw new \InvalidArgumentException('Invalid theme class.');
        foreach ($variables as $name => $value) {
            if (!is_string($name) || !preg_match('/^--[a-zA-Z_][a-zA-Z0-9_-]*$/D', $name) || !is_string($value)
                || preg_match('/[;{}<>\x00-\x1f\x7f]|\/\*|\*\//', $value)) throw new \InvalidArgumentException('Invalid theme CSS variable.');
        }
        if ($language !== null && !preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/D', $language)) throw new \InvalidArgumentException('Invalid document language.');
    }
}
