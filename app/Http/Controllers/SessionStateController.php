<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionStateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => true,
            'account_id' => $request->user()->id,
            'csrf_token' => csrf_token(),
        ]);
    }
}
