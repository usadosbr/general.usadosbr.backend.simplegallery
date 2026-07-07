<?php

namespace Mixdinternet\Galleries\Http\Controllers;

use App\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mixdinternet\Galleries\Image;

class GalleriesAdminController extends AdminController
{
    /**
     *
     * @param Request $request
     * @return array
     */
    public function upload(Request $request)
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            $fileInfo = pathinfo($file->getClientOriginalName());
            $fileName = str_slug(str_limit($fileInfo['filename'], 50, '') . '-' . rand(1, 999)) . '.' . $file->getClientOriginalExtension();

            $fullFile = file_get_contents($file);

            $subDir = implode('/', str_split(substr(md5($fileName), 0, 6), 2));

            Storage::disk('gcs')->put('/cache/media/gallery/' . $subDir . '/' . $fileName, $fullFile);

            // if (config('mgalleries.watermark')) {
            //     $imagine = new \Imagine\Imagick\Imagine();
            //     $image = $imagine->open('/cache/media/gallery/' . $subDir . '/' . $fileName);
            //     $watermark = $imagine->open(config('mgalleries.watermark'));
            //     $imageSize = $image->getSize();
            //     $watermarkSize = $watermark->getSize();
            //     $height = ($imageSize->getWidth() / ($watermarkSize->getWidth() / $watermarkSize->getHeight()));
            //     $watermark->resize(new \Imagine\Image\Box($imageSize->getWidth(), $height));
            //     $watermarkSize = $watermark->getSize();
            //     $position = new \Imagine\Image\Point(0, $imageSize->getHeight() - $watermarkSize->getHeight());
            //     $image->paste($watermark, $position);
            //     Storage::disk('gcs')->put('/cache/media/gallery/' . $subDir . '/' . $fileName, $image);
            //     // $image->save('/media/gallery/' . $subDir . '/' . $fileName);
            // }
            return [
                'status' => 'success',
                'name' => '/cache/media/gallery/' . $subDir . '/' . $fileName,
            ];
        }
        return [
            'status' => 'error',
        ];
    }

    /**
     *
     * @param Request $request
     * @return void
     */
    public function sort(Request $request)
    {
        $images = $request->get('image');
        foreach ($images as $k => $v) {
            Image::find($v)->update(['order' => $k]);
        }
    }

    /**
     *
     * @param Request $request
     * @return void
     */
    public function update(Request $request)
    {
        $id = $request->get('id');
        $description = $request->get('description');
        Image::find($id)->update(['description' => $description]);
    }

    /**
     *
     * @param Request $request
     * @return void
     */
    public function destroy(Request $request)
    {
        $id = $request->get('id');
        Image::destroy($id);
    }
}
