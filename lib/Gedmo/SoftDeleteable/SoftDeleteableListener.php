<?php

namespace Gedmo\SoftDeleteable;

use Doctrine\Common\Persistence\ObjectManager,
    Doctrine\Common\Persistence\Mapping\ClassMetadata,
    Doctrine\ORM\Mapping\ClassMetadataInfo,
    Doctrine\Common\EventArgs,

Gedmo\Mapping\MappedEventSubscriber,
    Gedmo\Mapping\Event\AdapterInterface,
    Gedmo\Loggable\Mapping\Event\LoggableAdapter;

/**
 * SoftDeleteable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
    /**
     * Pre soft-delete event
     *
     * @var string
     */
    const PRE_SOFT_DELETE = "preSoftDelete";

    /**
     * Post soft-delete event
     *
     * @var string
     */
    const POST_SOFT_DELETE = "postSoftDelete";


    /**
     * onDelete="CASCADE"
     */
    const ON_DELETE_CASCADE = 'DELETE';

    /**
     * onDelete="SET NULL"
     */
    const ON_DELETE_SET_NULL = 'SET_NULL';

    /**
     * Cascade operations mapping
     * @var bool
     */
    protected $onDelete = false;

    protected $visited = array();

    /** @var  CascadeResolver */
    protected $resolver = null;

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            'loadClassMetadata',
            'onFlush',
        );
    }

    /**
     * Maps additional metadata
     *
     * @param  EventArgs $eventArgs
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * @param  EventArgs $args
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $this->visited = array();

        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();

        if (!$this->resolver) {
            $this->resolver = new CascadeResolver($om);
        }

        $uow = $om->getUnitOfWork();

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            if ($this->isSoftDeleteable($om, $om->getClassMetadata(get_class($object)))) {
                $this->scheduleSoftDelete($ea,$object);
            }
        }
    }

    /**
     * If it's a SoftDeleteable object, update the "deletedAt" field
     * and skip the removal of the object
     *
     * @param AdapterInterface $ea
     * @param $object
     */
    protected function scheduleSoftDelete(AdapterInterface $ea,$object)
    {
        $object_hash = spl_object_hash($object);
        if (in_array($object_hash, $this->visited)) {
            return;
        }

        $this->visited[] = $object_hash;

        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();

        $meta = $om->getClassMetadata(get_class($object));

        $config = $this->getConfiguration($om, $meta->name);

        $reflProp = $meta->getReflectionProperty($config['fieldName']);
        $oldValue = $reflProp->getValue($object);
        if ($oldValue instanceof \Datetime) {
            return; // want to hard delete
        }

        $evm->dispatchEvent(
            self::PRE_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );

        $date = new \DateTime();
        $reflProp->setValue($object, $date);

        $om->persist($object);

        $uow->setOriginalEntityProperty(spl_object_hash($object), $config['fieldName'], $date);
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);

        $uow->scheduleExtraUpdate($object, array(
                $config['fieldName'] => array($oldValue, $date)
            ));

        $this->scheduleCascadeDeletes($ea, $object);

        $evm->dispatchEvent(
            self::POST_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );

    }

    /**
     * Find entries configured for DB cascade delete and delete them softly
     *
     * @param AdapterInterface $ea
     * @param $object
     */
    protected function scheduleCascadeDeletes(AdapterInterface $ea, $object)
    {
        $config = $this->resolver->getObjectCascadeConfiguration($object);

        if (!$config) {
            return;
        }

        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $metadataFactory = $om->getMetadataFactory();

        foreach ($config as $targetClass => &$cascade) {
            if (!$this->isSoftDeleteable($om, $metadataFactory->getMetadataFor($targetClass))) {
                unset($cascade[CascadeResolver::ON_DELETE_CASCADE]);
            }
        }

        $operations = $this->resolver->getCascadeOperations($object, $config);

        foreach ($operations as $targetClass => $cascade) {
            if (isset($cascade[CascadeResolver::ON_DELETE_SET_NULL])) {

                $targetMetadata = $metadataFactory->getMetadataFor($targetClass);

                foreach ($cascade[CascadeResolver::ON_DELETE_SET_NULL] as $association => $objects) {

                    foreach ($objects as $o) {
                        $oldValue = $targetMetadata->getFieldValue($o, $association);

                        if ($oldValue === null) {
                            continue;
                        }

                        $targetMetadata->setFieldValue($o, $association, null);
                        $uow->setOriginalEntityProperty(spl_object_hash($o), $association, null);

                        $uow->scheduleExtraUpdate(
                            $o,
                            array(
                                $association => array($oldValue, null)
                            )
                        );
                    }

                }
            }
        }

        foreach ($operations as $cascade) {
            if (isset($cascade[CascadeResolver::ON_DELETE_CASCADE])) {
                foreach ($cascade[CascadeResolver::ON_DELETE_CASCADE] as $object) {
                    $this->scheduleSoftDelete($ea, $object);
                }
            }
        }
    }

    public function isSoftDeleteable(ObjectManager $om, ClassMetadata $meta)
    {

        $config = $this->getConfiguration($om, $meta->name);

        if (isset($config['softDeleteable']) && $config['softDeleteable']) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
