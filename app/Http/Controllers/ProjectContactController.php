<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectContactController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json($project->contacts()->orderBy('name')->get());
    }

    public function sync(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'contacts'               => 'present|array',
            'contacts.*.name'        => 'required|string|max:255',
            'contacts.*.cargo'       => 'nullable|string|max:255',
            'contacts.*.email'       => 'nullable|email|max:255',
            'contacts.*.phone'       => 'nullable|string|max:50',
            'contacts.*.customer_contact_id' => 'nullable|exists:customer_contacts,id',
        ]);

        $project->contacts()->delete();

        foreach ($validated['contacts'] as $c) {
            ProjectContact::create(array_merge($c, ['project_id' => $project->id]));
        }

        return response()->json($project->contacts()->orderBy('name')->get());
    }
}
