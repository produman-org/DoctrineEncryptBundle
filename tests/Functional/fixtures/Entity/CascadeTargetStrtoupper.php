<?php

namespace Ambta\DoctrineEncryptBundle\Tests\Functional\fixtures\Entity;

use Ambta\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 *
 */
class CascadeTargetStrtoupper
{
    /**
     * @var int
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    private $id;

    /**
     * @Ambta\DoctrineEncryptBundle\Configuration\Encrypted()
     * @ORM\Column(type="string", nullable=true)
     */
    private $secret;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $notSecret;

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getSecret()
    {
        return $this->secret;
    }

    /**
     * @param mixed $secret
     */
    public function setSecret($secret): void
    {
        if (substr($secret, -strlen(DoctrineEncryptSubscriber::ENCRYPTION_MARKER)) != DoctrineEncryptSubscriber::ENCRYPTION_MARKER)
        {
            $secret = strtoupper ($secret);
        }

        $this->secret = $secret;
    }

    /**
     * @return mixed
     */
    public function getNotSecret()
    {
        return $this->notSecret;
    }

    /**
     * @param mixed $notSecret
     */
    public function setNotSecret($notSecret): void
    {
        $this->notSecret = $notSecret;
    }

}
