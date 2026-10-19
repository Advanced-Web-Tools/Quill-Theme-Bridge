<?php
namespace QuillThemeBridge\services;

use database\interface\ITransactionalProvider;
use QuillThemeBridge\api\LinkRepository;

final class DatabaseLinks implements LinkRepository
{
    public function __construct(private readonly ITransactionalProvider $db) {}
    public function find(int $themeKey, string $themePageKey): ?int
    {
        $statement = $this->db->execute('SELECT quill_page_id FROM quill_theme_bridge_link WHERE source_key = :key', [':key' => Keys::source($themeKey, $themePageKey)]);
        try { $rows = $statement->fetchAll(\PDO::FETCH_COLUMN); } finally { $statement->closeCursor(); }
        if (count($rows) > 1) throw new \UnexpectedValueException('Ambiguous source link.');
        return $rows === [] ? null : (int) $rows[0];
    }
    public function link(int $themeKey, string $themePageKey, int $quillPageId): void
    {
        if ($quillPageId < 1) throw new \InvalidArgumentException('Invalid Quill page ID.');
        // Primary source_key makes concurrent link attempts conflict atomically.
        $this->db->execute('INSERT INTO quill_theme_bridge_link (source_key, theme_key, theme_page_key, quill_page_id) VALUES (:key, :theme, :page, :quill)',
            [':key' => Keys::source($themeKey, $themePageKey), ':theme' => $themeKey, ':page' => $themePageKey, ':quill' => $quillPageId])->closeCursor();
    }
    public function unlink(int $themeKey, string $themePageKey, int $expectedQuillPageId): void
    {
        $this->db->execute('DELETE FROM quill_theme_bridge_link WHERE source_key = :key AND quill_page_id = :quill',
            [':key' => Keys::source($themeKey, $themePageKey), ':quill' => $expectedQuillPageId])->closeCursor();
    }
}
