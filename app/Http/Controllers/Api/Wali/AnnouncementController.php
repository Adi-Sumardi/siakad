<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AnnouncementController extends Controller
{
    /**
     * Every notice relevant to any of this guardian's children, merged into
     * one feed - a parent with two children in two units should not have to
     * check twice.
     */
    public function index(Request $request): JsonResponse
    {
        $students = Student::visibleTo($request->user())->get();

        $announcements = $students
            ->reduce(function (Collection $carry, Student $student) {
                $ids = Announcement::live()->forStudent($student)->pluck('id');

                return $carry->merge($ids);
            }, collect())
            ->unique();

        $list = Announcement::whereIn('id', $announcements)
            ->with(['schoolUnit', 'classroom'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get();

        return response()->json(['announcements' => AnnouncementResource::collection($list)]);
    }
}
