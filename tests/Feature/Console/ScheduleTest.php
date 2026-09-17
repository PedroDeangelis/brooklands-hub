<?php

namespace Tests\Feature\Console;

use App\Sync\SyncSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * What the scheduler will and will not start on its own.
 *
 * The important assertion here is a negative one: website delivery must not be
 * schedulable while it is disabled, because an unattended run would write
 * thousands of products to a live website.
 */
class ScheduleTest extends TestCase
{
    /**
     * Load the schedule with the given configuration.
     *
     * Registered against a fresh Schedule so each case sees only its own
     * entries. That is what makes the flag testable at all: its effect is on
     * registration, not on behaviour.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, Event>
     */
    private function events(array $config = []): array
    {
        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        $schedule = new Schedule;

        SyncSchedule::register($schedule);

        return $schedule->events();
    }

    /**
     * @param  array<int, Event>  $events
     * @return array<int, string>
     */
    private function commands(array $events): array
    {
        return array_map(static fn (Event $event): string => $event->command ?? '', $events);
    }

    private function contains(array $events, string $needle): bool
    {
        foreach ($this->commands($events) as $command) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------- website delivery

    /**
     * The whole point of the flag.
     */
    public function test_website_delivery_is_not_scheduled_by_default(): void
    {
        $events = $this->events();

        $this->assertFalse(
            $this->contains($events, 'website:deliver-products'),
            'website delivery must not be scheduled while it is disabled',
        );
    }

    public function test_website_delivery_is_not_scheduled_when_explicitly_disabled(): void
    {
        $events = $this->events(['sync.delivery.enabled' => false]);

        $this->assertFalse($this->contains($events, 'website:deliver-products'));
    }

    public function test_website_delivery_is_scheduled_only_when_enabled(): void
    {
        $events = $this->events(['sync.delivery.enabled' => true]);

        $this->assertTrue($this->contains($events, 'website:deliver-products'));
    }

    /**
     * One tick must not be able to queue the entire backlog.
     */
    public function test_the_scheduled_delivery_carries_the_configured_batch_limit(): void
    {
        $events = $this->events([
            'sync.delivery.enabled' => true,
            'sync.delivery.batch_size' => 25,
        ]);

        $this->assertTrue($this->contains($events, 'website:deliver-products --limit=25'));
    }

    public function test_the_batch_size_defaults_to_one_hundred(): void
    {
        $this->assertSame(100, (int) config('sync.delivery.batch_size'));
    }

    /**
     * The scheduler calls the existing command rather than reimplementing
     * selection, so a dry run and a scheduled run behave identically.
     */
    public function test_the_scheduled_delivery_invokes_the_existing_command(): void
    {
        $events = $this->events(['sync.delivery.enabled' => true]);

        $this->assertTrue($this->contains($events, "'artisan' website:deliver-products"));
    }

    // -------------------------------------------------------------- BC import

    public function test_the_item_import_is_scheduled(): void
    {
        $this->assertTrue($this->contains($this->events(), 'bc:import-items'));
    }

    public function test_the_quantity_import_is_scheduled(): void
    {
        $this->assertTrue($this->contains($this->events(), 'bc:import-item-quantities'));
    }

    /**
     * The quantity sweep must not carry a total cap: a capped run would leave
     * most of the endpoint unread, and this sweep is the only thing that sees
     * stock move.
     */
    public function test_the_quantity_sweep_is_not_capped(): void
    {
        foreach ($this->commands($this->events()) as $command) {
            if (str_contains($command, 'bc:import-item-quantities')) {
                $this->assertStringNotContainsString('--top', $command);
                $this->assertStringContainsString('--page-size=', $command);
            }
        }
    }

    public function test_the_quantity_sweep_runs_far_more_often_than_nightly(): void
    {
        $sweeps = array_filter(
            $this->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'bc:import-item-quantities')
                && ! str_contains((string) $event->command, '--full'),
        );

        $this->assertCount(1, $sweeps);
        $this->assertSame('*/15 * * * *', reset($sweeps)->expression);
    }

    public function test_a_nightly_quantity_reconciliation_is_scheduled(): void
    {
        $this->assertTrue($this->contains($this->events(), 'bc:import-item-quantities --full'));
    }

    public function test_the_marketing_text_import_is_scheduled(): void
    {
        $this->assertTrue($this->contains($this->events(), 'bc:import-item-marketing-text'));
    }

    public function test_a_nightly_marketing_text_reconciliation_is_scheduled(): void
    {
        $this->assertTrue($this->contains($this->events(), 'bc:import-item-marketing-text --full'));
    }

    /**
     * The marketing text import is incremental, so a total cap would leave the
     * remainder unfetched and the checkpoint unable to advance.
     */
    public function test_the_marketing_text_import_is_not_capped(): void
    {
        foreach ($this->commands($this->events()) as $command) {
            if (str_contains($command, 'bc:import-item-marketing-text')) {
                $this->assertStringNotContainsString('--top', $command);
                $this->assertStringContainsString('--page-size=', $command);
            }
        }
    }

    /**
     * Copy is edited by hand and changes rarely, so there is no enable flag:
     * a description that silently stops updating is not visibly broken.
     */
    public function test_the_marketing_text_import_cannot_be_turned_off(): void
    {
        $events = $this->events(['sync.import.items.enabled' => false]);

        $this->assertTrue($this->contains($events, 'bc:import-item-marketing-text'));
    }

    public function test_the_document_attachment_sweep_is_scheduled(): void
    {
        $this->assertTrue($this->contains($this->events(), 'bc:import-document-attachments'));
    }

