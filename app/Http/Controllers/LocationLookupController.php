<?php

namespace App\Http\Controllers;

use App\Services\PhilippineLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocationLookupController extends Controller
{
    public function names(Request $request, PhilippineLocations $locations): JsonResponse
    {
        $path = $request->validate(['path' => ['required', 'string', 'max:2000']])['path'];
        $parts = array_map('rawurldecode', explode('/', trim(parse_url($path, PHP_URL_PATH) ?? '', '/')));
        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
        try {
            $result = match (true) {
                $parts === ['locations', 'regions'] => $locations->namedOptions('regions'),
                count($parts) === 4 && $parts[0] === 'locations' && $parts[1] === 'regions' && in_array($parts[3], ['provinces', 'cities'], true) => $locations->namedOptions($parts[3], $parts[2]),
                count($parts) === 4 && $parts[0] === 'locations' && $parts[1] === 'cities' && $parts[3] === 'barangays' && is_string($query['region'] ?? null) => $locations->namedOptions('barangays', $query['region'], $parts[2], is_string($query['province'] ?? null) ? $query['province'] : null),
                default => abort(422, 'Select an available location lookup.'),
            };

            return response()->json($result)->header('Cache-Control', 'public, max-age=300');
        } catch (RuntimeException $error) {
            return response()->json(['message' => 'Location options are temporarily unavailable. Please retry.'], 503)->header('Cache-Control', 'no-store');
        }
    }

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
