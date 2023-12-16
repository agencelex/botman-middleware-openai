<?php declare(strict_types=1);

namespace BotMan\Middleware\OpenAI\MessageResponse;

use OpenAI\Client;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponseContentImageFileObject;

/**
 * @property-read string $url
 */
class ImageMessageResponse extends AbstractMessageResponse
{
    private bool $retrieved = false;

    private readonly string $url;

    protected readonly string $fileId;

    public function __construct(ThreadMessageResponseContentImageFileObject $imageFileObject, protected Client $client)
    {
        parent::__construct(json_encode($imageFileObject->toArray()));

        $this->fileId = $imageFileObject->imageFile->fileId;
    }

    public function __get($key)
    {
        if($key === 'url') {

            if(!$this->retrieved) {
                $this->url = $this->retrieve();
            }

            return $this->url;
        }

        return null;
    }

    private function retrieve(): string
    {
        $content = $this->client->files()->download($this->fileId);
        $this->retrieved = true;

        return collect($content)->get('url') ?: '';
    }
}
