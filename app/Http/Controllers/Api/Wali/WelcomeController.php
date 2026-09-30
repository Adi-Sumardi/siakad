<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WelcomeController extends Controller
{
    /**
     * Close the once-per-account welcome splash. First write wins - a repeat
     * call (double tap, second tab) keeps the original timestamp, mirroring
     * the activated_at idiom in OtpController::verify.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'welcome_shown_at' => $user->welcome_shown_at ?? now(),
        ])->save();

        return response()->json(['welcome_shown_at' => $user->welcome_shown_at]);
    }
}
