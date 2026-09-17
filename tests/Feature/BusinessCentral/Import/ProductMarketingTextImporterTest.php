<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\ProductMarketingTextImporter;
use App\Models\Product;
use App\Models\ProductMarketingText;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductMarketingTextImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BC_ID = '798c5fa0-3d1c-f111-8341-6045bde65a16';

    /**
     * Marketing copy describes a product, so one has to exist for it to attach
     * to. Cases about the missing-product rule create their own state instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create(['bc_id' => self::BC_ID, 'sku' => 'AA27']);
    }

    /**
     * A row shaped exactly as Business Central returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            '@odata.etag' => 'W/"JzIwOzE0OTA0NDQ0NjgzMTAxOTYyODI5MTswMDsn"',
            'itemId' => self::BC_ID,
            'itemNo' => 'AA27',
            'marketingText' => 'An air stone is also known as an aquarium bubbler. It supplies oxygen.',
            'lastModifiedDateTime' => '2026-04-17T03:38:41.39Z',
        ], $overrides);
    }

    private function importer(): ProductMarketingTextImporter
    {
        return app(ProductMarketingTextImporter::class);
    }

    public function test_it_stores_the_business_central_copy(): void
    {
        $copy = $this->importer()->import($this->row())->marketingText;

        $this->assertSame(self::BC_ID, $copy->bc_id);
        $this->assertSame('AA27', $copy->sku);
        $this->assertSame(
            'An air stone is also known as an aquarium bubbler. It supplies oxygen.',
            $copy->marketing_text,
        );
        $this->assertSame('2026-04-17 03:38:41', $copy->bc_modified_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $copy->bc_modified_at->timezoneName);
    }

    /**
     * The whole row is kept so a later question about what Business Central
     * actually sent can be answered without re-fetching it.
     */
    public function test_it_keeps_the_raw_row(): void
    {
        $copy = $this->importer()->import($this->row())->marketingText;

        $this->assertSame('AA27', $copy->bc_payload['itemNo']);
    }

    public function test_it_derives_the_short_description_from_the_first_sentence(): void
    {
        $copy = $this->importer()->import($this->row())->marketingText;

        $this->assertSame('An air stone is also known as an aquarium bubbler.', $copy->short_description);
    }

    /**
     * A description with no period is one sentence, so the whole of it is the
     * lead. Cutting at an arbitrary length would truncate a word.
     */
    public function test_copy_without_a_period_is_its_own_short_description(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'Single Outlet Power 2500w',
        ]))->marketingText;

        $this->assertSame('Single Outlet Power 2500w', $copy->short_description);
    }

    /**
     * A large part of this catalogue is spec tables rather than prose, and the
     * decimals in them are not sentence ends. The earlier rule cut at the first
     * period of any kind and published "Preferred pH 6." as the summary.
     */
    public function test_a_decimal_point_does_not_end_the_short_description(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'Preferred pH 6.0-8.0 Notes Known as the X-ray Tetra. Adaptable and social.',
        ]))->marketingText;

        $this->assertSame(
            'Preferred pH 6.0-8.0 Notes Known as the X-ray Tetra.',
            $copy->short_description,
        );
    }

    /**
     * Business Central's editor separates sentences with a tag as often as with
     * a space, so a period against a tag still ends the lead sentence.
     */
    public function test_a_period_against_a_tag_ends_the_short_description(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'First line.<br />Second line.',
        ]))->marketingText;

        $this->assertSame('First line.', $copy->short_description);
    }

    /**
     * The short description is a prefix of what is stored, so the two cannot
     * disagree about where the sentence ends.
     */
    public function test_the_short_description_is_a_prefix_of_the_stored_copy(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'Weight 0.31kg. Size 132mm.',
        ]))->marketingText;

        $this->assertStringStartsWith($copy->short_description, $copy->marketing_text);
        $this->assertSame('Weight 0.31kg.', $copy->short_description);
    }

    /**
     * A byte-wise cut at the matched period must not split a multibyte
     * character earlier in the copy.
     */
    public function test_it_cuts_cleanly_after_multibyte_characters(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'Temperature 24-28°C is ideal. More detail.',
        ]))->marketingText;

        $this->assertSame('Temperature 24-28°C is ideal.', $copy->short_description);
    }

    /**
     * Much of this catalogue's copy is a bulleted list, so the first sentence
     * usually ends inside an <li> inside a <ul>. Delivering that prefix as it
     * stands would send unbalanced HTML to the website, where an unclosed list
     * swallows the rest of the page.
     */
    public function test_the_short_description_closes_tags_the_cut_left_open(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => '<strong>Info:</strong><ul><li>Steady output.</li><li>Quiet.</li></ul>',
        ]))->marketingText;

        $this->assertSame(
            '<strong>Info:</strong><ul><li>Steady output.</li></ul>',
            $copy->short_description,
        );
    }

    public function test_the_short_description_closes_a_wrapping_paragraph(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => '<p>Wrapped copy. More detail.</p>',
        ]))->marketingText;

        $this->assertSame('<p>Wrapped copy.</p>', $copy->short_description);
    }

    /**
     * A self-closing tag has nothing to close, so it must not be carried on the
     * stack and emitted as a bogus closing tag.
     */
    public function test_self_closing_tags_are_not_closed_again(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'First line.<br />Second line.',
        ]))->marketingText;

        $this->assertStringNotContainsString('</br>', $copy->short_description);
    }

    public function test_missing_copy_is_stored_as_empty_rather_than_null(): void
    {
        $copy = $this->importer()->import($this->row(['marketingText' => null]))->marketingText;

        $this->assertSame('', $copy->marketing_text);
        $this->assertSame('', $copy->short_description);
    }

    /**
     * The copy reaches a public website verbatim, so it is cleaned on the way
     * in. Cleaning at the boundary means what is stored is what is delivered.
     */
    public function test_it_strips_script_from_the_copy(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'Safe copy.<script>alert("x")</script>',
        ]))->marketingText;

        $this->assertStringNotContainsString('<script', $copy->marketing_text);
        $this->assertStringNotContainsString('alert(', $copy->marketing_text);
        $this->assertStringContainsString('Safe copy.', $copy->marketing_text);
    }

    /**
     * Business Central's editor produces these heavily, and they carry the
     * intended line breaks, so they must survive.
     */
    public function test_it_keeps_the_formatting_tags_business_central_sends(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => 'First line.<br /><strong>Bold</strong> and <em>italic</em>.',
        ]))->marketingText;

        $this->assertStringContainsString('<br', $copy->marketing_text);
        $this->assertStringContainsString('<strong>Bold</strong>', $copy->marketing_text);
        $this->assertStringContainsString('<em>italic</em>', $copy->marketing_text);
    }

    public function test_it_strips_presentational_attributes(): void
    {
        $copy = $this->importer()->import($this->row([
            'marketingText' => '<p style="color:red" class="x" id="y">Copy.</p>',
        ]))->marketingText;

        $this->assertStringNotContainsString('style=', $copy->marketing_text);
        $this->assertStringNotContainsString('class=', $copy->marketing_text);
        $this->assertStringNotContainsString('id=', $copy->marketing_text);
    }

    /**
     * The same GUID must update in place: re-importing is how an edit in
     * Business Central reaches us, and a second row would leave two answers.
     */
    public function test_reimporting_updates_in_place(): void
    {
        $importer = $this->importer();

        $importer->import($this->row());
        $result = $importer->import($this->row(['marketingText' => 'Rewritten copy.']));

        $this->assertSame(1, ProductMarketingText::query()->count());
        $this->assertFalse($result->created);
        $this->assertSame('Rewritten copy.', $result->marketingText->marketing_text);
    }

    public function test_it_reports_the_copy_fields_that_moved(): void
    {
        $importer = $this->importer();

        $importer->import($this->row());
        $result = $importer->import($this->row(['marketingText' => 'Rewritten copy.']));

        $this->assertTrue($result->changed());
        $this->assertSame(['marketing_text', 'short_description'], $result->changedFields);
    }

    public function test_an_unchanged_row_reports_no_change(): void
    {
        $importer = $this->importer();

        $importer->import($this->row());
        $result = $importer->import($this->row());

        $this->assertFalse($result->changed());
        $this->assertSame([], $result->changedFields);
    }

    /**
     * Business Central touches this timestamp when the item record is saved,
     * not only when the copy is edited. Treating that as a change would open a
     * delivery carrying identical copy every time an unrelated field moved.
     */
    public function test_a_moved_timestamp_alone_is_not_a_change(): void
    {
        $importer = $this->importer();

        $importer->import($this->row());
        $result = $importer->import($this->row([
            'lastModifiedDateTime' => '2026-08-01T00:00:00Z',
        ]));

        $this->assertFalse($result->changed());
        $this->assertSame([], $result->changedFields);
    }

    /**
     * The etag moves whenever Business Central rewrites the record, so counting
     * the stored payload would make every row look changed.
     */
    public function test_a_moved_etag_alone_is_not_a_change(): void
    {
        $importer = $this->importer();

        $importer->import($this->row());
        $result = $importer->import($this->row(['@odata.etag' => 'W/"different"']));

        $this->assertFalse($result->changed());
    }

    public function test_a_new_row_counts_as_created_and_changed(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertTrue($result->created);
        $this->assertTrue($result->changed());
    }

    /**
     * Copy can arrive before the item it describes. Storing it anyway would
     * leave a row nothing reads and no later import would reconcile.
     */
    public function test_it_skips_a_row_whose_product_is_not_imported_yet(): void
    {
        $result = $this->importer()->import($this->row([
            'itemId' => '00000000-0000-0000-0000-000000000999',
            'itemNo' => 'NOPE',
        ]));

        $this->assertTrue($result->skipped);
        $this->assertFalse($result->changed());
        $this->assertNull($result->marketingText);
        $this->assertSame(0, ProductMarketingText::query()->count());
    }

    /**
     * Matching is by Business Central id only: a SKU match would be a guess,
     * and guessing here would attach one item's description to another.
     */
    public function test_it_does_not_match_on_sku(): void
    {
        $result = $this->importer()->import($this->row([
            'itemId' => '00000000-0000-0000-0000-000000000999',
        ]));

        $this->assertTrue($result->skipped);
    }

    public function test_it_rejects_a_row_with_no_item_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($this->row(['itemId' => '']));
    }
}
