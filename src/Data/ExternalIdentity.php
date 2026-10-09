<?php

declare(strict_types=1);

namespace Sso\Data;

final readonly class ExternalIdentity
{
    /**
     * @var string
     */
    public string $provider;

    /**
     * @var string
     */
    public string $id;

    /**
     * @var string
     */
    public string $name;

    /**
     * @var string
     */
    public ?string $firstName;

    /**
     * @var string
     */
    public ?string $lastName;

    /**
     * @var string|null
     */
    public ?string $email;

    /**
     * @var bool
     */
    public bool $emailVerified;

    /**
     * @var string|null
     */
    public ?string $avatarUrl;

    /**
     * @param string
     * @param string
     * @param string
     * @param string|null
     * @param bool
     * @param string|null
     */
    public function __construct(string $provider, string $id, string $name, ?string $email, bool $emailVerified, ?string $avatarUrl, ?string $firstName, ?string $lastName)
    {
        $this->provider = $provider;
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
        $this->emailVerified = $emailVerified;
        $this->avatarUrl = $avatarUrl;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }

    /**
     * @return array
     */
    public function details(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
        ];
    }
}
