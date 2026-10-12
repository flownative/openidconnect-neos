<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Neos\Tests\Unit;

use Flownative\OpenIdConnect\Neos\Aspect\SignOutAtIdentityProviderAspect;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Aop\Advice\AdviceChain;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Controller\AbstractController;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Exception\StopActionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class SignOutAtIdentityProviderAspectTest extends TestCase
{
    private const string LOGIN_PAGE_URI = 'https://www.example.com/neos/login';

    #[Test]
    public function aLogoutWithGetOnlySignsOutOfNeos(): void
    {
        $aspect = new SignOutAtIdentityProviderAspect();
        (new ReflectionProperty(SignOutAtIdentityProviderAspect::class, 'signOutAtIdentityProvider'))->setValue($aspect, true);

        try {
            // The identity token of the login is not injected, so reaching for it would fail
            $aspect->redirectToEndSessionEndpoint($this->createJoinPoint('GET'));
            static::fail('The logout did not redirect');
        } catch (StopActionException $exception) {
            static::assertSame(self::LOGIN_PAGE_URI, $exception->response->getHeaderLine('Location'));
        }
    }

    private function createJoinPoint(string $httpMethod): JoinPointInterface
    {
        $actionRequest = $this->createStub(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn(new ServerRequest($httpMethod, 'https://www.example.com/neos/logout'));
        $controllerContext = $this->createStub(ControllerContext::class);
        $controllerContext->method('getRequest')->willReturn($actionRequest);
        $controller = $this->createStub(AbstractController::class);
        $controller->method('getControllerContext')->willReturn($controllerContext);

        $adviceChain = $this->createStub(AdviceChain::class);
        $adviceChain->method('proceed')->willThrowException(StopActionException::createForResponse(new Response(303, ['Location' => self::LOGIN_PAGE_URI]), ''));
        $joinPoint = $this->createStub(JoinPointInterface::class);
        $joinPoint->method('getProxy')->willReturn($controller);
        $joinPoint->method('getAdviceChain')->willReturn($adviceChain);
        return $joinPoint;
    }
}
