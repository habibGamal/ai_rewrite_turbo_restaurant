<?php

namespace App\Http\Controllers;

use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use App\Models\OrderTypeRow;
use App\Services\Orders\FloorLayoutService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FloorLayoutController extends Controller
{
    public function show(string $code, FloorLayoutService $floor)
    {
        abort_unless($floor->isDineInType($code), 404);

        $label = (string) OrderTypeRow::query()->where('code', $code)->value('label');
        $layout = $floor->read($code);

        return Inertia::render('Floor/Designer', [
            'code' => $code,
            'label' => $label,
            'backUrl' => OrderTypeRowResource::getUrl('index'),
            'layout' => $layout,
        ]);
    }

    public function save(Request $request, string $code, FloorLayoutService $floor)
    {
        abort_unless($floor->isDineInType($code), 404);

        $floor->write($code, $request->all());

        return back()->with('success', 'تم حفظ المخطط');
    }
}
