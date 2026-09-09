<?php

namespace Mixdinternet\Galleries;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mixdinternet\Cars\Car;
use Mixdinternet\Galleries\Jobs\ProcessGalleryImages;
use Mixdinternet\Motorcycles\Motorcycle;
use Mixdinternet\Sailings\Sailing;
use Mixdinternet\Trucks\Truck;

trait GalleriableTrait
{
    public static function bootGalleriableTrait()
    {
        self::saved(function ($model) {
            // Both "gallery" and "images" must be present in the request, otherwise there is nothing to do.
            if (!request()->has('gallery') || !request()->has('images')) {
                return;
            }

            $savedStart = microtime(true);

            $reqGallery = request()->get('gallery');
            $reqImages = request()->get('images');

            $imageName = strtolower(self::buildGalleryImageName($model, $reqGallery));
            $modelClass = get_class($model);
            $queue = self::isIntegradorVehicle($model) ? 'imgs_integrador' : 'vehicles';

            // First pass: resolve/create every gallery and assign each image its
            // target path + order, without touching the network yet. Order is
            // decided here, from the original request position, so the parallel
            // GCS batches in ProcessGalleryImages can finish in any order without
            // corrupting it. This whole pass is cheap (no downloads/Imagick), so
            // it stays synchronous — only the network/CPU-heavy work is queued.
            $planned = [];

            foreach ($reqGallery as $galleryName) {
                if (!isset($reqImages[$galleryName])) {
                    continue;
                }

                // Reuse the memoized accessor so a later gallery()/flatGallery() call in the
                // same request doesn't re-query for the gallery we just fetched or created.
                $gallery = $model->gallery($galleryName);

                if (!$gallery) {
                    $gallery = $model->galleries($galleryName)->create(['name' => $galleryName]);
                    $model->resolvedGalleries[$galleryName] = $gallery;
                }

                // Continue numbering after the current highest order without loading every image.
                $maxOrder = $gallery->images()->max('order');
                $count = is_null($maxOrder) ? 0 : ((int) $maxOrder + 1);

                foreach ($reqImages[$galleryName] as $k => $v) {
                    $order = self::parseExplicitOrder($v);
                    $suffix = is_null($order) ? '' : '--' . $order;
                    $imagepath = $imageName . '-' . Str::random(2) . $suffix . '.webp';

                    $subDir = implode('/', str_split(substr(md5($imagepath), 0, 6), 2));
                    $targetPath = '/media/gallery/' . $subDir . '/' . $imagepath;

                    $planned[] = [
                        'source' => $v,
                        'targetPath' => $targetPath,
                        'imagePath' => $imagepath,
                        'subDir' => $subDir,
                        // $count alone: it already starts after the gallery's
                        // existing images and increments once per new image
                        // below. Adding $k too (pre-existing bug, predates this
                        // refactor) double-counted, since $k already advances
                        // in lockstep with $count — order came out as 0,2,4...
                        // instead of 0,1,2...
                        'order' => $count,
                        'galleryId' => $gallery->id,
                    ];

                    $count++;
                }
            }

            // A caller that needs something to run only after Image rows exist
            // (e.g. WebserviceDescriptionImages, which reads $vehicle->images)
            // passes factories here instead of dispatching right after touch():
            // $model->id is only known for sure once we're inside this saved()
            // event, so each factory is called now, synchronously, with it.
            $thenJobs = [];
            foreach (request()->get('gallery_then', []) as $factory) {
                if (is_callable($factory)) {
                    $thenJobs[] = $factory($model->id);
                }
            }

            if ($planned) {
                dispatch(new ProcessGalleryImages($planned, $modelClass, $model->id, $thenJobs))
                    ->onQueue($queue);
            }

            request()->replace(array_merge(request()->all(), ['gallery' => [''], 'images' => [], 'gallery_then' => []]));

            Log::info('GalleriableTrait: planejamento de galeria concluído', [
                'model' => $modelClass,
                'model_id' => $model->id,
                'galerias' => $reqGallery,
                'imagens_planejadas' => count($planned),
                'duration_ms' => round((microtime(true) - $savedStart) * 1000, 1),
            ]);
        });
    }

    /**
     * Build the base file name (without extension) used for every image of this model.
     */
    private static function buildGalleryImageName($model, array $reqGallery): string
    {
        $idHash = substr(md5($model->id), 2, 8);

        if (($reqGallery[0] ?? null) === 'images') {
            return $model->slug . '-' . $idHash;
        }

        if (!isset($model->version->model->brand->slug) && $model->external_id) {
            $brand = $model->slug;
            $modelo = $model->advertiser_id;
            $version = $model->external_id;
        } else {
            $brand = $model->version->model->brand->slug;
            $modelo = $model->version->model->slug;
            $version = $model->version->slug;
        }

        $name = implode('-', [
            $brand,
            $modelo,
            $version,
            $model->year_mod,
            $model->advertiser->city->slug,
            $idHash,
        ]);

        return str_replace([' ', '.'], '-', $name);
    }

    /**
     * A "--<n>" segment in the source path encodes an explicit order for the image.
     */
    private static function parseExplicitOrder(string $path): ?int
    {
        if (strpos($path, '--') === false) {
            return null;
        }

        $parts = explode('--', $path);

        return isset($parts[1]) ? (int) explode('.', $parts[1])[0] : null;
    }

    /**
     * Whether the vehicle comes from the integrador feed, which uses a dedicated queue.
     */
    private static function isIntegradorVehicle($model): bool
    {
        switch (get_class($model)) {
            case Car::class:
            case Motorcycle::class:
                return !is_null($model->code);
            case Truck::class:
            case Sailing::class:
                return true;
            default:
                return false;
        }
    }

    /**
     * Resolved galleries keyed by name, memoized per model instance so repeated
     * accessor/helper calls don't re-run the same "where name = ?" query.
     */
    protected $resolvedGalleries = [];

    /**
     * Flattened gallery images keyed by gallery name, memoized per model instance
     * so repeated calls don't re-run the same images query.
     */
    protected $resolvedFlatGalleries = [];

    public function galleries($name = 'images')
    {
        return $this->morphMany(\Mixdinternet\Galleries\Gallery::class, 'galleriable')->where('name', $name);
    }

    public function gallery($name = 'images')
    {
        if (!array_key_exists($name, $this->resolvedGalleries)) {
            $this->resolvedGalleries[$name] = $this->galleries($name)->first();
        }

        return $this->resolvedGalleries[$name];
    }

    public function getGalleryAttribute()
    {
        return $this->gallery();
    }

    /**
     * Return a flat array of images for the given gallery name, or an empty array if the gallery doesn't exist. This is useful for APIs that need to return a simple list of images without nested relationships.
     *
     * @param string $name The name of the gallery to retrieve images from.
     * @return array An array of images with 'id', 'name', 'description', and 'order' fields, or an empty array if the gallery doesn't exist.
     */
    public function flatGallery($name = 'images')
    {
        if (array_key_exists($name, $this->resolvedFlatGalleries)) {
            return $this->resolvedFlatGalleries[$name];
        }

        $gallery = $this->gallery($name);

        if (!$gallery) {
            return $this->resolvedFlatGalleries[$name] = [];
        }

        return $this->resolvedFlatGalleries[$name] = $gallery->images()->select('id', 'name', 'description', 'order')->get();
    }
}
