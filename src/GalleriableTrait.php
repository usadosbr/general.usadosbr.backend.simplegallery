<?php

namespace Mixdinternet\Galleries;

use Illuminate\Support\Facades\Storage;
use App\Jobs\InsertImagesVehicle;
use Carbon\Carbon;
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
            if (!request()->has('gallery')) {
                return;
            }

            if (!request()->has('images')) {
                return;
            }

            if (request()->get('gallery')[0] == 'images') {
                $idmd5 = substr(md5($model->id), 2, 8);
                $imageName = $model->slug . '-' . $idmd5;
            } else {
                if (!isset($model->version->model->brand->slug) && $model->external_id) {
                    $brand = $model->slug;
                    $modelo = $model->advertiser_id;
                    $version = $model->external_id;
                } else {
                    $brand = $model->version->model->brand->slug;
                    $modelo = $model->version->model->slug;
                    $version = $model->version->slug;
                }
                $year = $model->year_mod;
                $city = $model->advertiser->city->slug;
                $idmd5 = substr(md5($model->id), 2, 8);

                $imageName = $brand . '-' . $modelo . '-' . $version . '-' . $year . '-' . $city . '-' . $idmd5;
                $imageName = str_replace(' ', '-', $imageName);
                $imageName = str_replace('.', '-', $imageName);
            }

            $reqGallery = request()->get('gallery');
            $reqImages = request()->get('images');

            foreach ($reqGallery as $galleryName) {

                if (isset($reqImages[$galleryName])) {
                    $gallery = $model->galleries($galleryName)->first();
                    if ($gallery == null) {
                        $gallery = $model->galleries($galleryName)->create(['name' => $galleryName]);
                    }

                    $last = $gallery->images()->get()->last();
                    $count = 0;
                    if ($last) {
                        $count = ($last->order + 1);
                    }

                    foreach ($reqImages[$galleryName] as $k => $v) {
                        try {

                            $toOrder = false;
                            if (strpos($v, "--") !== false) {
                                $arrExplode1 = explode('--', $v);
                                if ($arrExplode1 != []) {
                                    $arrExplode2 = explode('.', $arrExplode1[1]);
                                    $order = (int) $arrExplode2[0];
                                    $toOrder = true;
                                }
                            }

                            $imageGCS = Storage::disk('gcs')->get($v);

                            $imagick = new \Imagick();

                            $imagick->readimageblob($imageGCS);
                            $imagick->stripImage();
                            $w = $imagick->getImageWidth();
                            $h = $imagick->getImageHeight();

                            $imageGCS = $imagick->getimageblob();

                            $friendlyName = explode('/', $v)[7];
                            $extension = pathinfo($friendlyName, PATHINFO_EXTENSION);

                            if ($toOrder) {
                                $imagepath = strtolower($imageName) . '-' . str_random(2) . '--' . $order . '.' . 'webp';
                            } else {
                                $imagepath = strtolower($imageName) . '-' . str_random(2) . '.' . 'webp';
                            }



                            //$imagepath = strtolower($imageName) . '-' . str_random(2) . '.' . $extension;

                            $subDir = implode('/', str_split(substr(md5($imagepath), 0, 6), 2));

                            $targetPath = '/media/gallery/' . $subDir . '/' . $imagepath;


                            $data = imagecreatefromstring($imageGCS);

                            ob_start();

                            imagejpeg($data);

                            $cont = ob_get_contents();

                            ob_end_clean();

                            $content = imagecreatefromstring($cont);

                            imagewebp($content, storage_path($imagepath));


                            $data = file_get_contents(storage_path($imagepath));

                            $image = new Image();
                            $uploaded = Storage::disk('gcs')->put($targetPath, $data);


                            unlink(storage_path($imagepath));

                            Storage::disk('gcs')->delete($v);
                            $image->name = $targetPath;
                            if ($uploaded) {

                                $modelType = get_class($model);
                                switch ($modelType) {
                                    case 'Mixdinternet\Cars\Car':
                                        $vehicleIntegrador = Car::where('id', $model->id)
                                            ->whereNotNull('code')->first();
                                        break;
                                    case 'Mixdinternet\Motorcycles\Motorcycle':
                                        $vehicleIntegrador = Motorcycle::where('id', $model->id)
                                            ->whereNotNull('code')->first();
                                        break;
                                    case 'Mixdinternet\Trucks\Truck':
                                        $vehicleIntegrador = Truck::where('id', $model->id)->first();
                                        break;
                                    case 'Mixdinternet\Sailings\Sailing':
                                        $vehicleIntegrador = Sailing::where('id', $model->id)->first();
                                        break;
                                    default:
                                        $vehicleIntegrador = false;
                                        break;
                                } //switch

                                if ($vehicleIntegrador) {
                                    dispatch(new InsertImagesVehicle($targetPath, $imagepath, $subDir, $model->id, get_class($model)))->onQueue('imgs_integrador');
                                } else {
                                    dispatch(new InsertImagesVehicle($targetPath, $imagepath, $subDir, $model->id, get_class($model)))->onQueue('vehicles');
                                }

                                $alreadySaved = Image::where('name', $image->name)->where('gallery_id', $gallery->id)->count();

                                if ($alreadySaved >= 1) {
                                    break;
                                }

                                $image->description = '';
                                $image->order = ($k + $count);
                                $count++;
                                $image->gallery()->associate($gallery);
                                $image->save();
                            }
                        } catch (Throwable $e) {
                            devlogs("Diretório da imagem falhada: {$v}");
                            devlogs($e->getMessage(), '');
                            devlogs('Falha na inserção de imagem do anunciante: ' . $model->advertiser->id, '');
                            devlogs('Falha na inserção de imagem do veículo: ' . $model->id, '');
                        }
                    }
                }
            }

            request()->replace(array_merge(request()->all(), ['gallery' => [''], 'images' => []]));
        });
    }

    public function galleries($name = 'images')
    {
        return $this->morphMany(\Mixdinternet\Galleries\Gallery::class, 'galleriable')->where('name', $name);
    }

    public function gallery($name = 'images')
    {
        return $this->galleries($name)->first();
    }

    public function getGalleryAttribute()
    {
        return $this->gallery();
    }

    public function flatGallery($name = 'images')
    {
        $gallery = $this->galleries($name)->first();
        if (!$gallery) {
            return [];
        }

        return $gallery->images()->select('id', 'name', 'description', 'order')->get();
    }
}
