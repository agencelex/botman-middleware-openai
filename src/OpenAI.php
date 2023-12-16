<?php declare(strict_types=1);

namespace BotMan\Middleware\OpenAI;

use BotMan\BotMan\BotMan;
use BotMan\BotMan\Interfaces\MiddlewareInterface;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\Middleware\OpenAI\MessageResponse\ImageMessageResponse;
use BotMan\Middleware\OpenAI\MessageResponse\TextMessageResponse;
use OpenAI\Client;
use OpenAI as OpenAIPHP;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponse;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponseContentImageFileObject;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponseContentTextObject;
use \Psr\Http\Client\ClientInterface as HttpClientInterface;

/**
 * API : https://platform.openai.com/docs/api-reference
 * Documentation : https://platform.openai.com/docs/assistants/overview
 */
class OpenAI implements MiddlewareInterface
{
    protected Client $client;

    public function __construct(string $apiKey, string $organization, HttpClientInterface $httpClient)
    {
        $this->client = OpenAIPHP::factory()
            ->withApiKey($apiKey)
            ->withOrganization($organization)
            ->withHttpClient($httpClient)
            ->withHttpHeader('OpenAI-Beta', 'assistants=v1')
            ->make();
    }

    /**
     * Create a new OpenAI middleware instance.
     *
     * @return static
     */
    public static function create(): static
    {
        return new static(
            apiKey: getenv('OPENAI_API_KEY'),
            organization: getenv('OPENAI_ORGANIZATION'),
            httpClient: new \GuzzleHttp\Client(['timeout' => getenv('OPENAI_REQUEST_TIMEOUT') ?: 30])
        );
    }

    public function received(IncomingMessage $message, $next, BotMan $bot)
    {
        // Check whether we already had a thread for this user
        $threadId = $bot->userStorage()->find('openapi')
            ->getOrPut(
                key: 'thread-id',
                value: fn () => $this->client->threads()->create([])->id
            );

        // Create a message
        $this->client->threads()->messages()->create(
            threadId: $threadId,
            parameters: [
                'role' => 'user',
                'content' => $message->getText()
            ]
        );

        // Run the message upon the thread
        $run = $this->client->threads()->runs()->create(
            threadId: $threadId,
            parameters: [
                'assistant_id' => getenv('OPENAI_ASSISTANT_ID'),
            ]
        );

        // Wait for the agent to finish processing our request
        $iteration = 0; $maxIterations = 150; $delay = 250 * 1000 /* 250ms */;
        do {

            if($run->status === 'requires_action') {
                // handle requires_action situation
                $this->executeTools($threadId, $run->id);
            }

            usleep($delay);

            logger()->info("About to run the thread after a delay of " . (float)($delay/1000000) . " seconds.");

            $run = $this->client->threads()->runs()->retrieve($threadId, $run->id);
            $iteration++;
            $delay = min($delay + (250 * 1000), (2 * 1000 * 1000)); // Increase by 250ms but do not go over 2 secs

        } while(!in_array($run->status, ['completed', 'failed', 'requires_action']) && $iteration < $maxIterations);

        logger()->info("Run ended after $iteration iterations.");

        // Get the response(s) of the agent
        $assistantMessageResponses = collect($this->client->threads()->messages()->list(
            threadId: $threadId,
            parameters: [
                'order' => 'desc',
                'limit' => 20
            ]
        )->data)
        ->filter(fn(ThreadMessageResponse $messageResponse) => $messageResponse->runId === $run->id && $messageResponse->role === 'assistant');

        // Transform message responses into local object
        $messageResponses = $assistantMessageResponses->map(fn(ThreadMessageResponse $messageResponse) => $messageResponse->content)
            ->flatMap(function (array $content) {
                return array_map(fn(ThreadMessageResponseContentTextObject|ThreadMessageResponseContentImageFileObject $threadMessageResponse): TextMessageResponse|ImageMessageResponse => match ($threadMessageResponse->type) {
                    'text' => new TextMessageResponse($threadMessageResponse),
                    'image_file' => new ImageMessageResponse($threadMessageResponse, $this->client)
                }, $content);
            });

        $message->addExtras('messageResponses', $messageResponses);

        return $next($message);
    }

    public function captured(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }

    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        return true;
    }

    public function heard(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }

    public function sending($payload, $next, BotMan $bot)
    {
        return $next($payload);
    }

    protected function executeTools(string $threadId, string $runId): void
    {
        // Execute each tool and build an array with their output
        $toolsOutputs = [];

        // Submit the result at once to OpenAI
        $this->client->threads()->runs()->submitToolOutputs(
            threadId: $threadId,
            runId: $runId,
            parameters: $toolsOutputs
        );
    }
}
