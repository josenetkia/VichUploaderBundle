<?php

namespace Vich\UploaderBundle\Handler;


use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Traits\Encrypt;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Vich\UploaderBundle\Crypt\Encryption;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;
use Vich\UploaderBundle\Injector\FileInjectorInterface;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use Vich\UploaderBundle\Storage\StorageInterface;

class UploadHandler extends AbstractHandler
{
    use Encrypt;

    /**
     * @var Encryption
     */
    protected $encryption;

    /**
     * @param PropertyMappingFactory $factory
     * @param StorageInterface $storage
     * @param FileInjectorInterface $injector
     * @param EventDispatcherInterface $dispatcher
     * @param Encryption $encryption
     */
    public function __construct(
        PropertyMappingFactory $factory,
        StorageInterface $storage,
        FileInjectorInterface $injector,
        EventDispatcherInterface $dispatcher,
        Encryption $encryption
    ) {
        parent::__construct($factory, $storage);

        $this->injector = $injector;
        $this->dispatcher = $dispatcher;
        $this->encryption = $encryption;
    }

    /**
     * {@inheritdoc}
     */
    public function upload($obj, string $fieldName): void
    {
        $mapping = $this->getMapping($obj, $fieldName);

        if (!$this->hasUploadedFile($obj, $mapping)) {
            return;
        }

        $this->dispatch(Events::PRE_UPLOAD, new Event($obj, $mapping));
        if ($this->isEncryptFile($mapping)) {
            $fileRealPath = $mapping->getFile($obj)->getRealPath();
            file_put_contents($fileRealPath, $this->encryption->encrypt(file_get_contents($fileRealPath)));
        }
        $this->storage->upload($obj, $mapping);
        $this->injector->injectFile($obj, $mapping);

        $this->dispatch(Events::POST_UPLOAD, new Event($obj, $mapping));
    }

    public function inject($obj, string $fieldName): void
    {
        $mapping = $this->getMapping($obj, $fieldName);

        $this->dispatch(Events::PRE_INJECT, new Event($obj, $mapping));

        $this->injector->injectFile($obj, $mapping);

        $this->dispatch(Events::POST_INJECT, new Event($obj, $mapping));
    }

    public function clean($obj, string $fieldName, ?string $forcedFilename = null): void
    {
        $mapping = $this->getMapping($obj, $fieldName);

        // nothing uploaded, do not remove anything
        if (!$this->hasUploadedFile($obj, $mapping)) {
            return;
        }

        $this->remove($obj, $fieldName, $forcedFilename);
    }

    public function remove($obj, string $fieldName, ?string $forcedFilename = null): void
    {
        $mapping = $this->getMapping($obj, $fieldName);
        $oldFilename = $mapping->getFileName($obj);

        // nothing to remove, avoid dispatching useless events
        if (empty($oldFilename)) {
            return;
        }

        $preEvent = new Event($obj, $mapping);

        $this->dispatch(Events::PRE_REMOVE, $preEvent);

        if ($preEvent->isCanceled()) {
            return;
        }

        $this->storage->remove($obj, $mapping, $forcedFilename);
        $mapping->erase($obj);

        $this->dispatch(Events::POST_REMOVE, new Event($obj, $mapping));
    }

    protected function dispatch(string $eventName, Event $event): void
    {
        $this->dispatcher->dispatch($event, $eventName);
    }

    protected function hasUploadedFile(object $obj, PropertyMapping $mapping): bool
    {
        $file = $mapping->getFile($obj);

        return null !== $file && $file instanceof UploadedFile;
    }
}