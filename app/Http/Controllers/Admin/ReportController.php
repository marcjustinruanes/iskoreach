<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use App\Services\AdminReports;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportRequest $request, AdminReports $reports): Response
    {
        $filters = $request->validated();

        return Inertia::render('admin/Reports', [
            'filters' => $filters, 'definition' => AdminReports::DEFINITIONS[$filters['type']],
            'options' => collect(AdminReports::DEFINITIONS)->map(fn ($definition, $key): array => ['value' => $key, 'label' => $definition['title']])->values(),
            'rows' => $reports->query($filters)->paginate(15)->withQueryString()->through(fn ($row): array => $reports->row($row, $filters['type'])),
            'export_url' => route('admin.reports.export', $filters), 'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function export(ReportRequest $request, AdminReports $reports): StreamedResponse
    {
        $filters = $request->validated();

        return response()->streamDownload(function () use ($filters, $reports): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['IskoReach', AdminReports::DEFINITIONS[$filters['type']]['title'], $filters['from'], $filters['to'], 'Asia/Manila'], ',', '"', '');
            fputcsv($stream, [AdminReports::DEFINITIONS[$filters['type']]['description']], ',', '"', '');
            fputcsv($stream, array_values(AdminReports::DEFINITIONS[$filters['type']]['columns']), ',', '"', '');
            foreach ($reports->query($filters)->cursor() as $row) {
                fputcsv($stream, array_map($reports->csvCell(...), array_values($reports->row($row, $filters['type']))), ',', '"', '');
            }
            fclose($stream);
        }, 'iskoreach-'.$filters['type'].'-'.$filters['from'].'-to-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