    /**
     * Every run reads the whole endpoint, so there is no incremental-and-full
     * pair and no cap: a capped run could not replace a parent's set, which is
     * the only way a file deleted in Business Central ever leaves.
     */
    public function test_the_document_attachment_sweep_is_not_capped_and_has_no_full_variant(): void
    {
        $sweeps = array_values(array_filter(
            $this->commands($this->events()),
            static fn (string $command): bool => str_contains($command, 'bc:import-document-attachments'),
        ));

        $this->assertCount(1, $sweeps);
        $this->assertStringNotContainsString('--top', $sweeps[0]);
        $this->assertStringNotContainsString('--full', $sweeps[0]);
        $this->assertStringContainsString('--page-size=', $sweeps[0]);
    }

    /**
     * No enable flag, for the reason given on campaigns: a file that silently
     * stops reaching the website is not visibly broken.
     */
    public function test_the_document_attachment_sweep_cannot_be_turned_off(): void
    {
        $events = $this->events(['sync.import.items.enabled' => false]);

        $this->assertTrue($this->contains($events, 'bc:import-document-attachments'));
    }

    public function test_an_import_can_be_turned_off(): void
    {
        $events = $this->events(['sync.import.items.enabled' => false]);

        $this->assertFalse($this->contains($events, 'bc:import-items'));
        $this->assertTrue($this->contains($events, 'bc:import-item-quantities'));
    }

    /**
     * The scheduler starts commands; Horizon does the per-product work. An
     * import job must never be scheduled directly.
     */
    public function test_only_commands_are_scheduled_never_jobs(): void
    {
        foreach ($this->commands($this->events()) as $command) {
            $this->assertStringContainsString("'artisan'", $command);
            $this->assertStringNotContainsString('ImportBcProduct', $command);
            $this->assertStringNotContainsString('DeliverProductToWebsite', $command);
        }
    }

    // ----------------------------------------------------------------- guards

    /**
     * A slow import must not start a second copy of itself.
     */
    public function test_every_entry_has_overlap_protection(): void
    {
        $events = $this->events(['sync.delivery.enabled' => true]);

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, $event->command.' may overlap itself');
            $this->assertTrue($event->onOneServer, $event->command.' may run on several hosts');
        }
    }

    public function test_every_entry_runs_in_the_configured_timezone(): void
    {
        foreach ($this->events() as $event) {
            $this->assertSame(config('sync.schedule.timezone'), $event->timezone);
        }
    }

    public function test_the_contact_import_is_scheduled_incrementally_and_nightly(): void
    {
        $events = $this->events();

        $this->assertTrue($this->contains($events, 'bc:import-contacts --page-size='));
        $this->assertTrue($this->contains($events, 'bc:import-contacts --full --page-size='));
    }

    public function test_the_contact_link_sweep_is_scheduled_and_not_capped(): void
    {
        $events = $this->events();

        $this->assertTrue($this->contains($events, 'bc:import-contact-links --page-size='));

        foreach ($this->commands($events) as $command) {
            if (str_contains($command, 'bc:import-contact-links')) {
                $this->assertStringNotContainsString('--top', $command);
            }
        }
    }

    public function test_the_sales_order_import_is_scheduled_incrementally_and_nightly(): void
    {
        $events = $this->events();

        $this->assertTrue($this->contains($events, 'bc:import-sales-orders --page-size='));
        $this->assertTrue($this->contains($events, 'bc:import-sales-orders --full --page-size='));
    }

    /**
     * Orders wait for their customer, so the order import runs after the
     * customer import within each five-minute cycle.
     */
    public function test_the_sales_order_import_runs_after_the_customer_import(): void
    {
        $minuteOf = function (string $needle): int {
            foreach ($this->events() as $event) {
                if (str_contains((string) $event->command, $needle) && ! str_contains((string) $event->command, '--full')) {
                    return (int) explode('-', explode(' ', $event->expression)[0])[0];
                }
            }

            $this->fail("no entry for {$needle}");
        };

        $this->assertGreaterThan($minuteOf('bc:import-customers'), $minuteOf('bc:import-sales-orders'));
    }

    public function test_the_sales_invoice_import_is_scheduled_incrementally_and_weekly(): void
    {
        $events = $this->events();

        $this->assertTrue($this->contains($events, 'bc:import-sales-invoices --page-size='));

        $full = array_filter(
            $events,
            fn (Event $event): bool => str_contains((string) $event->command, 'bc:import-sales-invoices --full'),
        );

        // Weekly: a posted invoice never changes, so a nightly re-read of
        // every one of them would cost one line request each for nothing.
        $this->assertCount(1, $full);
        $this->assertSame('10 4 * * 0', reset($full)->expression);
    }

    public function test_the_credit_memo_import_is_scheduled_incrementally_and_weekly(): void
    {
        $events = $this->events();

        $this->assertTrue($this->contains($events, 'bc:import-sales-credit-memos --page-size='));

        $full = array_filter(
            $events,
            fn (Event $event): bool => str_contains((string) $event->command, 'bc:import-sales-credit-memos --full'),
        );

        $this->assertCount(1, $full);
        $this->assertSame('25 4 * * 0', reset($full)->expression);
    }

    public function test_no_scheduled_entry_forces_a_redelivery(): void
    {
        foreach ($this->commands($this->events()) as $command) {
            $this->assertStringNotContainsString('--force', $command);
        }
    }

    public function test_the_imports_do_not_share_a_cron_slot(): void
    {
        $expressions = array_map(
            static fn (Event $event): string => $event->expression,
            $this->events(),
        );

        $this->assertSame($expressions, array_unique($expressions), 'imports should not start together');
    }
}
