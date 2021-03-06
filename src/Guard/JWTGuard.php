<?php
/**
 * JWT
 * [XinFox System] Copyright (c) 2011 - 2021 XINFOX.CN
 */

declare(strict_types=1);

namespace XinFox\Auth\Guard;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Nonstandard\Uuid;
use Throwable;
use XinFox\Auth\Exception\AuthException;
use XinFox\Auth\Exception\InvalidArgumentException;
use XinFox\Auth\Exception\UnauthorizedException;
use XinFox\Auth\Guard\JWT\Validation\Constraint\TokenValidWith;
use XinFox\Auth\GuardInterface;
use XinFox\Auth\Guest;
use XinFox\Auth\Token;
use XinFox\Auth\TokenProviderInterface;
use XinFox\Auth\UserProviderInterface;
use XinFox\Auth\VisitorInterface;

class JWTGuard implements GuardInterface
{
    private array $config;
    private Configuration $jwtConfig;
    private ServerRequestInterface $request;
    private UserProviderInterface $userProvider;
    private TokenProviderInterface $tokenProvider;
    private ?Token $token;

    /**
     * JWTGuard constructor.
     * @param array $config
     * @param UserProviderInterface $userProvider
     * @param TokenProviderInterface $tokenProvider
     */
    public function __construct(
        array $config,
        UserProviderInterface $userProvider,
        TokenProviderInterface $tokenProvider
    ) {
        if (empty($config['base64_encoded'])) {
            throw new InvalidArgumentException('配置base64_encoded缺失');
        }
        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::base64Encoded($config['base64_encoded'])
        );

        $jwtConfig->setValidationConstraints(
            new SignedWith(
                new Sha256(),
                InMemory::base64Encoded($config['base64_encoded'])
            ),
            new TokenValidWith($tokenProvider)
        );
        $this->jwtConfig = $jwtConfig;
        $this->config = $config;
        $this->userProvider = $userProvider;
        $this->tokenProvider = $tokenProvider;
    }

    /**
     * @param VisitorInterface $visitor
     * @return Token
     */
    public function login(VisitorInterface $visitor): Token
    {
        $now = new \DateTimeImmutable();
        $builder = $this->jwtConfig->builder();
        if (!empty($this->config['issued'])) {
            // Configures the issuer (iss claim)
            $builder->issuedBy($this->config['issued']);
        }
        if (!empty($this->config['permitted'])) {
            // Configures the audience (aud claim)
            $builder->permittedFor($this->config['permitted']);
        }
        $token = $builder->identifiedBy(Uuid::uuid4()->toString())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now->modify($this->config['can_only_be_used_after'] ?? '+1 second'))
            ->expiresAt($now->modify($this->config['expires_at'] ?? '+24 hour'))
            ->withClaim('uid', $visitor->getId())
            ->withHeader('version', 'v1.0.0')
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());


        $this->token = new Token($visitor, $token);
        $this->tokenProvider->save($this->token);

        return $this->token;
    }

    /**
     * @return void
     */
    public function logout(): void
    {
        if ($this->token instanceof Token) {
            $this->tokenProvider->delete($this->token->getJti());
        }

        $this->token = null;
    }

    /**
     * @param string|null $token
     * @return VisitorInterface
     * @throws AuthException|UnauthorizedException
     */
    public function user(string $token = null): VisitorInterface
    {
        $token = $token ?? $this->parseToken();

        try {
            if ($token) {
                $jwt = $this->jwtConfig
                    ->parser()
                    ->parse($token);

                $constraints = $this->jwtConfig->validationConstraints();
                $this->jwtConfig->validator()->assert($jwt, ...$constraints);

                $uid = $jwt->claims()->get('uid');
                return $this->userProvider->loginById($uid) ?? new Guest();
            }
            return new Guest();
        } catch (Throwable $exception) {
            throw $exception instanceof AuthException ? $exception : new UnauthorizedException(
                $exception->getMessage(),
                $this,
                $exception
            );
        }
    }

    public function parseToken(): ?string
    {
        if ($this->request->hasHeader('Authorization')) {
            $header = $this->request->getHeaderLine('Authorization');
            return trim(preg_replace('/^(?:\s+)?Bearer\s/', '', $header));
        }

        return null;
    }
}