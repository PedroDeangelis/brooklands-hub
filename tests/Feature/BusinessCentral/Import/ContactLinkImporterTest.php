<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\ContactLinkImporter;
use App\Models\Contact;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Attaching a customer to a contact from a customerContacts row.
 */
class ContactLinkImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CUSTOMER = '136ebb7f-791e-f111-8340-7ced8d3493eb';

    private function importer(): ContactLinkImporter
    {
        return app(ContactLinkImporter::class);
    }

    public function test_it_links_a_contact_to_its_customer(): void
    {
        $contact = Contact::factory()->create();

        $result = $this->importer()->link($contact->bc_id, self::CUSTOMER);

        $this->assertFalse($result->skipped);
        $this->assertTrue($result->changed);
        $this->assertSame(self::CUSTOMER, $contact->fresh()->customer_bc_id);
    }

    public function test_an_unchanged_link_reports_no_change(): void
    {
        $contact = Contact::factory()->create(['customer_bc_id' => self::CUSTOMER]);

        $this->assertFalse($this->importer()->link($contact->bc_id, self::CUSTOMER)->changed);
    }

    public function test_a_contact_that_has_not_arrived_is_skipped(): void
    {
        $this->assertTrue($this->importer()->link('00000000-0000-0000-0000-000000000000', self::CUSTOMER)->skipped);
        $this->assertTrue($this->importer()->link('', self::CUSTOMER)->skipped);
    }

    public function test_an_empty_customer_unlinks(): void
    {
        $contact = Contact::factory()->create(['customer_bc_id' => self::CUSTOMER]);

        $result = $this->importer()->link($contact->bc_id, '');

        $this->assertTrue($result->changed);
        $this->assertNull($contact->fresh()->customer_bc_id);
    }
}
