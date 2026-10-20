<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateTableRequest;
use App\Http\Requests\Admin\UpdateTableRequest;
use App\Http\Resources\Admin\AdminTableResource;
use App\Services\Admin\TableService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableController extends Controller
{
    use ApiResponse;

    protected TableService $tableService;

    public function __construct(TableService $tableService)
    {
        $this->tableService = $tableService;
    }

    /**
     * Get list of all dining tables (BKG-05).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tables = $this->tableService->getTables($request->all());

        return $this->successResponse(
            AdminTableResource::collection($tables),
            'Tables retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Get single table details.
     *
     * @param int $tableId
     * @return JsonResponse
     */
    public function show(int $tableId): JsonResponse
    {
        $table = $this->tableService->getTable($tableId);

        return $this->successResponse(
            new AdminTableResource($table),
            'Table details retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Create a new table (BKG-06).
     *
     * @param CreateTableRequest $request
     * @return JsonResponse
     */
    public function store(CreateTableRequest $request): JsonResponse
    {
        $table = $this->tableService->createTable($request->validated());

        return $this->successResponse(
            new AdminTableResource($table),
            'Table created successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Update table info (BKG-06).
     *
     * @param UpdateTableRequest $request
     * @param int $tableId
     * @return JsonResponse
     */
    public function update(UpdateTableRequest $request, int $tableId): JsonResponse
    {
        $table = $this->tableService->updateTable($tableId, $request->validated());

        return $this->successResponse(
            new AdminTableResource($table),
            'Table updated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Delete table (BKG-06).
     *
     * @param int $tableId
     * @return JsonResponse
     */
    public function destroy(int $tableId): JsonResponse
    {
        $this->tableService->deleteTable($tableId);

        return $this->successResponse(
            null,
            'Table deleted successfully',
            Response::HTTP_OK
        );
    }
}
