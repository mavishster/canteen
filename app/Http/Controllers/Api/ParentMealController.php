<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MealException;
use App\Exceptions\SisIntegrationUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\School;
use App\Models\Student;
use App\Services\MealSubscriptionService;
use App\Services\SisParentService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ParentMealController extends Controller
{
    public function students(Request $request, SisParentService $sisParents): JsonResponse
    {
        try {
            $students = $sisParents->linkedStudents($this->parentId($request));
        } catch (SisIntegrationUnavailableException $exception) {
            return $this->sisUnavailable($exception);
        }

        return response()->json([
            'data' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'student_id' => $student->student_code,
                'name' => $student->name,
                'grade' => $student->grade,
                'is_active' => $student->is_active,
                'wallet_balance' => $student->account->balance,
                'currency' => $student->school->currency,
            ])->values(),
        ]);
    }

    public function plans(Request $request, Student $student, SisParentService $sisParents): JsonResponse
    {
        $this->authorizeStudent($request, $student, $sisParents);
        $school = School::findOrFail($student->school_id);
        $plans = MealPlan::with('mealType')
            ->where('school_id', $student->school_id)
            ->where('is_active', true)
            ->whereHas('mealType', fn ($query) => $query->where('is_active', true))
            ->where(fn ($query) => $query->whereNull('grade')->orWhere('grade', $student->grade))
            ->orderBy('meal_type_id')
            ->orderBy('duration_months')
            ->get();

        return response()->json([
            'data' => $plans->map(fn (MealPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'meal_type' => ['id' => $plan->mealType->id, 'name' => $plan->mealType->name],
                'duration_months' => $plan->duration_months,
                'entitlement_quantity' => $plan->entitlement_quantity,
                'price' => $plan->price,
                'price_formatted' => Money::format($plan->price, $school->currency),
                'currency' => $school->currency,
            ])->values(),
        ]);
    }

    public function subscriptions(Request $request, Student $student, SisParentService $sisParents): JsonResponse
    {
        $this->authorizeStudent($request, $student, $sisParents);
        $today = now($student->school->timezone)->toDateString();
        $subscriptions = MealSubscription::with([
            'plan:id,name,duration_months',
            'mealType:id,name',
            'school:id,timezone',
        ])
            ->where('student_id', $student->id)
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $subscriptions->map(fn (MealSubscription $subscription) => [
                'id' => $subscription->id,
                'plan' => $subscription->plan->name,
                'meal_type' => $subscription->mealType->name,
                'valid_from' => $subscription->valid_from->toDateString(),
                'expires_on' => $subscription->expires_on->toDateString(),
                'status' => $subscription->status === 'active' && $subscription->expires_on->toDateString() < $today
                    ? 'expired'
                    : $subscription->status,
                'payment_status' => $subscription->payment_status,
                'price' => $subscription->price,
                'entitlement_quantity' => $subscription->entitlement_quantity,
                'consumed_quantity' => $subscription->consumed_quantity,
                'remaining_entitlements' => $subscription->remainingEntitlements($today),
            ])->values(),
        ]);
    }

    public function purchase(
        Request $request,
        Student $student,
        SisParentService $sisParents,
        MealSubscriptionService $subscriptions,
    ): JsonResponse {
        $this->authorizeStudent($request, $student, $sisParents);
        $request->mergeIfMissing(['idempotency_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate([
            'meal_plan_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $subscription = $subscriptions->purchase($student, (int) $data['meal_plan_id'], $data['idempotency_key']);
        } catch (InsufficientBalanceException $exception) {
            return response()->json([
                'reason' => 'insufficient_balance',
                'message' => 'The student wallet does not have enough balance for this plan.',
            ], 422);
        } catch (MealException|InvalidArgumentException $exception) {
            $reason = $exception instanceof MealException ? $exception->reason : 'idempotency_conflict';

            return response()->json(['reason' => $reason, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'payment_status' => $subscription->payment_status,
                'valid_from' => $subscription->valid_from->toDateString(),
                'expires_on' => $subscription->expires_on->toDateString(),
                'remaining_entitlements' => $subscription->remainingEntitlements(),
                'wallet_balance' => $student->account()->value('balance'),
            ],
        ], 201);
    }

    public function wallet(Request $request, Student $student, SisParentService $sisParents): JsonResponse
    {
        $this->authorizeStudent($request, $student, $sisParents);
        $account = $student->account;
        $transactions = LedgerEntry::where('account_id', $account->id)
            ->latest('id')
            ->limit(50)
            ->get(['id', 'type', 'amount', 'balance_after', 'description', 'reference', 'created_at']);

        return response()->json([
            'balance' => $account->balance,
            'currency' => $student->school->currency,
            'transactions' => $transactions,
        ]);
    }

    private function authorizeStudent(Request $request, Student $student, SisParentService $sisParents): void
    {
        try {
            $allowed = $sisParents->parentCanAccessStudent($this->parentId($request), $student);
        } catch (SisIntegrationUnavailableException $exception) {
            Log::error('SIS student authorization unavailable.', ['message' => $exception->getMessage()]);

            throw new HttpException(503, 'Parent authorization is temporarily unavailable.', $exception);
        }

        abort_unless($allowed, 404);
    }

    private function parentId(Request $request): string
    {
        $parentId = $request->attributes->get('sis_parent_id');
        abort_unless(is_string($parentId), 401);

        return $parentId;
    }

    private function sisUnavailable(SisIntegrationUnavailableException $exception): JsonResponse
    {
        Log::error('SIS student relationships unavailable.', ['message' => $exception->getMessage()]);

        return response()->json(['message' => 'Parent authorization is temporarily unavailable.'], 503);
    }
}
