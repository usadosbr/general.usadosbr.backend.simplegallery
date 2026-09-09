<?php

namespace Mixdinternet\Galleries\Jobs;

use App\Jobs\InsertImagesVehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mixdinternet\Galleries\Events\GalleryImagesReady;
use Throwable;

/**
 * Downloads, converts to WebP and uploads every planned gallery image, then
 * creates the Image rows — the work GalleriableTrait used to do inline inside
 * the Eloquent `saved` event (blocking whoever called touch()/save()). Moved
 * here so that caller gets its time back; production Horizon already runs
 * 5-20 processes on the queues this dispatches onto, so different vehicles'
 * image batches already process in parallel.
 */
class ProcessGalleryImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Mirrors WebserviceDownloadImagesCars::DOWNLOAD_CONCURRENCY in the main
     * app, which bounds memory/connections the same way.
     */
    private static $gcsConcurrency = 10;

    /**
     * Longest-side cap (px) applied before WebP encoding. WebP decode/encode
     * cost scales with pixel count, and originals from phone/DSLR cameras can
     * be 4000px+ on a side; 2048 keeps enough resolution for the gallery
     * lightbox/zoom while cutting conversion time substantially on large
     * sources. Mirrors the pre-shrink InsertImagesVehicle::buildAndUploadCrops
     * already does before generating its (smaller) crop variants.
     */
    private static $maxDimension = 2048;

    protected $planned;
    protected $modelClass;
    protected $modelId;
    protected $thenJobs;

    /**
     * @param array $planned Items with 'source' (GCS path), 'targetPath',
     *                       'imagePath', 'subDir', 'order' and 'galleryId'
     *                       from GalleriableTrait's planning pass.
     * @param array $thenJobs Already-constructed ShouldQueue jobs to dispatch
     *                        once every image is processed — for callers that
     *                        need something to run only after Image rows exist
     *                        (e.g. WebserviceDescriptionImages).
     */
    public function __construct(array $planned, string $modelClass, $modelId, array $thenJobs = [])
    {
        $this->planned = $planned;
        $this->modelClass = $modelClass;
        $this->modelId = $modelId;
        $this->thenJobs = $thenJobs;
    }

    public function tags()
    {
        return ['ProcessGalleryImages', $this->modelClass, 'Model:' . $this->modelId];
    }

    public function handle()
    {
        if (!$this->planned) {
            return;
        }

        $jobStart = microtime(true);
        $processed = [];

        $adapter = Storage::disk('gcs')->getDriver()->getAdapter();
        $bucket = $adapter->getBucket();
        $prefix = $adapter->getPathPrefix();
        $requestWrapper = self::gcsRequestWrapper($adapter->getStorageClient());
        $bucketName = rawurlencode($bucket->name());

        $batches = array_chunk($this->planned, self::$gcsConcurrency);
        $batchCount = count($batches);

        foreach ($batches as $batchIndex => $chunk) {
            // 1. Fetch every source from GCS concurrently. Deliberately not
            // passed in from the dispatching job (e.g. WebserviceDownloadImagesCars
            // already has these bytes) — each image's bytes, base64-encoded to
            // survive the Redis driver's JSON payload, added ~80KB per image
            // (~1.6MB for a 20-photo batch) to this job's queued payload size.
            // Under a dispatch burst that outpaces available workers, that is
            // enough to blow well past Redis's memory budget across the many
            // jobs then sitting in the queue at once — a GCS round-trip per
            // image is worth paying to keep the queued payload just a few
            // small strings.
            $downloadStart = microtime(true);
            $bytesByKey = [];
            $downloadPromises = [];

            foreach ($chunk as $i => $item) {
                $downloadPromises[$i] = $bucket->object($adapter->applyPathPrefix($item['source']))->downloadAsStreamAsync();
            }

            $downloaded = \GuzzleHttp\Promise\Utils::settle($downloadPromises)->wait();

            foreach ($downloaded as $i => $result) {
                if (($result['state'] ?? null) === 'fulfilled') {
                    $bytesByKey[$i] = $result['value']->getContents();
                } else {
                    self::logImageFailure($this->modelClass, $this->modelId, $chunk[$i]['source'], $result['reason'] ?? null);
                }
            }

            $downloadMs = round((microtime(true) - $downloadStart) * 1000, 1);

            // 2. Decode each source once (normalizeImage), reused for both the
            // master WebP and the 7 crop blobs — previously the crops re-decoded
            // the master's own WebP output, a second full decode/encode round
            // trip for no reason. CPU-bound, so this stays sequential — the
            // network calls (step 3 below) are what dominate runtime.
            $convertStart = microtime(true);
            $webps = [];
            $cropBlobsByKey = [];

            foreach ($chunk as $i => $item) {
                if (!isset($bytesByKey[$i])) {
                    continue;
                }

                $imageConvertStart = microtime(true);
                $base = null;
                try {
                    $base = InsertImagesVehicle::normalizeImage($bytesByKey[$i], self::$maxDimension);

                    $master = clone $base;
                    $master->setImageFormat('webp');
                    $master->setImageCompressionQuality(82);
                    $webps[$i] = $master->getImageBlob();
                    $master->clear();
                    $master->destroy();

                    $cropBlobsByKey[$i] = InsertImagesVehicle::buildCropBlobs($base, $item['imagePath'], $item['subDir']);

                    Log::info('ProcessGalleryImages: conversão para webp', [
                        'model' => $this->modelClass,
                        'model_id' => $this->modelId,
                        'source' => $item['source'],
                        'duration_ms' => round((microtime(true) - $imageConvertStart) * 1000, 1),
                    ]);
                } catch (Throwable $e) {
                    self::logImageFailure($this->modelClass, $this->modelId, $item['source'], $e);
                } finally {
                    if ($base) {
                        $base->clear();
                        $base->destroy();
                    }
                }
            }

            $convertMs = round((microtime(true) - $convertStart) * 1000, 1);
            unset($bytesByKey);

            $uploadMs = 0;
            $cropUploadMs = 0;
            $deleteMs = 0;
            if ($webps) {
                // 3. Upload every converted master WebP in this batch concurrently.
                $uploadStart = microtime(true);
                $uploadPromises = [];
                foreach ($webps as $i => $webp) {
                    $uploadPromises[$i] = $bucket->uploadAsync($webp, [
                        'name' => $prefix . ltrim($chunk[$i]['targetPath'], '/'),
                        'predefinedAcl' => 'publicRead',
                    ]);
                }
                $uploadResults = \GuzzleHttp\Promise\Utils::settle($uploadPromises)->wait();
                $uploadMs = round((microtime(true) - $uploadStart) * 1000, 1);

                // 4. Only originals whose master WebP is safely stored are kept:
                // their crops (already built above from the same decode) get
                // merged into one flat batch and uploaded together in a single
                // wide concurrent wave — instead of one image's 7 crops at a
                // time, ~70 crops across the whole 10-image batch upload at once.
                $allCropBlobs = [];
                $deletePromises = [];
                foreach (array_keys($webps) as $i) {
                    if (($uploadResults[$i]['state'] ?? null) !== 'fulfilled') {
                        self::logImageFailure($this->modelClass, $this->modelId, $chunk[$i]['source'], $uploadResults[$i]['reason'] ?? null);
                        continue;
                    }

                    $processed[] = $chunk[$i];

                    if (isset($cropBlobsByKey[$i])) {
                        $allCropBlobs += $cropBlobsByKey[$i];
                    }

                    $objectName = rawurlencode($adapter->applyPathPrefix($chunk[$i]['source']));
                    $deletePromises[$i] = $requestWrapper->sendAsync(
                        new \GuzzleHttp\Psr7\Request('DELETE', "https://storage.googleapis.com/storage/v1/b/{$bucketName}/o/{$objectName}")
                    );
                }
                unset($cropBlobsByKey);

                if ($allCropBlobs) {
                    $cropUploadStart = microtime(true);
                    try {
                        InsertImagesVehicle::uploadAll($allCropBlobs);
                    } catch (Throwable $e) {
                        // A single failed crop upload rejects the whole batched
                        // wave (GuzzleHttp\Promise\all() semantics) — since we
                        // can't tell which image it belonged to from here, this
                        // is logged batch-wide rather than per-image. The Image
                        // row is still created either way (crops are secondary
                        // display variants, not the gallery's source of truth).
                        Log::warning('ProcessGalleryImages: falha ao subir recortes em lote', [
                            'model' => $this->modelClass,
                            'model_id' => $this->modelId,
                            'imagens_no_lote_de_recortes' => count($allCropBlobs),
                            'erro' => $e->getMessage(),
                        ]);
                    }
                    $cropUploadMs = round((microtime(true) - $cropUploadStart) * 1000, 1);
                }
                unset($allCropBlobs);

                $deleteStart = microtime(true);
                if ($deletePromises) {
                    \GuzzleHttp\Promise\Utils::settle($deletePromises)->wait();
                }
                $deleteMs = round((microtime(true) - $deleteStart) * 1000, 1);
            }

            Log::info('ProcessGalleryImages: lote processado', [
                'model' => $this->modelClass,
                'model_id' => $this->modelId,
                'lote' => $batchIndex + 1,
                'total_lotes' => $batchCount,
                'imagens_no_lote' => count($chunk),
                'imagens_convertidas' => count($webps),
                'download_ms' => $downloadMs,
                'convert_ms' => $convertMs,
                'upload_ms' => $uploadMs,
                'crop_upload_ms' => $cropUploadMs,
                'delete_ms' => $deleteMs,
            ]);
        }

        // Bulk-create every Image row in one insert instead of one ->save()
        // per row: Image has no model events/observers, so a raw insert
        // (bypassing Eloquent) skips nothing.
        if ($processed) {
            $now = now();
            $rows = array_map(function ($item) use ($now) {
                return [
                    'gallery_id' => $item['galleryId'],
                    'name' => $item['targetPath'],
                    'description' => '',
                    'order' => $item['order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $processed);

            DB::table('galleries_images')->insert($rows);
        }

        Log::info('ProcessGalleryImages: processamento concluído', [
            'model' => $this->modelClass,
            'model_id' => $this->modelId,
            'imagens_planejadas' => count($this->planned),
            'imagens_processadas' => count($processed),
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 1),
        ]);

        // Refresh anything that assumed Image rows existed synchronously right
        // after touch()/save() (see the host app's listeners for this event).
        event(new GalleryImagesReady($this->modelClass, $this->modelId));

        foreach ($this->thenJobs as $job) {
            dispatch($job);
        }
    }

    private static function gcsRequestWrapper($storageClient)
    {
        $property = new \ReflectionProperty($storageClient, 'connection');
        $property->setAccessible(true);

        return $property->getValue($storageClient)->requestWrapper();
    }

    private static function logImageFailure(string $modelClass, $modelId, string $source, $reason = null): void
    {
        Log::warning('ProcessGalleryImages: falha ao processar imagem', [
            'model' => $modelClass,
            'model_id' => $modelId,
            'source' => $source,
            'erro' => $reason instanceof Throwable ? $reason->getMessage() : null,
        ]);
    }
}
