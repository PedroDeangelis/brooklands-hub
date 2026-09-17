<?php

namespace App\SalesInvoices;

/**
 * Which kind of posted sales document a SalesInvoice row is.
 *
 * On the website an invoice and a credit memo are the same `sales_invoice`
 * post, told apart by the status field. The hub mirrors that: one table, one
 * ledger entity, one payload shape, and this enum deciding the few places
 * where the two differ — the Business Central page they come from, the title
 * wording, and the status sent.
 *
 * The values are the website's own status choices, so the payload sends them
 * verbatim.
 */
enum SalesInvoiceKind: string
{
    case Invoice = 'invoice';

    case CreditMemo = 'credit_memo';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::CreditMemo => 'Credit memo',
        };
    }

    /**
     * The website status choice for this kind. The same as the value, and
     * named so a reader of the payload builder sees what is being sent.
     */
    public function websiteStatus(): string
    {
        return $this->value;
    }

    /**
     * The legacy upserter's title wording, kept so existing posts do not
     * change title when the sync moves here.
     */
    public function titlePrefix(): string
    {
        return match ($this) {
            self::Invoice => 'Sales Invoice',
            self::CreditMemo => 'Sales Credit Memo',
        };
    }

    public function dateLabel(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice date',
            self::CreditMemo => 'Credit memo date',
        };
    }

    public function orderLabel(): string
    {
        return match ($this) {
            self::Invoice => 'Posted from order',
            self::CreditMemo => 'Return order',
        };
    }
}
