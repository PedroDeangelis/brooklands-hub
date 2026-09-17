<?php

namespace App\BusinessCentral\Import;

use App\Models\Product;
use App\Models\ProductMarketingText;
use App\Support\HtmlSanitizer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Normalises a raw Business Central marketingTextExt row and stores it.
 *
 * This is the only place that knows how the marketing text field names map onto
 * local columns, and the only place the copy is sanitised.
 *
 * Sanitising happens here, on the way in, rather than when the payload is built.
 * The copy is authored in Business Central and reaches a public website
 * verbatim, so cleaning it once at the boundary means what is stored is what is
 * delivered: a stored row can be read without wondering what it will become,
 * and change detection compares the cleaned copy rather than re-cleaning on
 * every comparison.
 */
class ProductMarketingTextImporter
{
    /**
     * Columns excluded from change detection.
     *
     * bc_payload mirrors the whole row, so any incidental movement inside it —
     * a changed etag, a reordered key — would make every row look changed; the
     * timestamps are bookkeeping.
     *
     * bc_modified_at is excluded for a reason specific to this entity: Business
     * Central touches it when the item record is saved, not only when the copy
     * is edited. Treating it as a change would open a delivery carrying
     * identical copy every time somebody saved an unrelated field.
     *
     * @var array<int, string>
     */
    private const IGNORED_FOR_CHANGE_DETECTION = [
        'bc_payload',
        'bc_modified_at',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * Create or update the marketing copy for a Business Central item.
     *
     * The BC "itemId" GUID is the identity, and the same GUID the product
     * carries, so re-importing updates in place rather than creating a
     * duplicate.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable item id.
     */
    public function import(array $row): ProductMarketingTextImportResult
    {
        $bcId = trim((string) ($row['itemId'] ?? ''));

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central marketing text row is missing an "itemId".');
        }

        // Copy belongs to a product. Without one there is nothing for it to
        // describe, so the row is skipped rather than stored as an orphan: the
        // product may simply not have been imported yet, and the next run picks
        // the row up once it has been.
        //
        // Matching is by Business Central id only. A SKU match would be a guess,
        // and guessing here would attach one item's description to another.
        if (! Product::query()->where('bc_id', $bcId)->exists()) {
            return ProductMarketingTextImportResult::skipped();
        }

        $marketingText = ProductMarketingText::firstOrNew(['bc_id' => $bcId]);
        $created = ! $marketingText->exists;

        $marketingText->fill($this->normalize($row));

        // Read the dirty set before saving: afterwards getDirty() is empty.
        $changedFields = $created
            ? $this->withoutIgnored(array_keys($marketingText->getAttributes()))
            : $this->withoutIgnored(array_keys($marketingText->getDirty()));

        $marketingText->save();

        return ProductMarketingTextImportResult::imported($marketingText, $created, $changedFields);
    }

    /**
     * Map a Business Central row onto local columns.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $marketingText = $this->sanitizer->purify($this->text($row['marketingText'] ?? null));

        return [
            'bc_id' => trim((string) ($row['itemId'] ?? '')),
            'sku' => trim((string) ($row['itemNo'] ?? '')),
            'marketing_text' => $marketingText,
            'short_description' => $this->shortDescription($marketingText),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => $row,
        ];
    }

    /**
     * The lead sentence of the copy, used as the website's short description.
     *
     * Cut at the first sentence-ending period, as the website did before this
     * moved into Laravel — but only at one that actually ends a sentence. The
     * earlier rule cut at the first period of any kind, which mangles the spec
     * tables a large part of this catalogue uses: "Preferred pH 6.0-8.0" was
     * truncated to "Preferred pH 6." and published as the product summary.
     *
     * A period counts as sentence-ending only when it is followed by
     * whitespace, a tag, or the end of the copy, and is not preceded by a
     * digit. That keeps decimals, and leaves ordinary prose cut exactly where
     * the old rule cut it.
     *
     * An unterminated paragraph yields the whole copy: a description with no
     * sentence break is one sentence, and truncating it at an arbitrary length
     * would cut a word in half.
     */
    private function shortDescription(string $marketingText): string
    {
        if ($marketingText === '') {
            return '';
        }

        // (?<!\d) keeps decimals whole; (?=\s|<|$) requires the period to be
        // followed by a break rather than sitting inside a token.
        if (preg_match('/(?<!\d)\.(?=\s|<|$)/u', $marketingText, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $marketingText;
        }

        // The offset is in bytes and the match is the single-byte period, so a
        // byte-wise cut here is exact and cannot split a multibyte character.
        return $this->closeOpenTags(substr($marketingText, 0, $m[0][1] + 1));
    }

    /**
     * Close any tags the cut left hanging open.
     *
     * Cutting mid-markup is normal here rather than exceptional: much of this
     * catalogue's copy is a bulleted list, so the first sentence usually ends
     * inside an <li> inside a <ul>. Delivering that prefix as it stands would
     * send unbalanced HTML to the website, where it swallows the rest of the
     * page rather than showing a short description.
     *
     * Only the allowlist's tags can appear, none of which nest ambiguously, so
     * a stack is enough; no parser is warranted.
     */
    private function closeOpenTags(string $html): string
    {
        preg_match_all('/<(\/?)([a-z]+)[^>]*?(\/?)>/i', $html, $matches, PREG_SET_ORDER);

        /** @var list<string> $open */
        $open = [];

        foreach ($matches as $match) {
            $tag = mb_strtolower($match[2]);

            // <br /> and friends close themselves and never nest.
            if ($match[3] === '/' || $tag === 'br') {
                continue;
            }

            if ($match[1] === '/') {
                // Unwind to the matching open tag, discarding anything left
                // dangling by malformed markup rather than trusting the order.
                $at = array_search($tag, array_reverse($open, true), true);

                if ($at !== false) {
                    $open = array_slice($open, 0, (int) $at);
                }

                continue;
            }

            $open[] = $tag;
        }

        foreach (array_reverse($open) as $tag) {
            $html .= '</'.$tag.'>';
        }

        return $html;
    }

    /**
     * Business Central sends the copy as a string, but an absent field arrives
     * as null and a cleared one as an empty string; both mean "no copy".
     */
    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $timestamp = trim((string) $value);

        if ($timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function withoutIgnored(array $fields): array
    {
        $fields = array_values(array_diff($fields, self::IGNORED_FOR_CHANGE_DETECTION));

        sort($fields);

        return $fields;
    }
}
