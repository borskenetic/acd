<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_FOLDERS = [
        'default',
        'documents',
        'forms',
        'reports',
        'misc',
    ];

    public function index()
    {
        $files = File::all();

        return view('files.index', compact('files'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:pdf,doc,docx|max:20480',
            'folder' => 'nullable|string|max:64',
        ]);

        $file = $request->file('file');
        $folder = $this->sanitizeFolder($request->input('folder'));
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ?: 'upload';
        $filename = substr($filename, 0, 80).'_'.Str::lower(Str::random(8)).'.'.$extension;

        Storage::disk('public')->makeDirectory("files/{$folder}");

        $path = $file->storeAs("files/{$folder}", $filename, 'public');

        File::create([
            'folder' => $folder,
            'filename' => $filename,
            'filepath' => "public/{$path}",
        ]);

        return back()->with('success', 'File uploaded successfully.');
    }

    public function view($id)
    {
        $file = File::findOrFail($id);
        $path = storage_path('app/'.$file->filepath);

        if (! file_exists($path)) {
            return back()->with('error', 'File does not exist.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return response()->file($path);
        } elseif (in_array($extension, ['doc', 'docx'], true)) {
            return response()->download($path);
        }

        return back()->with('error', 'Unsupported file type.');
    }

    public function download($id)
    {
        $file = File::findOrFail($id);
        $path = storage_path('app/'.$file->filepath);

        if (! file_exists($path)) {
            return back()->with('error', 'File does not exist: '.$path);
        }

        $extension = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
        $forcePdf = ($extension !== 'pdf');

        $downloadName = $forcePdf
            ? pathinfo($file->filename, PATHINFO_FILENAME).'.pdf'
            : $file->filename;

        return response()->download($path, $downloadName, [
            'Content-Type' => mime_content_type($path),
        ]);
    }

    public function delete($id)
    {
        $file = File::findOrFail($id);
        Storage::delete($file->filepath);
        $file->delete();

        return back()->with('success', 'File deleted.');
    }

    private function sanitizeFolder(?string $folder): string
    {
        $folder = strtolower(trim((string) $folder));
        if ($folder === '' || ! in_array($folder, self::ALLOWED_FOLDERS, true)) {
            return 'default';
        }

        return $folder;
    }
}
