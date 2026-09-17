<?php

namespace App\Http\Controllers;

use App\Contacts\ContactEligibility;
use App\Contacts\ContactExclusionReason;
use App\Contacts\ContactFilter;
use App\Models\Contact;
use App\Sync\ContactSyncLedger;
use App\Sync\DeliveryStatus;
use App\Website\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * The contact list and detail pages.
 *
 * Business Central calls these Contacts; the website turns each qualifying
 * one into a user account. This dashboard shows both halves: whether the
 * contact qualifies and why not, and whether the website actually holds it.
 *
 * Read-only, like the rest of the dashboard: nothing here delivers, queues or
 * changes anything.
 */
class ContactController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ContactSyncLedger $ledger,
        private readonly ContactEligibility $eligibility,
        private readonly WebsiteClient $website,
    ) {}

    public function index(Request $request): View
    {
        $filter = ContactFilter::fromRequest($request);

        $contacts = $filter->apply(Contact::query(), $this->eligibility)
            ->with(['websiteSyncRecord', 'customer'])
            ->orderBy('contacts.number')
            ->orderBy('contacts.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('contacts.index', [
            'contacts' => $contacts,
            'filter' => $filter,
            'eligibility' => $this->eligibility,
            'reasons' => ContactExclusionReason::cases(),
            'onWebsite' => $this->onWebsiteFor($contacts->getCollection()),
        ]);
    }

    public function show(Contact $contact): View
    {
        $contact->load('customer');

        $syncRecord = $contact->websiteSyncRecord()->first();

        return view('contacts.show', [
            'contact' => $contact,
            'eligibility' => $this->eligibility->for($contact),
            'syncRecord' => $syncRecord,
            'deliveryStatus' => DeliveryStatus::forContact($contact, $this->eligibility, $syncRecord),
            'payload' => $this->ledger->payloadFor($contact),
            'plan' => $this->ledger->plan($contact),
            'onWebsite' => $this->onWebsite($contact),
        ]);
    }

    /**
     * What the website holds for a page of contacts, in one request.
     *
     * Null means the website could not be reached — shown as "unknown" rather
     * than as "missing", because those are very different things.
     *
     * @param  Collection<int, Contact>  $contacts
     * @return array<string, array{wp_id: int, status: string}|null>|null
     */
    private function onWebsiteFor($contacts): ?array
    {
        if ($contacts->isEmpty()) {
            return [];
        }

        try {
            return $this->website->status(
                ContactSyncLedger::ENTITY_CONTACT,
                $contacts->pluck('bc_id')->all(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the website actually holds for this contact, right now.
     *
     * The ledger can only say what the website confirmed at the moment of
     * delivery; asking the website directly is the only answer that cannot go
     * stale. For a contact this is also how the one deliberate contradiction
     * shows: an excluded contact whose user still exists.
     *
     * @return array{wp_id: int, status: string}|null|false False when absent.
     */
    private function onWebsite(Contact $contact): array|false|null
    {
        try {
            $records = $this->website->status(
                ContactSyncLedger::ENTITY_CONTACT,
                [$contact->bc_id],
            );
        } catch (Throwable) {
            return null;
        }

        return $records[$contact->bc_id] ?? false;
    }
}
