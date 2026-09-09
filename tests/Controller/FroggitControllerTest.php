<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\OpenHabPublisher;
use JsonException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FroggitControllerTest extends WebTestCase
{

    /**
     * @throws JsonException
     */
    public function testNoParameter(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/froggit');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $content = $client->getResponse()->getContent();
        $json = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertNotNull($json);
        $this->assertEquals('no parameter given', $json->message);
    }

    /**
     * @throws JsonException
     */
    public function testSuccess(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/froggit', [
            'phpunit' => 12.345
        ]);
        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $json = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertNotNull($json);
        $this->assertEquals('ok', $json->message);
    }

    /**
     * The route path itself is configurable via the FROGGIT_ROUTE env var (on top
     * of the always-on /froggit and /data/report/ routes) — .env.test sets it to
     * /froggit-configured, distinct from both fixed paths, to prove the override
     * actually reaches the router rather than just falling back to a fixed path.
     *
     * @throws JsonException
     */
    public function testConfiguredRoute(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/froggit-configured', [
            'phpunit' => 12.345
        ]);
        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $json = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertNotNull($json);
        $this->assertEquals('ok', $json->message);
    }

    /**
     * "All or nothing": a single failing (but configured) sink must make the
     * whole response count as an error, even if the other sinks succeed.
     *
     * @throws JsonException
     */
    public function testOneFailingSinkReturns500(): void
    {
        $client = self::createClient();

        $openHabPublisher = $this->createMock(OpenHabPublisher::class);
        $openHabPublisher->expects($this->once())->method('publish')->willThrowException(new RuntimeException('openHAB unreachable'));
        self::getContainer()->set(OpenHabPublisher::class, $openHabPublisher);

        $client->request(Request::METHOD_GET, '/froggit', [
            'phpunit' => 12.345
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $content = $client->getResponse()->getContent();
        $json = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertNotNull($json);
        $this->assertStringContainsString('openhab', (string) $json->message);
    }

}
