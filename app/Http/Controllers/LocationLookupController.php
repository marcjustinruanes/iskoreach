<?php

namespace App\Http\Controllers;

use App\Services\PhilippineLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocationLookupController extends Controller
{
    public function barangays(PhilippineLocations $locations, string $city): JsonResponse
    {
        try {
            return response()->json($locations->barangays($city))->header('Cache-Control', 'public, max-age=300');
        } catch (ValidationException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            return response()->json(['message' => 'Barangay options are temporarily unavailable. Please retry.'], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function provinces(PhilippineLocations $locations, string $region): JsonResponse
    {
        try {
            return response()->json($locations->provinces($region))->header('Cache-Control', 'public, max-age=300');
        } catch (ValidationException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            return response()->json(['message' => 'Province options are temporarily unavailable.'], 503)->header('Cache-Control', 'no-store');
        }
    }

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
