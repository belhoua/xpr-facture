<?php

declare(strict_types=1);

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Services\PartnerService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PartnerArchiveController
{
    public function __construct(private readonly PartnerService $partners) {}

    public function __invoke(string $partner): JsonResponse
    {
        $this->partners->archive($this->partners->findForCompany($partner));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
