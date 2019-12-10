<?php

declare(strict_types=1);

namespace Knp\DoctrineBehaviors\ORM\Geocodable;

use Doctrine\Common\Persistence\Mapping\ClassMetadata;

use Doctrine\DBAL\Platforms\MySqlPlatform;

use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

use Knp\DoctrineBehaviors\ORM\AbstractSubscriber,
    Knp\DoctrineBehaviors\ORM\Geocodable\Type\Point,
    Knp\DoctrineBehaviors\Reflection\ClassAnalyzer;

/**
 * GeocodableSubscriber handle Geocodable entites
 * Adds doctrine point type
 */
class GeocodableSubscriber extends AbstractSubscriber
{
    /**
     * @var callable
     */
    private $geolocationCallable;

    private $geocodableTrait;

    /**
     * @param \Knp\DoctrineBehaviors\Reflection\ClassAnalyzer $classAnalyzer
     * @param                                                 $isRecursive
     * @param                                                 $geocodableTrait
     * @param callable                                        $geolocationCallable
     */
    public function __construct(
        ClassAnalyzer $classAnalyzer,
        $isRecursive,
        $geocodableTrait,
        ?callable $geolocationCallable = null
    ) {
        parent::__construct($classAnalyzer, $isRecursive);

        $this->geocodableTrait = $geocodableTrait;
        $this->geolocationCallable = $geolocationCallable;
    }

    /**
     * Adds doctrine point type
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();

        if ($classMetadata->reflClass === null) {
            return;
        }

        if ($this->isGeocodable($classMetadata)) {
            if (! Type::hasType('point')) {
                Type::addType('point', 'Knp\DoctrineBehaviors\DBAL\Types\PointType');
            }

            $em = $eventArgs->getEntityManager();
            $con = $em->getConnection();

            // skip non-postgres platforms
            if (! $con->getDatabasePlatform() instanceof PostgreSqlPlatform &&
                ! $con->getDatabasePlatform() instanceof MySqlPlatform
            ) {
                return;
            }

            // skip platforms with registerd stuff
            if (! $con->getDatabasePlatform()->hasDoctrineTypeMappingFor('point')) {
                $con->getDatabasePlatform()->registerDoctrineTypeMapping('point', 'point');

                if ($con->getDatabasePlatform() instanceof PostgreSqlPlatform) {
                    $em->getConfiguration()->addCustomNumericFunction(
                        'DISTANCE',
                        'Knp\DoctrineBehaviors\ORM\Geocodable\Query\AST\Functions\DistanceFunction'
                    );
                }
            }

            $classMetadata->mapField(
                [
                    'fieldName' => 'location',
                    'type' => 'point',
                    'nullable' => true,
                ]
            );
        }
    }

    public function prePersist(LifecycleEventArgs $eventArgs): void
    {
        $this->updateLocation($eventArgs, false);
    }

    public function preUpdate(LifecycleEventArgs $eventArgs): void
    {
        $this->updateLocation($eventArgs, true);
    }

    /**
     * @return Point the location
     */
    public function getLocation($entity)
    {
        if ($this->geolocationCallable === null) {
            return false;
        }

        $callable = $this->geolocationCallable;

        return $callable($entity);
    }

    public function getSubscribedEvents()
    {
        return [
            Events::prePersist,
            Events::preUpdate,
            Events::loadClassMetadata,
        ];
    }

    public function setGeolocationCallable(callable $callable): void
    {
        $this->geolocationCallable = $callable;
    }

    private function updateLocation(LifecycleEventArgs $eventArgs, $override = false): void
    {
        $em = $eventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();
        $entity = $eventArgs->getEntity();

        $classMetadata = $em->getClassMetadata(get_class($entity));
        if ($this->isGeocodable($classMetadata)) {
            $oldValue = $entity->getLocation();
            if (! $oldValue instanceof Point || $override) {
                $newLocation = $this->getLocation($entity);

                if ($newLocation !== false) {
                    $entity->setLocation($newLocation);
                }

                $uow->propertyChanged($entity, 'location', $oldValue, $entity->getLocation());
                $uow->scheduleExtraUpdate(
                    $entity,
                    [
                        'location' => [$oldValue, $entity->getLocation()],
                    ]
                );
            }
        }
    }

    /**
     * Checks if entity is geocodable
     *
     * @param ClassMetadata $classMetadata The metadata
     *
     * @return boolean
     */
    private function isGeocodable(ClassMetadata $classMetadata)
    {
        return $this->getClassAnalyzer()->hasTrait(
            $classMetadata->reflClass,
            $this->geocodableTrait,
            $this->isRecursive
        );
    }
}
