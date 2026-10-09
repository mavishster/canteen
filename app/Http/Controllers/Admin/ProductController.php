<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with(['category:id,name', 'school:id,currency'])
            ->orderBy('name')
            ->get();

        $categories = Category::orderBy('name')->pluck('name');
        $currency = $request->user()->school_id
            ? School::findOrFail($request->user()->school_id)->currency
            : 'USD';

        return view('admin.products.index', compact('products', 'categories', 'currency'));
    }

    public function store(Request $request, StockService $stock)
    {
        $schoolId = $request->user()->school_id;

        if (! $schoolId) {
            return back()->withErrors(['name' => 'Log in as a school admin to add products.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $currency = School::findOrFail($schoolId)->currency;
        $price = $this->minorPrice($data['price'], $currency);

        $category = filled($data['category'] ?? null)
            ? Category::firstOrCreate(['school_id' => $schoolId, 'name' => trim($data['category'])])
            : null;

        // A starting stock figure turns tracking on; leaving it empty means "not tracked"
        $tracked = filled($data['stock'] ?? null);

        $product = Product::create([
            'school_id' => $schoolId,
            'category_id' => $category?->id,
            'name' => $data['name'],
            'price' => $price,
            'track_stock' => $tracked,
            'stock' => 0,
        ]);

        if ($tracked && (int) $data['stock'] > 0) {
            $stock->receive($product, (int) $data['stock'], $request->user()->id, 'Opening stock');
        }

        return redirect()->route('admin.products')->with('status', 'Product added.');
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $currency = School::findOrFail($product->school_id)->currency;
        $product->update(['price' => $this->minorPrice($data['price'], $currency)]);

        return redirect()->route('admin.products')->with('status', 'Price updated.');
    }

    public function toggle(Product $product)
    {
        $product->update(['is_active' => ! $product->is_active]);

        return redirect()->route('admin.products')
            ->with('status', $product->is_active ? 'Product shown on the till.' : 'Product hidden from the till.');
    }

    // ----------------------------------------------------------------- stock

    public function tracking(Product $product)
    {
        $product->update(['track_stock' => ! $product->track_stock]);

        return redirect()->route('admin.products')->with(
            'status',
            $product->track_stock ? 'Stock tracking turned on. Add stock with Receive or Set.' : 'Stock tracking turned off.'
        );
    }

    public function receive(Request $request, Product $product, StockService $stock)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $stock->receive($product, (int) $data['quantity'], $request->user()->id, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['stock' => $e->getMessage()]);
        }

        return redirect()->route('admin.products')->with('status', "Received {$data['quantity']} × {$product->name}.");
    }

    public function count(Request $request, Product $product, StockService $stock)
    {
        $data = $request->validate([
            'counted' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $stock->count($product, (int) $data['counted'], $request->user()->id, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['stock' => $e->getMessage()]);
        }

        return redirect()->route('admin.products')->with('status', "{$product->name}: stock set to {$data['counted']}.");
    }

    public function movements()
    {
        Paginator::useBootstrapFive();

        $movements = StockMovement::with(['product:id,name', 'user:id,name', 'school:id,timezone'])
            ->latest('id')
            ->paginate(30);

        return view('admin.stock.index', compact('movements'));
    }

    private function minorPrice(string|int|float $value, string $currency): int
    {
        $minor = Money::toMinor($value, $currency);

        if ($minor < 1) {
            throw ValidationException::withMessages(['price' => 'The price is too small.']);
        }

        return $minor;
    }
}
