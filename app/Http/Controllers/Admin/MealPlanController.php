<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\School;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MealPlanController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->school($request);
        $mealTypes = MealType::withCount('plans')->orderBy('name')->get();
        $plans = MealPlan::with('mealType')->orderBy('meal_type_id')->orderBy('grade')->orderBy('duration_months')->get();
        $currency = $school->currency;

        return view('admin.meal-plans.index', compact('mealTypes', 'plans', 'currency'));
    }

    public function storeType(Request $request)
    {
        $school = $this->school($request);
        $request->merge(['code' => Str::lower(trim((string) $request->input('code', '')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'alpha_dash', 'max:50',
                Rule::unique('meal_types')->where('school_id', $school->id)],
            'service_starts_at' => ['nullable', 'date_format:H:i'],
            'service_ends_at' => ['nullable', 'date_format:H:i'],
            'available_days' => ['nullable', 'array'],
            'available_days.*' => ['integer', 'between:1,7'],
        ]);

        $start = $data['service_starts_at'] ?? null;
        $end = $data['service_ends_at'] ?? null;
        if (($start === null) !== ($end === null) || ($start !== null && $start >= $end)) {
            throw ValidationException::withMessages([
                'service_starts_at' => 'Set both collection times, with the start earlier than the end.',
            ]);
        }

        MealType::create([
            'school_id' => $school->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'service_starts_at' => $start,
            'service_ends_at' => $end,
            'available_days' => isset($data['available_days'])
                ? collect($data['available_days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all()
                : null,
            'is_active' => true,
        ]);

        return redirect()->route('admin.meal-plans')->with('status', 'Meal type added.');
    }

    public function store(Request $request)
    {
        $school = $this->school($request);
        $data = $request->validate([
            'meal_type_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
            'duration_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'entitlement_quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $mealType = MealType::where('school_id', $school->id)
            ->where('is_active', true)
            ->findOrFail($data['meal_type_id']);
        $price = Money::toMinor($data['price'], $school->currency);

        if ($price <= 0) {
            throw ValidationException::withMessages(['price' => 'The price must be at least one minor currency unit.']);
        }

        MealPlan::create([
            'school_id' => $school->id,
            'meal_type_id' => $mealType->id,
            'name' => $data['name'],
            'grade' => $data['grade'] ?? null,
            'duration_months' => $data['duration_months'],
            'entitlement_quantity' => $data['entitlement_quantity'],
            'price' => $price,
            'is_active' => true,
        ]);

        return redirect()->route('admin.meal-plans')->with('status', 'Subscription plan added.');
    }

    public function toggle(Request $request, MealPlan $mealPlan)
    {
        $school = $this->school($request);
        abort_unless((int) $mealPlan->school_id === (int) $school->id, 404);
        $mealPlan->update(['is_active' => ! $mealPlan->is_active]);

        return redirect()->route('admin.meal-plans')->with('status', 'Subscription plan status updated.');
    }

    public function toggleType(Request $request, MealType $mealType)
    {
        $school = $this->school($request);
        abort_unless((int) $mealType->school_id === (int) $school->id, 404);
        $mealType->update(['is_active' => ! $mealType->is_active]);

        return redirect()->route('admin.meal-plans')->with('status', 'Meal type status updated.');
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school admin to manage meal plans.');

        return School::findOrFail($request->user()->school_id);
    }
}
