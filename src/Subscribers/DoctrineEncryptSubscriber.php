<?php

namespace Ambta\DoctrineEncryptBundle\Subscribers;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use ReflectionClass;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Util\ClassUtils;
use Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Ambta\DoctrineEncryptBundle\Mapping\AttributeReader;
use ReflectionProperty;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Doctrine event subscriber which encrypt/decrypt entities
 */
class DoctrineEncryptSubscriber implements EventSubscriber
{
    /**
     * Appended to end of encrypted value
     */
    const ENCRYPTION_MARKER = '<ENC>';

    /**
     * Encryptor interface namespace
     */
    const ENCRYPTOR_INTERFACE_NS = 'Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface';

    /**
     * Encrypted annotation full name
     */
    const ENCRYPTED_ANN_NAME = 'Ambta\DoctrineEncryptBundle\Configuration\Encrypted';

    /**
     * Encryptor
     * @var EncryptorInterface|null
     */
    private $encryptor;

    /**
     * Annotation reader
     * @var Reader|AttributeReader
     */
    private $annReader;

    /**
     * Used for restoring the encryptor after changing it
     * @var EncryptorInterface|string
     */
    private $restoreEncryptor;

    /**
     * Count amount of decrypted values in this service
     * @var integer
     */
    public $decryptCounter = 0;

    /**
     * Count amount of encrypted values in this service
     * @var integer
     */
    public $encryptCounter = 0;

    /** @var array */
    private $cachedDecryptions = [];

    /** @var array */
    private $cachedClassProperties = [];

    /** @var array */
    private $cachedClassPropertiesAreEmbedded = [];

    /** @var array */
    private $cachedClassPropertiesAreEncrypted = [];

    /** @var array */
    private $cachedClassesContainAnEncryptProperty = [];

    /**
     * Initialization of subscriber
     *
     * @param Reader|AttributeReader $annReader
     * @param EncryptorInterface $encryptor (Optional)  An EncryptorInterface.
     */
    public function __construct(Reader|AttributeReader $annReader, EncryptorInterface $encryptor)
    {
        $this->annReader = $annReader;
        $this->encryptor = $encryptor;
        $this->restoreEncryptor = $this->encryptor;
    }

    /**
     * Change the encryptor
     *
     * @param EncryptorInterface|null $encryptor
     */
    public function setEncryptor(?EncryptorInterface $encryptor = null)
    {
        $this->encryptor = $encryptor;
    }

    /**
     * Get the current encryptor
     *
     * @return EncryptorInterface|null returns the encryptor class or null
     */
    public function getEncryptor(): ?EncryptorInterface
    {
        return $this->encryptor;
    }

    /**
     * Restore encryptor to the one set in the constructor.
     */
    public function restoreEncryptor()
    {
        $this->encryptor = $this->restoreEncryptor;
    }

