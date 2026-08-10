<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\FaceMatchService;
use App\Support\AdvisoryScope;
use Illuminate\Http\Request;

class FaceEnrollmentController extends Controller
{
    public function store(Request $request, Student $student, FaceMatchService $faces)
    {
        if (! AdvisoryScope::canMutateStudent($student)) {
            abort(403, 'You cannot enroll faces outside your grade access.');
        }

        $request->validate([
            'descriptor' => 'required|array|size:'.config('face.descriptor_length', 128),
            'descriptor.*' => 'numeric',
        ]);

        $descriptor = $faces->normalizeDescriptor($request->input('descriptor'));
        if ($descriptor === null) {
            return response()->json(['message' => 'Invalid face descriptor.'], 422);
        }

        $student->update([
            'face_descriptor' => $descriptor,
            'face_enrolled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Face enrolled successfully.',
            'enrolled_at' => $student->face_enrolled_at?->toIso8601String(),
        ]);
    }

    public function destroy(Student $student)
    {
        if (! AdvisoryScope::canMutateStudent($student)) {
            abort(403, 'You cannot remove face enrollment outside your grade access.');
        }

        $student->update([
            'face_descriptor' => null,
            'face_enrolled_at' => null,
        ]);

        return response()->json(['message' => 'Face enrollment removed.']);
    }
}
