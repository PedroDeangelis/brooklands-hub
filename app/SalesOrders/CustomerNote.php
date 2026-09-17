<?php

namespace App\SalesOrders;

/**
 * Lifts the customer's note out of a sales order's work description.
 *
 * The website writes the note into the work description under a "Customer
 * Note:" heading when it creates an order in Business Central, and staff add
 * their own headed sections after it. This takes back only the customer's
 * paragraphs: everything after the heading up to the next heading, stopping
 * early at the first paragraph that does not end like a sentence, because a
 * trailing fragment is usually the start of someone else's note.
 *
 * Ported from the legacy website upserter without changing the rule, so the
 * note the website already shows does not change when the sync moves here.
 */
final class CustomerNote
{
    private const HEADING = '/Customer Note:\s*/i';

    private const NEXT_HEADING = '/^[A-Z][A-Za-z0-9 ]+:\s*/';

    private const PARAGRAPH_BREAK = '/\n\s*\n+|(?<=\n)\s*(?=[A-Z][A-Za-z0-9 ]+:\s*)/';

    private const ENDS_LIKE_A_SENTENCE = '/[.!?]["\')\]]*$/';

    public static function fromWorkDescription(string $workDescription): string
    {
        $text = trim($workDescription);

        if ($text === '') {
            return '';
        }

        $text = preg_replace("/\r\n?/", "\n", $text);

        if ($text === null) {
            return '';
        }

        if (! preg_match(self::HEADING, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $match = $matches[0][0] ?? '';
        $offset = $matches[0][1] ?? null;

        if (! is_string($match) || ! is_int($offset)) {
            return '';
        }

        $note = trim(substr($text, $offset + strlen($match)));

        if ($note === '') {
            return '';
        }

        if (! str_contains($note, "\n")) {
            $note = preg_replace('/\s+/', ' ', $note);

            return is_string($note) ? $note : '';
        }

        $paragraphs = preg_split(self::PARAGRAPH_BREAK, $note);

        if ($paragraphs === false) {
            return '';
        }

        $kept = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = preg_replace('/\s+/', ' ', trim($paragraph));

            if (! is_string($paragraph) || $paragraph === '') {
                continue;
            }

            if (preg_match(self::NEXT_HEADING, $paragraph)) {
                break;
            }

            $endsLikeASentence = (bool) preg_match(self::ENDS_LIKE_A_SENTENCE, $paragraph);

            if ($kept !== [] && ! $endsLikeASentence) {
                break;
            }

            $kept[] = $paragraph;

            if (! $endsLikeASentence) {
                break;
            }
        }

        return implode(' ', $kept);
    }
}
