<?php

namespace Mixdinternet\Galleries;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mixdinternet\Galleries\Events\GalleryImagesReady;

class Gallery extends Model
{
    use SoftDeletes;

    /**
     * How long a reservation from reserveOrders() is remembered: long enough
     * to outlive a backed-up queue, after which every reserved row either
     * exists (and max(order) covers it) or never will.
     */
    const RESERVED_ORDER_TTL_HOURS = 6;

    protected $fillable = ['name'];

    public function galleriable()
    {
        return $this->morphTo();
    }

    /**
     * Images in display order. "id" breaks ties: without it, two rows with
     * the same "order" come back in whatever order MySQL picks, so the cover
     * could change from one query to the next.
     */
    public function images()
    {
        return $this->hasMany(\Mixdinternet\Galleries\Image::class)->orderBy('order', 'asc')->orderBy('id', 'asc');
    }

    public function image()
    {
        return $this->images()->first();
    }

    /**
     * Reserve $count consecutive "order" values after every image this
     * gallery has or is about to get, and return the first one.
     *
     * Image rows are created on a queue (ProcessGalleryImages), possibly
     * minutes after their order was planned, so max(order) alone misses
     * every batch still waiting: two saves in that window used to get the
     * same orders. The highest reserved value is kept in the cache, under a
     * per-gallery lock, so concurrent reservations never overlap.
     */
    public function reserveOrders($count)
    {
        $reserve = function () use ($count) {
            $key = 'gallery_next_order:' . $this->id;

            $max = Image::where('gallery_id', $this->id)->max('order');
            $next = max(is_null($max) ? 0 : ((int) $max + 1), (int) Cache::get($key, 0));

            Cache::put($key, $next + $count, now()->addHours(self::RESERVED_ORDER_TTL_HOURS));

            return $next;
        };

        try {
            return Cache::lock('gallery_order_lock:' . $this->id, 10)->block(5, $reserve);
        } catch (LockTimeoutException $e) {
            // Still better than failing the upload: the worst case is the
            // duplicate the lock exists to prevent, and images() breaks ties.
            Log::warning('Gallery: reserva de ordem sem lock', ['gallery_id' => $this->id]);

            return $reserve();
        }
    }

    /**
     * Pick the "order" of each new image about to be added to the gallery.
     *
     * Without a layout the new images go after every existing (and pending)
     * one, in the order of $newKeys. A layout is the gallery's final
     * sequence, one ['existing' => image id] or ['new' => key from $newKeys]
     * per position, so a new image can land before existing ones: the
     * existing rows it lists are moved right away, and whatever it leaves
     * out goes after it (new images first, then existing ones in their
     * current order).
     *
     * @param array      $newKeys Keys identifying each new image, in order
     * @param array|null $layout
     * @return array [new key => order]
     */
    public function planOrders(array $newKeys, array $layout = null)
    {
        $sequence = [];

        if (is_null($layout)) {
            foreach ($newKeys as $key) {
                $sequence[] = ['new' => $key];
            }
        } else {
            $existingIds = $this->images()->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();
            $pendingIds = array_flip($existingIds);
            $pendingKeys = array_flip($newKeys);

            foreach ($layout as $entry) {
                if (isset($entry['existing']) && isset($pendingIds[(int) $entry['existing']])) {
                    unset($pendingIds[(int) $entry['existing']]);
                    $sequence[] = ['existing' => (int) $entry['existing']];
                } elseif (isset($entry['new']) && isset($pendingKeys[$entry['new']])) {
                    unset($pendingKeys[$entry['new']]);
                    $sequence[] = ['new' => $entry['new']];
                }
            }

            foreach ($newKeys as $key) {
                if (isset($pendingKeys[$key])) {
                    $sequence[] = ['new' => $key];
                }
            }

            foreach ($existingIds as $id) {
                if (isset($pendingIds[$id])) {
                    $sequence[] = ['existing' => $id];
                }
            }
        }

        if (!$sequence) {
            return [];
        }

        // Every position gets a freshly reserved value, existing rows too:
        // a new image placed before them can only get a lower order than
        // theirs if they move up past it.
        $base = $this->reserveOrders(count($sequence));
        $orders = [];

        DB::transaction(function () use ($sequence, $base, &$orders) {
            foreach ($sequence as $position => $entry) {
                if (isset($entry['existing'])) {
                    Image::where('id', $entry['existing'])->update(['order' => $base + $position]);
                } else {
                    $orders[$entry['new']] = $base + $position;
                }
            }
        });

        return $orders;
    }

    /**
     * Renumber the images 0..n-1: the ones in $imageIds first, in that
     * order, then every other image in its current order. With no ids it
     * only compacts the current order, removing duplicates and NULLs.
     *
     * @param array $imageIds Ids of this gallery's images; others are ignored
     * @return int How many rows changed position
     */
    public function arrange(array $imageIds = [])
    {
        return DB::transaction(function () use ($imageIds) {
            $images = $this->images()->lockForUpdate()->get(['id', 'order'])->keyBy('id');

            $listed = collect($imageIds)->map(function ($id) {
                return (int) $id;
            })->unique()->filter(function ($id) use ($images) {
                return $images->has($id);
            });

            $sequence = $listed->map(function ($id) use ($images) {
                return $images->get($id);
            })->concat($images->except($listed->all())->values());

            $moved = 0;
            foreach ($sequence->values() as $order => $image) {
                if (is_null($image->order) || (int) $image->order !== $order) {
                    $moved += Image::where('id', $image->id)->update(['order' => $order]);
                }
            }

            return $moved;
        });
    }

    /**
     * Let the host app refresh whatever caches this gallery's images
     * (search index, feeds) after they were added, removed or reordered.
     */
    public function imagesChanged()
    {
        if ($this->galleriable_type && $this->galleriable_id) {
            event(new GalleryImagesReady($this->galleriable_type, $this->galleriable_id));
        }
    }
}
