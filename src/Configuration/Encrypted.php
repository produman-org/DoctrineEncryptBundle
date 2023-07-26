<?php

namespace Ambta\DoctrineEncryptBundle\Configuration;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;
use InvalidArgumentException;

/**
 * The Encrypted class handles the @Encrypted annotation.
 *
 * @author Victor Melnik <melnikvictorl@gmail.com>
 * @Annotation
 * @NamedArgumentConstructor
 * @Target("PROPERTY")
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Encrypted implements Annotation
{
    private const ALLOWED_TYPES = [
        'string',
        'datetime',
        'json',
        'array'
    ];

    /**
     * @var string
     */
    public $type;



    public function __construct(?string $type = 'string')
    {
        if (!in_array ($type, self::ALLOWED_TYPES))
        {
            throw new InvalidArgumentException (sprintf('%s is not a supported type for %s', $type, self::class));
        }
        $this->type = $type;
    }
}
