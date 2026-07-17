<?php

namespace App\Http\Controllers;

use App\Models\ArchiveObject;
use App\Models\UsageMeter;
use Illuminate\View\View;

class UsageController extends Controller
{
    /**
     * Monthly document counters — the input for client tier billing and
     * the guard on our own poštár API spend.
     */
    public function index(): View
    {
        $meters = UsageMeter::orderByDesc('period')->orderBy('metric')->get();

        return view('usage.index', [
            'meters' => $meters->groupBy('period'),
            'archive' => [
                'objects' => ArchiveObject::count(),
                'bytes' => (int) ArchiveObject::sum('size_bytes'),
            ],
        ]);
    }
}
