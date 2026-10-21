<?php

namespace App\Http\Controllers;

use App\Exceptions\CardException;
use App\Exceptions\MealException;
use App\Exceptions\SaleException;
use App\Models\MealType;
use App\Models\School;
use App\Services\CardService;
use App\Services\MealCollectionService;
use App\Services\SaleService;
use App\Services\StudentLookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MealScreenController extends Controller
{
    public function index(Request $request, MealCollectionService $meals)
    {
        $school = $this->school($request);

        $mealTypes = MealType::where('is_active', true)->orderBy('name')->get()->map(fn (MealType $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'from' => $t->service_starts_at ? substr((string) $t->service_starts_at, 0, 5) : null,
            'to' => $t->service_ends_at ? substr((string) $t->service_ends_at, 0, 5) : null,
        ])->values();

        return view('till.meals', [
            'meals' => $mealTypes,
            'serverNow' => CarbonImmutable::now($school->timezone)->format('H:i'),
            'counts' => $meals->countsToday($school),
            'manualEnabled' => (bool) data_get($school->settings, 'till.manual_entry', true),
        ]);
    }

    /**
     * One tap (card) or one typed number. A typed number first returns {confirm: true} so the staff member
     * confirms the name on screen; only the repeated request with confirm_student records the meal.
     */
    public function collect(Request $request, MealCollectionService $meals, CardService $cards, SaleService $sales, StudentLookup $lookup)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:40'],
            'meal_type_id' => ['required', 'integer'],
            'key' => ['required', 'string', 'max:64'],
            'confirm_student' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();

        if (! $user->school_id) {
            return $this->reject('Log in as a school user to serve meals.', 'no_school');
        }

        $schoolId = (int) $user->school_id;
        $input = trim($data['identifier']);

        try {
            $found = $lookup->identify($schoolId, $input);

            if ($found['type'] === 'card') {
                $student = $cards->resolve($input, $schoolId)->student;   // refuses blocked and retired cards
                $identifiedBy = 'card';
            } elseif ($found['type'] === 'student') {
                $student = $found['student'];
                $sales->assertManualAllowed($schoolId, $student);          // same rules as the till
                $identifiedBy = 'manual';

                if (trim((string) ($data['confirm_student'] ?? '')) !== (string) $student->student_code) {
                    return response()->json(['confirm' => true, 'student' => [
                        'name' => $student->name,
                        'first_name' => Str::before($student->name, ' '),
                        'grade' => $student->grade,
                        'code' => $student->student_code,
                    ]]);
                }
            } elseif ($found['looks_like_card']) {
                throw CardException::unknownCard();
            } else {
                return $this->reject('Card or student number not found.', 'unknown_identifier');
            }

            $consumption = $meals->collect($student, $schoolId, $user->id, (int) $data['meal_type_id'], $identifiedBy, $data['key']);
        } catch (CardException | SaleException | MealException $e) {
            return $this->reject($e->getMessage(), $e->reason);
        } catch (InvalidArgumentException) {
            return $this->reject('This tap was already used for something else. Please try again.', 'key_conflict');
        }

        $student = $consumption->student;
        $school = School::findOrFail($schoolId);

        return response()->json([
            'ok' => true,
            'student' => [
                'name' => $student->name,
                'first_name' => Str::before($student->name, ' '),
                'code' => $student->student_code,
            ],
            'meal' => $consumption->mealType->name,
            'time' => $consumption->created_at->timezone($school->timezone)->format('H:i'),
            'counts' => $meals->countsToday($school),
        ]);
    }

    public function counts(Request $request, MealCollectionService $meals)
    {
        return response()->json(['counts' => $meals->countsToday($this->school($request))]);
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school user to serve meals.'));

        return School::findOrFail($request->user()->school_id);
    }

    private function reject(string $message, string $reason)
    {
        return response()->json(['message' => $message, 'reason' => $reason], 422);
    }
}
