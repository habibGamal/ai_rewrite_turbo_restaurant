<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Barcodes\Code128;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The printable badge card. The plain code is handed over once through the
 * session by the «طباعة بادج» action and never stored.
 */
class BadgePrintController extends Controller
{
    public function __invoke(Request $request, User $user): View
    {
        abort_unless($request->user()?->can('update', $user), 403);

        $code = $request->session()->pull('badge_print.'.$user->getKey());
        $code = is_string($code) ? $code : null;

        return view('badges.print', [
            'user' => $user,
            'code' => $code,
            'svg' => $code !== null ? Code128::svg($code, 60) : null,
            'shop' => (string) setting(SettingKey::RESTAURANT_NAME),
            'back' => UserResource::getUrl('index'),
        ]);
    }
}
