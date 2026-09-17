<?php

namespace Tests\Feature\Http;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_loads(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Total Products');
    }

    public function test_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_shows_the_total_product_count(): void
    {
        Product::factory()->count(3)->create();

        $this->get(route('dashboard'))->assertSeeInOrder(['Total Products', '3']);
    }

    public function test_counts_sync_records_by_status(): void
    {
        $pending = Product::factory()->count(2)->create();
        $synced = Product::factory()->create();
        $failed = Product::factory()->create();

        foreach ($pending as $product) {
            SyncRecord::factory()->create(['bc_id' => $product->bc_id, 'status' => SyncStatus::Pending]);
        }
        SyncRecord::factory()->synced()->create(['bc_id' => $synced->bc_id]);
        SyncRecord::factory()->failed()->create(['bc_id' => $failed->bc_id]);

        $response = $this->get(route('dashboard'));

        $response->assertSeeInOrder(['Pending', '2']);
        $response->assertSeeInOrder(['Synced', '1']);
        $response->assertSeeInOrder(['Failed', '1']);
    }

    public function test_counts_blocked_and_retired_products_as_excluded(): void
    {
        Product::factory()->create(['blocked' => true]);
        Product::factory()->create(['item_category_id' => 'RETIRE']);
        Product::factory()->count(3)->create(['blocked' => false, 'item_category_id' => 'TOYS']);

        $this->get(route('dashboard'))->assertSeeInOrder(['Excluded', '2']);
    }

    public function test_ignores_sync_records_from_other_channels_in_the_counts(): void
    {
        $product = Product::factory()->create();
        SyncRecord::factory()->create([
            'bc_id' => $product->bc_id,
            'channel' => 'customers',
            'status' => SyncStatus::Pending,
        ]);

        $this->get(route('dashboard'))->assertSeeInOrder(['Pending', '0']);
    }
}
