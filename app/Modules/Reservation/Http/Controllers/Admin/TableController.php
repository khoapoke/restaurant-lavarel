<?php

namespace App\Modules\Reservation\Http\Controllers\Admin;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Reservation\Http\Requests\Admin\CreateTableRequest;
use App\Modules\Reservation\Http\Requests\Admin\UpdateTableRequest;
use App\Modules\Reservation\Http\Resources\Admin\AdminTableResource;
use App\Modules\Reservation\Services\Admin\TableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableController extends BaseController
{

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

    /**
     * Update table status (BKG-08).
     *
     * @param Request $request
     * @param int $tableId
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $tableId): JsonResponse
    {
        $status = (string) ($request->input('status') ?? $request->input('targetStatus'));
        $table = $this->tableService->updateTableStatus($tableId, $status);

        return $this->successResponse(
            new AdminTableResource($table),
            'Table status updated successfully',
            Response::HTTP_OK
        );
    }
}


