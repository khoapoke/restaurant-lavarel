<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Traits\ApiResponse;

abstract class BaseController extends Controller
{
    use ApiResponse;
}

