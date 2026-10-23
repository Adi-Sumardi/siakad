<?php

namespace App\Http\Controllers\Api\Executive;

use App\Http\Controllers\Controller;
use App\Services\Executive\ExecutiveSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dashboard Direktur Pendidikan & BPH. Hanya membaca, hanya angka per unit.
 * ?unit=SD-13 mempersempit ke satu unit; tanpa itu, semua unit.
 * Admin pusat boleh membuka untuk pratinjau dengan ?as=direktur_pendidikan|bph.
 */
class SummaryController extends Controller
{
    public function show(Request $request, ExecutiveSummaryService $service): JsonResponse
    {
        $request->validate([
            'unit' => ['nullable', 'string', 'max:30'],
            'as' => ['nullable', Rule::in(['direktur_pendidikan', 'bph'])],
        ]);

        $user = $request->user();
        $view = $user->isSuperAdmin() ? ($request->query('as') ?? 'bph') : null;

        $payload = $service->forViewer($user, $request->query('unit'), $view);

        if ($payload === null) {
            return response()->json(['message' => 'Unit tidak ditemukan.'], 404);
        }

        return response()->json($payload);
    }
}
