<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\ContactImporter;
use App\Models\Contact;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Normalising one Business Central contact row.
 */
class ContactImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => '475df605-1a3a-f111-bec2-00224810e61c',
            'number' => 'CT003705',
            'type' => 'Person',
            'displayName' => 'Brooklands Staff Sales',
            'companyNumber' => 'CT003704',
            'companyName' => 'Brooklands Staff  Sales',
            'contactBusinessRelation' => 'Customer',
            'addressLine1' => '',
            'addressLine2' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'postalCode' => '2143',
            'phoneNumber' => '',
            'mobilePhoneNumber' => '021 000 000',
            'email' => 'Office@Brooklands.co.nz',
            'privacyBlocked' => false,
            'lastModifiedDateTime' => '2026-05-13T04:56:29.26Z',
            'organisationalLevelCode' => 'user',
        ], $overrides);
    }

    private function importer(): ContactImporter
    {
        return app(ContactImporter::class);
    }

    public function test_it_stores_a_contact_from_a_business_central_row(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertTrue($result->created);
        $this->assertSame('CT003705', $result->contact->number);
        $this->assertSame('Brooklands Staff Sales', $result->contact->display_name);
        $this->assertSame('Person', $result->contact->type);
        $this->assertSame('CT003704', $result->contact->company_number);
        $this->assertSame('Customer', $result->contact->contact_business_relation);
        $this->assertSame('021 000 000', $result->contact->mobile);
        $this->assertSame('2143', $result->contact->postal_code);
        $this->assertFalse($result->contact->privacy_blocked);
    }

    public function test_the_bc_id_is_the_identity(): void
    {
        $this->importer()->import($this->row());
        $this->importer()->import($this->row(['number' => 'CT999999']));

        $this->assertSame(1, Contact::count());
        $this->assertSame('CT999999', Contact::first()->number);
    }

    public function test_a_row_without_an_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($this->row(['id' => '']));
    }

    public function test_it_keeps_the_millisecond_precision_business_central_sent(): void
    {
        $this->importer()->import($this->row(['lastModifiedDateTime' => '2026-05-13T04:56:29.267Z']));

        $this->assertSame('2026-05-13T04:56:29.267Z', Contact::first()->bc_modified_at->toIso8601ZuluString('millisecond'));
    }

    // ---------------------------------------------------- normalisation

    /**
     * The email becomes the website login and is what the duplicate rule
     * compares, so "Office@" and "office@" must be one address.
     */
    public function test_the_email_is_lowercased(): void
    {
        $result = $this->importer()->import($this->row(['email' => '  Office@Brooklands.co.nz ']));

        $this->assertSame('office@brooklands.co.nz', $result->contact->email);
    }

    public function test_the_organisational_level_is_uppercased(): void
    {
        $result = $this->importer()->import($this->row(['organisationalLevelCode' => ' user ']));

        $this->assertSame('USER', $result->contact->organisational_level_code);
    }

    public function test_privacy_blocked_accepts_the_shapes_business_central_sends(): void
    {
        $this->assertTrue($this->importer()->import($this->row(['privacyBlocked' => true]))->contact->privacy_blocked);
        $this->assertTrue($this->importer()->import($this->row(['id' => 'b', 'privacyBlocked' => 'true']))->contact->privacy_blocked);
        $this->assertFalse($this->importer()->import($this->row(['id' => 'c', 'privacyBlocked' => 'false']))->contact->privacy_blocked);
        $this->assertFalse($this->importer()->import($this->row(['id' => 'd']))->contact->privacy_blocked);
    }

    // ------------------------------------------------- change detection

    public function test_an_unchanged_row_reports_no_change(): void
    {
        $this->importer()->import($this->row());

        $this->assertFalse($this->importer()->import($this->row())->changed());
    }

    public function test_a_changed_field_is_reported(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row(['mobilePhoneNumber' => '027 111 111']));

        $this->assertSame(['mobile'], $result->changedFields);
    }

    /**
     * The customer link comes from another page, so a contact re-import must
     * never wipe it.
     */
    public function test_a_reimport_does_not_touch_the_customer_link(): void
    {
        $this->importer()->import($this->row());
        Contact::first()->update(['customer_bc_id' => '136ebb7f-791e-f111-8340-7ced8d3493eb']);

        $result = $this->importer()->import($this->row(['displayName' => 'Renamed']));

        $this->assertSame('136ebb7f-791e-f111-8340-7ced8d3493eb', $result->contact->fresh()->customer_bc_id);
        $this->assertSame(['display_name'], $result->changedFields);
    }
}
