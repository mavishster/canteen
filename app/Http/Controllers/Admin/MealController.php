<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MealException;
use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Services\MealSubscriptionService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MealController extends Controller
{
    public function index(Request $request)
    {
        $school = $this->school($request);

        return view('admin.meals.index', [
            'types' => MealType::orderBy('name')->get(),
            'plans' => MealPlan::with('mealType')->orderBy('meal_type_id')->orderBy('duration_months')->get(),
            'subscriptions' => MealSubscription::with(['student:id,name,student_code', 'plan:id,name', 'mealType:id,name'])
                ->latest('id')->limit(30)->get(),
            'currency' => $school->currency,
            'sellKey' => (string) Str::uuid(),   // one key per page load: a double click cannot charge twice
        ]);
    }

    public function storeType(Request $request)
    {
        $school = $this->school($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'service_starts_at' => ['nullable', 'date_format:H:i'],
            'service_ends_at' => ['nullable', 'date_format:H:i'],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
        ]);

        $start = $data['service_starts_at'] ?? null;
        $end = $data['service_ends_at'] ?? null;

        if (($start === null) !== ($end === null) || ($start !== null && $start >= $end)) {
            throw ValidationException::withMessages([
                'service_starts_at' => __('Set both collection times, with the start earlier than the end, or leave both empty.'),
            ]);
        }

        MealType::create([
            'school_id' => $school->id,
            'code' => $this->uniqueCode($school, $data['name']),
            'name' => $data['name'],
            'service_starts_at' => $start,
            'service_ends_at' => $end,
            'available_days' => ! empty($data['days'])
                ? collect($data['days'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all()
                : null,
            'is_active' => true,
        ]);

        return redirect()->route('admin.meals')->with('status', __('Meal added.'));
    }

    public function storePlan(Request $request)
    {
        $school = $this->school($request);

        $data = $request->validate([
            'meal_type_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'min_grade' => ['nullable', 'integer', 'between:0,12'],
            'max_grade' => ['nullable', 'integer', 'between:0,12'],
            'duration_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'price' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        if (isset($data['min_grade'], $data['max_grade']) && $data['max_grade'] < $data['min_grade']) {
            throw ValidationException::withMessages(['max_grade' => __('The last grade cannot be lower than the first.')]);
        }

        $mealType = MealType::where('is_active', true)->findOrFail($data['meal_type_id']);
        $price = Money::toMinor($data['price'], $school->currency);

        if ($price < 1) {
            throw ValidationException::withMessages(['price' => __('The price is too small.')]);
        }

        MealPlan::create([
            'school_id' => $school->id,
            'meal_type_id' => $mealType->id,
            'name' => $data['name'],
            'min_grade' => $data['min_grade'] ?? null,
            'max_grade' => $data['max_grade'] ?? null,
            'duration_months' => $data['duration_months'],
            'price' => $price,
            'is_active' => true,
        ]);

        return redirect()->route('admin.meals')->with('status', __('Plan added.'));
    }

    public function toggleType(MealType $mealType)
    {
        $mealType->update(['is_active' => ! $mealType->is_active]);

        return redirect()->route('admin.meals')->with('status', $mealType->is_active ? 'Meal switched on.' : 'Meal switched off.');
    }

    public function togglePlan(MealPlan $mealPlan)
    {
        $mealPlan->update(['is_active' => ! $mealPlan->is_active]);

        return redirect()->route('admin.meals')->with('status', $mealPlan->is_active ? 'Plan switched on.' : 'Plan switched off.');
    }

    /** Sells a plan to a student from their wallet (until parents do it themselves in the app). */
    public function sell(Request $request, MealSubscriptionService $meals)
    {
        $school = $this->school($request);

        $data = $request->validate([
            'student_code' => ['required', 'string', 'max:50'],
            'plan_id' => ['required', 'integer'],
            'key' => ['required', 'string', 'max:64'],
        ]);

        $student = Student::where('student_code', trim($data['student_code']))->first();

        if (! $student) {
            return back()->withErrors(['sell' => __('No student has that number.')])->withInput();
        }

        try {
            $subscription = $meals->purchase($student, (int) $data['plan_id'], 'admin-' . $data['key'], $request->user()->id);
        } catch (MealException | InvalidArgumentException $e) {
            return back()->withErrors(['sell' => $e->getMessage()])->withInput();
        } catch (InsufficientBalanceException $e) {
            return back()->withErrors(['sell' => __('Not enough balance. Balance: :value, needed: :value2.', ['value' => Money::format($e->balance, $school->currency), 'value2' => Money::format($e->required, $school->currency)])])->withInput();
        }

        return redirect()->route('admin.meals')->with('status',
            __(':name: :name2, from :value to :value2.', ['name' => $student->name, 'name2' => $subscription->plan->name, 'value' => $subscription->valid_from->toDateString(), 'value2' => $subscription->expires_on->toDateString()]));
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school admin to manage meals.'));

        return School::findOrFail($request->user()->school_id);
    }

    private function uniqueCode(School $school, string $name): string
    {
        $base = Str::slug($name) ?: 'meal';
        $code = $base;
        $i = 2;

        while (MealType::withoutGlobalScopes()->where('school_id', $school->id)->where('code', $code)->exists()) {
            $code = "{$base}-{$i}";
            $i++;
        }

        return $code;
    }
}
