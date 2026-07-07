<?php

namespace Mixdinternet\Galleries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Image extends Model
{
    use SoftDeletes;

    protected $table = 'galleries_images';

    protected $fillable = ['name', 'description', 'order'];

    protected $appends = ['crop_images'];

    public function gallery()
    {
        return $this->belongsTo(\Mixdinternet\Galleries\Gallery::class);
    }

    public function getCropImagesAttribute(){
        $name = $this->name;
        $ext = array_reverse(explode('.',$name));
        $name = substr($name, 0 , (strrpos($this->name, ".")));
       
        return  [
            '126x95'   => "/manipulatedImages".$name."-image-126x95-crop.".$ext[0],
            '160x120'   => "/manipulatedImages".$name."-image-160x120-crop.".$ext[0],
            '223x160'   => "/manipulatedImages".$name."-image-223x160-crop.".$ext[0],
            '256x192'   => "/manipulatedImages".$name."-image-256x192-crop.".$ext[0],
            '300x225'   => "/manipulatedImages".$name."-image-300x225-crop.".$ext[0],
            '360x270'   => "/manipulatedImages".$name."-image-360x270-crop.".$ext[0],
            '760x570'   => "/manipulatedImages".$name."-image-760x570-crop.".$ext[0],
        ];
    }
}
