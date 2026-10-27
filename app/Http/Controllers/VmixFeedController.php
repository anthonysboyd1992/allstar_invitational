<?php

namespace App\Http\Controllers;

use App\Services\VmixFeed;
use Illuminate\Http\JsonResponse;

class VmixFeedController extends Controller
{
    public function lineup(VmixFeed $feed): JsonResponse
    {
        return $this->respond($feed->lineup());
    }

    public function driver(VmixFeed $feed): JsonResponse
    {
        return $this->respond($feed->driver());
    }

    public function data(VmixFeed $feed): JsonResponse
    {
        return $this->respond((object) $feed->data());
    }

    /**
     * @param  array<string, mixed>|object  $data
     */
    private function respond(array|object $data): JsonResponse
    {
        return response()
            ->json($data, options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'no-store');
    }
}
