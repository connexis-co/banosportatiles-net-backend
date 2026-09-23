<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Normalizer;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Page FAQs = inline repeater rows first, then referenced bank FAQs (duplicates by question removed).
 */
final class FaqNormalizer
{
    /** @var \Closure(string): string */
    private \Closure $sanitize;

    /**
     * @param  (callable(string): string)|null  $sanitize  Sanitizer for inline answers (HTML fragment).
     */
    public function __construct(private readonly ReferenceResolver $refs, ?callable $sanitize = null)
    {
        $this->sanitize = $sanitize !== null ? \Closure::fromCallable($sanitize) : static fn (string $a): string => $a;
    }

    /**
     * @return list<array{q: string, a: string}>
     */
    public function normalize(mixed $inline, mixed $refs): array
    {
        $faqs = [];
        $seen = [];
        $push = static function (string $q, string $a) use (&$faqs, &$seen): void {
            $key = mb_strtolower(trim($q));
            if ($q === '' || $a === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $faqs[] = ['q' => $q, 'a' => $a];
        };

        foreach (is_array($inline) ? $inline : [] as $row) {
            if (is_array($row)) {
                $push(Arr::string($row, 'q'), ($this->sanitize)(Arr::string($row, 'a')));
            }
        }

        foreach (Arr::ids($refs) as $id) {
            $faq = $this->refs->faq($id);
            if ($faq !== null) {
                $push($faq['q'], $faq['a']);
            }
        }

        return $faqs;
    }
}
