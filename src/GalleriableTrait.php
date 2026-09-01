<?php

namespace Mixdinternet\Galleries;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\InsertImagesVehicle;
use Throwable;
use Mixdinternet\Cars\Car;
use Mixdinternet\Motorcycles\Motorcycle;
use Mixdinternet\Sailings\Sailing;
use Mixdinternet\Trucks\Truck;

trait GalleriableTrait
{
    /**
     * How many GCS requests (download/upload/delete) run concurrently per
     * batch inside processImages(). Mirrors
     * WebserviceDownloadImagesCars::DOWNLOAD_CONCURRENCY in the main app,
     * which bounds memory/connections the same way.
     */
    private static $gcsConcurrency = 10;

    public static function bootGalleriableTrait()
    {
        self::saved(function ($model) {
            // Both "gallery" and "images" must be present in the request, otherwise there is nothing to do.
            if (!request()->has('gallery') || !request()->has('images')) {
                return;
            }

            $reqGallery = request()->get('gallery');
            $reqImages = request()->get('images');

            $imageName = strtolower(self::buildGalleryImageName($model, $reqGallery));
            $modelClass = get_class($model);
            $queue = self::isIntegradorVehicle($model) ? 'imgs_integrador' : 'vehicles';

            // First pass: resolve/create every gallery and assign each image its
            // target path + order, without touching the network yet. Order is
            // decided here, from the original request position, so the parallel
            // GCS batches below can finish in any order without corrupting it.
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
                        'order' => $k + $count,
                        'gallery' => $gallery,
                        'galleryName' => $galleryName,
                    ];

                    $count++;
                }
            }

            // Download, convert and upload every image in bounded concurrent
            // batches instead of one full get->convert->put->delete round trip
            // at a time. Collect every processed image across all galleries so
            // a single job handles the whole batch instead of one job (and one
            // vehicle-exists query) per photo.
            $pendingImages = [];

            foreach (self::processImages($planned, $model) as $item) {
                try {
                    $pendingImages[] = [
                        'targetPath' => $item['targetPath'],
                        'imagePath' => $item['imagePath'],
                        'subDir' => $item['subDir'],
                    ];

                    // Skip persisting a duplicate record for the same gallery.
                    // $alreadySaved = Image::where('name', $item['targetPath'])
                    //     ->where('gallery_id', $item['gallery']->id)
                    //     ->exists();

                    // if ($alreadySaved) {
                    //     continue;
                    // }

                    $image = new Image();
                    $image->name = $item['targetPath'];
                    $image->description = '';
                    $image->order = $item['order'];
                    $image->gallery()->associate($item['gallery']);
                    $image->save();

                    // Keep an already-resolved flatGallery() cache in sync so a later
                    // call in the same request sees the image we just created.
                    if (array_key_exists($item['galleryName'], $model->resolvedFlatGalleries)) {
                        $model->resolvedFlatGalleries[$item['galleryName']][] = $image;
                    }
                } catch (Throwable $e) {
                    self::logImageFailure($model, $item['source'], $e);
                }
            }

            // One job per save, regardless of photo count: the job checks the
            // vehicle exists once and processes every crop internally.
            if (!empty($pendingImages)) {
                dispatch(new InsertImagesVehicle($pendingImages, $model->id, $modelClass))
                    ->onQueue($queue);
            }

