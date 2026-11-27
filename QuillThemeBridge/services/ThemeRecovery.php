<?php
namespace QuillThemeBridge\services;

use database\interface\ITransactionalProvider;
use Quill\models\QuillPage;
use Quill\services\editor\ExternalPageOverrides;
use ThemeEngine\service\Engine;

/** Retains Quill's original owner and snapshots when an installed theme gets a new ID. */
final class ThemeRecovery
{
    private ?array $mappings = null;

    public function __construct(private readonly Engine $engine, private readonly ITransactionalProvider $db) {}

    private function mappings(): array
    {
        if ($this->mappings !== null) return $this->mappings;
        $statement = $this->db->execute('SELECT theme_key, previous_theme_key, theme_name FROM quill_theme_bridge_recovery');
        try {
            return $this->mappings = $statement->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
    }

    public function previousThemeId(int $themeId): ?int
    {
        $mapping = $this->mappings()[$themeId] ?? null;
        $themes = $this->engine->registry->all();
        if ($mapping === null || ($themes[$themeId]->name ?? null) !== $mapping['theme_name']) return null;
        $previous = (int) $mapping['previous_theme_key'];
        return isset($themes[$previous]) ? null : $previous;
    }

    public function installedThemeId(int $themeId): int
    {
        if (isset($this->engine->registry->all()[$themeId])) return $themeId;
        foreach ($this->mappings() as $installed => $mapping) {
            if ((int) $mapping['previous_theme_key'] === $themeId && $this->previousThemeId((int) $installed) === $themeId) return (int) $installed;
        }
        return $themeId;
    }

    public function resourceThemeId(int $themeId, string $type, string $key): int
    {
        $previous = $this->previousThemeId($themeId);
        if ($previous === null) return $themeId;
        $overrides = new ExternalPageOverrides();
        $kind = $type === 'page' ? 'page' : 'component';
        if ($overrides->find(Keys::resource($themeId, $type, $key), $kind) !== null) return $themeId;
        return $overrides->find(Keys::resource($previous, $type, $key), $kind) === null ? $themeId : $previous;
    }

    /** @return array{recovered: int, conflicts: list<string>} */
    public function recover(int $previousId, int $themeId, string $themeName): array
    {
        $themes = $this->engine->registry->all();
        if ($previousId < 1 || isset($themes[$previousId])) throw new \InvalidArgumentException('The previous theme ID must be absent from the theme registry.');
        $engine = (new ThemeDocuments($this->engine))->selected($themeId);
        if ($engine->activeTheme()->name !== $themeName) throw new \InvalidArgumentException('The package name does not match the installed theme.');

        $types = ['page' => $engine->definition()->pages, 'part' => $engine->definition()->sections, 'widget' => $engine->widgets->all()];
        $owners = (new QuillPage())->newQuery()->where(['source_type' => 'urpc', 'deleted_at' => null])->getModels();
        $keys = [];
        foreach ($owners as $owner) $keys[$owner->source_key] = true;
        $recovered = 0;
        $conflicts = [];
        foreach ($types as $type => $resources) {
            $kind = $type === 'page' ? 'page' : 'component';
            foreach (array_keys($resources) as $key) {
                $oldKey = ExternalPageOverrides::resourceKey(Keys::resource($previousId, $type, $key), $kind);
                if (!isset($keys[$oldKey])) continue;
                $newKey = ExternalPageOverrides::resourceKey(Keys::resource($themeId, $type, $key), $kind);
                if (isset($keys[$newKey])) {
                    $conflicts[] = $type . ' ' . $key;
                    continue;
                }
                $recovered++;
            }
        }
        if ($recovered === 0 && $conflicts === []) throw new \DomainException('No saved Quill external resources match the installed theme.');
        if ($recovered > 0) $this->saveMapping($previousId, $themeId, $themeName);
        return ['recovered' => $recovered, 'conflicts' => $conflicts];
    }

    private function saveMapping(int $previousId, int $themeId, string $themeName): void
    {
        $mappings = $this->mappings();
        if ($this->previousThemeId($themeId) === $previousId) return;
        if (isset($mappings[$themeId])) throw new \DomainException('The installed ID already has a recovery mapping.');
        $bindings = [':theme' => $themeId, ':previous' => $previousId, ':name' => $themeName];
        foreach ($mappings as $installed => $mapping) {
            if ((int) $mapping['previous_theme_key'] !== $previousId) continue;
            if (isset($this->engine->registry->all()[$installed]) || $mapping['theme_name'] !== $themeName) {
                throw new \DomainException('The previous ID is already mapped to another theme.');
            }
            $statement = $this->db->execute('UPDATE quill_theme_bridge_recovery SET theme_key = :theme WHERE theme_key = :installed AND previous_theme_key = :previous AND theme_name = :name',
                $bindings + [':installed' => (int) $installed]);
            try {
                if ($statement->rowCount() !== 1) throw new \DomainException('The recovery mapping changed. Run the command again.');
            } finally {
                $statement->closeCursor();
            }
            $this->mappings = null;
            return;
        }
        $this->db->execute('INSERT INTO quill_theme_bridge_recovery (theme_key, previous_theme_key, theme_name) VALUES (:theme, :previous, :name)', $bindings)->closeCursor();
        $this->mappings = null;
    }
}
