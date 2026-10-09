<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request)
    {
        $schoolId = $request->user()->school_id;

        if (! $schoolId) {
            return back()->withErrors(['name' => 'Log in as a school admin to add products.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $currency = School::findOrFail($schoolId)->currency;
        $price = $this->minorPrice($data['price'], $currency);

        $category = filled($data['category'] ?? null)
            ? Category::firstOrCreate(['school_id' => $schoolId, 'name' => trim($data['category'])])
            : null;

        Product::create([
            'school_id' => $schoolId,
            'category_id' => $category?->id,
            'name' => $data['name'],
            'price' => $price,
        ]);

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

    private function minorPrice(string|int|float $value, string $currency): int
    {
        $minor = Money::toMinor($value, $currency);

        if ($minor < 1) {
            throw ValidationException::withMessages(['price' => 'The price is too small.']);
        }

        return $minor;
    }
}