            request()->replace(array_merge(request()->all(), ['gallery' => [''], 'images' => []]));
        });
    }

    /**
     * Download every planned image's source blob from GCS, convert it to
     * WebP, upload it and delete the source — in bounded concurrent batches
     * per phase instead of one full round trip per image. Mirrors the
     * chunked promise pattern already used by
     * InsertImagesVehicle::uploadAll() and
     * WebserviceDownloadImagesCars::photos() in the main app.
     *
     * @param array $planned Items with 'source', 'targetPath', 'imagePath',
     *                       'subDir', 'order', 'gallery', 'galleryName' from
     *                       the planning pass in bootGalleriableTrait().
     * @return array The items whose WebP was uploaded successfully.
     */
    private static function processImages(array $planned, $model): array
    {
        if (!$planned) {
            return [];
        }

        $adapter = Storage::disk('gcs')->getDriver()->getAdapter();
        $bucket = $adapter->getBucket();
        $prefix = $adapter->getPathPrefix();
        $requestWrapper = self::gcsRequestWrapper($adapter->getStorageClient());
        $bucketName = rawurlencode($bucket->name());

        $processed = [];

        foreach (array_chunk($planned, self::$gcsConcurrency) as $chunk) {
            // 1. Fetch every source in this batch concurrently.
            $downloadPromises = [];
            foreach ($chunk as $i => $item) {
                $downloadPromises[$i] = $bucket->object($adapter->applyPathPrefix($item['source']))->downloadAsStreamAsync();
            }
            $downloaded = \GuzzleHttp\Promise\Utils::settle($downloadPromises)->wait();

            // 2. Convert whatever downloaded successfully. CPU-bound, so this
            // stays sequential — the network calls are what dominate runtime.
            $webps = [];
            foreach ($chunk as $i => $item) {
                if (($downloaded[$i]['state'] ?? null) !== 'fulfilled') {
                    self::logImageFailure($model, $item['source'], $downloaded[$i]['reason'] ?? null);
                    continue;
                }

                try {
                    $webps[$i] = self::toWebp($downloaded[$i]['value']->getContents());
                } catch (Throwable $e) {
                    self::logImageFailure($model, $item['source'], $e);
                }
            }

            if (!$webps) {
                continue;
            }

            // 3. Upload every converted WebP in this batch concurrently.
            $uploadPromises = [];
            foreach ($webps as $i => $webp) {
                $uploadPromises[$i] = $bucket->uploadAsync($webp, [
                    'name' => $prefix . ltrim($chunk[$i]['targetPath'], '/'),
                    'predefinedAcl' => 'publicRead',
                ]);
            }
            $uploadResults = \GuzzleHttp\Promise\Utils::settle($uploadPromises)->wait();

            // 4. Only originals whose WebP is safely stored are queued for
            // deletion, and only successful uploads are returned for saving.
            $deletePromises = [];
            foreach (array_keys($webps) as $i) {
                if (($uploadResults[$i]['state'] ?? null) !== 'fulfilled') {
                    self::logImageFailure($model, $chunk[$i]['source'], $uploadResults[$i]['reason'] ?? null);
                    continue;
                }

                $processed[] = $chunk[$i];

                $objectName = rawurlencode($adapter->applyPathPrefix($chunk[$i]['source']));
                $deletePromises[$i] = $requestWrapper->sendAsync(
                    new \GuzzleHttp\Psr7\Request('DELETE', "https://storage.googleapis.com/storage/v1/b/{$bucketName}/o/{$objectName}")
                );
            }

            if ($deletePromises) {
                \GuzzleHttp\Promise\Utils::settle($deletePromises)->wait();
            }
        }

        return $processed;
    }

    /**
     * The GCS PHP client has no public async-delete API. Its request layer
     * (auth + retries) is reachable only through StorageClient's private
     * $connection, so it's pulled out once via reflection and reused as
     * requestWrapper()->sendAsync() to drive concurrent deletes. Mirrors
     * WebserviceDownloadImagesCars::gcsRequestWrapper() in the main app.
     */
    private static function gcsRequestWrapper($storageClient)
    {
        $property = new \ReflectionProperty($storageClient, 'connection');
        $property->setAccessible(true);

        return $property->getValue($storageClient)->requestWrapper();
    }

    /**
     * Same log shape as the previous single-catch-block loop, so existing
     * log-based monitoring/alerts for this event keep working unchanged.
     */
    private static function logImageFailure($model, string $source, $reason = null): void
    {
        devlogs("Diretório da imagem falhada: {$source}");
        if ($reason instanceof Throwable) {
            devlogs($reason->getMessage(), '');
        }
        devlogs('Falha na inserção de imagem do anunciante: ' . $model->advertiser->id, '');
        devlogs('Falha na inserção de imagem do veículo: ' . $model->id, '');
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
     * Convert a raw image blob to a stripped WebP blob (in memory, no temp files).
     */
    private static function toWebp(string $blob): string
    {
        $imagick = new \Imagick();
        $imagick->readImageBlob($blob);
        $imagick->stripImage();
        $imagick->setImageFormat('webp');
        $webp = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return $webp;
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
