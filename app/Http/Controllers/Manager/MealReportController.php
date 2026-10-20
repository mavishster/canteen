<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\MealConsumption;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Support\Audit\AuditRecorder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\ValidationException;

/**
 * How many meals were served: per meal, per day and per student. A meal's date is the school day it was served on.
 */
class MealReportController extends Controller
{
    private const ENDING_SOON_DAYS = 14;

    public function index(Request $request)
    {
        Paginator::useBootstrapFive();

        [$school, $from, $to] = $this->range($request);
        $request->validate([
            'meal' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $meal = $request->filled('meal') ? (int) $request->input('meal') : null;
        $term = trim((string) $request->input('q', ''));
        $today = now($school->timezone)->toDateString();

        $types = MealType::withoutGlobalScopes()->where('school_id', $school->id)->orderBy('name')->get(['id', 'name', 'is_active']);
        $base = $this->consumptions($school, $from, $to);

        // ---- per meal
        $perMeal = (clone $base)
            ->selectRaw('meal_type_id, count(*) as meals, count(distinct student_id) as students')
            ->groupBy('meal_type_id')
            ->get()
            ->keyBy('meal_type_id');

        $servedToday = MealConsumption::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereDate('service_date', $today)
            ->selectRaw('meal_type_id, count(*) as n')
            ->groupBy('meal_type_id')
            ->pluck('n', 'meal_type_id');

        $withPlanToday = MealSubscription::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereDate('valid_from', '<=', $today)
            ->whereDate('expires_on', '>=', $today)
            ->selectRaw('meal_type_id, count(distinct student_id) as n')
            ->groupBy('meal_type_id')
            ->pluck('n', 'meal_type_id');

        // ---- per day: date => [meal type => count]
        $byDay = [];
        (clone $base)
            ->selectRaw('service_date, meal_type_id, count(*) as n')
            ->groupBy('service_date', 'meal_type_id')
            ->orderBy('service_date')
            ->get()
            ->each(function ($row) use (&$byDay) {
                $byDay[Carbon::parse($row->service_date)->toDateString()][$row->meal_type_id] = (int) $row->n;
            });

        // ---- per student, biggest first
        $matchingIds = $term !== ''
            ? Student::withoutGlobalScopes()
                ->where('school_id', $school->id)
                ->where(fn ($w) => $w
                    ->where('student_code', 'like', '%' . $this->like($term) . '%')
                    ->orWhere('name', 'like', '%' . $this->like($term) . '%'))
                ->pluck('id')
            : null;

        $students = (clone $base)
            ->when($meal, fn ($q) => $q->where('meal_type_id', $meal))
            ->when($matchingIds !== null, fn ($q) => $q->whereIn('student_id', $matchingIds))
            ->selectRaw('student_id, count(*) as total, max(service_date) as last_date')
            ->groupBy('student_id')
            ->orderByDesc('total')
            ->orderBy('student_id')
            ->paginate(50)
            ->withQueryString();

        $ids = $students->pluck('student_id');

        $matrix = [];
        (clone $base)
            ->whereIn('student_id', $ids)
            ->selectRaw('student_id, meal_type_id, count(*) as n')
            ->groupBy('student_id', 'meal_type_id')
            ->get()
            ->each(function ($row) use (&$matrix) {
                $matrix[$row->student_id][$row->meal_type_id] = (int) $row->n;
            });

        $people = Student::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'student_code', 'name', 'grade'])->keyBy('id');

        // ---- plans that end soon and have not been renewed
        $until = Carbon::parse($today)->addDays(self::ENDING_SOON_DAYS)->toDateString();

        $endingSoon = MealSubscription::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->selectRaw('student_id, meal_type_id, max(expires_on) as ends')
            ->groupBy('student_id', 'meal_type_id')
            ->havingRaw('max(expires_on) between ? and ?', [$today, $until])
            ->orderBy('ends')
            ->limit(100)
            ->get();

        $endingPeople = Student::withoutGlobalScopes()->whereIn('id', $endingSoon->pluck('student_id'))->get(['id', 'student_code', 'name'])->keyBy('id');

        return view('manager.reports.meals', [
            'school' => $school,
            'from' => $from,
            'to' => $to,
            'meal' => $meal,
            'term' => $term,
            'types' => $types,
            'perMeal' => $perMeal,
            'servedToday' => $servedToday,
            'withPlanToday' => $withPlanToday,
            'byDay' => $byDay,
            'students' => $students,
            'matrix' => $matrix,
            'people' => $people,
            'endingSoon' => $endingSoon,
            'endingPeople' => $endingPeople,
            'endingDays' => self::ENDING_SOON_DAYS,
        ]);
    }

    /** One line per student: how many of each meal in the period. Names are left out on purpose. */
    public function csv(Request $request)
    {
        [$school, $from, $to] = $this->range($request);

        AuditRecorder::log(
            'report.exported', 'report', 'Meals',
            "Meals report exported ({$from->toDateString()} to {$to->toDateString()})",
            schoolId: $school->id
        );

        $types = MealType::withoutGlobalScopes()->where('school_id', $school->id)->orderBy('name')->get(['id', 'name']);

        $counts = [];
        $this->consumptions($school, $from, $to)
            ->selectRaw('student_id, meal_type_id, count(*) as n')
            ->groupBy('student_id', 'meal_type_id')
            ->get()
            ->each(function ($row) use (&$counts) {
                $counts[$row->student_id][$row->meal_type_id] = (int) $row->n;
            });

        $people = Student::withoutGlobalScopes()
            ->whereIn('id', array_keys($counts))
            ->orderBy('student_code')
            ->get(['id', 'student_code', 'grade']);

        return response()->streamDownload(function () use ($types, $counts, $people) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['student_code', 'grade'], $types->pluck('name')->map(fn ($n) => $this->safeCell($n))->all(), ['total']));

            foreach ($people as $student) {
                $row = [$this->safeCell($student->student_code), $student->grade];
                $total = 0;

                foreach ($types as $type) {
                    $n = $counts[$student->id][$type->id] ?? 0;
                    $row[] = $n;
                    $total += $n;
                }

                fputcsv($out, array_merge($row, [$total]));
            }

            fclose($out);
        }, "meals-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ----------------------------------------------------------------- helpers

    private function consumptions(School $school, Carbon $from, Carbon $to)
    {
        return MealConsumption::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereDate('service_date', '>=', $from->toDateString())
            ->whereDate('service_date', '<=', $to->toDateString());
    }

    /** @return array{0: School, 1: Carbon, 2: Carbon} school, first day and last day (school days) */
    private function range(Request $request): array
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school user to see reports.');

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $school = School::findOrFail($request->user()->school_id);
        $timezone = $school->timezone;

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'), $timezone)->startOfDay()
            : now($timezone)->startOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'), $timezone)->startOfDay()
            : $to->copy()->startOfMonth();

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start date cannot be after the end date.']);
        }

        if (abs($from->diffInDays($to)) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose a period of at most one year.']);
        }

        return [$school, $from, $to];
    }

    private function like(string $term): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $term);
    }

    /** Stops spreadsheet programs from running a cell as a formula. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
