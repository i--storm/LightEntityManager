# LightEntityManager
Light Entity Manager for Doctrine based projects.

Partially emulates Doctrine's EntitManager behaviour.

Initially was created for executing large CRON tasks in my Symfony project.

Doctrine is great, but it fails with memory overlimit error if you try to create or update too much entites. This project eleminates this feature without overwritig whole code. Just create it

```php
$lem=$this->getContainer()->get('light_entity_manager');
```

And then use as ordinary EntityManager.

Note: I've tested it only with my project and it's fully replaces original Doctrine's EntityManager on heavy cron tasks. May be some function calls used in yours are abscent. Try it on your own risk and make data backups first.
