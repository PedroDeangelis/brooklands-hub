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

    public function test_the_imports_do_not_share_a_cron_slot(): void
    {
        $expressions = array_map(
            static fn (Event $event): string => $event->expression,
            $this->events(),
        );

        $this->assertSame($expressions, array_unique($expressions), 'imports should not start together');
    }
}
