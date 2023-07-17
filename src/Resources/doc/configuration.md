# Configuration Reference

There is only 1 paramater in the configuration of the Doctrine encryption bundle.
This parameter is also optional.

* **encryptor_class** - Custom class for encrypting data
    * Encryptor class, [your own encryptor class](https://github.com/DoctrineEncryptBundle/DoctrineEncryptBundle/blob/master/src/Resources/doc/custom_encryptor.md) will override encryptor paramater
    * Default: Halite

## yaml

``` yaml
ambta_doctrine_encrypt:
    encryptor_class: Halite # or Defuse
    secret_directory_path: '%kernel.project_dir%'   # Path where to store the keyfiles
```

Due to the Doctrine Annotations [deprecation](https://www.doctrine-project.org/projects/doctrine-annotations/en/stable/index.html#deprecation-notice) it has been made possible to change the reader to the ambta_doctrine_attribute_reader reader only and skip using annotations completely.

Attributes are faster to read than annotations so it is definitely recommended.

The default to use annotations have been kept the default as most projects are probably still using annotations and have not yet been switched to PHP attributes.

``` yaml
services:
    # Skip trying to read annotations. Only read attributes
    ambta_doctrine_encrypt.orm_subscriber:
        class: Ambta\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber
        arguments: ["@ambta_doctrine_attribute_reader", "@ambta_doctrine_encrypt.encryptor"]
        tags:
            -  { name: doctrine.event_subscriber }

    ambta_doctrine_encrypt.command.decrypt.database:
        class: Ambta\DoctrineEncryptBundle\Command\DoctrineDecryptDatabaseCommand
        tags: ['console.command']
        arguments:
            - "@doctrine.orm.entity_manager"
            - "@ambta_doctrine_attribute_reader"
            - "@ambta_doctrine_encrypt.subscriber"

    ambta_doctrine_encrypt.command.encrypt.database:
        class: Ambta\DoctrineEncryptBundle\Command\DoctrineEncryptDatabaseCommand
        tags: ['console.command']
        arguments:
            - "@doctrine.orm.entity_manager"
            - "@ambta_doctrine_attribute_reader"
            - "@ambta_doctrine_encrypt.subscriber"

    ambta_doctrine_encrypt.command.encrypt.status:
        class: Ambta\DoctrineEncryptBundle\Command\DoctrineEncryptStatusCommand
        tags: ['console.command']
        arguments:
            - "@doctrine.orm.entity_manager"
            - "@ambta_doctrine_attribute_reader"
            - "@ambta_doctrine_encrypt.subscriber"
```

## Important!

If you want to use Defuse, make sure to require it!

composer require "defuse/php-encryption ^2.0"

## Usage

Read how to use the database encryption bundle in your project.
#### [Usage](https://github.com/DoctrineEncryptBundle/DoctrineEncryptBundle/blob/master/src/Resources/doc/usage.md)
