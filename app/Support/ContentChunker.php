<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Split HTML/plain store content into embeddable text chunks.
 */
final class ContentChunker
{
    /**
     * @return list<string>
     */
    public static function chunk(string $htmlOrText, int $maxChars = 1200, int $overlap = 150): array
    {
        $text = self::toPlainText($htmlOrText);

        if ($text === '') {
            return [];
        }

        $maxChars = max(200, $maxChars);
        $overlap = max(0, min($overlap, (int) floor($maxChars / 3)));

        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $slice = mb_substr($text, $offset, $maxChars);
            $breakAt = self::preferredBreak($slice);

            if ($breakAt > 0 && $offset + $maxChars < $length) {
                $slice = mb_substr($slice, 0, $breakAt);
            }

            $slice = trim($slice);

            if ($slice !== '') {
                $chunks[] = $slice;
            }

            if ($offset + mb_strlen($slice) >= $length) {
                break;
            }

            $advance = max(1, mb_strlen($slice) - $overlap);
            $offset += $advance;
        }

        return $chunks;
    }

    public static function toPlainText(string $htmlOrText): string
    {
        $withoutTags = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlOrText));
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace("/[ \t]+/", ' ', $decoded) ?? $decoded;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function preferredBreak(string $slice): int
    {
        $paragraph = mb_strrpos($slice, "\n\n");
        if ($paragraph !== false && $paragraph > (int) (mb_strlen($slice) * 0.4)) {
            return $paragraph;
        }

        $newline = mb_strrpos($slice, "\n");
        if ($newline !== false && $newline > (int) (mb_strlen($slice) * 0.5)) {
            return $newline;
        }

        $space = mb_strrpos($slice, ' ');
        if ($space !== false && $space > (int) (mb_strlen($slice) * 0.5)) {
            return $space;
        }

        return 0;
    }
}
