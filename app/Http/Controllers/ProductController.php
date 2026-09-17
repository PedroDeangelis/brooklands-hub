<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Products\ProductFilter;
use App\Products\ProductWebsiteStateBuilder;
use App\Products\WebsiteEligibility;
use App\Sync\DeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly WebsiteEligibility $eligibility,
        private readonly ProductWebsiteStateBuilder $websiteState,
    ) {}

    public function index(Request $request): View
    {
        $filter = ProductFilter::fromRequest($request);

        $products = $filter->apply(Product::query(), $this->eligibility)
            ->with('itemsSyncRecord')
            ->orderBy('sku')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'filter' => $filter,
            'eligibility' => $this->eligibility,
        ]);
    }

    public function show(Product $product): View
    {
        $websiteState = $this->websiteState->build($product);
        $syncRecord = $product->itemsSyncRecord()->first();

        return view('products.show', [
            'product' => $product,
            'eligibility' => $websiteState->eligibility,
            'websiteState' => $websiteState,
            'syncRecord' => $syncRecord,
            'deliveryStatus' => DeliveryStatus::for($product, $this->eligibility, $syncRecord),
        ]);
    }
}