    /**
     * Listen a postUpdate lifecycle event.
     * Decrypt entities property's values when post updated.
     *
     * So for example after form submit the preUpdate encrypted the entity
     * We have to decrypt them before showing them again.
     *
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();
        $this->processFields($entity, $args->getObjectManager(), false);
    }

    /**
     * Listen a preUpdate lifecycle event.
     * Encrypt entities property's values on preUpdate, so they will be stored encrypted
     *
     * @param PreUpdateEventArgs $args
     */
    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getObject();
        $this->processFields($entity, $args->getObjectManager(), true);
    }

    /**
     * Listen a postLoad lifecycle event.
     * Decrypt entities property's values when loaded into the entity manger
     *
     * @param LifecycleEventArgs $args
     */
    public function postLoad(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();
        $this->processFields($entity, $args->getObjectManager(), false);
    }

    /**
     * Listen to onflush event
     * Encrypt entities that are inserted into the database
     *
     * @param PreFlushEventArgs $preFlushEventArgs
     */
    public function preFlush(PreFlushEventArgs $preFlushEventArgs)
    {
        $unitOfWOrk = $preFlushEventArgs->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWOrk->getIdentityMap() as $entityName => $entityArray) {
            if (isset($this->cachedDecryptions[$entityName])) {
                foreach ($entityArray as $entityId => $instance) {
                    $this->processFields($instance, $preFlushEventArgs->getObjectManager(), true);
                }
            }
        }
        $this->cachedDecryptions = [];
    }

    /**
     * Listen to onflush event
     * Encrypt entities that are inserted into the database
     *
     * @param OnFlushEventArgs $onFlushEventArgs
     */
    public function onFlush(OnFlushEventArgs $onFlushEventArgs)
    {
        $unitOfWork = $onFlushEventArgs->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $encryptCounterBefore = $this->encryptCounter;
            $this->processFields($entity,$onFlushEventArgs->getObjectManager(),true);
            if ($this->encryptCounter > $encryptCounterBefore ) {
                $classMetadata = $onFlushEventArgs->getObjectManager()->getClassMetadata(get_class($entity));
                $unitOfWork->recomputeSingleEntityChangeSet($classMetadata, $entity);
            }
        }
    }

    /**
     * Listen to postFlush event
     * Decrypt entities after having been inserted into the database
     *
     * @param PostFlushEventArgs $postFlushEventArgs
     */
    public function postFlush(PostFlushEventArgs $postFlushEventArgs)
    {
        $unitOfWork = $postFlushEventArgs->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $entityMap) {
            foreach ($entityMap as $entity) {
                $this->processFields($entity,$postFlushEventArgs->getObjectManager(), false);
            }
        }
    }

    public function onClear(OnClearEventArgs $onClearEventArgs)
    {
        $this->cachedDecryptions = [];
        $this->decryptCounter = 0;
        $this->encryptCounter = 0;
    }

    /**
     * Realization of EventSubscriber interface method.
     *
     * @return array Return all events which this subscriber is listening
     */
    public function getSubscribedEvents(): array
    {
        return array(
            Events::postUpdate,
            Events::preUpdate,
            Events::postLoad,
            Events::onFlush,
            Events::preFlush,
            Events::postFlush,
            Events::onClear,
        );
    }

    /**
     * Process (encrypt/decrypt) entities fields
     *
     * @param Object $entity doctrine entity
     * @param EntityManagerInterface $entityManager
     * @param Boolean $isEncryptOperation If true - encrypt, false - decrypt entity
     *
     * @return object|null
     *@throws \RuntimeException
     *
     */
    public function processFields(object $entity,  EntityManagerInterface $entityManager, bool $isEncryptOperation = true): ?object
    {
        if (!empty($this->encryptor) && $this->containsEncryptProperties($entity)) {
            // Check which operation to be used
            $encryptorMethod = $isEncryptOperation ? 'encrypt' : 'decrypt';

            $realClass = ClassUtils::getClass($entity);

            // Get ReflectionClass of our entity
            $properties = $this->getClassProperties($realClass);

            // Foreach property in the reflection class
            foreach ($properties as $refProperty) {
                if ($this->isPropertyAnEmbeddedMapping($refProperty)) {
                    $this->handleEmbeddedAnnotation($entity, $entityManager, $refProperty, $isEncryptOperation);
                    continue;
                }

                /**
                 * If property is an normal value and contains the Encrypt tag, lets encrypt/decrypt that property
                 */
                if ($this->isPropertyEncryped($refProperty)) {
                    $rootEntityName = $entityManager->getClassMetadata(get_class($entity))->rootEntityName;

                    $pac = PropertyAccess::createPropertyAccessor();
                    $value = $pac->getValue($entity, $refProperty->getName());
                    if ($encryptorMethod === 'decrypt') {
                        if (!is_null($value) and !empty($value)) {
                            if (substr($value, -strlen(self::ENCRYPTION_MARKER)) == self::ENCRYPTION_MARKER) {
                                $this->decryptCounter++;
                                $currentPropValue = $this->encryptor->decrypt(substr($value, 0, -5));
                                $pac->setValue($entity, $refProperty->getName(), $currentPropValue);
                                $this->cachedDecryptions[$rootEntityName][spl_object_id($entity)][$refProperty->getName()][$currentPropValue] = $value;
                            }
                        }
                    } else {
                        if (!is_null($value) and !empty($value)) {
                            if (isset($this->cachedDecryptions[$rootEntityName][spl_object_id($entity)][$refProperty->getName()][$value])) {
                                $pac->setValue($entity, $refProperty->getName(), $this->cachedDecryptions[$rootEntityName][spl_object_id($entity)][$refProperty->getName()][$value]);
                            } elseif (substr($value, -strlen(self::ENCRYPTION_MARKER)) != self::ENCRYPTION_MARKER) {
                                $this->encryptCounter++;
                                $currentPropValue = $this->encryptor->encrypt($value).self::ENCRYPTION_MARKER;
                                $pac->setValue($entity, $refProperty->getName(), $currentPropValue);
                            }
                        }
                    }
                }
            }

            return $entity;
        }

        return $entity;
    }

    private function handleEmbeddedAnnotation($entity, EntityManagerInterface $entityManager, ReflectionProperty $embeddedProperty, bool $isEncryptOperation = true)
    {
        $propName = $embeddedProperty->getName();

        $pac = PropertyAccess::createPropertyAccessor();

        $embeddedEntity = $pac->getValue($entity, $propName);

        if ($embeddedEntity) {
            $this->processFields($embeddedEntity, $entityManager, $isEncryptOperation);
        }
    }

    /**
     * Recursive function to get an associative array of class properties
     * including inherited ones from extended classes
     *
     * @param string $className Class name
     *
     * @return array|ReflectionProperty[]
     */
    private function getClassProperties(string $className): array
    {
        if (!array_key_exists($className,$this->cachedClassProperties)) {
            $reflectionClass = new ReflectionClass($className);
            $properties      = $reflectionClass->getProperties();
            $propertiesArray = array();

            foreach ($properties as $property) {
                $propertyName = $property->getName();
                $propertiesArray[$propertyName] = $property;
            }

            if ($parentClass = $reflectionClass->getParentClass()) {
                $parentPropertiesArray = $this->getClassProperties($parentClass->getName());
                if (count($parentPropertiesArray) > 0) {
                    $propertiesArray = array_merge($parentPropertiesArray, $propertiesArray);
                }
            }

            $this->cachedClassProperties[$className] = $propertiesArray;
        }

        return $this->cachedClassProperties[$className];
    }

    /**
     * @return bool
     */
    private function isPropertyAnEmbeddedMapping(ReflectionProperty $refProperty)
    {
        $key = $refProperty->getDeclaringClass()->getName().$refProperty->getName();
        if (!array_key_exists($key,$this->cachedClassPropertiesAreEmbedded)) {
            $this->cachedClassPropertiesAreEmbedded[$key] = (bool) $this->annReader->getPropertyAnnotation($refProperty, 'Doctrine\ORM\Mapping\Embedded');
        }

        return $this->cachedClassPropertiesAreEmbedded[$key];
    }

    /**
     * @return bool
     */
    private function isPropertyEncryped(ReflectionProperty $refProperty)
    {
        $key = $refProperty->getDeclaringClass()->getName().$refProperty->getName();
        if (!array_key_exists($key,$this->cachedClassPropertiesAreEncrypted)) {
            $this->cachedClassPropertiesAreEncrypted[$key] = (bool) $this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME);
        }

        return $this->cachedClassPropertiesAreEncrypted[$key];
    }

    private function containsEncryptProperties($entity)
    {
        $realClass = ClassUtils::getClass($entity);

        if (!array_key_exists($realClass,$this->cachedClassesContainAnEncryptProperty)) {
            $this->cachedClassesContainAnEncryptProperty[$realClass] = false;

            // Get ReflectionClass of our entity
            $properties = $this->getClassProperties($realClass);

            // Foreach property in the reflection class
            foreach ($properties as $refProperty) {
                if ($this->isPropertyAnEmbeddedMapping($refProperty)) {
                    $pac = PropertyAccess::createPropertyAccessor();

                    $embeddedEntity = $pac->getValue($entity, $refProperty->getName());

                    if ($this->containsEncryptProperties($embeddedEntity)) {
                        $this->cachedClassesContainAnEncryptProperty[$realClass] = true;
                    }
                } else {
                    if ($this->isPropertyEncryped($refProperty)) {
                        $this->cachedClassesContainAnEncryptProperty[$realClass] = true;
                    }
                }
            }
        }

        return $this->cachedClassesContainAnEncryptProperty[$realClass];
    }
}
