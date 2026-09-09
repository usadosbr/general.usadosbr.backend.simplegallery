<?php

namespace Mixdinternet\Galleries\Events;

/**
 * Fired once ProcessGalleryImages finishes creating Image rows for a model,
 * so host apps can refresh anything that assumed images existed synchronously
 * right after touch()/save() — safe to fire even when nothing listens.
 */
class GalleryImagesReady
{
    public $modelClass;
    public $modelId;

    public function __construct(string $modelClass, $modelId)
    {
        $this->modelClass = $modelClass;
        $this->modelId = $modelId;
    }
}
