<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\StudentIdCardService;
use App\Support\AdvisoryScope;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class IdCardController extends Controller
{
    public function __construct(
        private StudentIdCardService $idCards
    ) {}

    public function front(int $id): Response
    {
        $student = $this->findAccessibleStudent($id);

        return $this->idCards->renderFront($student)->response('png');
    }

    public function back(int $id): Response
    {
        $student = $this->findAccessibleStudent($id);

        return $this->idCards->renderBack($student)->response('png');
    }

    public function download(int $id): BinaryFileResponse
    {
        $student = $this->findAccessibleStudent($id);

        $front = (string) $this->idCards->renderFront($student)->encode('png');
        $back = (string) $this->idCards->renderBack($student)->encode('png');

        $zipPath = storage_path("app/temp_id_{$id}.zip");
        $frontPath = storage_path("app/front_{$id}.png");
        $backPath = storage_path("app/back_{$id}.png");

        file_put_contents($frontPath, $front);
        file_put_contents($backPath, $back);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFile($frontPath, "{$student->lastname}_{$student->firstname}_front.png");
            $zip->addFile($backPath, "{$student->lastname}_{$student->firstname}_back.png");
            $zip->close();
        }

        unlink($frontPath);
        unlink($backPath);

        return response()->download($zipPath, "{$student->lastname}_{$student->firstname}_ID.zip")
            ->deleteFileAfterSend(true);
    }

    protected function findAccessibleStudent(int $id): Student
    {
        $student = Student::findOrFail($id);
        if (! AdvisoryScope::canAccessStudent($student)) {
            abort(403, 'You cannot access ID cards outside your grade access.');
        }

        return $student;
    }
}
