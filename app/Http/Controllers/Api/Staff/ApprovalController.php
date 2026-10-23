<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\ApprovalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ApprovalDecisionRequest;
use App\Http\Requests\Staff\CreateApprovalRequest;
use App\Http\Requests\Staff\ReasonOnlyRequest;
use App\Http\Resources\Staff\ApprovalResource;
use App\Models\ApprovalRequest;
use App\Services\Staff\ApprovalService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(ApprovalResource::collection($this->approvals->list([
            'status' => $request->query('status', 'PENDING'),
            'sessionId' => $request->integer('sessionId') ?: null,
        ])), 'Approval requests retrieved');
    }

    public function store(CreateApprovalRequest $request): JsonResponse
    {
        $approval = $this->approvals->request(
            ApprovalType::from($request->validated('type')),
            $request->user(),
            (int) $request->validated('targetId'),
            $request->validated('payload') ?? [],
            $request->validated('reason'),
        );

        return $this->successResponse(new ApprovalResource($approval), 'Approval requested', 201);
    }

    public function approve(ApprovalDecisionRequest $request, ApprovalRequest $approval): JsonResponse
    {
        return $this->successResponse(new ApprovalResource($this->approvals->approve($approval, $request->user(), $request->validated('note'))), 'Request approved');
    }

    public function reject(ReasonOnlyRequest $request, ApprovalRequest $approval): JsonResponse
    {
        return $this->successResponse(new ApprovalResource($this->approvals->reject($approval, $request->user(), $request->validated('note'))), 'Request declined');
    }

    public function cancel(Request $request, ApprovalRequest $approval): JsonResponse
    {
        return $this->successResponse(new ApprovalResource($this->approvals->cancel($approval, $request->user())), 'Request withdrawn');
    }
}
