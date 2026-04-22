<?php

// ─── CodingLookupController ──────────────────────────────────────────────────
// Phase 13.1. Search endpoints powering the SNOMED + RxNorm autocomplete
// pickers on the Problem and Allergy/Medication tabs.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\RxnormLookup;
use App\Models\SnomedLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodingLookupController extends Controller
{
    public function snomed(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (strlen($term) < 2) return response()->json(['results' => []]);

        $rows = SnomedLookup::search($term)
            ->orderBy('display')
            ->limit(25)
            ->get(['code', 'display', 'category', 'icd10_code']);

        return response()->json(['results' => $rows]);
    }

    public function rxnorm(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $allergenOnly = $request->boolean('allergen_only', false);
        if (strlen($term) < 2) return response()->json(['results' => []]);

        $q = RxnormLookup::search($term);
        if ($allergenOnly) $q->allergenCandidates();
        $rows = $q->orderBy('display')->limit(25)->get(['code', 'display', 'tty', 'is_allergen_candidate']);

        return response()->json(['results' => $rows]);
    }
}
