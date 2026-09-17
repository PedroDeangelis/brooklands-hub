<?php

namespace Tests\Feature\SalesOrders;

use App\SalesOrders\CustomerNote;
use Tests\TestCase;

/**
 * Lifting the customer's note out of a work description.
 *
 * Ported from the legacy website upserter; each case pins one rule so the
 * note customers already see does not change when the sync moves here.
 */
class CustomerNoteTest extends TestCase
{
    public function test_an_empty_description_has_no_note(): void
    {
        $this->assertSame('', CustomerNote::fromWorkDescription(''));
        $this->assertSame('', CustomerNote::fromWorkDescription("   \n  "));
    }

    public function test_a_description_without_the_heading_has_no_note(): void
    {
        $this->assertSame('', CustomerNote::fromWorkDescription('Please deliver to the back gate.'));
    }

    public function test_a_single_line_note_is_returned_whole(): void
    {
        $this->assertSame(
            'Leave at the back gate.',
            CustomerNote::fromWorkDescription('Customer Note: Leave at the back gate.'),
        );
    }

    public function test_the_heading_is_matched_regardless_of_case(): void
    {
        $this->assertSame('Ring first.', CustomerNote::fromWorkDescription('CUSTOMER NOTE:   Ring first.'));
    }

    public function test_whitespace_inside_a_single_line_note_is_collapsed(): void
    {
        $this->assertSame(
            'Leave at the back gate.',
            CustomerNote::fromWorkDescription("Customer Note: Leave   at\tthe back   gate."),
        );
    }

    public function test_windows_line_endings_are_normalised(): void
    {
        $this->assertSame(
            'Leave at the back gate. Ring the bell.',
            CustomerNote::fromWorkDescription("Customer Note: Leave at the back gate.\r\n\r\nRing the bell."),
        );
    }

    public function test_the_note_stops_at_the_next_heading(): void
    {
        $this->assertSame(
            'Leave at the back gate.',
            CustomerNote::fromWorkDescription("Customer Note: Leave at the back gate.\n\nInternal Note: call the rep."),
        );
    }

    public function test_a_second_paragraph_that_is_a_fragment_is_dropped(): void
    {
        $this->assertSame(
            'Leave at the back gate.',
            CustomerNote::fromWorkDescription("Customer Note: Leave at the back gate.\n\nJohn from dispatch"),
        );
    }

    public function test_the_note_stops_after_a_paragraph_that_does_not_end_like_a_sentence(): void
    {
        $this->assertSame(
            'Back gate please',
            CustomerNote::fromWorkDescription("Customer Note: Back gate please\n\nRing the bell."),
        );
    }

    public function test_several_complete_sentences_are_joined(): void
    {
        $this->assertSame(
            'Leave at the back gate. Ring the bell! Thanks?',
            CustomerNote::fromWorkDescription("Customer Note:\nLeave at the back gate.\n\nRing the bell!\n\nThanks?"),
        );
    }

    public function test_a_heading_with_nothing_after_it_has_no_note(): void
    {
        $this->assertSame('', CustomerNote::fromWorkDescription("Customer Note:\n\n"));
    }
}
