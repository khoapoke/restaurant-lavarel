<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateTableRequest;
use App\Http\Requests\Admin\UpdateTableRequest;
use App\Http\Resources\Admin\AdminTableResource;
use App\Services\Admin\TableService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class TableController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TableService $tableService) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            ['items' => AdminTableResource::collection($this->tableService->getTables())],
            'Tables retrieved successfully'
        );
    }

    public function store(CreateTableRequest $request): JsonResponse
    {
        return $this->successResponse(
            new AdminTableResource($this->tableService->create($request->validated())),
            'Table created successfully',
            201
        );
    }

    public function update(UpdateTableRequest $request, int $tableId): JsonResponse
    {
        return $this->successResponse(
            new AdminTableResource($this->tableService->update($tableId, $request->validated())),
            'Table updated successfully'
        );
    }

    public function rotateQrToken(int $tableId): JsonResponse
    {
        return $this->successResponse(
            new AdminTableResource($this->tableService->rotateQrToken($tableId)),
            'Table QR code regenerated successfully'
        );
    }
}
