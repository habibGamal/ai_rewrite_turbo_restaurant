<?php

namespace App\Http\Controllers;

use App\Labels\LabelPrintJobs;
use App\Models\LabelTemplate;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Label print jobs. The manifest and page images are fetched by the
 * Windows print app through signed links (no login); the browser page is
 * the fallback for a PC without the app.
 */
class LabelJobController extends Controller
{
    public function manifest(string $job, LabelPrintJobs $jobs): JsonResponse
    {
        abort_unless(app(FeatureService::class)->labelPrintingEnabled(), 404);

        $manifest = $jobs->manifest($job);
        abort_if($manifest === null, 404, 'انتهت صلاحية مهمة الطباعة.');

        return response()->json($manifest);
    }

    public function page(string $job, int $page, LabelPrintJobs $jobs): Response
    {
        abort_unless(app(FeatureService::class)->labelPrintingEnabled(), 404);

        $png = $jobs->pagePng($job, $page);
        abort_if($png === null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=7200',
        ]);
    }

    /**
     * Browser printing: every page image at its real size in mm, one per
     * printed page.
     */
    public function browser(string $job, LabelPrintJobs $jobs): View
    {
        abort_unless(app(FeatureService::class)->labelPrintingEnabled(), 404);
        abort_unless(auth()->user()?->can('print_labels'), 403);

        $data = $jobs->find($job);
        $template = $data === null ? null : LabelTemplate::find($data['template_id']);
        abort_if($data === null || $template === null, 404, 'انتهت صلاحية مهمة الطباعة.');

        $expires = now()->addHours(2);
        $pages = [];
        foreach ($data['rows'] as $index => $row) {
            $pages[] = [
                'image' => URL::temporarySignedRoute('labels.jobs.page', $expires, ['job' => $job, 'page' => $index]),
                'copies' => $row['copies'],
            ];
        }

        return view('print.label-job', [
            'template' => $template,
            'pages' => $pages,
            'total' => $data['total'],
        ]);
    }
}
