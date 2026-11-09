<?php

namespace App\Http\Controllers;

use App\Services\Orders\FloorLayoutService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FloorLayoutController extends Controller
{
    public function show(string $code, FloorLayoutService $floor)
    {
        abort_unless($floor->isDineInType($code), 404);

        return Inertia::render('Floor/Designer', ['code' => $code, 'layout' => $floor->read($code)]);
    }

    public function save(Request $request, string $code, FloorLayoutService $floor)
    {
        abort_unless($floor->isDineInType($code), 404);

        $floor->write($code, $request->all());

        return back()->with('success', 'تم حفظ المخطط');
    }
}
