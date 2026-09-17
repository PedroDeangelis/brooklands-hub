<?php

namespace Tests\Feature\Http;

use App\DocumentAttachments\AttachmentParentType;
use App\DocumentAttachments\DocumentAttachmentFilter;
use App\Models\Customer;
use App\Models\DocumentAttachment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The attachment list page.
 *
 * Read-only, so what is worth asserting is what the page lets someone see: the
 * file, the record it hangs off, and the one state a person has to act on — a
 * file whose parent is not here.
 */
class DocumentAttachmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_list_renders(): void
    {
        $product = Product::factory()->create(['sku' => 'ABC123', 'name' => 'Pond Pump']);

        DocumentAttachment::factory()->forProduct($product)->create(['file_name' => 'safety-data-sheet.pdf']);

        $this->actingAs($this->user)
            ->get(route('document-attachments.index'))
            ->assertOk()
            ->assertSee('safety-data-sheet.pdf')
            ->assertSee('ABC123');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->actingAs($this->user)
            ->get(route('document-attachments.index'))
            ->assertOk()
            ->assertSee('No document attachments have been imported yet.');
    }

    public function test_it_requires_a_signed_in_user(): void
    {
        $this->get(route('document-attachments.index'))->assertRedirect(route('login'));
    }

    public function test_it_links_a_file_to_the_record_it_hangs_off(): void
    {
        $customer = Customer::factory()->create();

        DocumentAttachment::factory()->forCustomer($customer)->create();

        $this->actingAs($this->user)
            ->get(route('document-attachments.index'))
            ->assertOk()
            ->assertSee(route('customers.show', $customer));
    }

    /**
     * The one state worth acting on: the file points at a record this
     * application does not hold, so nothing will ever deliver it.
     */
    public function test_it_flags_a_file_whose_parent_is_missing(): void
    {
        DocumentAttachment::factory()->create(['parent_bc_id' => 'dddddddd-0000-0000-0000-000000000009']);

        $this->actingAs($this->user)
            ->get(route('document-attachments.index'))
            ->assertOk()
            ->assertSee('Parent missing');
    }

    public function test_it_filters_by_what_the_file_is_attached_to(): void
    {
        DocumentAttachment::factory()->forProduct()->create(['file_name' => 'product-sheet.pdf']);
        DocumentAttachment::factory()->forSalesOrder()->create(['file_name' => 'order-note.pdf']);

        $this->actingAs($this->user)
            ->get(route('document-attachments.index', [
                DocumentAttachmentFilter::PARAM_PARENT_TYPE => AttachmentParentType::SalesOrder->value,
            ]))
            ->assertOk()
            ->assertSee('order-note.pdf')
            ->assertDontSee('product-sheet.pdf');
    }

    public function test_it_filters_to_files_whose_parent_is_missing(): void
    {
        DocumentAttachment::factory()->forProduct()->create(['file_name' => 'placed.pdf']);
        DocumentAttachment::factory()->create(['file_name' => 'orphan.pdf']);

        $this->actingAs($this->user)
            ->get(route('document-attachments.index', [DocumentAttachmentFilter::PARAM_ORPHANED => '1']))
            ->assertOk()
            ->assertSee('orphan.pdf')
            ->assertDontSee('placed.pdf');
    }

    public function test_it_searches_by_file_name(): void
    {
        DocumentAttachment::factory()->forProduct()->create(['file_name' => 'safety-data-sheet.pdf']);
        DocumentAttachment::factory()->forProduct()->create(['file_name' => 'installation-manual.pdf']);

        $this->actingAs($this->user)
            ->get(route('document-attachments.index', [DocumentAttachmentFilter::PARAM_SEARCH => 'safety']))
            ->assertOk()
            ->assertSee('safety-data-sheet.pdf')
            ->assertDontSee('installation-manual.pdf');
    }

    /**
     * The link goes at the signed route that streams the file from Business
     * Central, which is the same thing the website links its visitors at.
     */
    public function test_it_links_the_file_at_the_streaming_route(): void
    {
        $attachment = DocumentAttachment::factory()->forProduct()->create();

        $this->actingAs($this->user)
            ->get(route('document-attachments.index'))
            ->assertOk()
            ->assertSee(url('/bc-doc/'.$attachment->bc_id));
    }
}
