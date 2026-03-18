<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadNoteRequest;
use App\Http\Resources\LeadNoteResource;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LeadNoteController extends Controller
{
    public function store(StoreLeadNoteRequest $request, Lead $lead): JsonResponse
    {
        $note = $lead->notes()->create([
            'user_id' => Auth::id(),
            'note' => $request->validated('note'),
        ]);

        return LeadNoteResource::make($note)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
