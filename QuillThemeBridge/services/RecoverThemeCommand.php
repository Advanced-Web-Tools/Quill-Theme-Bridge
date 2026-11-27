<?php
namespace QuillThemeBridge\services;

use cli\interfaces\CLICommand;

final class RecoverThemeCommand implements CLICommand
{
    private string $message = '';

    public function __construct(private readonly ThemeRecovery $recovery) {}

    public function getCommand(): string { return 'quill-theme-recover'; }
    public function getHelp(): string { return 'Reconnect saved Quill external resources after a theme is reinstalled.'; }
    public function getArguments(): array { return ['<previous-theme-id>', '<installed-theme-id>', '<theme-package-name>']; }

    public function execute(string $command, array $args = []): void
    {
        try {
            if (count($args) !== 3) throw new \InvalidArgumentException('Usage: quill-theme-recover <previous-theme-id> <installed-theme-id> <theme-package-name>');
            [$previous, $installed, $name] = $args;
            $previous = filter_var($previous, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $installed = filter_var($installed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($previous === false || $installed === false) throw new \InvalidArgumentException('Theme IDs must be positive integers.');
            $result = $this->recovery->recover($previous, $installed, $name);
            $this->message = "Reconnected {$result['recovered']} saved external resources for {$name}.";
            if ($result['conflicts'] !== []) {
                $this->message .= ' Kept existing resources for the installed ID: ' . implode(', ', $result['conflicts']) . '.';
            }
        } catch (\Throwable $error) {
            $this->message = 'Recovery failed: ' . $error->getMessage();
        }
    }

    public function result(): string { return $this->message; }
}
