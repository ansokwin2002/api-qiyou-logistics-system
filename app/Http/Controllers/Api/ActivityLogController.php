<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Controllers\Traits\FrontendResponse;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use ApiResponse, FrontendResponse;

    public function index(Request $request)
    {
        $query = ActivityLog::query()->latest('created_at')->latest('id');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($action = $request->input('action')) {
            $query->where('action', 'like', $action.'%');
        }

        return $this->ok($query->paginate($request->integer('per_page', 20)));
    }

    public function list(Request $request)
    {
        $params = $this->getParams($request);
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = max(1, (int) ($params['pageSize'] ?? 20));

        $query = ActivityLog::query()->latest('created_at')->latest('id');

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('user_name', 'like', "%{$keyword}%")
                    ->orWhere('action', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(fn ($log) => [
            'id' => $log->id,
            'userName' => $log->user_name ?? 'system',
            'action' => $log->action,
            'description' => $log->description,
            'method' => $log->method,
            'path' => $log->path,
            'ip' => $log->ip_address,
            'statusCode' => $log->status_code,
            'addTime' => $log->created_at ? strtotime($log->created_at) : time(),
        ])->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }
}
