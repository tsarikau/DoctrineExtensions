<?php

namespace Gedmo\SoftDeleteable;

use Doctrine\Common\Persistence\ObjectManager,
    Doctrine\Common\Persistence\Mapping\ClassMetadata,
    Doctrine\ORM\Mapping\ClassMetadataInfo,
    Gedmo\Mapping\MappedEventSubscriber,
    Gedmo\Mapping\Event\AdapterInterface,
    Gedmo\Loggable\Mapping\Event\LoggableAdapter,
    Doctrine\Common\EventArgs
;

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

    protected $cascadeDeletes=false;

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
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getConfiguration($om, $meta->name);

            if (isset($config['softDeleteable']) && $config['softDeleteable']) {
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
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
        $uow->scheduleExtraUpdate($object, array(
                $config['fieldName'] => array($oldValue, $date)
            ));

        $evm->dispatchEvent(
            self::POST_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );

        $this->scheduleCascadeDeletes($ea,$object);
    }

    /**
     * Find entries configured for DB cascade delete and delete them softly
     *
     * @param AdapterInterface $ea
     * @param $object
     */
    protected function scheduleCascadeDeletes(AdapterInterface $ea, $object)
    {
        $om = $ea->getObjectManager();

        $this->loadCascadesConfig($ea);

        $meta = $om->getClassMetadata(get_class($object));

        if (!isset($this->cascadeDeletes[$meta->getName()])) {
            return;
        }

        $identifier=$meta->getIdentifierValues($object);

        if (count($identifier)===1) {
            $identifier=reset($identifier);
        }

        foreach ($this->cascadeDeletes[$meta->getName()] as $objectClass=>$fields) {

            /** @var \Doctrine\ORM\QueryBuilder $qb */
            $qb=$om->getRepository($objectClass)->createQueryBuilder('e');

            $orx=$qb->expr()->orX();

            foreach ($fields as $field) {
                $orx->add($qb->expr()->eq(sprintf('e.%s',$field),':identifier'));
            }

            $qb
                ->where($orx)
                ->setParameter('identifier',$identifier);

            foreach ($qb->getQuery()->getResult() as $cascadeObject) {
                $this->scheduleSoftDelete($ea,$cascadeObject);
            }
        }
    }

    /**
     * Load cascades mapping
     * @todo: metadata cache
     *
     * @param AdapterInterface $ea
     */
    protected function loadCascadesConfig(AdapterInterface $ea)
    {
        if ($this->cascadeDeletes!==false) {
            return;
        }

        $this->cascadeDeletes=array();

        $om = $ea->getObjectManager();

        $metadataFactory=$om->getMetadataFactory();

        /** @var \Doctrine\ORM\Mapping\ClassMetadata $meta  */
        foreach ($metadataFactory->getAllMetadata() as $meta) {

            if ($meta->isMappedSuperclass) {
                continue;
            }

            $config = $this->getConfiguration($om, $meta->name);

            if (!(isset($config['softDeleteable']) && $config['softDeleteable'])) {
                continue;
            }

            foreach ($meta->associationMappings as $association=>$mapping) {

                if ($mapping['type'] === ClassMetadataInfo::MANY_TO_ONE) {

                    $joinColumnMapping=$mapping['joinColumns'][0];

                    if (!isset($joinColumnMapping['onDelete'])) {
                        continue;
                    }

                    if (strtolower($joinColumnMapping['onDelete'])!=='cascade') {
                        continue;
                    }

                } else {
                    continue;
                }

                $targetEntityMetadata=$metadataFactory->getMetadataFor($mapping['targetEntity']);

                foreach (array_merge(array($targetEntityMetadata->name),$targetEntityMetadata->subClasses) as $targetEntity) {
                    if (!isset($this->cascadeDeletes[$targetEntity])) {
                        $this->cascadeDeletes[$targetEntity]=array();
                    }

                    if (!isset($this->cascadeDeletes[$targetEntity][$meta->name])) {
                        $this->cascadeDeletes[$targetEntity][$meta->name]=array();
                    }

                    $this->cascadeDeletes[$targetEntity][$meta->name][]=$association;
                }
            }

        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
