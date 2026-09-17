<?php

namespace App\Http\Controllers;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\DocumentAttachmentsQuery;
use App\BusinessCentral\SalesCreditMemosQuery;
use App\BusinessCentral\SalesInvoicesQuery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Streams documents out of Business Central: attachments and rendered PDFs.
 *
 * Reached only through the bc.signed middleware, like the pictures.
 *
 * Nothing here is cached, and deliberately. A PDF is rendered on demand, is
 * read once or twice ever, and a stale copy would be a document someone acts
 * on; see the note on MediaCache.
 */
class BcDocumentController extends Controller
{
    /**
     * Private, and short. The response is a customer's own invoice, so it must
     * not be held by a shared cache, and a browser may reuse it only briefly.
     */
    private const CACHE_CONTROL = 'private, max-age=3600';

    /**
     * The extensions served with their sniffed type rather than as a PDF.
     */
    private const INLINE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public function __construct(private readonly BusinessCentralClient $client) {}

    /**
     * One document attachment, streamed under its original filename.
     */
    public function attachment(string $attachmentId): Response
    {
        $metadata = $this->client->getStandard(
            DocumentAttachmentsQuery::metadataPath($attachmentId),
        );

        $mediaReadLink = $metadata[DocumentAttachmentsQuery::MEDIA_READ_LINK] ?? null;

        if (! is_string($mediaReadLink) || $mediaReadLink === '') {
            abort(404);
        }

        $filename = is_string($metadata['fileName'] ?? null) && $metadata['fileName'] !== ''
            ? $metadata['fileName']
            : 'document.pdf';

        $media = $this->client->getMedia($mediaReadLink);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // An attachment is usually a PDF but not always, and an image shown as
        // one renders as a broken document. Only the image types are served
        // with their own type; everything else keeps the PDF default the
        // website's viewer expects.
        $contentType = in_array($extension, self::INLINE_IMAGE_EXTENSIONS, true)
            ? $media->contentTypeOr()
            : 'application/pdf';

        return $this->inline($media->body, $contentType, $filename);
    }

    /**
     * One posted sales invoice, as its rendered PDF.
     */
    public function salesInvoicePdf(string $invoiceId): Response
    {
        $mediaReadLink = $this->pdfMediaReadLink(SalesInvoicesQuery::pdfMetadataPath($invoiceId))
            ?? $this->redressedInvoiceMediaReadLink($invoiceId);

        if ($mediaReadLink === null) {
            abort(404, 'PDF not available.');
        }

        return $this->inline(
            $this->client->getMedia($mediaReadLink)->body,
            'application/pdf',
            'invoice-'.$invoiceId.'.pdf',
        );
    }

    /**
     * One posted sales credit memo, as its rendered PDF.
     *
     * The id the website holds is the posted page's own id, which is not always
     * the GUID the standard entity answers to, so it is translated through
     * documentApiId first — the rule documented on SalesCreditMemosQuery.
     */
    public function creditMemoPdf(string $creditMemoId): Response
    {
        $mediaReadLink = $this->pdfMediaReadLink(
            SalesCreditMemosQuery::pdfMetadataPath($this->creditMemoDocumentApiId($creditMemoId)),
        );

        if ($mediaReadLink === null) {
            abort(404, 'PDF not available.');
        }

        return $this->inline(
            $this->client->getMedia($mediaReadLink)->body,
            'application/pdf',
            'credit-memo-'.$creditMemoId.'.pdf',
        );
    }

    /**
     * The standard GUID a credit memo's PDF is addressed by.
     *
     * Falls back to the requested id when the lookup fails, so a memo whose
     * ids do agree still resolves rather than 404ing on a failed translation.
     */
    private function creditMemoDocumentApiId(string $creditMemoId): string
    {
        try {
            $posted = $this->client->getCustom(
                SalesCreditMemosQuery::PUBLISHER,
                SalesCreditMemosQuery::GROUP,
                SalesCreditMemosQuery::VERSION,
                SalesCreditMemosQuery::postedHeaderPath($creditMemoId),
                ['$select' => 'id,documentApiId'],
            );

            return SalesCreditMemosQuery::documentApiId($posted) ?: $creditMemoId;
        } catch (BusinessCentralException) {
            return $creditMemoId;
        }
    }

    /**
     * The mediaReadLink on a PDF metadata response, or null when there is none.
     *
     * A missing link is an ordinary answer — Business Central has not rendered
     * a PDF for this document — so it is returned rather than thrown.
     */
    private function pdfMediaReadLink(string $metadataPath): ?string
    {
        $metadata = $this->client->getStandard($metadataPath);

        $link = $metadata['pdfDocumentContent@odata.mediaReadLink'] ?? null;

        return is_string($link) && $link !== '' ? $link : null;
    }

    /**
     * The PDF of an invoice the standard API does not key by the website's id.
     *
     * The website stores the posted header's SystemId, but an invoice posted
     * from a draft (credit-and-recharge, manual invoices) is keyed in the
     * standard salesInvoices API by the draft's id, so the direct lookup finds
     * nothing. The same document is re-addressed here via its invoice number.
     *
     * Best-effort: any failure returns null and the caller answers the original
     * 404, because this path exists to rescue a lookup that already failed.
     */
    private function redressedInvoiceMediaReadLink(string $invoiceId): ?string
    {
        try {
            $posted = $this->client->getCustom(
                SalesInvoicesQuery::PUBLISHER,
                SalesInvoicesQuery::GROUP,
                SalesInvoicesQuery::VERSION,
                SalesInvoicesQuery::postedHeaderPath($invoiceId),
                ['$select' => 'number'],
            );

            $number = is_string($posted['number'] ?? null) ? $posted['number'] : '';

            if ($number === '') {
                return null;
            }

            $match = $this->client->getStandard(
                SalesInvoicesQuery::STANDARD_ENTITY_SET,
                SalesInvoicesQuery::idForNumber($number),
            );

            $resolvedId = $match['value'][0]['id'] ?? null;

            if (! is_string($resolvedId) || $resolvedId === '' || $resolvedId === $invoiceId) {
                return null;
            }

            Log::info('bc-sales-invoice-pdf: resolved draft-posted invoice via number', [
                'requested_id' => $invoiceId,
                'resolved_id' => $resolvedId,
                'number' => $number,
            ]);

            return $this->pdfMediaReadLink(SalesInvoicesQuery::pdfMetadataPath($resolvedId));
        } catch (BusinessCentralException) {
            return null;
        }
    }

    /**
     * A document response the browser shows rather than downloads.
     */
    private function inline(string $body, string $contentType, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
    }
}
