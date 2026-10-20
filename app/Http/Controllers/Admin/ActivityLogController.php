<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListActivityLogsRequest;
use App\Services\AdminAuditHistory;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(ListActivityLogsRequest $request, AdminAuditHistory $history): Response
    {
        $filters = array_fill_keys(['action', 'type', 'actor', 'record', 'from', 'to'], '');
        foreach ($filters as $key => $value) {
            $filters[$key] = (string) ($request->validated($key) ?? '');
        }

        return Inertia::render('admin/ActivityLogs', [
            'filters' => $filters,
            'logs' => $history->query($filters)->with('actor:id,name,role')->orderByDesc('occurred_at')->orderByDesc('id')->paginate(15)->withQueryString()->through($history->row(...)),
            'actions' => collect(AdminAuditHistory::ACTIONS)->map(fn ($value, $key): array => ['value' => $key, 'label' => $value[0]])->values(),
            'types' => collect(AdminAuditHistory::TYPES)->map(fn ($value, $key): array => ['value' => $key, 'label' => ucfirst(str_replace('_', ' ', $key))])->values(),
        ]);
    }
}
