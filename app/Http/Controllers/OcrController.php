<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Ocr\OcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OcrController extends Controller
{
    /** POST /documents/{document}/ocr */
    public function process(Request $request, Document $document, OcrService $svc): JsonResponse
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_if($document->tenant_id !== $u->tenant_id, 403);

        $doc = $svc->process($document, $u);
        return response()->json([
            'document'          => $doc->only(['id', 'ocr_processed_at', 'ocr_engine']),
            'text_length'       => strlen($doc->ocr_text ?? ''),
            'extracted_fields'  => $doc->ocr_extracted_fields,
        ]);
    }

    /** GET /documents/search?q=... — simple ILIKE on ocr_text. */
    public function search(Request $request): JsonResponse
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 3) {
            return response()->json(['rows' => [], 'count' => 0]);
        }
        $rows = Document::where('tenant_id', $u->tenant_id)
            ->whereNotNull('ocr_text')
            ->where('ocr_text', 'ilike', '%' . $q . '%')
            ->select(['id', 'file_name', 'document_category', 'participant_id'])
            ->limit(50)->get();
        return response()->json(['rows' => $rows, 'count' => $rows->count()]);
    }
}
