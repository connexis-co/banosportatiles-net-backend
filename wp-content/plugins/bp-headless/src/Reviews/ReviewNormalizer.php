<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * ReviewRecord → the public "Review" object (contract §3.1). Pure. Only public data: visible name and its
 * initials, rating, title, plain-text content, date and the owner's response. Never the email or the IP.
 *
 *   {id, author, initials, rating, title?, content, date, response?: {content, date?, author}}
 */
final class ReviewNormalizer
{
    public const ANONYMOUS = 'Anónimo';

    /**
     * @param  string  $owner  Name shown on the owner's response (the brand).
     * @return array<string, mixed>
     */
    public static function normalize(ReviewRecord $record, string $owner): array
    {
        $author = self::line($record->author);
        $author = $author !== '' ? $author : self::ANONYMOUS;

        $review = [
            'id' => $record->id,
            'author' => $author,
            'initials' => self::initials($author),
            'rating' => max(RatingSummary::WORST, min(RatingSummary::BEST, $record->rating)),
        ];
        $title = self::line($record->title);
        if ($title !== '') {
            $review['title'] = $title;
        }
        $review['content'] = self::plainText($record->content);
        $review['date'] = ($record->date ?? new \DateTimeImmutable('@0'))->format(DATE_ATOM);

        $response = self::plainText($record->response);
        if ($response !== '') {
            $review['response'] = ['content' => $response]
                + ($record->responseDate !== null ? ['date' => $record->responseDate->format(DATE_ATOM)] : [])
                + ['author' => $owner];
        }

        return $review;
    }

    /** Up to two initials: first and last word ("Ana María Pérez" → "AP", "josé" → "J"). */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s\-_.]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = [];
        foreach ($words as $word) {
            if (preg_match('/\p{L}|\p{N}/u', $word, $m) === 1) {
                $letters[] = mb_strtoupper($m[0]);
            }
        }
        if ($letters === []) {
            return mb_substr(self::ANONYMOUS, 0, 1);
        }

        return count($letters) === 1 ? $letters[0] : $letters[0].$letters[count($letters) - 1];
    }

    /** HTML or text → plain text that keeps paragraphs and line breaks. */
    public static function plainText(string $text): string
    {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|div|li|h[1-6])>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace("/[ \t]+/u", ' ', $text);
        $text = (string) preg_replace("/ *\n */u", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
