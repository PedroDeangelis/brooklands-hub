<?php

namespace Tests\Feature\SalesOrders;

use App\SalesOrders\SalesOrderStatus;
use Tests\TestCase;

/**
 * The website status derived from Business Central's status and the lines.
 *
 * This reproduces the rule the legacy website upserter carried, so each case
 * pins one branch of it: the website will store whatever this says, and a
 * change here changes what every customer sees in their account.
 */
class SalesOrderStatusTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function line(float $quantity, float $shipped = 0.0, float $invoiced = 0.0): array
    {
        return [
            'quantity' => $quantity,
            'quantity_shipped' => $shipped,
            'quantity_invoiced' => $invoiced,
        ];
    }

    // ------------------------------------------------------ header status

    public function test_a_draft_is_processing(): void
    {
        $this->assertSame(SalesOrderStatus::Processing, SalesOrderStatus::derive('Draft', [$this->line(2, 2, 2)]));
    }

    public function test_an_open_order_is_received(): void
    {
        $this->assertSame(SalesOrderStatus::Received, SalesOrderStatus::derive('Open', [$this->line(2, 2, 2)]));
    }

    public function test_approval_and_review_are_on_hold(): void
    {
        $this->assertSame(SalesOrderStatus::OnHold, SalesOrderStatus::derive('Pending Approval', []));
        $this->assertSame(SalesOrderStatus::OnHold, SalesOrderStatus::derive('In Review', []));
    }

    public function test_an_unknown_status_is_on_hold(): void
    {
        $this->assertSame(SalesOrderStatus::OnHold, SalesOrderStatus::derive('Pending Prepayment', []));
        $this->assertSame(SalesOrderStatus::OnHold, SalesOrderStatus::derive('', []));
    }

    // -------------------------------------------------- released, by lines

    public function test_released_with_no_lines_is_processing(): void
    {
        $this->assertSame(SalesOrderStatus::Processing, SalesOrderStatus::derive('Released', []));
    }

    public function test_released_with_nothing_posted_is_processing(): void
    {
        $this->assertSame(SalesOrderStatus::Processing, SalesOrderStatus::derive('Released', [
            $this->line(2), $this->line(5),
        ]));
    }

    public function test_released_with_some_shipped_is_partially_shipped(): void
    {
        $this->assertSame(SalesOrderStatus::PartiallyShipped, SalesOrderStatus::derive('Released', [
            $this->line(2, 2), $this->line(5),
        ]));

        // Part of one line is still partial.
        $this->assertSame(SalesOrderStatus::PartiallyShipped, SalesOrderStatus::derive('Released', [
            $this->line(5, 3),
        ]));
    }

    public function test_released_with_everything_shipped_is_fully_shipped(): void
    {
        $this->assertSame(SalesOrderStatus::FullyShipped, SalesOrderStatus::derive('Released', [
            $this->line(2, 2), $this->line(5, 5),
        ]));
    }

    public function test_released_with_everything_shipped_and_invoiced_is_completed(): void
    {
        $this->assertSame(SalesOrderStatus::Completed, SalesOrderStatus::derive('Released', [
            $this->line(2, 2, 2), $this->line(5, 5, 5),
        ]));
    }

    /**
     * The legacy quirk, kept on purpose: a line invoiced ahead of shipping
     * does not count as complete.
     */
    public function test_invoiced_but_not_shipped_is_not_completed(): void
    {
        $this->assertSame(SalesOrderStatus::PartiallyShipped, SalesOrderStatus::derive('Released', [
            $this->line(2, 0, 2),
        ]));
    }

    public function test_lines_with_no_quantity_are_ignored(): void
    {
        // Only the zero-quantity line exists: nothing to ship, so processing.
        $this->assertSame(SalesOrderStatus::Processing, SalesOrderStatus::derive('Released', [
            $this->line(0),
        ]));

        // A zero-quantity line beside a completed one does not hold it back.
        $this->assertSame(SalesOrderStatus::Completed, SalesOrderStatus::derive('Released', [
            $this->line(0), $this->line(2, 2, 2),
        ]));
    }

    public function test_quantities_are_compared_with_a_tolerance(): void
    {
        $this->assertSame(SalesOrderStatus::FullyShipped, SalesOrderStatus::derive('Released', [
            $this->line(0.3, 0.1 + 0.2),
        ]));
    }

    public function test_only_completed_is_final(): void
    {
        foreach (SalesOrderStatus::cases() as $case) {
            $this->assertSame($case === SalesOrderStatus::Completed, $case->isFinal(), $case->value);
        }
    }
}
