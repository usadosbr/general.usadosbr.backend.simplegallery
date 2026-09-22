<?php

namespace Mixdinternet\Galleries\Facades;

use Illuminate\Support\Facades\Cache;

class Html
{
    public function form($model = '', $name = 'images')
    {
        $gallery = $model->galleries($name)->first();
        $pendingCount = 0;

        if ($gallery) {
            $cacheKey = 'gallery_pending:' . $gallery->id;
            $pendingPaths = Cache::get($cacheKey, []);

            if ($pendingPaths) {
                $landed = $gallery->images()->whereIn('name', $pendingPaths)->pluck('name')->all();
                $stillPending = array_values(array_diff($pendingPaths, $landed));

                if ($stillPending !== $pendingPaths) {
                    Cache::put($cacheKey, $stillPending, now()->addMinutes(15));
                }

                $pendingCount = count($stillPending);
            }
        }

        return view('mixdinternet/galleries::admin.galleries.form', [
            'gallery' => $gallery,
            'name' => $name,
            'pendingCount' => $pendingCount,
        ])->render();
    }
}
