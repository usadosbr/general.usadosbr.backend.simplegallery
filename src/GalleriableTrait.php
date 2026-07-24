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

            // Collect every processed image across all galleries so a single job
            // handles the whole batch instead of one job (and one vehicle-exists
            // query) per photo.
            $pendingImages = [];

            foreach ($reqGallery as $galleryName) {
                if (!isset($reqImages[$galleryName])) {
                    continue;
                }

                $gallery = $model->galleries($galleryName)->first()
                    ?? $model->galleries($galleryName)->create(['name' => $galleryName]);

                // Continue numbering after the current highest order without loading every image.
                $maxOrder = $gallery->images()->max('order');
                $count = is_null($maxOrder) ? 0 : ((int) $maxOrder + 1);

                foreach ($reqImages[$galleryName] as $k => $v) {
                    try {
                        $order = self::parseExplicitOrder($v);
                        $suffix = is_null($order) ? '' : '--' . $order;
                        $imagepath = $imageName . '-' . Str::random(2) . $suffix . '.webp';

                        $subDir = implode('/', str_split(substr(md5($imagepath), 0, 6), 2));
                        $targetPath = '/media/gallery/' . $subDir . '/' . $imagepath;

                        $webp = self::toWebp(Storage::disk('gcs')->get($v));

                        if (!Storage::disk('gcs')->put($targetPath, $webp)) {
                            continue;
                        }

                        // Only remove the source once the converted image is safely stored.
                        Storage::disk('gcs')->delete($v);

                        $pendingImages[] = [
                            'targetPath' => $targetPath,
                            'imagePath' => $imagepath,
                            'subDir' => $subDir,
                        ];

                        // Skip persisting a duplicate record for the same gallery.
                        // $alreadySaved = Image::where('name', $targetPath)
                        //     ->where('gallery_id', $gallery->id)
                        //     ->exists();

                        // if ($alreadySaved) {
                        //     continue;
                        // }

                        $image = new Image();
                        $image->name = $targetPath;
                        $image->description = '';
                        $image->order = $k + $count;
                        $image->gallery()->associate($gallery);
                        $image->save();

                        $count++;
                    } catch (Throwable $e) {
                        devlogs("Diretório da imagem falhada: {$v}");
                        devlogs($e->getMessage(), '');
                        devlogs('Falha na inserção de imagem do anunciante: ' . $model->advertiser->id, '');
                        devlogs('Falha na inserção de imagem do veículo: ' . $model->id, '');
                    }
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

    public function flatGallery($name = 'images')
    {
        $gallery = $this->gallery($name);
        if (!$gallery) {
            return [];
        }

        return $gallery->images()->select('id', 'name', 'description', 'order')->get();
    }
}
