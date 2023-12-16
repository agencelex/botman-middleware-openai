<?php declare(strict_types=1);

namespace BotMan\Middleware\OpenAI\MessageResponse;

use OpenAI\Responses\Threads\Messages\ThreadMessageResponseContentTextAnnotationFileCitationObject;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponseContentTextAnnotationFilePathObject;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponseContentTextObject;

class TextMessageResponse extends AbstractMessageResponse
{
    public readonly string $text;

    public function __construct(ThreadMessageResponseContentTextObject $textObject)
    {
        parent::__construct(json_encode($textObject->toArray()));

        $text = $textObject->text->value;
        $annotations = collect($textObject->text->annotations)->map(fn(ThreadMessageResponseContentTextAnnotationFilePathObject|ThreadMessageResponseContentTextAnnotationFileCitationObject $annotation) => $annotation->text)->all();
        $this->text = str_replace($annotations, '', $text);
    }
}
