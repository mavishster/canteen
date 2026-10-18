<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        Paginator::useBootstrapFive();

        $query = $this->filtered($request);

        return view('admin.audit.index', [
            'logs' => (clone $query)->with('school:id,timezone')->latest('id')->paginate(50)->withQueryString(),
            'entities' => AuditLog::query()->distinct()->orderBy('entity')->pluck('entity'),
            'actors' => AuditLog::query()->whereNotNull('user_id')->select('user_id', 'actor_name')->distinct()->orderBy('actor_name')->get(),
        ]);
    }

    /** The same filters, as a file. The export itself is written to the log. */
    public function csv(Request $request)
    {
        $query = $this->filtered($request)->with('school:id,timezone')->orderBy('id');

        AuditRecorder::log(
            'report.exported', 'report', 'Audit log',
            'Audit log exported' . ($request->query() ? ' (filtered)' : ''),
            schoolId: $request->user()->school_id
        );

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'who', 'role', 'action', 'entity', 'subject', 'summary', 'ip']);

            $query->limit(20000)->get()->each(function (AuditLog $log) use ($out) {
                fputcsv($out, array_map([$this, 'safeCell'], [
                    $log->created_at->timezone($log->school?->timezone ?? 'UTC')->format('Y-m-d H:i:s'),
                    $log->actor_name,
                    $log->actor_role,
                    $log->action,
                    $log->entity,
                    $log->subject_label,
                    $log->summary,
                    $log->ip,
                ]));
            });

            fclose($out);
        }, 'audit-log-' . now()->toDateString() . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'entity' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $timezone = $request->user()->school_id
            ? (School::find($request->user()->school_id)?->timezone ?? 'UTC')
            : 'UTC';

        $query = AuditLog::query();

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from'), $timezone)->startOfDay()->utc()->toDateTimeString());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to'), $timezone)->endOfDay()->utc()->toDateTimeString());
        }

        if ($request->filled('entity')) {
            $query->where('entity', $request->input('entity'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $request->input('q')) . '%';
            $query->where(fn ($w) => $w
                ->where('summary', 'like', $term)
                ->orWhere('subject_label', 'like', $term)
                ->orWhere('actor_name', 'like', $term));
        }

        return $query;
    }

    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
