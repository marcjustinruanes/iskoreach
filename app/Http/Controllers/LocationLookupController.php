<?php

namespace App\Http\Controllers;

use App\Services\PhilippineLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocationLookupController extends Controller
{
    public function __invoke(PhilippineLocations $locations, ?string $region = null): JsonResponse
    {
        try {
            return response()->json($region === null ? $locations->regions() : $locations->cities($region))->header('Cache-Control', 'public, max-age=300');
        } catch (ValidationException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            return response()->json(['message' => 'Location options are temporarily unavailable. Please retry shortly.'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
