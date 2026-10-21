<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\StudentBan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The list of product categories. Products choose from it: nobody types a category name again. */
class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->schoolId($request);

        $categories = Category::withCount('products')->orderBy('name')->get();

        $bans = StudentBan::whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');

        return view('admin.categories.index', compact('categories', 'bans'));
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']));

        $this->assertNameFree($name, null);

        Category::create(['school_id' => $schoolId, 'name' => $name]);

        return redirect()->route('admin.categories')->with('status', __('Category “:name” added.', ['name' => $name]));
    }

    public function rename(Request $request, Category $category)
    {
        $this->schoolId($request);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']));

        $this->assertNameFree($name, $category->id);

        $category->update(['name' => $name]);

        return redirect()->route('admin.categories')->with('status', __('Category renamed.'));
    }

    public function toggle(Request $request, Category $category)
    {
        $this->schoolId($request);

        $category->update(['is_active' => ! $category->is_active]);

        return redirect()->route('admin.categories')->with(
            'status',
            $category->is_active ? 'Category is available again.' : 'Category hidden: it can no longer be chosen for new products.'
        );
    }

    /** Moves every product and every ban of this category into another one, then removes this one. */
    public function merge(Request $request, Category $category)
    {
        $this->schoolId($request);

        $data = $request->validate(['into_id' => ['required', 'integer']]);

        if ((int) $data['into_id'] === (int) $category->id) {
            return back()->withErrors(['category' => __('Choose a different category to merge into.')]);
        }

        $target = Category::find($data['into_id']);   // limited to this school by the tenant scope

        if (! $target) {
            return back()->withErrors(['category' => __('That category does not exist.')]);
        }

        DB::transaction(function () use ($category, $target) {
            Product::where('category_id', $category->id)->update(['category_id' => $target->id]);

            // A student already banned from the target keeps one ban, not two
            foreach (StudentBan::where('category_id', $category->id)->get() as $ban) {
                $alreadyBanned = StudentBan::where('student_id', $ban->student_id)
                    ->where('category_id', $target->id)
                    ->exists();

                $alreadyBanned ? $ban->delete() : $ban->update(['category_id' => $target->id]);
            }

            $category->delete();
        });

        return redirect()->route('admin.categories')->with('status', __('“:name” was merged into “:name2”.', ['name' => $category->name, 'name2' => $target->name]));
    }

    public function destroy(Request $request, Category $category)
    {
        $this->schoolId($request);

        if (Product::where('category_id', $category->id)->exists() || StudentBan::where('category_id', $category->id)->exists()) {
            return back()->withErrors(['category' => __('This category is in use. Merge it into another one instead.')]);
        }

        $category->delete();

        return redirect()->route('admin.categories')->with('status', __('Category deleted.'));
    }

    // ------------------------------------------------------------------ helpers

    private function schoolId(Request $request): int
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school admin to manage categories.'));

        return (int) $request->user()->school_id;
    }

    /** Refuses a name that is only another way of writing a category that already exists. */
    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $key = Category::normalizedName($name);

        foreach (Category::all() as $other) {
            if ($other->id !== $exceptId && Category::normalizedName($other->name) === $key) {
                throw ValidationException::withMessages([
                    'name' => __('A category like “:name” already exists. Use that one.', ['name' => $other->name]),
                ]);
            }
        }
    }
}
