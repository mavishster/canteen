<?php

namespace App\Http\Controllers;

use App\Exceptions\CardException;
use App\Exceptions\MealException;
use App\Models\MealType;
use App\Services\MealDistributionService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MealDistributionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school user to distribute meals.');

        $mealTypes = MealType::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $manualEnabled = (bool) config('canteen.manual_meal_identification_enabled');

        return view('till.meals', compact('mealTypes', 'manualEnabled'));
    }

    public function collect(Request $request, MealDistributionService $distribution)
    {
        $data = $request->validate([
            'meal_type_id' => ['required', 'integer'],
            'identifier_type' => ['required', 'in:rfid_card_id,student_id'],
            'identification_method' => ['required', 'in:rfid,manual'],
            'identifier' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:64'],
        ]);

        $operator = $request->user();
        $schoolId = $operator->school_id;
        if (! $schoolId) {
            return response()->json([
                'reason' => 'no_school',
                'message' => 'Log in as a school user to distribute meals.',
            ], 403);
        }

        try {
            $consumption = $distribution->collect(
                $schoolId,
                $operator->id,
                $data['identifier_type'],
                $data['identification_method'],
                $data['identifier'],
                (int) $data['meal_type_id'],
                $data['key'],
            );
        } catch (CardException|MealException|InvalidArgumentException $exception) {
            $reason = $exception instanceof CardException || $exception instanceof MealException
                ? $exception->reason
                : 'idempotency_conflict';

            return response()->json(['reason' => $reason, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'student' => ['name' => $consumption->student->name, 'code' => $consumption->student->student_code],
            'meal_type' => $consumption->mealType->name,
            'remaining_entitlements' => $consumption->subscription->remainingEntitlements(),
        ]);
    }
}
