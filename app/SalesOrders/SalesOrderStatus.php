<?php

namespace App\SalesOrders;

/**
 * The status the website shows for a sales order.
 *
 * These are the website's own choices for its order post, decided here rather
 * than there: Business Central's status alone does not say how far an order
 * has progressed, and working that out from the lines is a rule the legacy
 * website upserter carried. Moving it here means the website only ever stores
 * what it is told. The rule is reproduced exactly, including its quirks.
 *
 * Stored on the order at import so the list can filter on it in SQL, and sent
 * in the payload so the website never re-derives it.
 */
enum SalesOrderStatus: string
{
    /** Open in Business Central and not yet released. */
    case Received = 'received';

    /** Released, but nothing has shipped or invoiced yet; also a draft. */
    case Processing = 'processing';

    /** Awaiting approval or review, or in a state the website has no word for. */
    case OnHold = 'on-hold';

    /** Released with some, but not every, line fully shipped. */
    case PartiallyShipped = 'partially-shipped';

    /** Released with every line shipped and at least one not yet invoiced. */
    case FullyShipped = 'fully-shipped';

    /** Released with every line both shipped and invoiced. */
    case Completed = 'completed';

    /**
     * Quantities within this much of each other count as the same.
     */
    private const TOLERANCE = 0.00001;

    /**
     * Derive the website status from Business Central's status and the lines.
     *
     * @param  list<array<string, mixed>>  $lines  Normalised lines, as SalesOrderImporter stores them.
     */
    public static function derive(string $bcStatus, array $lines): self
    {
        return match ($bcStatus) {
            'Draft' => self::Processing,
            'Pending Approval', 'In Review' => self::OnHold,
            'Open' => self::Received,
            'Released' => self::fromLines($lines),
            default => self::OnHold,
        };
    }

    /**
     * How far a released order has progressed, read from its lines.
     *
     * Lines with no quantity are ignored. An order with no such lines reads
     * as processing, because nothing on it can have shipped.
     *
     * "Completed" needs every line both fully shipped and fully invoiced. A
     * line invoiced ahead of shipping does not count as complete: that is the
     * legacy rule, kept so an order does not flip to completed early.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private static function fromLines(array $lines): self
    {
        $hasRelevantLines = false;
        $allUnposted = true;
        $allFullyShipped = true;
        $allFullyInvoiced = true;

        foreach ($lines as $line) {
            $quantity = self::quantity($line['quantity'] ?? null);

            if ($quantity <= 0) {
                continue;
            }

            $hasRelevantLines = true;

            $shipped = self::quantity($line['quantity_shipped'] ?? null);
            $invoiced = self::quantity($line['quantity_invoiced'] ?? null);

            $isFullyShipped = self::same($shipped, $quantity);
            $isFullyInvoiced = self::same($invoiced, $quantity);

            if (! self::same($shipped, 0.0) || ! self::same($invoiced, 0.0)) {
                $allUnposted = false;
            }

            if (! $isFullyShipped) {
                $allFullyShipped = false;
            }

            if (! $isFullyShipped || ! $isFullyInvoiced) {
                $allFullyInvoiced = false;
            }
        }

        if (! $hasRelevantLines) {
            return self::Processing;
        }

        if ($allFullyInvoiced) {
            return self::Completed;
        }

        if ($allFullyShipped) {
            return self::FullyShipped;
        }

        if ($allUnposted) {
            return self::Processing;
        }

        return self::PartiallyShipped;
    }

    private static function quantity(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function same(float $left, float $right): bool
    {
        return abs($left - $right) < self::TOLERANCE;
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processing => 'Processing',
            self::OnHold => 'On hold',
            self::PartiallyShipped => 'Partially shipped',
            self::FullyShipped => 'Fully shipped',
            self::Completed => 'Completed',
        };
    }

    public function explain(): string
    {
        return match ($this) {
            self::Received => 'Open in Business Central and not yet released.',
            self::Processing => 'Released (or still a draft); nothing has shipped or invoiced yet.',
            self::OnHold => 'Awaiting approval or review in Business Central.',
            self::PartiallyShipped => 'Released; some lines have shipped but not all of them fully.',
            self::FullyShipped => 'Released; every line has shipped, and at least one is not yet invoiced.',
            self::Completed => 'Released; every line has both shipped and been invoiced.',
        };
    }

    /**
     * Whether the order has reached the end of its life on the website.
     */
    public function isFinal(): bool
    {
        return $this === self::Completed;
    }
}
