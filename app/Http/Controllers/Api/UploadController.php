<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096'],
        ]);

        $file = $request->file('file');
        $dir = $request->input('dir', 'settings');
        $dir = trim(preg_replace('/[^a-z0-9_\-]/i', '', $dir), '/') ?: 'settings';

        $name = Str::random(20).'.'.($file->extension() ?: 'png');
        $path = $file->storeAs($dir, $name, 'public');

        return response()->json([
            'code' => '1',
            'message' => 'Uploaded',
            'data' => [
                'filePath' => '/storage/'.$path,
                'path' => $path,
                'url' => asset('storage/'.$path),
            ],
        ], 200);
    }
}
