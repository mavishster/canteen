<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** When each product category may be bought (school time). Empty = any time. */
class CategoryHoursController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->school($request);

        return view('admin.categories.hours', [
            'school' => $school,
            'categories' => Category::withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $this->school($request);

        $data = $request->validate([
            'sell_from' => ['nullable', 'date_format:H:i'],
            'sell_to' => ['nullable', 'date_format:H:i'],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
        ]);

        $from = $data['sell_from'] ?? null;
        $to = $data['sell_to'] ?? null;

        if (($from === null) !== ($to === null) || ($from !== null && $from >= $to)) {
            throw ValidationException::withMessages([
                'category' => __(':name: set both times, with the start earlier than the end, or leave both empty.', ['name' => $category->name]),
            ]);
        }

        $days = collect($data['days'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

        $category->update([
            'sell_from' => $from,
            'sell_to' => $to,
            'sell_days' => ($days === [] || count($days) === 7) ? null : $days,   // every day is the same as no limit
        ]);

        return redirect()->route('admin.category-hours')->with('status', __(':name: selling hours saved.', ['name' => $category->name]));
    }

    public function clear(Request $request, Category $category)
    {
        $this->school($request);

        $category->update(['sell_from' => null, 'sell_to' => null, 'sell_days' => null]);

        return redirect()->route('admin.category-hours')->with('status', __(':name can be bought at any time again.', ['name' => $category->name]));
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school admin to change selling hours.'));

        return School::findOrFail($request->user()->school_id);
    }
}
