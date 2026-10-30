<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\HallGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HallController extends Controller
{
    /**
     * POST /halls/generate: a layout (plan section 7.1) from table and chair counts.
     */
    public function generate(Request $request, HallGenerator $generator): JsonResponse
    {
        $data = $request->validate([
            'tables' => ['required', 'integer', 'min:1', 'max:'.HallGenerator::MAX_TABLES],
            'chairs' => ['required', 'integer', 'min:1', 'max:'.HallGenerator::MAX_CHAIRS],
            'layout' => ['sometimes', 'in:mixed,split'],
            'shape' => ['sometimes', 'in:round,rect'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        return response()->json($generator->generate(
            (int) $data['tables'],
            (int) $data['chairs'],
            $data['layout'] ?? 'mixed',
            $data['shape'] ?? 'round',
            $data['name'] ?? 'Main hall',
        ));
    }
}
