<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaqrizStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TaqrizResource;
use App\Models\Taqriz;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TaqrizController extends Controller
{
    /**
     * The permalink for a single review, so a taqrizchi can share their own
     * work directly.
     *
     * An unapproved taqriz is 404 rather than 403: the distinction would
     * confirm that a review exists and is being held, which is nobody's
     * business but the author's and the admin's.
     */
    public function show(Taqriz $taqriz): TaqrizResource
    {
        if ($taqriz->status !== TaqrizStatus::Approved) {
            throw new NotFoundHttpException;
        }

        $taqriz->load(['taqrizchi', 'book']);

        return new TaqrizResource($taqriz);
    }
}
