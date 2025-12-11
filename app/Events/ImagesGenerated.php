<?php

namespace App\Events;

use App\Models\Upload;
use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class ImagesGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param \App\Models\Upload $upload
     * @param \Illuminate\Support\Collection $images
     * @param array $metadata
     */
    public function __construct(
        public readonly Upload $upload,
        public readonly Collection $images,
        public readonly array $metadata = []
    ) {
    }

    /**
     * Get the number of images generated.
     *
     * @return int
     */
    public function getImageCount(): int
    {
        return $this->images->count();
    }

    /**
     * Get event data for logging.
     *
     * @return array
     */
    public function getEventData(): array
    {
        return [
            'upload_id' => $this->upload->id,
            'original_filename' => $this->upload->original_filename,
            'images_count' => $this->getImageCount(),
            'variants' => $this->images->pluck('variant')->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get image IDs.
     *
     * @return array
     */
    public function getImageIds(): array
    {
        return $this->images->pluck('id')->toArray();
    }
}
